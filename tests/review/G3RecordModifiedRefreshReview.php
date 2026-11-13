<?php
/**
 * G3-03/G3-04 only: offline, synthetic planning gate for Data 360 Ingestion API.
 *
 * NO RECORDS, NO NETWORK, NO DB, NO TRANSPORT, NO APPROVAL.
 * A "review_candidate_only" is a hypothetical architecture choice, never
 * an attestation of production source time or permission to send.
 */
final class G3RecordModifiedRefreshReview
{
    private static $keys = [
        'object', 'schema_candidate', 'refresh_mode', 'transport',
        'record_modified_field', 'source_provenance',
        'verified_source_modified_utc', 'source_revision_evidence',
        'payload_shape', 'source_state_rechecked',
    ];

    public static function inspect(array $evidence)
    {
        if (array_keys($evidence) !== self::$keys) {
            // No identifying values, customer email, order ID, payload or secrets.
            throw new RuntimeException('g3_refresh_evidence_shape_invalid');
        }
        self::oneOf($evidence['object'], ['PSCustomer', 'PSOrder']);
        self::oneOf($evidence['schema_candidate'], ['v0.2', 'v0.3']);
        self::oneOf($evidence['refresh_mode'], ['unselected', 'full', 'partial']);
        self::oneOf($evidence['transport'], ['unselected', 'streaming', 'bulk']);
        self::oneOf($evidence['record_modified_field'], [
            'unselected', 'none', 'source_updated_local',
            'captured_at_utc', 'source_updated_utc',
        ]);
        self::oneOf($evidence['source_provenance'], [
            'historical_unverified', 'hook_capture_only',
            'independently_verified_source_write',
        ]);
        self::oneOf($evidence['source_revision_evidence'], [
            'unknown', 'latest_independently_verified', 'stale_verified',
        ]);
        self::oneOf($evidence['payload_shape'], [
            'unknown', 'complete_record', 'selected_fields',
        ]);
        if (!is_bool($evidence['source_state_rechecked'])) {
            throw new RuntimeException('g3_refresh_source_state_flag_invalid');
        }
        $utc = $evidence['verified_source_modified_utc'];
        if ($utc !== null) {
            if (!is_string($utc)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/D', $utc)) {
                throw new RuntimeException('g3_refresh_unverified_utc_format');
            }
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s', substr($utc, 0, 19), new DateTimeZone('UTC')
            );
            if ($date === false || $date->format('Y-m-d\TH:i:s') . '.000Z' !== $utc) {
                throw new RuntimeException('g3_refresh_unverified_utc_calendar');
            }
        }

        $reasons = [];
        if ($evidence['refresh_mode'] === 'unselected'
            || $evidence['transport'] === 'unselected') {
            $reasons[] = 'refresh_and_transport_not_selected';
        }
        if ($evidence['record_modified_field'] === 'source_updated_local'
            || $evidence['record_modified_field'] === 'captured_at_utc') {
            // A SQL DATETIME without write-time TZ and a hook capture are
            // not source record modification and must not become a watermark.
            $reasons[] = 'unqualified_time_not_record_modified';
        }
        if ($evidence['source_provenance'] !== 'independently_verified_source_write') {
            $reasons[] = 'historical_or_hook_time_not_source_modified';
        }
        if ($utc === null
            || $evidence['record_modified_field'] !== 'source_updated_utc'
            || $evidence['schema_candidate'] !== 'v0.3') {
            $reasons[] = 'approved_schema_source_utc_missing';
        }
        if ($evidence['source_revision_evidence'] !== 'latest_independently_verified') {
            $reasons[] = $evidence['source_revision_evidence'] === 'stale_verified'
                ? 'stale_source_revision_quarantine'
                : 'source_revision_not_verified';
        }
        if (!$evidence['source_state_rechecked']) {
            $reasons[] = 'latest_source_state_not_rechecked';
        }

        if ($evidence['refresh_mode'] === 'partial') {
            // Official Salesforce Ingestion API: partial updates apply to
            // STREAMING only. Bulk acts as upsert and omitted fields become
            // null, despite Partial being selected on the Data Stream.
            if ($evidence['transport'] !== 'streaming') {
                $reasons[] = 'partial_requires_streaming_not_bulk';
            }
            if ($evidence['payload_shape'] !== 'selected_fields') {
                $reasons[] = 'partial_payload_shape_mismatch';
            }
        } elseif ($evidence['refresh_mode'] === 'full') {
            // Full is NOT permission to infer a complete daily replacement
            // or automatically delete missing entities. Require all fields
            // for this hypothetical upsert and independently verified order.
            if ($evidence['payload_shape'] !== 'complete_record') {
                $reasons[] = 'full_upsert_requires_complete_record';
            }
        }
        $reasons = array_values(array_unique($reasons));

        return [
            'object' => $evidence['object'],
            'status' => $reasons ? 'hold_for_owner_evidence'
                : 'review_candidate_only',
            'reasons' => $reasons,
            // Source attestation from a hypothetical test is not an
            // owner signature; never promote these to an outbound gate.
            'source_provenance_certified_for_bc' => false,
            'record_modified_authorized' => false,
            'refresh_mode_approved' => false,
            'data_360_schema_upload_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'historical_backfill_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function oneOf($value, array $allowed)
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException('g3_refresh_evidence_enum_invalid');
        }
    }
}
