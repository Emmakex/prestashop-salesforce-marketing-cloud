<?php
/** Actual dormant module source; provider choice is explicit, not guessed. */
require_once dirname(__DIR__)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;

function providerCheck($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, 'Growth provider router: ' . $reason . PHP_EOL);
        exit(1);
    }
}
function providerReject($call, $expected)
{
    try {
        call_user_func($call);
    } catch (RuntimeException $e) {
        providerCheck($e->getMessage() === $expected, $expected);
        return;
    }
    providerCheck(false, 'accepted_' . $expected);
}
foreach ([
    '1.7.8.11' => '1.7.8',
    '8.2.8' => '8',
    '9.1.5' => '9'
] as $version => $family) {
    $router = new ExplicitProviderRouter(
        $version, 'ABH', ExplicitProviderRouter::GROWTH,
        'growth_ci_shopone', 'test'
    );
    $customer = $router->plan(1, 'customer', 77);
    $order = $router->plan(1, 'order', 77);
    $cart = $router->plan(2, 'cart', 77);
    providerCheck($customer['prestashop_family'] === $family
        && $customer['prestashop_version'] === $version
        && $customer['provider'] === ExplicitProviderRouter::GROWTH
        && $customer['route_target'] === 'growth_data_360_not_configured'
        && $customer['source_entity_key'] === 'growth_ci_shopone:1:customer:77'
        && $customer['source_entity_key'] !== $order['source_entity_key']
        && $order['source_entity_key'] !== $cart['source_entity_key']
        && $cart['source_entity_key'] === 'growth_ci_shopone:2:cart:77',
        'expected_route_' . $family);
    foreach (['namespace_global_uniqueness_certified',
        'tenant_data_contract_approved', 'external_transport_configured',
        'data_360_ingestion_authorized', 'growth_flow_authorized',
        'marketing_sends_authorized', 'outbound_worker_authorized'] as $key) {
        providerCheck(array_key_exists($key, $customer)
            && $customer[$key] === false,
            'unsafe_transport_flag_' . $key);
    }
    providerCheck($customer['mode'] === 'test'
        && $customer['status'] === 'blocked', 'must_be_blocked');
    providerReject(function () use ($router, $customer) {
        $router->dispatch($customer);
    }, 'growth_router_outbound_not_authorized');
}
$siteA = new ExplicitProviderRouter('8.2.8', 'ABH',
    ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', 'test');
$siteB = new ExplicitProviderRouter('8.2.8', 'ABH',
    ExplicitProviderRouter::GROWTH, 'growth_ci_siteb', 'test');
$other = new ExplicitProviderRouter('8.2.8', 'OTHER_MERCHANT',
    ExplicitProviderRouter::ENGAGEMENT_LEGACY, 'other_ci_site', 'test');
$siteKeyA = $siteA->plan(1, 'customer', 7)['source_entity_key'];
$siteKeyB = $siteB->plan(1, 'customer', 7)['source_entity_key'];
$otherKey = $other->plan(1, 'customer', 7)['source_entity_key'];
providerCheck($siteKeyA !== $siteKeyB && $siteKeyA !== $otherKey
    && $siteKeyB !== $otherKey, 'cross_installation_collision');
providerCheck($other->plan(1, 'order', 7)['route_target']
    === 'engagement_legacy_not_certified', 'legacy_provider_not_isolated');
providerReject(function () use ($other) {
    $other->dispatch($other->plan(1, 'order', 7));
}, 'growth_router_outbound_not_authorized');

// No fallback to Engagement when ABH provider is absent or wrong.
foreach (['', null, 'marketing_cloud_engagement', 'unsupported'] as $provider) {
    $expected = $provider === 'marketing_cloud_engagement'
        ? 'growth_router_abh_requires_growth'
        : 'growth_router_explicit_provider_required';
    providerReject(function () use ($provider) {
        new ExplicitProviderRouter('8.2.8', 'ABH', $provider,
            'growth_ci_sitea', 'test');
    }, $expected);
}
foreach (['1.7.7.9', '1.7.9.0', '9.1.5-alpha', '10.0.0', '', '8.2'] as $version) {
    providerReject(function () use ($version) {
        new ExplicitProviderRouter($version, 'ABH',
            ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', 'test');
    }, 'growth_router_unsupported_prestashop_version');
}
foreach (['', 'bad:namespace', 'x', str_repeat('a', 33), 'shop-1'] as $ns) {
    providerReject(function () use ($ns) {
        new ExplicitProviderRouter('8.2.8', 'ABH',
            ExplicitProviderRouter::GROWTH, $ns, 'test');
    }, 'growth_router_namespace_required');
}
foreach (['', '?', 'a', 'abh', str_repeat('A', 33)] as $merchant) {
    providerReject(function () use ($merchant) {
        new ExplicitProviderRouter('8.2.8', $merchant,
            ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', 'test');
    }, 'growth_router_merchant_required');
}
foreach (['production', '', 'standby', null] as $mode) {
    providerReject(function () use ($mode) {
        new ExplicitProviderRouter('8.2.8', 'ABH',
            ExplicitProviderRouter::GROWTH, 'growth_ci_sitea', $mode);
    }, 'growth_router_test_only');
}
foreach ([[0, 'cart', 1], [1, 'cart', 0], [1, 'order', -4],
          [1, 'customer', '1'], ['1', 'cart', 1]] as $input) {
    providerReject(function () use ($siteA, $input) {
        $siteA->plan($input[0], $input[1], $input[2]);
    }, 'growth_router_entity_scope_invalid');
}
foreach (['', 'contact', 'cart_old', 'CART', null, 42] as $type) {
    providerReject(function () use ($siteA, $type) {
        $siteA->plan(1, $type, 7);
    }, 'growth_router_entity_type_invalid');
}
$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php');
foreach (['curl_', 'file_get_contents(', 'Db::', 'Configuration::',
    '/interaction/v1/events', '/api/v1/ingest/', 'requestAccessToken(',
    '->execute(', '->query(', '->insert(', '->update('] as $forbidden) {
    providerCheck(strpos($source, $forbidden) === false,
        'router_has_outbound_or_database_' . $forbidden);
}
$module = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/pssalesforcebridge.php');
providerCheck(strpos($module, 'ExplicitProviderRouter') === false,
    'must_not_rewire_legacy_module_without_gate');
echo "Growth provider route planner: 1.7/8/9, multi-tenant, all outbound blocked.\n";
