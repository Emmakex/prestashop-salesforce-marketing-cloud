<?php
/** Live PrestaShop 1.7/8/9 smoke: Growth-only module, no transport. */
if (PHP_SAPI !== 'cli') { exit(1); }
$root = getenv('PS_ROOT') ?: '/var/www/html';
chdir($root);
require_once $root . '/config/config.inc.php';

function growthRuntimeAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, "Growth runtime failed: " . $reason . PHP_EOL);
        exit(1);
    }
}
function growthRuntimeHookRegistered($moduleId, $name)
{
    $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'hook_module hm '
        . 'INNER JOIN ' . _DB_PREFIX_ . 'hook h ON h.id_hook = hm.id_hook '
        . 'WHERE hm.id_module = ' . (int) $moduleId
        . " AND LOWER(h.name) = LOWER('" . pSQL($name) . "')";
    return (int) Db::getInstance()->getValue($sql) > 0;
}
function growthRuntimeOldTableCount()
{
    $table = pSQL(_DB_PREFIX_ . 'sfmc_queue');
    $exists = (int) Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'"
    );
    if ($exists !== 1) {
        return null;
    }
    return (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'sfmc_queue'
    );
}
growthRuntimeAssert(!getenv('EXPECTED_PS_VERSION') ||
    (string) _PS_VERSION_ === (string) getenv('EXPECTED_PS_VERSION'),
    'wrong_prestashop_version');
growthRuntimeAssert(Module::isInstalled('pssalesforcebridge'), 'module_not_installed');
$module = Module::getInstanceByName('pssalesforcebridge');
growthRuntimeAssert(is_object($module) && $module->version === '0.9.4'
    && $module->author === 'ALFABH',
    'wrong_growth_module_release');
growthRuntimeAssert((string) Configuration::get('PS_SFMC_MODE') === 'test',
    'upgrade_or_install_not_test_mode');
growthRuntimeAssert((string) Configuration::get('PS_SFMC_CRON_TOKEN') !== '',
    'protected_token_missing');
growthRuntimeAssert(
    PsSalesforceBridge::PROVIDER === 'marketing_cloud_growth'
    && PsSalesforceBridge::DATA_PLATFORM === 'data_cloud',
    'runtime_wrong_product');
foreach ([
    'actionCustomerAccountAdd',
    'actionCustomerAccountUpdate',
    'actionValidateOrder'
] as $name) {
    growthRuntimeAssert(growthRuntimeHookRegistered($module->id, $name),
        'hook_missing_' . $name);
}

$before = growthRuntimeOldTableCount();
$customer = (object) [
    'id' => 101, 'id_shop' => 1,
    'email' => 'not-sent@example.invalid',
    'newsletter' => 1
];
$order = (object) [
    'id' => 201, 'id_shop' => 1,
    'reference' => 'never-exported'
];
growthRuntimeAssert($module->hookActionCustomerAccountAdd(
    ['newCustomer' => $customer]) === true
    && $module->hookActionCustomerAccountUpdate(
        ['customer' => $customer]) === true
    && $module->hookActionValidateOrder(
        ['order' => $order]) === true,
    'hook_failed');
$after = growthRuntimeOldTableCount();
growthRuntimeAssert($before === $after, 'old_queue_mutated_by_growth_hook');

// Exercise the actual PrestaShop hook dispatcher as well as the direct module
// methods above. This catches hook registration/dispatch compatibility gaps
// across PS 1.7, 8 and 9 without creating a customer, writing Growth tables
// or invoking unrelated modules: the third argument selects our module only.
// Staging capture must be OFF throughout this disposable runtime check.
growthRuntimeAssert(
    (string) Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'off',
    'native_hook_dispatch_requires_local_capture_off'
);
$nativeCustomer = new Customer();
$nativeCustomer->id = 987654321; // Synthetic, deliberately never persisted.
$nativeCustomer->id_shop = 1;
$nativeCustomer->email = 'hook-ci@example.invalid';
$nativeCustomer->firstname = 'CI';
$nativeCustomer->lastname = 'ONLY';
$nativeCustomer->newsletter = 0;
$nativeCustomer->optin = 0;
$nativeDispatched = Hook::exec(
    'actionCustomerAccountAdd',
    ['newCustomer' => $nativeCustomer],
    (int) $module->id
);
growthRuntimeAssert(
    !empty($nativeDispatched),
    'native_customer_add_hook_not_dispatched'
);
growthRuntimeAssert(
    growthRuntimeOldTableCount() === $after
    && (string) Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'off',
    'native_dispatch_mutated_legacy_queue_or_capture_mode'
);

