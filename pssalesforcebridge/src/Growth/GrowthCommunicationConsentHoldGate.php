<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * G6 read-only, purpose-bound Growth consent/suppression candidate.
 *
 * PrestaShop newsletter/optin are NEGATIVE HOLD indicators only.
 * Actual Marketing Cloud Growth consent is managed through Salesforce's
 * Communication Subscription Consent (CSC), not through contact flags,
 * the PrestaShop PST streams, or direct writes into a consent DMO.
 *
 * This reader is NOT hooked to cron, worker or sender. A good CSC
 * snapshot remains a local REVIEW CANDIDATE: a future campaign must use
 * Salesforce's send-time consent controls and an atomic final exclusion.
 *
 * The CSC reader is an injected read-only callback, never an invented HTTP
 * connection. Its returned snapshot must be treated as untrusted evidence.
 */
final class GrowthCommunicationConsentHoldGate
{
    const MAX_EVIDENCE_AGE_SECONDS = 300;
    const MAX_FUTURE_SKEW_SECONDS = 30;
    const CHANNEL = 'email';

    public static function inspect(
        $shopId, $customerId, $namespace, $subscriptionId,
        $asOfUtc, $selectCustomerRows, $prefix, $readCsc
    ) {
        if (!is_int($shopId) || $shopId <= 0
            || !is_int($customerId) || $customerId <= 0
            || !is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
            || !is_string($subscriptionId)
            || !preg_match('/^[a-zA-Z0-9_-]{6,96}$/D', $subscriptionId)
            || !is_callable($readCsc)) {
            throw new RuntimeException('growth_csc_input_scope_invalid');
        }
        $now = self::utc($asOfUtc);
        if ($now === null) {
            throw new RuntimeException('growth_csc_now_utc_invalid');
        }

        // First verify the native shop/customer. Never send an ID, email, or
        // a contact point to an external callback if local suppression
        // already blocks the customer. No cached PrestaShop SELECT.
        $local = GrowthLocalCustomerHoldReader::inspect(
            $shopId, $customerId, $selectCustomerRows, $prefix
        );
        if ($local['local_customer_source_state'] !== 'no_local_negative_observed') {
            return self::blocked(
                array_values(array_unique(array_merge(
                    ['local_customer_hold'], $local['reasons']
                ))),
                'not_checked_due_to_local_hold', $local['local_customer_source_state']
            );
        }

        $sourceKey = $namespace . ':' . $shopId . ':customer:' . $customerId;
        try {
            $records = call_user_func(
                $readCsc, $sourceKey, $subscriptionId, self::CHANNEL
            );
        } catch (Throwable $error) {
            return self::blocked(
                ['salesforce_csc_read_failed'], 'unknown',
                $local['local_customer_source_state']
            );
        }

        // Fail closed on absent, duplicated, incompatible or stale records.
        // This callback must eventually be bound to a genuine Salesforce
        // consent read; a caller-supplied array never certifies the adapter.
        if ($records === []) {
            return self::blocked(
                ['salesforce_csc_missing'], 'missing',
                $local['local_customer_source_state']
            );
        }
        if (!is_array($records) || count($records) !== 1
            || !isset($records[0]) || !is_array($records[0])) {
            return self::blocked(
                ['salesforce_csc_ambiguous_or_invalid'], 'unknown',
                $local['local_customer_source_state']
            );
        }
        $record = $records[0];
        $keys = array_keys($record);
        sort($keys);
        if ($keys !== [
            'channel', 'contact_point_source_key', 'observed_at_utc',
            'source', 'status', 'subscription_id'
        ]) {
            return self::blocked(
                ['salesforce_csc_shape_invalid'], 'unknown',
                $local['local_customer_source_state']
            );
        }
        if ($record['contact_point_source_key'] !== $sourceKey
            || $record['subscription_id'] !== $subscriptionId
            || $record['channel'] !== self::CHANNEL
            || $record['source'] !== 'salesforce_communication_subscription_consent') {
            return self::blocked(
                ['salesforce_csc_scope_mismatch'], 'unknown',
                $local['local_customer_source_state']
            );
        }
        $state = $record['status'];
        if ($state === 'opt_out') {
            return self::blocked(
                ['salesforce_csc_opted_out'], 'opt_out',
                $local['local_customer_source_state']
            );
        }
        if ($state !== 'opt_in') {
            return self::blocked(
                ['salesforce_csc_unverified_or_unknown'], 'unknown',
                $local['local_customer_source_state']
            );
        }
        $observed = self::utc($record['observed_at_utc']);
        if ($observed === null
            || $observed > $now + self::MAX_FUTURE_SKEW_SECONDS
            || $now - $observed > self::MAX_EVIDENCE_AGE_SECONDS) {
            return self::blocked(
                ['salesforce_csc_stale_or_invalid'], 'unknown',
                $local['local_customer_source_state']
            );
        }

        // A positive recent CSC is REVIEW-ONLY. This result is deliberately
        // NOT send permission: no certified CSC connector/source policy,
        // no atomic pre-dispatch fence and no Core/Growth campaign setup.
        return [
            'status' => 'review_candidate_only',
            'csc_source_state' => 'opt_in_observed_for_requested_subscription',
            'local_customer_source_state' => $local['local_customer_source_state'],
            'reasons' => ['csc_adapter_and_send_time_fence_not_certified'],
            'salesforce_csc_reader_certified_for_bc' => false,
            'salesforce_send_time_consent_certified' => false,
            'local_suppression_checked' => true,
            'external_suppression_sources_complete' => false,
            'final_atomic_exclusion_fence_ready' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    /**
     * Read-only native PrestaShop 1.7/8/9 adapter for the local HALF of the
     * evidence. The other half is only an injected read-only CSC adapter,
     * which MUST be validated and certified independently before deployment.
     */
    public static function inspectPrestaShop(
        $shopId, $customerId, $namespace, $subscriptionId, $asOfUtc, $readCsc
    ) {
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new RuntimeException('growth_csc_prestashop_unavailable');
        }
        return self::inspect(
            $shopId, $customerId, $namespace, $subscriptionId, $asOfUtc,
            function ($sql) {
                return \Db::getInstance()->executeS($sql, true, false);
            },
            _DB_PREFIX_, $readCsc
        );
    }

    private static function utc($value)
    {
        if (!is_string($value)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D',
                $value)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC')
        );
        if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            return null;
        }
        return $time->getTimestamp();
    }

    private static function blocked(array $reasons, $sourceState, $localState)
    {
        return [
            'status' => 'hold_for_review',
            'csc_source_state' => $sourceState,
            'local_customer_source_state' => $localState,
            'reasons' => $reasons,
            'salesforce_csc_reader_certified_for_bc' => false,
            'salesforce_send_time_consent_certified' => false,
            'local_suppression_checked' =>
                $localState !== 'unknown',
            'external_suppression_sources_complete' => false,
            'final_atomic_exclusion_fence_ready' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private function __construct()
    {
    }
}
