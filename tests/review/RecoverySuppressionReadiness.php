<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Marketing;

use RuntimeException;

/**
 * Review-only, non-I/O decision contract.
 *
 * No database access, requests, tokens, contact identifiers or outbound sends.
 * This contract is outside the installable module and not wired to Journey.
 * The actual Marketing Cloud subscriber/publication/suppression API reader
 * must be developed and certified independently before enabling sends.
 */
final class RecoverySuppressionReadiness
{
    public static function assess(array $signals)
    {
        $requiredBoolean = [
            'customer_active',
            'customer_deleted',
            'first_party_newsletter',
            'newsletter_provenance_verified',
            'email_matches_current_source',
            'namespace_verified',
            'subscriber_key_matched',
            'cart_activity_time_verified',
            'cart_in_recovery_window',
            'cart_has_order',
            'message_purpose_approved',
            'publication_list_required',
        ];
        $requiredString = [
            'all_subscribers_status',
            'publication_list_status',
            'suppression_state',
        ];
        $allowed = array_merge($requiredBoolean, $requiredString);
        foreach (array_keys($signals) as $key) {
            if (!in_array($key, $allowed, true)) {
                // Forbid accidental PII, contact keys or arbitrary API payloads.
                throw new RuntimeException('recovery_readiness_unknown_signal');
            }
        }
        foreach ($requiredBoolean as $key) {
            if (!array_key_exists($key, $signals) || !is_bool($signals[$key])) {
                throw new RuntimeException('recovery_readiness_boolean_signal_required');
            }
        }
        foreach ($requiredString as $key) {
            if (!array_key_exists($key, $signals) || !is_string($signals[$key])) {
                throw new RuntimeException('recovery_readiness_status_signal_required');
            }
        }
        if (!in_array($signals['all_subscribers_status'],
            ['Active', 'Unsubscribed', 'Held', 'Bounced', 'Deleted', 'Unknown'], true)
            || !in_array($signals['publication_list_status'],
                ['Active', 'Unsubscribed', 'Held', 'Unknown', 'NotApplicable'], true)
            || !in_array($signals['suppression_state'],
                ['clear', 'suppressed', 'unknown'], true)) {
            throw new RuntimeException('recovery_readiness_invalid_status');
        }

        $reasons = [];
        if (!$signals['customer_active'] || $signals['customer_deleted']) {
            $reasons[] = 'source_customer_unavailable';
        }
        if (!$signals['first_party_newsletter']) {
            $reasons[] = 'first_party_newsletter_off';
        }
        if (!$signals['newsletter_provenance_verified']) {
            $reasons[] = 'newsletter_provenance_unverified';
        }
        if (!$signals['email_matches_current_source']) {
            $reasons[] = 'email_changed';
        }
        if (!$signals['namespace_verified'] || !$signals['subscriber_key_matched']) {
            $reasons[] = 'contact_identity_unverified';
        }
        if (!$signals['cart_activity_time_verified'] || !$signals['cart_in_recovery_window']) {
            $reasons[] = 'cart_freshness_unverified_or_expired';
        }
        if ($signals['cart_has_order']) {
            $reasons[] = 'cart_converted';
        }
        if (!$signals['message_purpose_approved']) {
            $reasons[] = 'message_purpose_unapproved';
        }
        if ($signals['all_subscribers_status'] !== 'Active') {
            $reasons[] = 'sfmc_all_subscribers_not_active';
        }
        if ($signals['publication_list_required']
            && $signals['publication_list_status'] !== 'Active') {
            $reasons[] = 'sfmc_publication_not_active';
        }
        if (!$signals['publication_list_required']
            && $signals['publication_list_status'] !== 'NotApplicable') {
            $reasons[] = 'sfmc_publication_scope_ambiguous';
        }
        if ($signals['suppression_state'] !== 'clear') {
            $reasons[] = 'sfmc_suppression_not_clear';
        }

        return [
            'status' => 'review_only',
            'candidate_after_independent_checks' => count($reasons) === 0,
            'reasons' => $reasons,
            'outbound_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_send_authorized' => false,
        ];
    }
}
