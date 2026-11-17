<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthStreamingIngestionPlan;
use RuntimeException;

/**
 * G7 deterministic worker rehearsal against the SAME G3 outbox in g3_ci.
 *
 * The injected HTTP outcome is a synthetic value, NOT an HTTP client or
 * callback. The lease constructor refuses every database except disposable
 * g3_ci. This class is NOT loaded by the PrestaShop entrypoint or cron.
 * It never serializes customer data or grants a delivery permit.
 */
final class GrowthBlockedDispatchRehearsal
{
    public static function simulate(
        GrowthOutboxDeliveryLease $lease,
        $namespace, $shop, $type, $id, $sequence, $token,
        $responseReceived, $httpStatus
    ) {
        if (!is_bool($responseReceived)
            || ($responseReceived && (!is_int($httpStatus)
                || $httpStatus < 100 || $httpStatus > 599))
            || (!$responseReceived && $httpStatus !== null)) {
            throw new RuntimeException('g7_rehearsal_simulated_outcome_invalid');
        }

        // No source rows are fetched here and no PII-bearing payload exists.
        // A 'ready' row may only be constructed explicitly by the CI fixture;
        // the installed module's rows stay 'blocked' by their CHECK guard.
        $claimed = $lease->claim(
            $namespace, $shop, $type, $id, $sequence, $token, 60
        );
        if (!$claimed) {
            return self::report('claim_denied', false, null);
        }

        // Existing shared classifier preserves the asynchronous 202 boundary.
        $classification = GrowthStreamingIngestionPlan::classify(
            $httpStatus, $responseReceived
        );
        if (!$responseReceived) {
            $finalized = $lease->markUnknownUnconfirmed(
                $namespace, $shop, $type, $id, $sequence, $token
            );
            $state = 'reconcile';
        } elseif ($httpStatus === 202) {
            $finalized = $lease->markAcceptedUnconfirmed(
                $namespace, $shop, $type, $id, $sequence, $token
            );
            $state = 'reconcile';
        } else {
            // Even an unexpected 200 is NOT delivery confirmation for the
            // asynchronous upsert endpoint. No automatic retry or release.
            $finalized = $lease->holdClaim(
                $namespace, $shop, $type, $id, $sequence, $token,
                $httpStatus
            );
            $state = 'held';
        }

        if (!$finalized) {
            // A concurrent cancellation, expired lease or lost ownership
            // must never be silently treated as successful dispatch.
            return self::report(
                'lease_finalization_requires_review', true,
                $classification['outcome_state']
            );
        }
        return self::report(
            $state, true, $classification['outcome_state']
        );
    }

    private static function report($state, $claimed, $outcome)
    {
        return [
            'status' => 'sandbox_simulation_only',
            'state' => $state,
            'lease_claimed' => $claimed,
            'outcome_state' => $outcome,
            'simulated_remote_operation' => true,
            'http_request_performed' => false,
            'source_payload_read' => false,
            'record_persisted_certified' => false,
            'outbox_delivery_authorized' => false,
            'automatic_retry_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private function __construct() {}
}
