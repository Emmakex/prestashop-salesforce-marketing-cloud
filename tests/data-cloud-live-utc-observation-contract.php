<?php
/** G3: UTC observation of NEW hooks only; no BC records, Salesforce or SQL. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\LiveEventUtcObservation;

function utcObservationAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
function utcObservationReject($type)
{
    try {
        LiveEventUtcObservation::observeNow($type);
        utcObservationAssert(false, 'Unexpected hook accepted.');
    } catch (RuntimeException $exception) {
        utcObservationAssert($exception->getMessage() === 'data_cloud_live_observation_hook_not_allowed',
            'Wrong failure for unsupported hook.');
    }
}

$allowed = [
    'CUSTOMER_CREATED', 'CUSTOMER_UPDATED',
    'ORDER_CREATED', 'ORDER_UPDATED', 'ORDER_PAID', 'ORDER_CANCELLED',
];

$originalZone = date_default_timezone_get();
try {
    // DST and timezone changes must not affect the captured UTC instant.
    foreach (['UTC', 'Europe/Madrid', 'America/Lima', 'Pacific/Auckland'] as $zone) {
        date_default_timezone_set($zone);
        foreach ($allowed as $eventType) {
            $before = time();
            $result = LiveEventUtcObservation::observeNow($eventType);
            $after = time();
            $raw = $result['captured_at_utc'];
            utcObservationAssert(
                preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/D', $raw) === 1,
                'Timestamp is not canonical UTC.'
            );
            $actualEpoch = strtotime($raw);
            utcObservationAssert($actualEpoch !== false
                && $actualEpoch >= $before && $actualEpoch <= $after,
                'UTC instant does not reflect the observation moment.'
            );
            utcObservationAssert($result['event_type'] === $eventType,
                'Hook provenance must be retained.');
            utcObservationAssert($result['captured_at_precision'] === 'seconds'
                && $result['clock_source'] === 'php_unix_epoch_seconds'
                && $result['clock_sync_verified'] === false,
                'Clock source/precision/trust must be explicit.');
            utcObservationAssert($result['source_modified_provenance'] === 'unverified'
                && array_key_exists('source_updated_utc', $result)
                && $result['source_updated_utc'] === null,
                'Observed event time must not masquerade as verified source update.');
            foreach (['record_modified_authorized', 'data_360_ingestion_authorized',
                'marketing_sends_authorized'] as $flag) {
                utcObservationAssert($result[$flag] === false, $flag . ' must remain false.');
            }
            utcObservationAssert(count($result) === 10,
                'Only fixed, non-PII metadata must leave the contract.');
        }
    }
} finally {
    date_default_timezone_set($originalZone);
}

foreach (['', 'customer_updated', 'CUSTOMER_BACKFILL', 'ORDER_IMPORTED',
    'CART_ABANDONED', 'SOAP_SUBSCRIBER', 'MARKETING_SEND', null, false, 42,
    ['CUSTOMER_CREATED'], "CUSTOMER_CREATED\n"] as $bad) {
    utcObservationReject($bad);
}

// This does not call existing sender, queue, DB, file, or process the source
// customer/order; no ID, email, source date or historical row is required.
$source = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php');
foreach (['Db::', 'curl_', 'file_get_contents(', '->enqueue(',
    '->execute(', '->save(', '->post(', '/interaction/v1/events',
    '/api/v1/ingest/'] as $forbidden) {
    utcObservationAssert(strpos($source, $forbidden) === false,
        'The observation class must remain offline and non-mutating.');
}
foreach (['CUSTOMER_CREATED', 'CUSTOMER_UPDATED', 'ORDER_CREATED',
    'ORDER_PAID', 'ORDER_CANCELLED'] as $eventType) {
    utcObservationAssert(strpos($source, "'" . $eventType . "'") !== false,
        'A bridge hook event type is missing.');
}

echo "Future live event UTC observation contract passed; unverified clock, no Record Modified or sends.\n";
