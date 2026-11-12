<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Offline, bounded, aggregate-only date quality gate. This class does not
 * query PrestaShop or Salesforce, return record identifiers or authorize sends.
 * Samples are deliberately date-only and never persisted by this class.
 */
final class TimestampQualityAudit
{
    const MAX_SAMPLE = 500;

    /**
     * @param array $samples Each item: source_updated_local and optional verified_absolute_utc
     * @param string $shopTimezone Explicit shop IANA zone (never server default)
     * @param array $policy Required as_of_utc; optional trusted_from_utc plus write_path_verified=true
     * @return array Only aggregate counts, never per-record data or identifying fields
     */
    public static function inspect(array $samples, $shopTimezone, array $policy)
    {
        if (count($samples) > self::MAX_SAMPLE) {
            throw new RuntimeException('data_cloud_quality_sample_limit');
        }
        if (!is_string($shopTimezone) || !in_array($shopTimezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw new RuntimeException('data_cloud_quality_timezone_required');
        }
        $asOf = self::utcEpoch(isset($policy['as_of_utc']) ? $policy['as_of_utc'] : null);
        $verifiedPath = isset($policy['write_path_verified']) && $policy['write_path_verified'] === true;
        $cutover = null;
        if (array_key_exists('trusted_from_utc', $policy)) {
            $cutover = self::utcEpoch($policy['trusted_from_utc']);
            if ($cutover > $asOf) {
                throw new RuntimeException('data_cloud_quality_future_cutover');
            }
        }
        if ($verifiedPath && $cutover === null) {
            throw new RuntimeException('data_cloud_quality_cutover_required');
        }

        $counts = [
            'eligible_evidenced' => 0,
            'eligible_after_verified_cutover' => 0,
            'unverified_history' => 0,
            'quarantine_missing' => 0,
            'quarantine_invalid' => 0,
            'quarantine_dst_gap' => 0,
            'quarantine_dst_overlap' => 0,
            'quarantine_future' => 0,
            'quarantine_evidence_mismatch' => 0,
        ];
        foreach ($samples as $sample) {
            if (!is_array($sample) ||
                array_diff(array_keys($sample), ['source_updated_local', 'verified_absolute_utc'])) {
                // Guard against accidentally passing PII or IDs to an audit.
                throw new RuntimeException('data_cloud_quality_date_only_samples_required');
            }
            if (!isset($sample['source_updated_local']) || $sample['source_updated_local'] === '') {
                ++$counts['quarantine_missing'];
                continue;
            }
            try {
                $candidate = SourceTimestampNormalizer::toUtc($sample['source_updated_local'], $shopTimezone);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'data_cloud_timestamp_ambiguous_local_time') {
                    ++$counts['quarantine_dst_overlap'];
                } elseif ($e->getMessage() === 'data_cloud_timestamp_nonexistent_local_time') {
                    ++$counts['quarantine_dst_gap'];
                } else {
                    ++$counts['quarantine_invalid'];
                }
                continue;
            }
            $candidateEpoch = self::utcEpoch($candidate);
            if ($candidateEpoch > $asOf + 300) {
                ++$counts['quarantine_future'];
                continue;
            }
            if (array_key_exists('verified_absolute_utc', $sample)) {
                // A separate reliable UTC audit source is required. A conflicting
                // source is quarantined even if the local string looks plausible.
                try {
                    $verifiedEpoch = self::utcEpoch($sample['verified_absolute_utc']);
                } catch (RuntimeException $e) {
                    ++$counts['quarantine_evidence_mismatch'];
                    continue;
                }
                if ($verifiedEpoch !== $candidateEpoch) {
                    ++$counts['quarantine_evidence_mismatch'];
                    continue;
                }
                ++$counts['eligible_evidenced'];
            } elseif ($verifiedPath && $cutover !== null && $candidateEpoch >= $cutover) {
                // Only an independently certified write path can make this true.
                ++$counts['eligible_after_verified_cutover'];
            } else {
                ++$counts['unverified_history'];
            }
        }

        $eligible = $counts['eligible_evidenced'] + $counts['eligible_after_verified_cutover'];
        $quarantined = 0;
        foreach ($counts as $key => $count) {
            if (strpos($key, 'quarantine_') === 0) {
                $quarantined += $count;
            }
        }
        $total = count($samples);
        $scope = 'hold';
        if ($eligible > 0) {
            $scope = $verifiedPath && $cutover !== null
                ? 'verified_cutover_or_evidence_only_candidate'
                : 'individually_evidenced_only_candidate';
        }

        return [
            'status' => 'review_only',
            'sampled' => $total,
            'counts' => $counts,
            'eligible_in_sample' => $eligible,
            'quarantined_in_sample' => $quarantined,
            'unverified_in_sample' => $counts['unverified_history'],
            'quarantined_percent' => $total ? round(100 * $quarantined / $total, 2) : 0,
            'recommended_scope' => $scope,
            'historical_backfill_allowed' => false,
            'record_ingestion_allowed' => false,
            'schema_upload_allowed' => false,
            'marketing_sends_allowed' => false,
        ];
    }

    private static function utcEpoch($value)
    {
        if (!is_string($value) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/D', $value)) {
            throw new RuntimeException('data_cloud_quality_utc_evidence_invalid');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new RuntimeException('data_cloud_quality_utc_evidence_invalid');
        }
        return $parsed->getTimestamp();
    }
}
