<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\SourceTimestampNormalizer;

function timestamp_ok($valid, $error) {
    if (!$valid) { fwrite(STDERR, $error . "\n"); exit(1); }
}
function timestamp_reject($local, $zone, $code) {
    try {
        SourceTimestampNormalizer::toUtc($local, $zone);
        timestamp_ok(false, 'Invalid source timestamp accepted.');
    } catch (RuntimeException $error) {
        timestamp_ok($error->getMessage() === $code, 'Incorrect sanitized failure code: ' . $error->getMessage());
    }
}
foreach ([
    ['2026-11-09 12:00:00', 'Europe/Madrid', '2026-11-09T11:00:00.000Z'],
    ['2026-07-09 12:00:00', 'Europe/Madrid', '2026-07-09T10:00:00.000Z'],
    ['2026-01-09 12:00:00', 'UTC', '2026-01-09T12:00:00.000Z'],
    ['2026-05-09 12:00:00', 'America/Lima', '2026-05-09T17:00:00.000Z'],
    ['2026-03-29 01:59:59', 'Europe/Madrid', '2026-03-29T00:59:59.000Z'],
    ['2026-03-29 03:00:00', 'Europe/Madrid', '2026-03-29T01:00:00.000Z'],
] as $case) {
    timestamp_ok(SourceTimestampNormalizer::toUtc($case[0], $case[1]) === $case[2],
        'UTC conversion mismatch: ' . $case[0]);
}
timestamp_reject('2026-03-29 02:30:00', 'Europe/Madrid', 'data_cloud_timestamp_nonexistent_local_time');
timestamp_reject('2026-10-25 02:30:00', 'Europe/Madrid', 'data_cloud_timestamp_ambiguous_local_time');
timestamp_reject('2026-02-30 10:00:00', 'UTC', 'data_cloud_timestamp_bad_calendar_date');
timestamp_reject('2026-11-09T12:00:00Z', 'UTC', 'data_cloud_timestamp_bad_format');
timestamp_reject('2026-11-09 12:00:00', 'CET', 'data_cloud_timestamp_timezone_required');
timestamp_reject('2026-11-09 12:00:00', '', 'data_cloud_timestamp_timezone_required');
timestamp_reject('2026-11-09 12:00:00', 'Europe/Madird', 'data_cloud_timestamp_timezone_required');
$src = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php');
timestamp_ok(strpos($src, 'curl_') === false && strpos($src, 'file_get_contents(') === false
    && strpos($src, 'DateTimeZone::listIdentifiers') !== false,
    'Timestamp converter must remain deterministic and offline.');
$ci = file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');
timestamp_ok(strpos($ci, 'php tests/data-cloud-timestamp-contract.php') !== false,
    'PHP CI must certify timestamp policy.');
fwrite(STDOUT, "Data Cloud timestamp conversion contract passed; no I/O.\n");
