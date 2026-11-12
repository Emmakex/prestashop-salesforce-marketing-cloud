<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/TimestampQualityAudit.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TimestampQualityAudit;

function auditAssert($cond, $message)
{
    if (!$cond) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function auditReject($samples, $zone, $policy, $code)
{
    try {
        TimestampQualityAudit::inspect($samples, $zone, $policy);
        auditAssert(false, 'Unsafe audit input accepted.');
    } catch (RuntimeException $e) {
        auditAssert($e->getMessage() === $code, 'Unexpected failure code: ' . $e->getMessage());
    }
}
$policy = [
    'as_of_utc' => '2026-11-12T10:00:00.000Z',
    'trusted_from_utc' => '2026-11-01T00:00:00.000Z',
    'write_path_verified' => true,
];
$samples = [
    ['source_updated_local' => '2026-11-11 15:00:00'],
    ['source_updated_local' => '2026-02-10 15:00:00'],
    ['source_updated_local' => '2026-03-29 02:30:00'],
    ['source_updated_local' => '2026-10-25 02:30:00'],
    ['source_updated_local' => '2026-11-09 15:00:00', 'verified_absolute_utc' => '2026-11-09T14:00:00.000Z'],
    ['source_updated_local' => '2026-11-09 15:00:00', 'verified_absolute_utc' => '2026-11-09T13:00:00.000Z'],
    ['source_updated_local' => '2026-11-13 15:00:00'],
    ['source_updated_local' => ''],
    ['source_updated_local' => '2026-02-30 10:00:00'],
];
$r = TimestampQualityAudit::inspect($samples, 'Europe/Madrid', $policy);
auditAssert($r['sampled'] === 9 && $r['eligible_in_sample'] === 2, 'Eligible count is wrong.');
auditAssert($r['quarantined_in_sample'] === 6 && $r['unverified_in_sample'] === 1, 'Quarantine counts are wrong.');
foreach (['quarantine_missing', 'quarantine_invalid', 'quarantine_dst_gap',
    'quarantine_dst_overlap', 'quarantine_future', 'quarantine_evidence_mismatch'] as $type) {
    auditAssert($r['counts'][$type] === 1, 'Missing quarantine class: ' . $type);
}
auditAssert($r['counts']['eligible_after_verified_cutover'] === 1, 'Recent cutover test failed.');
auditAssert($r['counts']['eligible_evidenced'] === 1, 'Corroborated history test failed.');
auditAssert($r['recommended_scope'] === 'verified_cutover_or_evidence_only_candidate', 'No cutover recommendation.');
auditAssert(!$r['record_ingestion_allowed'] && !$r['marketing_sends_allowed'] &&
    !$r['schema_upload_allowed'] && !$r['historical_backfill_allowed'], 'Never authorize side effects.');
$unverified = TimestampQualityAudit::inspect(
    [['source_updated_local' => '2026-11-11 15:00:00']],
    'Europe/Madrid',
    ['as_of_utc' => '2026-11-12T10:00:00.000Z']
);
auditAssert($unverified['eligible_in_sample'] === 0 &&
    $unverified['unverified_in_sample'] === 1 && $unverified['recommended_scope'] === 'hold',
    'Unverified current timezone must not certify a date.');
auditReject([['source_updated_local' => '2026-11-11 15:00:00', 'email' => 'not-allowed']],
    'Europe/Madrid', $policy, 'data_cloud_quality_date_only_samples_required');
auditReject([], 'CET', $policy, 'data_cloud_quality_timezone_required');
auditReject([], 'Europe/Madrid', ['as_of_utc' => '2026-11-12T10:00:00.000Z', 'write_path_verified' => true],
    'data_cloud_quality_cutover_required');
auditReject([], 'Europe/Madrid', ['as_of_utc' => '2026-11-12T10:00:00.000Z',
    'trusted_from_utc' => '2026-11-13T00:00:00.000Z'], 'data_cloud_quality_future_cutover');
auditReject([], 'Europe/Madrid', ['as_of_utc' => 'not-a-date'], 'data_cloud_quality_utc_evidence_invalid');
auditReject(array_fill(0, 501, []), 'UTC', $policy, 'data_cloud_quality_sample_limit');
$src = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/TimestampQualityAudit.php');
auditAssert(strpos($src, 'curl_') === false && strpos($src, 'Db::') === false &&
    strpos($src, 'file_get_contents(') === false, 'Quality gate must remain pure and offline.');
echo "Data Cloud date-quality contract passed; no network, database or personal data.\n";
