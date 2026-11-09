<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;

function dc_assert($ok, $reason) {
    if (!$ok) { fwrite(STDERR, $reason . "\n"); exit(1); }
}

$m = new IngestionRecordMapper('abh_group');
$c = [
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'person@example.test', 'firstname' => 'Test', 'lastname' => 'Customer',
    'newsletter' => 0, 'optin' => 1, 'active' => 1,
    'date_upd' => '2026-11-09 09:35:00',
];
$first = $m->customer($c);
dc_assert($first['source_key'] === 'abh_group:ps_2_17', 'Incorrect stable customer key.');
dc_assert($first['newsletter_flag'] === false && $first['partner_optin_flag'] === true, 'Source flags lost.');
dc_assert(!isset($first['marketing_consent']) && !isset($first['send_email']), 'Consent must not be inferred.');
dc_assert($first === $m->customer($c), 'Customer mapping not deterministic.');
dc_assert($first['source_updated_local'] === $c['date_upd'], 'Timestamp zone assumed.');

$other = $c; $other['shop_id'] = 3; $other['contact_key'] = 'ps_3_17';
dc_assert($m->customer($other)['source_key'] !== $first['source_key'], 'Multishop collision.');
dc_assert((new IngestionRecordMapper('other_shop'))->customer($c)['source_key'] !== $first['source_key'], 'Merchant collision.');

$o = [
    'shop_id' => 2, 'order_id' => 7, 'order_key' => 'ps_2_7',
    'customer_id' => 17, 'contact_key' => 'ps_2_17', 'cart_id' => 9,
    'currency' => 'eur', 'total_paid' => 72.55,
    'paid' => false, 'state_id' => 10, 'date_upd' => '2026-11-09 10:00:00'
];
$order = $m->order($o);
dc_assert($order['source_key'] === 'abh_group:ps_2_7', 'Incorrect order key.');
dc_assert($order['customer_source_key'] === $first['source_key'], 'Customer relation mismatch.');
dc_assert($order['cart_source_key'] === 'abh_group:ps_2_9', 'Cart relation mismatch.');
dc_assert($order['currency'] === 'EUR' && $order['paid_flag'] === false, 'Order conversion invalid.');
dc_assert(!isset($order['RecoveryUrl']) && !isset($order['journey_event_key']), 'Messaging fields forbidden.');

$bad = $c; $bad['contact_key'] = 'ps_3_17';
try { $m->customer($bad); dc_assert(false, 'Cross-shop customer accepted.'); }
catch (RuntimeException $e) { dc_assert($e->getMessage() === 'data_cloud_customer_key_mismatch', 'Wrong customer diagnostic.'); }
$bad = $o; $bad['contact_key'] = 'ps_3_17';
try { $m->order($bad); dc_assert(false, 'Cross-shop order accepted.'); }
catch (RuntimeException $e) { dc_assert($e->getMessage() === 'data_cloud_order_contact_mismatch', 'Wrong order diagnostic.'); }
$bad = $c; $bad['email'] = 'invalid email';
try { $m->customer($bad); dc_assert(false, 'Invalid email accepted.'); }
catch (RuntimeException $e) { dc_assert($e->getMessage() === 'data_cloud_invalid_email', 'Wrong email diagnostic.'); }
$bad = $o; $bad['total_paid'] = 'NaN';
try { $m->order($bad); dc_assert(false, 'Invalid total accepted.'); }
catch (RuntimeException $e) { dc_assert($e->getMessage() === 'data_cloud_invalid_total', 'Wrong money diagnostic.'); }
foreach (['', 'a', '../etc', 'with spaces', 'https://bad.example', str_repeat('x', 33)] as $badNs) {
    try { new IngestionRecordMapper($badNs); dc_assert(false, 'Unsafe namespace accepted.'); }
    catch (RuntimeException $e) { dc_assert($e->getMessage() === 'data_cloud_invalid_namespace', 'Wrong namespace diagnostic.'); }
}
$yaml = file_get_contents(dirname(__DIR__) . '/schemas/data-cloud-ingestion-DRAFT.yaml');
dc_assert(is_string($yaml) && strpos($yaml, 'openapi: 3.0.3') !== false && strpos($yaml, 'DRAFT') !== false, 'Unapproved schema marker missing.');
foreach (['PSCustomer:', 'PSOrder:', 'PSCartEvent:', 'source_key:', 'event_time:'] as $expected) {
    dc_assert(strpos($yaml, $expected) !== false, 'Schema field missing.');
}
$ci = file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');
dc_assert(strpos($ci, 'php tests/data-cloud-mapping-contract.php') !== false, 'Contract not in CI.');
$source = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php');
dc_assert(strpos($source, 'curl_') === false && strpos($source, 'file_get_contents(') === false, 'Mapper must be offline only.');
fwrite(STDOUT, "Data Cloud offline mapping contract passed.\n");
