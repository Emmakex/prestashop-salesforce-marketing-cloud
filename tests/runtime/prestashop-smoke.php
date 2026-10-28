<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "runtime smoke must run from CLI\n");
    exit(1);
}

$root = getenv('PS_ROOT');
if (!$root) {
    $root = '/var/www/html';
}

chdir($root);
require_once $root . '/config/config.inc.php';

function runtimeFail($message)
{
    fwrite(STDERR, 'runtime certification failed: ' . $message . PHP_EOL);
    exit(1);
}

function runtimeAssert($condition, $message)
{
    if (!$condition) {
        runtimeFail($message);
    }
}

function runtimeTableExists($name)
{
    $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . pSQL(_DB_PREFIX_ . $name) . "'";
    return (int) Db::getInstance()->getValue($sql) === 1;
}

function runtimeHookRegistered($moduleId, $hookName)
{
    $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'hook_module` hm '
        . 'INNER JOIN `' . _DB_PREFIX_ . 'hook` h ON h.id_hook = hm.id_hook '
        . 'WHERE hm.id_module = ' . (int) $moduleId
        . " AND LOWER(h.name) = LOWER('" . pSQL($hookName) . "')";

    return (int) Db::getInstance()->getValue($sql) > 0;
}

function runtimeCustomer()
{
    $id = (int) Db::getInstance()->getValue(
        'SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer` WHERE active = 1 ORDER BY id_customer ASC'
    );

    if ($id > 0) {
        return new Customer($id);
    }

    $context = Context::getContext();
    $customer = new Customer();
    $customer->id_shop = isset($context->shop->id) ? (int) $context->shop->id : 1;
    $customer->id_shop_group = isset($context->shop->id_shop_group) ? (int) $context->shop->id_shop_group : 1;
    $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    if ($customer->id_default_group <= 0) {
        $customer->id_default_group = 3;
    }
    $customer->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $customer->firstname = 'Runtime';
    $customer->lastname = 'Certification';
    $customer->email = 'runtime-cert-' . time() . '@example.invalid';
    $password = 'Runtime-Certification-123!';
    if (method_exists('Tools', 'hash')) {
        $customer->passwd = Tools::hash($password);
    } else {
        $customer->passwd = Tools::encrypt($password);
    }
    $customer->active = 1;
    $customer->newsletter = 1;
    $customer->optin = 1;

    runtimeAssert($customer->add(), 'unable to create runtime customer');

    return $customer;
}

function runtimeCart($customer)
{
    $context = Context::getContext();
    $cart = new Cart();
    $cart->id_shop = isset($context->shop->id) ? (int) $context->shop->id : 1;
    $cart->id_shop_group = isset($context->shop->id_shop_group) ? (int) $context->shop->id_shop_group : 1;
    $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    if ($cart->id_currency <= 0 && isset($context->currency->id)) {
        $cart->id_currency = (int) $context->currency->id;
    }
    $cart->id_customer = (int) $customer->id;
    if (isset($customer->secure_key)) {
        $cart->secure_key = (string) $customer->secure_key;
    }

    // Cart::add() exercises the real core object lifecycle. Keep this fixture
    // empty because updateQty fans out to unrelated stock/analytics modules
    // whose CLI dependencies are outside this connector's certification scope.
    runtimeAssert($cart->add(), 'unable to create runtime cart');

    if ($cart->id_currency > 0) {
        $context->currency = new Currency((int) $cart->id_currency);
    }
    $context->customer = $customer;
    $context->cart = $cart;

    return $cart;
}

$expectedVersion = getenv('EXPECTED_PS_VERSION');
if ($expectedVersion) {
    runtimeAssert((string) _PS_VERSION_ === (string) $expectedVersion, 'unexpected PrestaShop version: ' . _PS_VERSION_);
}

runtimeAssert(Module::isInstalled('pssalesforcebridge'), 'module is not installed');
$module = Module::getInstanceByName('pssalesforcebridge');
runtimeAssert(is_object($module), 'module instance could not be loaded');
runtimeAssert((string) $module->version === '0.7.0', 'module runtime version is not 0.7.0');
runtimeAssert(!empty($module->id), 'module has no installed id');

$tables = [
    'sfmc_queue',
    'sfmc_log',
    'sfmc_customer_sync',
    'sfmc_customer_backfill',
    'sfmc_order_sync',
    'sfmc_cart',
    'sfmc_cart_item',
    'sfmc_journey_event',
    'sfmc_recovery_token',
    'sfmc_recovery_order',
];
foreach ($tables as $table) {
    runtimeAssert(runtimeTableExists($table), 'missing table ' . $table);
}

