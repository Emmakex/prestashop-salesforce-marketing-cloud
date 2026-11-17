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
growthRuntimeAssert(is_object($module) && $module->version === '0.8.2'
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

$health = $module->healthReport();
$cron = $module->cronReport();
growthRuntimeAssert($health['status'] === 'blocked_test'
    && $health['mode'] === 'test'
    && $health['module_version'] === '0.8.2'
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
