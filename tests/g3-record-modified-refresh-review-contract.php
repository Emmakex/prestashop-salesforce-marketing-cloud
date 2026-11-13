<?php
/** G3-03/G3-04 synthetic decisions only. PHP 7.1+. No network or SQL. */
require_once __DIR__ . '/review/G3RecordModifiedRefreshReview.php';

function g3RefreshAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'G3 refresh test: ' . $message . PHP_EOL);
        exit(1);
    }
}
function g3RefreshReject($case, $expected)
{
    try {
        G3RecordModifiedRefreshReview::inspect($case);
    } catch (RuntimeException $e) {
        g3RefreshAssert($e->getMessage() === $expected,
            'unexpected failure code');
        return;
    }
    g3RefreshAssert(false, 'invalid evidence unexpectedly accepted');
}
function g3RefreshHold($plan, $mustInclude)
{
    $result = G3RecordModifiedRefreshReview::inspect($plan);
    g3RefreshAssert($result['status'] === 'hold_for_owner_evidence',
        'unsafe plan was considered candidate');
    foreach ((array) $mustInclude as $expected) {
        g3RefreshAssert(in_array($expected, $result['reasons'], true),
            'missing quarantine reason ' . $expected);
    }
    return $result;
}
function g3RefreshNonAuthorizing($result)
{
    foreach ([
        'source_provenance_certified_for_bc',
        'record_modified_authorized', 'refresh_mode_approved',
        'data_360_schema_upload_authorized',
        'data_360_ingestion_authorized',
        'historical_backfill_authorized', 'marketing_sends_authorized',
    ] as $flag) {
        g3RefreshAssert(isset($result[$flag]) && $result[$flag] === false,
            'draft review illegally authorized ' . $flag);
    }
}

$base = [
    'object' => 'PSCustomer',
    'schema_candidate' => 'v0.2',
    'refresh_mode' => 'unselected',
    'transport' => 'unselected',
    'record_modified_field' => 'source_updated_local',
    'source_provenance' => 'historical_unverified',
    'verified_source_modified_utc' => null,
    'source_revision_evidence' => 'unknown',
    'payload_shape' => 'unknown',
    'source_state_rechecked' => false,
];
$baseline = g3RefreshHold($base, [
    'refresh_and_transport_not_selected',
    'unqualified_time_not_record_modified',
    'historical_or_hook_time_not_source_modified',
    'approved_schema_source_utc_missing',
    'source_revision_not_verified',
    'latest_source_state_not_rechecked',
]);
g3RefreshNonAuthorizing($baseline);

// The confirmed current Europe/Madrid setting and valid-looking local
// dates, including DST dates, DO NOT attest old write-time provenance.
// No actual dates or shop records are needed to enforce that contract.
$hypothetical = $base;
$hypothetical['schema_candidate'] = 'v0.3';
$hypothetical['refresh_mode'] = 'partial';
$hypothetical['transport'] = 'streaming';
$hypothetical['record_modified_field'] = 'source_updated_utc';
$hypothetical['payload_shape'] = 'selected_fields';
$hypothetical['source_provenance'] = 'hook_capture_only';
$hook = g3RefreshHold($hypothetical, [
    'historical_or_hook_time_not_source_modified',
    'approved_schema_source_utc_missing',
    'source_revision_not_verified',
]);
g3RefreshNonAuthorizing($hook);

// Ingestion API Partial supports sparse fields on streaming only.
// Bulk under Partial can null omitted values; must reject without fallback.
$good = $hypothetical;
$good['source_provenance'] = 'independently_verified_source_write';
$good['verified_source_modified_utc'] = '2026-11-13T16:00:00.000Z';
$good['source_revision_evidence'] = 'latest_independently_verified';
$good['source_state_rechecked'] = true;
$good['object'] = 'PSOrder';
$bulk = $good;
$bulk['transport'] = 'bulk';
g3RefreshNonAuthorizing(g3RefreshHold($bulk,
    'partial_requires_streaming_not_bulk'));