runtimeAssert((string) Configuration::get('PS_SFMC_MODE') === 'test', 'fresh install must default to test mode');
runtimeAssert(trim((string) Configuration::get('PS_SFMC_CRON_TOKEN')) !== '', 'cron token is missing');
runtimeAssert((int) Configuration::get('PS_SFMC_RETENTION_QUEUE_DAYS') === 30, 'queue retention default mismatch');
runtimeAssert((int) Configuration::get('PS_SFMC_RETENTION_JOURNEY_DAYS') === 90, 'journey retention default mismatch');

$hooks = [
    'actionCustomerAccountAdd',
    'actionCustomerAccountUpdate',
    'actionValidateOrder',
    'actionOrderStatusPostUpdate',
    'actionCartSave',
];
foreach ($hooks as $hook) {
    runtimeAssert(runtimeHookRegistered((int) $module->id, $hook), 'hook is not registered: ' . $hook);
}

$customer = runtimeCustomer();
runtimeAssert($module->hookActionCustomerAccountUpdate(['customer' => $customer]) !== false, 'customer hook returned failure');
$customerEvents = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "sfmc_queue` WHERE entity_type = 'customer' AND entity_id = '" . (int) $customer->id . "'"
);
runtimeAssert($customerEvents > 0, 'customer hook did not enqueue an event');

$cart = runtimeCart($customer);
runtimeAssert($module->hookActionCartSave(['cart' => $cart]) !== false, 'cart hook returned failure');
$cartRows = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'sfmc_cart` WHERE id_shop = ' . (int) $cart->id_shop . ' AND id_cart = ' . (int) $cart->id
);
runtimeAssert($cartRows === 1, 'cart hook did not persist a cart snapshot');

$order = new Order();
$order->id = 900000000 + (int) $cart->id;
$order->id_shop = (int) $cart->id_shop;
$order->id_cart = (int) $cart->id;
$order->id_customer = (int) $customer->id;
$order->id_currency = (int) $cart->id_currency;
$order->reference = 'RUNTIME' . (int) $cart->id;
$order->total_paid = 10.0;
$order->total_paid_tax_incl = 10.0;
$order->total_products = 10.0;
$order->total_products_wt = 10.0;
$order->total_shipping = 0.0;
$order->total_shipping_tax_incl = 0.0;
$order->total_discounts = 0.0;
$order->total_discounts_tax_incl = 0.0;
$order->payment = 'Runtime';
$order->module = 'runtime';
$order->current_state = (int) Configuration::get('PS_OS_PREPARATION');
$order->valid = false;
$order->date_add = date('Y-m-d H:i:s');
$order->date_upd = $order->date_add;
$currency = new Currency((int) $cart->id_currency);

runtimeAssert($module->hookActionValidateOrder([
    'order' => $order,
    'customer' => $customer,
    'cart' => $cart,
    'currency' => $currency,
]) !== false, 'order hook returned failure');

$orderEvents = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "sfmc_queue` WHERE event_type = 'ORDER_CREATED' AND entity_type = 'order' AND entity_id = '" . (int) $order->id . "'"
);
runtimeAssert($orderEvents === 1, 'order hook did not enqueue ORDER_CREATED');

$customerQueue = $module->processCustomerQueue(10);
$orderQueue = $module->processOrderQueue(10);
$journeyQueue = $module->processAbandonedCartJourneyQueue(10);
runtimeAssert(isset($customerQueue['skipped']) && $customerQueue['skipped'] === 'test_mode', 'customer delivery must remain blocked in test mode');
runtimeAssert(isset($orderQueue['skipped']) && $orderQueue['skipped'] === 'test_mode', 'order delivery must remain blocked in test mode');
runtimeAssert(isset($journeyQueue['skipped']) && $journeyQueue['skipped'] === 'test_mode', 'Journey delivery must remain blocked in test mode');

$backfill = $module->processCustomerBackfill();
runtimeAssert(is_array($backfill), 'customer backfill cron component did not return an array');
$cartDetection = $module->processCartAbandonmentCandidates(100);
runtimeAssert(is_array($cartDetection), 'cart abandonment cron component did not return an array');
$retention = $module->processRetention(false);
runtimeAssert(is_array($retention), 'retention cron component did not return an array');

$health = $module->healthReport();
runtimeAssert(is_array($health), 'health report did not return an array');
runtimeAssert(isset($health['mode']) && $health['mode'] === 'test', 'health report mode mismatch');
runtimeAssert(isset($health['module_version']) && $health['module_version'] === '0.7.0', 'health report module version mismatch');
runtimeAssert(!empty($health['ok']), 'health report should be healthy in test mode');

fwrite(STDOUT, 'runtime smoke: ok on PrestaShop ' . _PS_VERSION_ . PHP_EOL);
