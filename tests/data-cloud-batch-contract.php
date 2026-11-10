<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionPayloadBuilder;

function batch_check($ok, $message) {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function batch_bad($builder, $object, array $records, $code) {
    try {
        $builder->encode($object, $records);
        batch_check(false, 'Invalid batch accepted.');
    } catch (RuntimeException $error) {
        batch_check($error->getMessage() === $code, 'Unexpected sanitized diagnostic.');
    }
}
$map = new IngestionRecordMapper('abh_pst');
$c = $map->customer([
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'fake@example.test', 'firstname' => 'Synthetic',
    'lastname' => 'Customer', 'newsletter' => false, 'optin' => true,
    'active' => true, 'date_upd' => '2026-11-10 10:15:00',
]);
$o = $map->order([
    'shop_id' => 2, 'order_id' => 44, 'order_key' => 'ps_2_44',
    'customer_id' => 17, 'contact_key' => 'ps_2_17', 'cart_id' => 12,
    'currency' => 'EUR', 'total_paid' => 95.20, 'paid' => false,
    'state_id' => 10, 'date_upd' => '2026-11-10 10:20:00',
]);
$b = new IngestionPayloadBuilder();
foreach (['PSCustomer' => $c, 'PSOrder' => $o] as $name => $record) {
    $json = $b->encode($name, [$record]);
    $decoded = json_decode($json, true);
    batch_check(is_array($decoded) && count($decoded) === 1
        && isset($decoded['data'][0]) && $decoded['data'][0] === $record,
        'Invalid Salesforce data envelope.');
    batch_check(strpos($json, 'RecoveryUrl') === false
        && strpos($json, 'marketing_consent') === false, 'Unapproved marketing data.');
}
batch_bad($b, 'PSCartEvent', [$c], 'data_cloud_payload_object_not_allowed');
batch_bad($b, 'PSCustomer', [], 'data_cloud_payload_invalid_count');
batch_bad($b, 'PSCustomer', array_fill(0, 51, $c), 'data_cloud_payload_invalid_count');
batch_bad($b, 'PSCustomer', [$c, $c], 'data_cloud_payload_duplicate_identity');
$bad = $c; $bad['marketing_consent'] = true;
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_schema_drift');
$bad = $c; unset($bad['newsletter_flag']);
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_schema_drift');
$bad = $c; $bad['newsletter_flag'] = 'true';
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_invalid_type');
$bad = $c; $bad['shop_id'] = 3;
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_invalid_identity');
$bad = $c; $bad['email'] = 'not-email';
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_invalid_email');
$bad = $o; $bad['customer_source_key'] = 'other:ps_2_17';
batch_bad($b, 'PSOrder', [$bad], 'data_cloud_payload_cross_shop_relation');
$bad = $o; $bad['currency'] = 'EUROS';
batch_bad($b, 'PSOrder', [$bad], 'data_cloud_payload_invalid_currency');
$bad = $o; $bad['total_paid'] = INF;
batch_bad($b, 'PSOrder', [$bad], 'data_cloud_payload_invalid_type');
$bad = $c; $bad['first_name'] = str_repeat('x', 204801);
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_size_exceeded');
$bad = $c; $bad['first_name'] = "\xFF";
batch_bad($b, 'PSCustomer', [$bad], 'data_cloud_payload_json_error');
$source = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php');
batch_check(strpos($source, 'curl_') === false && strpos($source, 'file_get_contents(') === false
    && strpos($source, '/api/v1/ingest/') === false, 'Builder must be offline only.');
batch_check(strpos(file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml'),
    'php tests/data-cloud-batch-contract.php') !== false, 'Batch test missing from CI.');
fwrite(STDOUT, "Data Cloud offline batch contract passed (2 objects, zero network writes).\n");
