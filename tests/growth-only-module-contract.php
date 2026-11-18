<?php
/** Growth + Data Cloud only: module, upgrade, config and no-PII hooks. */
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.8');
}
class Module
{
    public $registerCalls = [];
    public function __construct() {}
    public function install() { return true; }
    public function uninstall() { return true; }
    public function registerHook($name) { $this->registerCalls[] = $name; return true; }
    public function l($message) { return $message; }
    public function displayError($message) { return "ERROR:" . $message; }
    public function displayConfirmation($message) { return "OK:" . $message; }
    public function displayInformation($message) { return "INFO:" . $message; }
}
class Configuration
{
    public static $values = [];
    public static $writes = [];
    public static $deleted = [];
    public static function get($key) {
        return array_key_exists($key, self::$values) ? self::$values[$key] : false;
    }
    public static function updateValue($key, $value) {
        self::$values[$key] = $value;
        self::$writes[$key] = $value;
        return true;
    }
    public static function deleteByName($key) {
        self::$deleted[] = $key;
        unset(self::$values[$key]);
        return true;
    }
}
class Tools
{
    public static $input = [];
    public static function getValue($key) {
        return array_key_exists($key, self::$input) ? self::$input[$key] : false;
    }
}
require_once dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/upgrade/upgrade-0.8.0.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/upgrade/upgrade-0.8.1.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/upgrade/upgrade-0.8.2.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/upgrade/upgrade-0.8.3.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/upgrade/upgrade-0.8.4.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/upgrade/upgrade-0.8.6.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOnlyHookPreflight as Hook;

function growthAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, "Growth-only module FAIL: " . $reason . PHP_EOL);
        exit(1);
    }
}

$old = [
    'PS_SFMC_CLIENT_ID' => 'SENSITIVE_OLD_CLIENT',
    'PS_SFMC_CLIENT_SECRET' => 'SENSITIVE_OLD_SECRET',
    'PS_SFMC_AUTH_BASE_URI' => 'https://legacy.example.invalid',
    'PS_SFMC_CUSTOMER_DE_KEY' => 'SENSITIVE_OLD_RETIRED_STORE',
    'PS_SFMC_RETENTION_QUEUE_DAYS' => 'SENSITIVE_OLD_SETTING',
    'PS_SFMC_MODE' => 'production',
];
Configuration::$values = $old;
growthAssert(upgrade_module_0_8_0(null) === true
    && upgrade_module_0_8_1(null) === true
    && upgrade_module_0_8_2(null) === true
    && upgrade_module_0_8_3(null) === true
    && upgrade_module_0_8_4(null) === true
    && upgrade_module_0_8_5(null) === true
    && upgrade_module_0_8_6(null) === true, 'upgrade_failed');
growthAssert(Configuration::get('PS_SFMC_MODE') === 'test', 'upgrade_not_fail_closed');
foreach ($old as $key => $value) {
    if ($key !== 'PS_SFMC_MODE') {
        growthAssert(Configuration::get($key) === $value
            && !array_key_exists($key, Configuration::$writes),
            'upgrade_deleted_old_config_' . $key);
    }
}

// Updating from an armed 0.8.1 staging install must stop capture,
 // preserve the local HMAC and namespace, and never authorize sends.
Configuration::$values = [
    'PS_SFMC_MODE' => 'test',
    'PS_GROWTH_LOCAL_CAPTURE' => 'staging_armed',
    'PS_GROWTH_CAPTURE_HMAC_KEY' => str_repeat('c', 64),
    'PS_GROWTH_MERCHANT' => 'ABH',
    'PS_GROWTH_NAMESPACE' => 'abh_ci_previous_install',
];
Configuration::$writes = [];
growthAssert(upgrade_module_0_8_2(null) === true
    && Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'off'
    && Configuration::get('PS_GROWTH_CAPTURE_HMAC_KEY') === str_repeat('c', 64)
    && Configuration::get('PS_GROWTH_NAMESPACE') === 'abh_ci_previous_install',
    'upgrade_081_revocation_or_identity_preservation');

Configuration::$values = [];
Configuration::$writes = [];
$module = new PsSalesforceBridge();
$readbackMethod=new ReflectionMethod('PsSalesforceBridge','verifyStagingNativeReadback');
$readbackMethod->setAccessible(true);
growthAssert(strpos($readbackMethod->invoke($module),
    'Local staging capture must be armed')!==false,
    'g7_readback_allowed_while_off');
$safeReason = new ReflectionMethod('PsSalesforceBridge', 'readbackSafeFailureCode');
$safeReason->setAccessible(true);
growthAssert($safeReason->invoke(null,
    new RuntimeException('g7_native_draft_customer_flag_invalid'))
    === 'native_customer_flag_unverified'
    && $safeReason->invoke(null,
        new RuntimeException('g7_readback_ledger_outdated'))
        === 'ledger_event_not_latest'
    && $safeReason->invoke(null,
        new RuntimeException('SQL private customer@fixture.invalid id=1234'))
        === 'unclassified_blocked',
    'readback_diagnostic_leaked_raw_exception');
growthAssert($module->version === '0.8.6'
    && $module->author === 'ALFABH'
    && PsSalesforceBridge::PROVIDER === 'marketing_cloud_growth'
    && PsSalesforceBridge::DATA_PLATFORM === 'data_cloud',
    'wrong_product_or_version');
growthAssert($module->install() === true, 'install_failed');
growthAssert($module->registerCalls === [
    'actionCustomerAccountAdd',
    'actionCustomerAccountUpdate',
    'actionValidateOrder',
], 'wrong_hooks');
growthAssert(Configuration::get('PS_SFMC_MODE') === 'test'
    && strlen((string) Configuration::get('PS_SFMC_CRON_TOKEN')) >= 40
    && Configuration::get('PS_GROWTH_LOCAL_CAPTURE') === 'off',
    'new_install_test_defaults');

