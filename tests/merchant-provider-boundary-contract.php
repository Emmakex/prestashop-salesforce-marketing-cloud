<?php
/**
 * Real-module provider boundary — no SQL, Salesforce requests or callbacks.
 * Tests run with PHP 7.1/8.5 and are independent of credentials/merchant data.
 */
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.8');
}
class Module
{
    public function __construct() {}
    public function l($text) { return $text; }
    public function displayError($message) { return 'ERROR:' . $message; }
    public function displayConfirmation($message) { return 'OK:' . $message; }
    public function displayInformation($message) { return 'INFO:' . $message; }
}
class Configuration
{
    public static $values = [];
    public static $writes = [];
    public static function get($key)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : false;
    }
    public static function updateValue($key, $value)
    {
        self::$values[$key] = $value;
        self::$writes[$key] = $value;
        return true;
    }
}
class Tools
{
    public static $values = [];
    public static function getValue($key)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : false;
    }
}
require_once dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\MerchantProviderGate as Gate;

function boundaryAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Provider module boundary failed: " . $message . PHP_EOL);
        exit(1);
    }
}
function boundaryReject($call, $expected)
{
    try {
        call_user_func($call);
    } catch (RuntimeException $e) {
        boundaryAssert($e->getMessage() === $expected, 'wrong_reject_' . $expected);
        return;
    }
    boundaryAssert(false, 'invalid_setting_accepted_' . $expected);
}
foreach ([
    [null, Gate::GROWTH, 'test', 'provider_merchant_required'],
    ['ABH', null, 'test', 'provider_explicit_selection_required'],
    ['ABH', Gate::ENGAGEMENT, 'test', 'provider_abh_growth_only'],
    ['ABH', Gate::GROWTH, 'production', 'provider_growth_not_authorized_for_production'],
    ['', Gate::ENGAGEMENT, 'test', 'provider_merchant_required'],
    ['lowercase', Gate::ENGAGEMENT, 'test', 'provider_merchant_required'],
    ['', Gate::UNSELECTED, 'production', 'provider_unselected_must_be_test'],
    ['ABH', Gate::UNSELECTED, 'test', 'provider_unselected_must_be_test'],
    ['OTHER', Gate::GROWTH, 'unknown', 'provider_mode_invalid'],
] as $scenario) {
    boundaryReject(function () use ($scenario) {
        Gate::validate($scenario[0], $scenario[1], $scenario[2]);
    }, $scenario[3]);
}
boundaryAssert(Gate::validate('', Gate::UNSELECTED, 'test') === true
    && Gate::validate('ABH', Gate::GROWTH, 'test') === true
    && Gate::validate('OTHER', Gate::ENGAGEMENT, 'production') === true,
    'valid_explicit_provider_rejected');

foreach ([false, null, '', 'marketing_cloud_engagement', 'unselected'] as $provider) {
    boundaryAssert(!Gate::legacyCaptureAllowed('ABH', $provider)
        && !Gate::legacyDeliveryAllowed('ABH', $provider, 'production'),
        'abh_fell_back_to_engagement');
}
boundaryAssert(!Gate::legacyCaptureAllowed('OTHER', Gate::GROWTH)
    && !Gate::legacyDeliveryAllowed('OTHER', Gate::GROWTH, 'production')
    && Gate::legacyCaptureAllowed('OTHER', Gate::ENGAGEMENT)
    && !Gate::legacyDeliveryAllowed('OTHER', Gate::ENGAGEMENT, 'test')
    && Gate::legacyDeliveryAllowed('OTHER', Gate::ENGAGEMENT, 'production')
    && Gate::growthTestSelected('ABH', Gate::GROWTH, 'test'),
    'delivery_not_scoped_by_merchant_provider_mode');

