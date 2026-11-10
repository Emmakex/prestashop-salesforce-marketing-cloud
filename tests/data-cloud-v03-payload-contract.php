<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcPayloadBuilder.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionPayloadBuilder;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedUtcRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedUtcPayloadBuilder;

function v03_assert($value, $message) {
    if (!$value) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function v03_reject($builder, $object, array $rows, $code) {
    try {
        $builder->encode($object, $rows);
        v03_assert(false, 'Unsafe v0.3 payload accepted.');
    } catch (RuntimeException $e) {
        v03_assert($e->getMessage() === $code, 'Unexpected v0.3 rejection code.');
    }
}

$mapper = new StagedUtcRecordMapper(new IngestionRecordMapper('abh_pst'));
$builder = new StagedUtcPayloadBuilder(new IngestionPayloadBuilder());
$customer = $mapper->customer([
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'synthetic@example.invalid', 'firstname' => 'Synthetic',
    'lastname' => 'Customer', 'newsletter' => false, 'optin' => false,
    'active' => true, 'date_upd' => '2026-11-10 14:00:00',
], 'Europe/Madrid');
$order = $mapper->order([
    'shop_id' => 2, 'order_id' => 44, 'order_key' => 'ps_2_44',
    'customer_id' => 17, 'contact_key' => 'ps_2_17', 'cart_id' => 12,
    'currency' => 'EUR', 'total_paid' => 42.4, 'paid' => true,
    'state_id' => 2, 'date_upd' => '2026-07-10 14:00:00',
], 'Europe/Madrid');

foreach (['PSCustomer' => $customer, 'PSOrder' => $order] as $object => $record) {
    $json = $builder->encode($object, [$record]);
    $decoded = json_decode($json, true);
    v03_assert(is_array($decoded) && $decoded === ['data' => [$record]],
        'Wrong v0.3 Ingestion API data envelope.');
    v03_assert(count($decoded['data'][0]) === 12, 'Expected twelve v0.3 fields.');
    v03_assert(strlen($json) < IngestionPayloadBuilder::MAX_BYTES, 'Payload size exceeded.');
    v03_assert(strpos($json, 'marketing_consent') === false
        && strpos($json, 'recovery_url') === false,
        'Not permitted to assert marketing consent or include recovery URL.');
}
v03_assert($customer['source_updated_utc'] === '2026-11-10T13:00:00.000Z',
    'Winter Madrid UTC conversion failed.');
v03_assert($order['source_updated_utc'] === '2026-07-10T12:00:00.000Z',
    'Summer Madrid UTC conversion failed.');

v03_reject($builder, 'PSCartEvent', [$customer], 'data_cloud_utc_payload_object_not_allowed');
v03_reject($builder, 'PSCustomer', [], 'data_cloud_utc_payload_invalid_count');
v03_reject($builder, 'PSCustomer', array_fill(0, 51, $customer), 'data_cloud_utc_payload_invalid_count');
v03_reject($builder, 'PSCustomer', [$customer, $customer], 'data_cloud_payload_duplicate_identity');
$bad = $customer; unset($bad['source_updated_utc']);
v03_reject($builder, 'PSCustomer', [$bad], 'data_cloud_utc_payload_schema_drift');
$bad = $customer; $bad['marketing_consent'] = true;
v03_reject($builder, 'PSCustomer', [$bad], 'data_cloud_utc_payload_schema_drift');
foreach ([
    '2026-02-30T13:00:00.000Z',
    '2026-11-10 13:00:00',
    '2026-11-10T13:00:00+01:00',
    '2026-11-10T13:00:00.000+00:00',
    '2026-11-10T13:00:00.000Z ',
] as $badTime) {
    $bad = $customer; $bad['source_updated_utc'] = $badTime;
    v03_reject($builder, 'PSCustomer', [$bad], 'data_cloud_utc_payload_invalid_timestamp');
}
$bad = $customer; $bad['shop_id'] = 999;
v03_reject($builder, 'PSCustomer', [$bad], 'data_cloud_payload_invalid_identity');
$bad = $customer; $bad['first_name'] = str_repeat('x', 204800);
v03_reject($builder, 'PSCustomer', [$bad], 'data_cloud_payload_size_exceeded');
$bad = $customer; $bad['source_updated_utc'] = false;
v03_reject($builder, 'PSCustomer', [$bad], 'data_cloud_utc_payload_invalid_timestamp');
$src = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcPayloadBuilder.php');
v03_assert(strpos($src, 'curl_') === false
    && strpos($src, 'file_get_contents(') === false
    && strpos($src, '/api/v1/ingest/') === false,
    'UTC staging builder must be offline.');
v03_assert(strpos(file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml'),
    'php tests/data-cloud-v03-payload-contract.php') !== false,
    'UTC staging CI contract missing.');
fwrite(STDOUT, "PST v0.3 UTC payload contract passed: schema-stage only; zero HTTP writes.\n");