// Verify the actual native Customer object (not stdClass) also crosses the
// Growth capture boundary. Inject a disposable in-memory store; do NOT arm
// local capture, create tables or export a record. This directly tests the
// source shape of account-add on PS 1.7/8/9, including nullable fields.
$nativeCaptureStoreCalls = 0;
$nativeCapture = \Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture::capture(
    'actionCustomerAccountAdd',
    ['newCustomer' => $nativeCustomer],
    (string) _PS_VERSION_,
    'ABH',
    'growth_g7_ps_native_ci',
    str_repeat('c', 64),
    function ($identity, $newRowFactory) use (&$nativeCaptureStoreCalls) {
        ++$nativeCaptureStoreCalls;
        $row = $newRowFactory();
        $row['accepted_sequence'] = 1;
        return ['row' => $row, 'inserted' => true];
    }
);
growthRuntimeAssert(
    $nativeCaptureStoreCalls === 1
    && $nativeCapture['status'] === 'blocked_local_only'
    && $nativeCapture['inserted'] === true
    && $nativeCapture['outbound_worker_authorized'] === false
    && $nativeCapture['marketing_sends_authorized'] === false
    && growthRuntimeOldTableCount() === $after
    && (string) Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'off',
    'native_customer_source_shape_rejected_or_side_effect'
);

// G7 order gate: native PrestaShop Order object must cross the same blocked
// local capture boundary, not only a stdClass fixture. No real order, no
// payment module, no Salesforce request and no new database table.
$nativeOrder = new Order();
$nativeOrder->id = 987654322; // Synthetic, never persisted.
$nativeOrder->id_shop = 1;
$nativeOrder->id_customer = 987654321;
$nativeOrder->id_cart = 987654323;
$nativeOrder->id_currency = 1;
$nativeOrder->total_paid = 44.00;
// PrestaShop dispatches actionValidateOrder before initial state is applied.
$nativeOrder->current_state = null;
$nativeOrder->date_upd = '2026-11-18 08:00:00';
$orderDispatched = Hook::exec(
    'actionValidateOrder',
    ['order' => $nativeOrder],
    (int) $module->id
);
growthRuntimeAssert(
    !empty($orderDispatched) && growthRuntimeOldTableCount() === $after,
    'native_order_hook_not_dispatched_or_legacy_queue_touched'
);
$nativeOrderStoreCalls = 0;
$nativeOrderCapture = \Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture::capture(
    'actionValidateOrder',
    ['order' => $nativeOrder],
    (string) _PS_VERSION_,
    'ABH',
    'growth_g7_ps_native_ci',
    str_repeat('c', 64),
    function ($identity, $factory) use (&$nativeOrderStoreCalls) {
        ++$nativeOrderStoreCalls;
        $row = $factory();
        $row['accepted_sequence'] = 2;
        return ['row' => $row, 'inserted' => true];
    }
);
growthRuntimeAssert(
    $nativeOrderStoreCalls === 1
    && $nativeOrderCapture['status'] === 'blocked_local_only'
    && $nativeOrderCapture['inserted'] === true
    && $nativeOrderCapture['outbound_worker_authorized'] === false
    && $nativeOrderCapture['marketing_sends_authorized'] === false
    && growthRuntimeOldTableCount() === $after
    && (string) Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'off',
    'native_order_source_shape_rejected_or_side_effect'
);

$health = $module->healthReport();
$cron = $module->cronReport();
growthRuntimeAssert($health['status'] === 'blocked_test'
    && $health['mode'] === 'test'
    && $health['module_version'] === '0.9.4'
    && $health['record_ingestion_allowed'] === false
    && $health['marketing_sends_allowed'] === false
    && $cron['status'] === 'blocked_test'
    && $cron['marketing_sends']['skipped'] === 'approval_required',
    'outbound_gate_not_blocked');

$preflight = \Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\PrestaShopReadinessProbe::inspect(
    \Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\ShopResolver::activeShopIds(Context::getContext())
);
growthRuntimeAssert($preflight['status'] === 'blocked'
    && $preflight['schema_upload_allowed'] === false
    && $preflight['record_ingestion_allowed'] === false
    && $preflight['marketing_sends_allowed'] === false
    && $preflight['checks']['shops_identified'] === true
    && $preflight['checks']['date_columns_inspected'] === true,
    'metadata_preflight_unavailable_or_unsafe');
$html = (new \Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\InstallationReadinessDashboard())
    ->render($preflight);
growthRuntimeAssert(strpos($html, 'BLOCKED') !== false,
    'dashboard_not_fail_closed');

echo "Growth-only runtime PASS on PS " . _PS_VERSION_ . ": no old queue writes or sends.\n";