// Fully supported hypothetical evidence permits only a REVIEW CANDIDATE,
// never tenant schema publication or actual ingestion.
$partialReview = G3RecordModifiedRefreshReview::inspect($good);
g3RefreshAssert($partialReview['status'] === 'review_candidate_only'
    && $partialReview['reasons'] === [], 'hypothetical partial review rejected');
g3RefreshNonAuthorizing($partialReview);

// Order/customer have independently chosen stream strategies; a valid
// synthetically verified order event does not authorize customer data.
$complete = $good;
$complete['object'] = 'PSCustomer';
$complete['refresh_mode'] = 'full';
$complete['payload_shape'] = 'complete_record';
$complete['transport'] = 'bulk';
$fullReview = G3RecordModifiedRefreshReview::inspect($complete);
g3RefreshAssert($fullReview['status'] === 'review_candidate_only',
    'hypothetical full record upsert incorrectly rejected');
g3RefreshNonAuthorizing($fullReview);

$fullV02 = $complete;
$fullV02['schema_candidate'] = 'v0.2';
$fullV02['record_modified_field'] = 'none';
$fullV02['verified_source_modified_utc'] = null;
g3RefreshHold($fullV02, 'approved_schema_source_utc_missing');

// Partial cannot pretend to be a full record replacement or vice versa.
$badShape = $good;
$badShape['payload_shape'] = 'complete_record';
g3RefreshHold($badShape, 'partial_payload_shape_mismatch');
$badShape = $complete;
$badShape['payload_shape'] = 'selected_fields';
g3RefreshHold($badShape, 'full_upsert_requires_complete_record');

// No causal source revision exists from local journal sequence or hook
// captured_at_utc. A stale or unknown revision is never a good candidate.
$stale = $good;
$stale['source_revision_evidence'] = 'stale_verified';
g3RefreshHold($stale, 'stale_source_revision_quarantine');
$unknown = $good;
$unknown['source_revision_evidence'] = 'unknown';
g3RefreshHold($unknown, 'source_revision_not_verified');
$notRechecked = $good;
$notRechecked['source_state_rechecked'] = false;
g3RefreshHold($notRechecked, 'latest_source_state_not_rechecked');

$invalidField = $good;
$invalidField['record_modified_field'] = 'captured_at_utc';
g3RefreshHold($invalidField, 'unqualified_time_not_record_modified');
$invalidField = $good;
$invalidField['record_modified_field'] = 'source_updated_local';
g3RefreshHold($invalidField, 'unqualified_time_not_record_modified');

foreach ([
    '2026-02-30T10:00:00.000Z',
    '2026-11-13 16:00:00',
    '2026-11-13T16:00:00+01:00',
    '2026-11-13T16:00:00.123Z',
] as $invalidUtc) {
    $test = $good;
    $test['verified_source_modified_utc'] = $invalidUtc;
    g3RefreshReject($test, $invalidUtc === '2026-02-30T10:00:00.000Z'
        ? 'g3_refresh_unverified_utc_calendar' : 'g3_refresh_unverified_utc_format');
}
$extra = $good;
$extra['email'] = 'synthetic@example.invalid';
g3RefreshReject($extra, 'g3_refresh_evidence_shape_invalid');
$wrong = $good;
$wrong['refresh_mode'] = 'engagement';
g3RefreshReject($wrong, 'g3_refresh_evidence_enum_invalid');
$wrong = $good;
$wrong['source_state_rechecked'] = 1;
g3RefreshReject($wrong, 'g3_refresh_source_state_flag_invalid');

$src = file_get_contents(__DIR__ . '/review/G3RecordModifiedRefreshReview.php');
foreach ([
    'curl_', 'Db::', 'PDO', 'file_get_contents(', '->enqueue(',
    '->fire(', '/api/v1/ingest/', 'https://',
] as $forbidden) {
    g3RefreshAssert(strpos($src, $forbidden) === false,
        'review guard has side effects');
}
echo "G3 timestamp/refresh review: partial bulk blocked, verified-source-only hypothetical; zero writes.\n";
