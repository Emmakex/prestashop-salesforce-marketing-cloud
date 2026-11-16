<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use DateTimeImmutable;
use DateTimeZone;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\SourceTimestampNormalizer;
use RuntimeException;

/**
 * G7 offline elapsed-time review of the 60-minute abandoned-cart threshold.
 *
 * Reuses the existing strict DST-aware local -> UTC normalizer. The input
 * clock, current shop timezone, and source write-path provenance are caller
 * assertions: even an elapsed review is NOT consent, snapshot validity, a
 * checkout exclusion fence or permission to communicate.
 *
 * Never returns the input clock, UTC instant, customer/cart IDs or PII.
 */
final class GrowthCartInactivityWindowReview
{
    const MIN_SECONDS = 3600;

    public static function inspect($localDateUpd, $shopTimezone, $asOfUtc, $writePathEvidence)
    {
        if (!is_string($asOfUtc)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',
                $asOfUtc)) {
            throw new RuntimeException('growth_cart_window_now_utc_invalid');
        }
        $now = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z', $asOfUtc, new DateTimeZone('UTC')
        );
        if ($now === false || $now->format('Y-m-d\TH:i:s\Z') !== $asOfUtc) {
            throw new RuntimeException('growth_cart_window_now_utc_invalid');
        }
        if (!is_string($writePathEvidence)
            || !in_array($writePathEvidence, [
                'unverified', 'shop_local_write_path_asserted'
            ], true)) {
            throw new RuntimeException('growth_cart_window_evidence_invalid');
        }

        // Do NOT reinterpret timestamps merely because PS_TIMEZONE currently
        // equals Europe/Madrid (or another valid IANA identifier). Different
        // writers/importers/cron paths may store different clock semantics.
        if ($writePathEvidence !== 'shop_local_write_path_asserted') {
            return self::hold('write_path_provenance_unknown');
        }
        try {
            $sourceUtc = SourceTimestampNormalizer::toUtc(
                $localDateUpd, $shopTimezone
            );
        } catch (RuntimeException $error) {
            $kind = $error->getMessage();
            $known = [
                'data_cloud_timestamp_ambiguous_local_time' => 'dst_overlap_ambiguous',
                'data_cloud_timestamp_nonexistent_local_time' => 'dst_gap_nonexistent',
                'data_cloud_timestamp_bad_calendar_date' => 'invalid_source_calendar',
                'data_cloud_timestamp_bad_format' => 'invalid_source_format',
                'data_cloud_timestamp_timezone_required' => 'shop_timezone_unknown',
            ];
            return self::hold(isset($known[$kind]) ? $known[$kind]
                : 'timestamp_conversion_unverified');
        }

        // DateTimeImmutable with explicit UTC timezone; no PHP/DB local TZ.
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s', substr($sourceUtc, 0, 19),
            new DateTimeZone('UTC')
        );
        if ($instant === false
            || $instant->format('Y-m-d\TH:i:s') !== substr($sourceUtc, 0, 19)) {
            return self::hold('timestamp_conversion_unverified');
        }
        $elapsed = $now->getTimestamp() - $instant->getTimestamp();
        if ($elapsed < 0) {
            return self::hold('source_clock_in_future');
        }
        if ($elapsed < self::MIN_SECONDS) {
            return self::hold('under_60_minutes');
        }

        // The 60 minutes have elapsed ONLY UNDER the explicit writer,
        // timezone and "now" assertions. It is NEVER a send decision.
        return self::hold('elapsed_60m_candidate_under_asserted_provenance');
    }

    private static function hold($state)
    {
        return [
            'status' => 'hold_for_review',
            'inactivity_window_state' => $state,
            'minimum_seconds' => self::MIN_SECONDS,
            'source_write_path_certified_for_bc' => false,
            'as_of_utc_clock_certified_for_bc' => false,
            'cart_snapshot_equivalence_certified' => false,
            'abandoned_cart_confirmed_for_bc' => false,
            'race_with_checkout_and_optout_closed' => false,
            'growth_purpose_consent_certified' => false,
            'recovery_link_creation_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private function __construct()
    {
    }
}
