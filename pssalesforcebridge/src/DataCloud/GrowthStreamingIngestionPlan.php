<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * G7 streaming Data Cloud request plan: in-memory only, no HTTP or queue.
 *
 * Reuses the canonical PST v0.4 encoder and requires explicit installation
 * and shop ownership. The response body can contain source PII and must never
 * be logged, stored in diagnostics, or returned to an admin UI.
 *
 * The object upsert endpoint accepts asynchronously with 202. By contrast
 * the /actions/test endpoint validates synchronously and MUST NOT be
 * treated as interchangeable with an actual upsert.
 *
 * This class never grants permission to ingest: G5 /actions/test remains
 * quarantined, PSCart is unsupported, and G6 marketing send time consent
 * is not certified. Not loaded by PrestaShop entrypoint/hooks/cron.
 */
final class GrowthStreamingIngestionPlan
{
    const MAX_BATCH = 50;
    const MAX_BODY_BYTES = 204800;

    public static function prepare(
        $origin, $sourceName, $object, array $records,
        $namespace, $shopId, $provider, $mode
    ) {
        if ($provider !== 'marketing_cloud_growth' || $mode !== 'test') {
            throw new RuntimeException('g7_streaming_growth_test_only');
        }
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
            || !is_int($shopId) || $shopId < 1) {
            throw new RuntimeException('g7_streaming_namespace_or_shop_invalid');
        }
        if (!in_array($object, ['PSCustomer', 'PSOrder'], true)) {
            throw new RuntimeException('g7_streaming_object_not_approved');
        }
        if (!is_string($sourceName)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,95}$/D', $sourceName)) {
            throw new RuntimeException('g7_streaming_connector_invalid');
        }
        $canonicalOrigin = self::origin($origin);

        // Enforce that every source record belongs to the SAME explicit shop.
        // Do this before constructing the PII-bearing serialized body.
        if (count($records) < 1 || count($records) > self::MAX_BATCH) {
            throw new RuntimeException('g7_streaming_batch_size_invalid');
        }
        foreach ($records as $record) {
            if (!is_array($record)
                || !isset($record['source_namespace'], $record['shop_id'])
                || $record['source_namespace'] !== $namespace
                || $record['shop_id'] !== $shopId) {
                throw new RuntimeException('g7_streaming_cross_shop_or_namespace');
            }
        }

        // This encoder also checks the 11 exact fields per record, typed PK/FK,
        // duplicate keys, source local calendar, and 200KB limit.
        $body = GrowthTypedPstPayloadBuilder::encode(
            $object, $records, $provider, $mode
        );
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('g7_streaming_body_limit');
        }

        return [
            'status' => 'blocked_transport_plan_only',
            'provider' => 'marketing_cloud_growth',
            'object' => $object,
            'method' => 'POST',
            'url' => $canonicalOrigin . '/api/v1/ingest/sources/'
                . $sourceName . '/' . $object,
            // Contains potential customer data: never expose to an artifact.
            'body' => $body,
            'count' => count($records),
            'async_acceptance_http_status' => 202,
            'source_timestamp_verified' => false,
            'merchant_namespace_approved' => false,
            'g5_synthetic_validation_certified' => false,
            'growth_native_consent_certified' => false,
            'persisted_in_data_cloud_certified' => false,
            'dispatch_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    /**
     * Sanitized result classifier for a future authorized sender.
     *
     * 202 is not proof of a persisted DLO/DMO record. Timeouts and unknown
     * outcomes must never be blindly replayed. Status hints do not start
     * any retry, revoke suppression, or release an outbox claim.
     */
    public static function classify($httpStatus, $responseReceived)
    {
        if (!is_bool($responseReceived)) {
            throw new RuntimeException('g7_streaming_outcome_input_invalid');
        }
        if (!$responseReceived) {
            return self::outcome('unknown_after_transport', false, true);
        }
        if (!is_int($httpStatus) || $httpStatus < 100
            || $httpStatus > 599) {
            return self::outcome('invalid_http_status_hold', false, false);
        }
        if ($httpStatus === 202) {
            return self::outcome('accepted_asynchronously_unconfirmed', true, false);
        }
        if ($httpStatus === 429 || $httpStatus === 500
            || $httpStatus === 502 || $httpStatus === 503
            || $httpStatus === 504) {
            return self::outcome('backoff_and_reconcile_before_retry', false, true);
        }
        if ($httpStatus === 401) {
            return self::outcome('authentication_requires_review', false, false);
        }
        if ($httpStatus === 400 || $httpStatus === 403
            || $httpStatus === 404 || $httpStatus === 409
            || $httpStatus === 422) {
            return self::outcome('request_or_schema_requires_review', false, false);
        }
        // In particular a 200 from the real async upsert endpoint does NOT
        // inherit /actions/test's meaning; it may be the wrong route.
        return self::outcome('unexpected_http_status_hold', false, false);
    }

    /**
     * Classification is intentionally unable to authorize another attempt:
     * future retry/delivery logic must reconcile a 202 or timeout against
     * Data Cloud and apply G5/G6 plus local exclusions at actual send time.
     */
    private static function outcome($state, $accepted, $needsReconciliation)
    {
        return [
            'status' => 'hold_for_review',
            'outcome_state' => $state,
            'request_accepted_asynchronously' => $accepted,
            'requires_manual_or_source_reconciliation' => $needsReconciliation,
            'record_persisted_certified' => false,
            'record_mapped_to_dmo_certified' => false,
            'marketing_consent_certified' => false,
            'automatic_retry_authorized' => false,
            'outbox_claim_release_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function origin($value)
    {
        if (!is_string($value) || strlen($value) > 253) {
            throw new RuntimeException('g7_streaming_origin_invalid');
        }
        $url = parse_url($value);
        if (!is_array($url) || !isset($url['scheme'], $url['host'])
            || strtolower($url['scheme']) !== 'https'
            || isset($url['user']) || isset($url['pass'])
            || isset($url['port']) || isset($url['query'])
            || isset($url['fragment'])
            || isset($url['path']) && $url['path'] !== ''
                && $url['path'] !== '/'
            || !preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$/Di',
                $url['host']
            )) {
            throw new RuntimeException('g7_streaming_origin_invalid');
        }
        return 'https://' . strtolower($url['host']);
    }

    private function __construct() {}
}
