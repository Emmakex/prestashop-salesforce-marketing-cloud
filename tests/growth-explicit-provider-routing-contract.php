<?php
/** Only Growth + Data Cloud can be routed, for PS 1.7/8/9. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;

function routerAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, 'Growth-only routing FAIL: ' . $reason . PHP_EOL);
        exit(1);
    }
}
function routerReject($callback, $expected)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        routerAssert($e->getMessage() === $expected, 'wrong_denial_' . $expected);
        return;
    }
    routerAssert(false, 'invalid_accepted_' . $expected);
}
foreach ([
    '1.7.8.11' => '1.7.8',
    '8.2.8' => '8',
    '9.1.5' => '9',
] as $version => $family) {
    $router = new ExplicitProviderRouter(
        $version, 'ABH', ExplicitProviderRouter::GROWTH,
        'growth_ci_shopone', 'test'
    );
    $customer = $router->plan(1, 'customer', 77);
    $order = $router->plan(1, 'order', 77);
    $cart = $router->plan(2, 'cart', 77);
    routerAssert($customer['prestashop_family'] === $family
        && $customer['prestashop_version'] === $version
        && $customer['provider'] === ExplicitProviderRouter::GROWTH
        && $customer['route_target'] === 'growth_data_cloud_schema_pending'
        && $customer['source_entity_key'] === 'growth_ci_shopone:1:customer:77'
        && $customer['source_entity_key'] !== $order['source_entity_key']
        && $order['source_entity_key'] !== $cart['source_entity_key'],
        'canonical_growth_route_' . $version);
    foreach ([
        'namespace_global_uniqueness_certified',
        'tenant_data_contract_approved',
        'external_transport_configured',
        'data_360_ingestion_authorized',
        'growth_flow_authorized',
        'marketing_sends_authorized',
        'outbound_worker_authorized',
    ] as $flag) {
        routerAssert(isset($customer[$flag]) && $customer[$flag] === false,
            'unauthorized_flag_' . $flag);
    }
    routerReject(function () use ($router, $customer) {
        $router->dispatch($customer);
    }, 'growth_router_outbound_not_authorized');
}
$a = new ExplicitProviderRouter('8.2.8', 'ABH',
    ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', 'test');
$b = new ExplicitProviderRouter('8.2.8', 'ABH',
    ExplicitProviderRouter::GROWTH, 'growth_ci_siteb', 'test');
$c = new ExplicitProviderRouter('8.2.8', 'OTHER',
    ExplicitProviderRouter::GROWTH, 'growth_ci_other', 'test');
routerAssert($a->plan(1, 'customer', 7)['source_entity_key']
    !== $b->plan(1, 'customer', 7)['source_entity_key']
    && $b->plan(1, 'customer', 7)['source_entity_key']
    !== $c->plan(1, 'customer', 7)['source_entity_key'],
    'cross_installation_collision');
foreach ([null, '', 'marketing_cloud_engagement', 'unsupported'] as $provider) {
    routerReject(function () use ($provider) {
        new ExplicitProviderRouter('8.2.8', 'ABH', $provider,
            'growth_ci_sitea', 'test');
    }, 'growth_router_growth_only');
}
foreach (['1.7.7.9', '9.1.5-alpha', '10.0.0', '', '8.2'] as $version) {
    routerReject(function () use ($version) {
        new ExplicitProviderRouter($version, 'ABH',
            ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', 'test');
    }, 'growth_router_unsupported_prestashop_version');
}
foreach (['', 'x', 'shop-1', str_repeat('a', 33)] as $namespace) {
    routerReject(function () use ($namespace) {
        new ExplicitProviderRouter('8.2.8', 'ABH',
            ExplicitProviderRouter::GROWTH, $namespace, 'test');
    }, 'growth_router_namespace_required');
}
foreach (['', 'x', 'lowercase', str_repeat('A', 33)] as $merchant) {
    routerReject(function () use ($merchant) {
        new ExplicitProviderRouter('8.2.8', $merchant,
            ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', 'test');
    }, 'growth_router_merchant_required');
}
foreach (['production', '', null] as $mode) {
    routerReject(function () use ($mode) {
        new ExplicitProviderRouter('8.2.8', 'ABH',
            ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', $mode);
    }, 'growth_router_test_only');
}
foreach ([[0, 'customer', 1], [1, 'order', 0], [1, 'cart', -1],
          [1, 'customer', '1']] as $scope) {
    routerReject(function () use ($a, $scope) {
        $a->plan($scope[0], $scope[1], $scope[2]);
    }, 'growth_router_entity_scope_invalid');
}
foreach (['', 'unknown', 'CART'] as $type) {
    routerReject(function () use ($a, $type) {
        $a->plan(1, $type, 7);
    }, 'growth_router_entity_type_invalid');
}
$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php');
foreach (['curl_', 'Db::', 'Configuration::', '->insert(',
          '->execute(', '->update(', '/interaction/v1/events'] as $forbidden) {
    routerAssert(strpos($source, $forbidden) === false,
        'provider_has_unsafe_io_' . $forbidden);
}
routerAssert(strpos($source, 'ENGAGEMENT_LEGACY') === false,
    'another_provider_still_exists');
echo "Growth-only route: 1.7/8/9, multi-merchant isolation, all outbound blocked.\n";
