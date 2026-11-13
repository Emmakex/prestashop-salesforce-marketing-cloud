<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * Candidate-only UTC observation of a FUTURE PrestaShop event hook.
 *
 * Not wired to hooks, queue, persistence, existing v0.7.1 senders or
 * Salesforce/Data 360. This is the UTC instant when PHP observes a hook,
 * NOT the time the source record was modified or the event was committed.
 * Uses PHP Unix epoch seconds: independent of PS_TIMEZONE and DST.
 */
final class LiveEventUtcObservation
{
    /**
     * The actual event names currently emitted by PrestaShop bridge hooks.
     * Intentional allowlist. Do not accept arbitrary strings or old backfills.
     */
    private static $hookEvents = [
        'CUSTOMER_CREATED',
        'CUSTOMER_UPDATED',
        'ORDER_CREATED',
        'ORDER_UPDATED',
        'ORDER_PAID',
        'ORDER_CANCELLED',
    ];

    /**
     * @param string $eventType Exact existing hook event type.
     * @return array Pure metadata. NEVER a Salesforce ingest payload.
     */
    public static function observeNow($eventType)
    {
        if (!is_string($eventType) || !in_array($eventType, self::$hookEvents, true)) {
            throw new RuntimeException('data_cloud_live_observation_hook_not_allowed');
        }

        // time() represents a Unix epoch regardless of PHP's configured
        // timezone. It is NOT proof that this host clock is NTP synchronized.
        $epochSeconds = time();
        if (!is_int($epochSeconds)
            || $epochSeconds < 946684800
            || $epochSeconds >= 4102444800) {
            throw new RuntimeException('data_cloud_live_observation_clock_invalid');
        }

        $timestamp = gmdate('Y-m-d\TH:i:s', $epochSeconds) . '.000Z';
        if (!is_string($timestamp)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/D', $timestamp)) {
            throw new RuntimeException('data_cloud_live_observation_format_invalid');
        }

        // Observation time is not a durable version/watermark: two changes may
        // occur within one second, network delivery may be out of order, and
        // a replay must not forge a new source_modified timestamp.
        return [
            'event_type' => $eventType,
            'captured_at_utc' => $timestamp,
            'captured_at_precision' => 'seconds',
            'clock_source' => 'php_unix_epoch_seconds',
            'clock_sync_verified' => false,
            'source_modified_provenance' => 'unverified',
            'source_updated_utc' => null,
            'record_modified_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }
}