$privateCustomer = (object) [
    'id' => 101, 'id_shop' => 1,
    'email' => 'DO_NOT_EXPORT@example.invalid',
    'firstname' => 'PRIVATE_CI_NAME',
    'newsletter' => 1,
];
$order = (object) [
    'id' => 202, 'id_shop' => 1,
    'reference' => 'PRIVATE_CI_ORDER', 'total_paid' => 55.00,
];
foreach ([
    ['CUSTOMER_CREATED', ['newCustomer' => $privateCustomer]],
    ['CUSTOMER_UPDATED', ['customer' => $privateCustomer]],
    ['ORDER_CREATED', ['order' => $order]]
] as $fixture) {
    $report = Hook::inspect($fixture[0], $fixture[1]);
    growthAssert($report['status'] === 'review_only_not_captured'
        && $report['record_ingestion_allowed'] === false
        && $report['marketing_sends_allowed'] === false, 'hook_preflight_mismatch');
    growthAssert(!preg_match('/DO_NOT_EXPORT|PRIVATE_CI|55\.00|101|202/',
        json_encode($report)), 'pii_or_identity_leaked');
}
foreach ([
    ['CUSTOMER_CREATED', ['newCustomer' => (object)['id' => 1]]],
    ['ORDER_CREATED', ['order' => (object)['id' => 3, 'id_shop' => 0]]],
    ['ORDER_CREATED', ['order' => (object)['id' => '01', 'id_shop' => 1]]],
    ['CART_ABANDONED', ['cart' => (object)['id' => 5, 'id_shop' => 1]]],
    ['CUSTOMER_UPDATED', []],
] as $fixture) {
    $report = Hook::inspect($fixture[0], $fixture[1]);
    growthAssert($report['status'] === 'blocked', 'missing_scope_or_unsupported_accepted');
}
growthAssert($module->hookActionCustomerAccountAdd(['newCustomer' => $privateCustomer]) === true
    && $module->hookActionCustomerAccountUpdate(['customer' => $privateCustomer]) === true
    && $module->hookActionValidateOrder(['order' => $order]) === true
    && $module->hookActionCartSave([]) === true
    && $module->hookActionOrderStatusPostUpdate([]) === true,
    'hook_caused_error');
growthAssert(array_keys(Configuration::$writes) === [
    'PS_SFMC_CRON_TOKEN', 'PS_SFMC_MODE', 'PS_GROWTH_LOCAL_CAPTURE'
], 'hook_did_config_write');

$save = new ReflectionMethod('PsSalesforceBridge', 'saveGrowthSettings');
$save->setAccessible(true);
Tools::$input = ['PS_GROWTH_MERCHANT' => 'ABH', 'PS_GROWTH_NAMESPACE' => 'abh_ci_shopone'];
growthAssert(strpos($save->invoke($module), 'OK:') === 0, 'valid_test_identity_not_saved');
growthAssert(Configuration::get('PS_GROWTH_MERCHANT') === 'ABH'
    && Configuration::get('PS_GROWTH_NAMESPACE') === 'abh_ci_shopone',
    'identity_state_invalid');
Tools::$input = ['PS_GROWTH_MERCHANT' => 'ABH', 'PS_GROWTH_NAMESPACE' => 'changed_ci_shopone'];
growthAssert(strpos($save->invoke($module), 'ERROR:') === 0, 'namespace_reassignment_allowed');
growthAssert(Configuration::get('PS_GROWTH_NAMESPACE') === 'abh_ci_shopone',
    'namespace_was_rewritten');
Tools::$input = ['PS_GROWTH_MERCHANT' => 'lowercase', 'PS_GROWTH_NAMESPACE' => 'abh_ci_shopone'];
growthAssert(strpos($save->invoke($module), 'ERROR:') === 0, 'invalid_identity_allowed');

$health = $module->healthReport();
$cron = $module->cronReport();
growthAssert($health['provider'] === 'marketing_cloud_growth'
    && $health['mode'] === 'test'
    && $health['record_ingestion_allowed'] === false
    && $health['marketing_sends_allowed'] === false
    && $health['schema_upload_allowed'] === false
    && $cron['status'] === 'blocked_test'
    && $cron['data_cloud_ingestion']['skipped'] === 'schema_required',
    'runtime_did_not_block_outbound');
growthAssert(!array_key_exists('customer_delivery', $cron)
    && !array_key_exists('retired_flow_delivery', $cron), 'old_delivery_still_exposed');

growthAssert($module->uninstall() === true, 'uninstall_failed');
growthAssert(Configuration::$deleted === [
    'PS_GROWTH_MERCHANT', 'PS_GROWTH_NAMESPACE'
], 'uninstall_deleted_unowned_historical_configuration');
// No unexpected integration directories may be distributed.
foreach (scandir(dirname(__DIR__) . '/pssalesforcebridge/src') as $sub) {
    if ($sub === '.' || $sub === '..' || !is_dir(dirname(__DIR__) . '/pssalesforcebridge/src/' . $sub)) {
        continue;
    }
    growthAssert(in_array($sub, ['Compatibility', 'DataCloud', 'Growth', 'Provider'], true),
        'unsupported_module_directory_' . $sub);
}
$moduleCode = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
$cronCode = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/controllers/front/cron.php');
foreach (['curl_exec(', 'stream_socket_client(', 'fsockopen('] as $forbidden) {
    growthAssert(strpos($moduleCode, $forbidden) === false
        && strpos($cronCode, $forbidden) === false,
        'unreviewed_transport_' . $forbidden);
}
echo "Growth-only module: clean install, safe upgrade, no legacy execution or sends.\n";