$module = new PsSalesforceBridge();
$denyPaths = function () use ($module) {
    boundaryAssert($module->hookActionCustomerAccountAdd(['newCustomer' => (object)['id' => 1]]) === false,
        'legacy_customer_hook_open');
    boundaryAssert($module->hookActionCustomerAccountUpdate(['customer' => (object)['id' => 1]]) === false,
        'legacy_customer_update_open');
    boundaryAssert($module->hookActionValidateOrder(['order' => (object)['id' => 1]]) === false,
        'legacy_order_hook_open');
    boundaryAssert($module->hookActionOrderStatusPostUpdate(['id_order' => 1]) === false,
        'legacy_order_status_hook_open');
    boundaryAssert($module->hookActionCartSave(['cart' => (object)['id' => 1]]) === false,
        'legacy_cart_hook_open');
    foreach ([
        'processCustomerQueue',
        'processOrderQueue',
        'processAbandonedCartJourneyQueue',
        'processCustomerBackfill',
        'processCartAbandonmentCandidates',
    ] as $method) {
        $out = $module->$method();
        boundaryAssert(is_array($out) && isset($out['skipped'])
            && $out['skipped'] === 'provider_not_legacy',
            'legacy_worker_or_backfill_open_' . $method);
    }
};
$denyPaths(); // No legacy keys yet: fail closed.
Configuration::$values = [
    'PS_SFMC_MERCHANT' => 'ABH',
    'PS_SFMC_PROVIDER' => Gate::GROWTH,
    'PS_SFMC_MODE' => 'test',
    'PS_SFMC_CLIENT_SECRET' => 'RETAIN_ONLY_EXISTING_LEGACY_SECRET',
    'PS_SFMC_CLIENT_ID' => 'RETAIN_ONLY_LEGACY_CLIENT',
    'PS_SFMC_AUTH_BASE_URI' => 'https://old-legacy.example.invalid',
    'PS_SFMC_REST_BASE_URI' => 'https://old-rest.example.invalid',
    'PS_SFMC_CUSTOMER_DE_KEY' => 'DO_NOT_OVERWRITE_DE',
    'PS_SFMC_ORDER_DE_KEY' => 'DO_NOT_OVERWRITE_ORDER_DE',
    'PS_SFMC_JOURNEY_EVENT_DEFINITION_KEY' => 'DO_NOT_OVERWRITE_JOURNEY',
];
$denyPaths(); // Explicit ABH Growth never executes Engagement hook/sender.
$save = new ReflectionMethod('PsSalesforceBridge', 'saveConfiguration');
$save->setAccessible(true);
Tools::$values = [
    'PS_SFMC_MERCHANT' => 'ABH',
    'PS_SFMC_PROVIDER' => Gate::GROWTH,
    'PS_SFMC_MODE' => 'test',
    'PS_SFMC_CLIENT_SECRET' => 'INJECTED_SHOULD_NOT_SAVE',
    'PS_SFMC_CLIENT_ID' => 'NEW_LEGACY_NOT_APPLICABLE',
    'PS_SFMC_AUTH_BASE_URI' => 'https://new-legacy.example.invalid',
    'PS_SFMC_REST_BASE_URI' => 'https://new-rest.example.invalid',
    'PS_SFMC_CUSTOMER_DE_KEY' => 'NEW_LEGACY_DE_NOT_APPLICABLE',
    'PS_SFMC_ORDER_DE_KEY' => 'NEW_LEGACY_ORDER_NOT_APPLICABLE',
    'PS_SFMC_JOURNEY_EVENT_DEFINITION_KEY' => 'NEW_LEGACY_JOURNEY_NOT_APPLICABLE',
    'PS_SFMC_CART_CONSENT_MODE' => 'marketing_optin',
];
$out = $save->invoke($module);
boundaryAssert(strpos($out, 'OK:') === 0, 'growth_test_config_not_saved');
foreach ([
    'PS_SFMC_CLIENT_SECRET' => 'RETAIN_ONLY_EXISTING_LEGACY_SECRET',
    'PS_SFMC_CLIENT_ID' => 'RETAIN_ONLY_LEGACY_CLIENT',
    'PS_SFMC_AUTH_BASE_URI' => 'https://old-legacy.example.invalid',
    'PS_SFMC_REST_BASE_URI' => 'https://old-rest.example.invalid',
    'PS_SFMC_CUSTOMER_DE_KEY' => 'DO_NOT_OVERWRITE_DE',
    'PS_SFMC_ORDER_DE_KEY' => 'DO_NOT_OVERWRITE_ORDER_DE',
    'PS_SFMC_JOURNEY_EVENT_DEFINITION_KEY' => 'DO_NOT_OVERWRITE_JOURNEY',
] as $key => $expected) {
    boundaryAssert(Configuration::get($key) === $expected
        && !array_key_exists($key, Configuration::$writes),
        'growth_selection_overwrote_legacy_credentials_' . $key);
}
$test = new ReflectionMethod('PsSalesforceBridge', 'testConnection');
$test->setAccessible(true);
boundaryAssert(strpos($test->invoke($module), 'INFO:') === 0,
    'growth_selection_ran_legacy_oauth_client');
foreach ([
    ['ABH', Gate::ENGAGEMENT, 'test'],
    ['ABH', Gate::GROWTH, 'production'],
    ['', Gate::GROWTH, 'test'],
    ['ABH', Gate::UNSELECTED, 'test'],
] as $invalid) {
    Tools::$values['PS_SFMC_MERCHANT'] = $invalid[0];
    Tools::$values['PS_SFMC_PROVIDER'] = $invalid[1];
    Tools::$values['PS_SFMC_MODE'] = $invalid[2];
    Configuration::$writes = [];
    boundaryAssert(strpos($save->invoke($module), 'ERROR:') === 0
        && Configuration::$writes === [],
        'invalid_provider_committed_settings');
}
boundaryAssert(Configuration::get('PS_SFMC_PROVIDER') === Gate::GROWTH
    && Configuration::get('PS_SFMC_MODE') === 'test',
    'invalid_provider_was_committed');

$code = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
foreach (['function processCustomerQueue', 'function processOrderQueue',
          'function processAbandonedCartJourneyQueue'] as $entry) {
    $idx = strpos($code, $entry);
    boundaryAssert($idx !== false
        && strpos(substr($code, $idx, 420), 'legacyDeliveryAllowed()') !== false,
        'missing_egress_entry_guard');
}
boundaryAssert(strpos($code, "CONFIG_PROVIDER = 'PS_SFMC_PROVIDER'") !== false
    && strpos($code, 'MerchantProviderGate::UNSELECTED') !== false,
    'provider_selection_missing_from_real_module');
echo "Real PrestaShop module: explicit provider selection, ABH Growth Test, legacy paths denied.\n";
