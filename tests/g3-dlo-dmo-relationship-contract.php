<?php
/** G3-05/G3-06 synthetic PrestaShop source-key joins, no Salesforce data. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once __DIR__ . '/review/G3DloDmoRelationshipPreview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;

function g3RelationAssert($ok, $reason)
{
    if (!$ok) {
        fwrite(STDERR, 'G3 relation test failure: ' . $reason . PHP_EOL);
        exit(1);
    }
}
function g3RelationReject($callback, $error)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        g3RelationAssert($e->getMessage() === $error, 'wrong rejection ' . $error);
        return;
    }
    g3RelationAssert(false, 'unsafe join accepted');
}
function g3RelationCustomer($mapper, $shop, $id)
{
    $out = $mapper->customer([
        'shop_id' => $shop, 'customer_id' => $id,
        'contact_key' => 'ps_' . $shop . '_' . $id,
        'newsletter' => false, 'optin' => false, 'active' => true,
        'date_upd' => '2026-11-10 15:00:00',
    ]);
    return [
        'source_key' => $out['source_key'],
        'source_namespace' => $out['source_namespace'],
        'shop_id' => $out['shop_id'],
        'customer_id' => $out['customer_id'],
    ];
}
function g3RelationOrder($mapper, $shop, $id, $customerId, $cartId = 0)
{
    $out = $mapper->order([
        'shop_id' => $shop, 'order_id' => $id,
        'order_key' => 'ps_' . $shop . '_' . $id,
        'customer_id' => $customerId,
        'contact_key' => $customerId > 0 ? 'ps_' . $shop . '_' . $customerId : '',
        'cart_id' => $cartId,
        'currency' => 'EUR', 'total_paid' => 12.3,
        'paid' => true, 'state_id' => 2,
        'date_upd' => '2026-11-10 15:00:00',
    ]);
    return [
        'source_key' => $out['source_key'],
        'source_namespace' => $out['source_namespace'],
        'shop_id' => $out['shop_id'],
        'order_id' => $out['order_id'],
        'customer_source_key' => $out['customer_source_key'],
        'cart_source_key' => $out['cart_source_key'],
    ];
}

$a = new IngestionRecordMapper('site_alpha');
$b = new IngestionRecordMapper('site_beta');
$customers = [
    g3RelationCustomer($a, 1, 17),
    g3RelationCustomer($a, 2, 17),
    g3RelationCustomer($b, 1, 17),
];
$orders = [
    g3RelationOrder($a, 1, 17, 17, 9),
    g3RelationOrder($a, 1, 18, 17),
    g3RelationOrder($a, 2, 17, 17),
    g3RelationOrder($b, 1, 17, 17),
    g3RelationOrder($a, 1, 90, 999), // syntactically valid, parent absent
    g3RelationOrder($a, 1, 91, 0),   // no customer reference
    g3RelationOrder($a, 1, 92, 17),
    g3RelationOrder($a, 1, 93, 17),
];
$orders[6]['customer_source_key'] = 'site_beta:ps_1_17'; // forbidden cross-install
$orders[7]['cart_source_key'] = 'site_beta:ps_1_9';       // forbidden cross-cart

$result = G3DloDmoRelationshipPreview::inspect($customers, $orders);
g3RelationAssert($result['status'] === 'review_only', 'status not review only');
g3RelationAssert($result['counts'] === [
    'customers' => 3,
    'orders' => 8,
    'locally_consistent_orders' => 4,
    'orphan_orders_quarantined' => 1,
    'missing_customer_reference_quarantined' => 1,
    'cross_scope_orders_quarantined' => 2,
    'cart_references_unmapped' => 2,
], 'wrong scoped relation aggregate');
g3RelationAssert($customers[0]['source_key'] === $orders[0]['source_key'],
    'same textual key across different object types needs isolation by DLO');
g3RelationAssert($customers[0]['source_key'] !== $customers[2]['source_key'],
    'same local ID across installations must not deduplicate');

foreach ([
    'tenant_dmo_catalog_verified', 'dlo_dmo_mapping_save_authorized',
    'identity_resolution_authorized', 'order_customer_relationship_authorized',
    'cart_relationship_authorized', 'data_360_ingestion_authorized',
    'marketing_sends_authorized',
] as $flag) {
    g3RelationAssert($result[$flag] === false,
        'source key consistency MUST NOT authorize ' . $flag);
}

g3RelationReject(function () use ($customers, $orders) {
    $bad = $customers;
    $bad[] = $bad[0];
    G3DloDmoRelationshipPreview::inspect($bad, $orders);
}, 'g3_relation_duplicate_customer_key');
g3RelationReject(function () use ($customers, $orders) {
    $bad = $orders;
    $bad[] = $bad[0];
    G3DloDmoRelationshipPreview::inspect($customers, $bad);
}, 'g3_relation_duplicate_order_key');
g3RelationReject(function () use ($customers, $orders) {
    $bad = $customers;
    $bad[0]['source_key'] = 'site_alpha:ps_2_17';
    G3DloDmoRelationshipPreview::inspect($bad, $orders);
}, 'g3_relation_source_identity_invalid');
g3RelationReject(function () use ($customers, $orders) {
    $bad = $orders;
    $bad[0]['order_id'] = 0;
    G3DloDmoRelationshipPreview::inspect($customers, $bad);
}, 'g3_relation_source_identity_invalid');
g3RelationReject(function () use ($customers, $orders) {
    $bad = $orders;
    $bad[0]['customer_source_key'] = 17;
    G3DloDmoRelationshipPreview::inspect($customers, $bad);
}, 'g3_relation_reference_not_string');
g3RelationReject(function () use ($customers, $orders) {
    $bad = $customers;
    $bad[0]['email'] = 'synthetic@example.invalid';
    G3DloDmoRelationshipPreview::inspect($bad, $orders);
}, 'g3_relation_source_fields_only');
g3RelationReject(function () use ($customers, $orders) {
    G3DloDmoRelationshipPreview::inspect(array_fill(0, 501, $customers[0]), $orders);
}, 'g3_relation_sample_limit');

$unbound = $orders;
$unbound[1]['customer_source_key'] = 'site_alpha:ps_2_17';
$counter = G3DloDmoRelationshipPreview::inspect($customers, $unbound);
g3RelationAssert($counter['counts']['cross_scope_orders_quarantined'] === 3
    && $counter['counts']['locally_consistent_orders'] === 3,
    'cross-shop 1:N join not quarantined');

$raw = file_get_contents(__DIR__ . '/review/G3DloDmoRelationshipPreview.php');
foreach (['Db::', 'PDO', 'curl_', 'file_get_contents(', '->enqueue(', '->fire(',
    '/api/v1/ingest/', 'https://'] as $forbidden) {
    g3RelationAssert(strpos($raw, $forbidden) === false,
        'review code must not have side effects');
}
echo "G3 DLO/DMO source relation preview passed: 4 consistent, 4 quarantined, 0 mapping writes.\n";
