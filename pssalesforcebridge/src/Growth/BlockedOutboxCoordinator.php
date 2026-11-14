<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;
use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use RuntimeException;

/**
 * Dormant Growth-only pipeline: explicit provider route -> blocked local outbox.
 *
 * This has no sender, PrestaShop hooks, database connection or module install.
 * Caller must inject an insert-or-load store implementing the atomic interface.
 * The disposable g3_ci store is used solely in integration tests; NO install
 * or upgrade in Barcelona Confort until G3 and a separate DB approval.
 */
final class BlockedOutboxCoordinator
{
    /**
     * @param ExplicitProviderRouter $router Configured explicitly for a merchant
     * @param int $shopId
     * @param string $entityType Only customer/order; NO PSCart schema yet
     * @param int $entityId
     * @param string $eventType Allowlisted current PrestaShop hook event
     * @param string $legacyDedupeKey Existing source fingerprint, no PII/payload
     * @param callable $insertOrLoad Isolated atomic Growth store; not a sender
     */
    public static function capture(
        ExplicitProviderRouter $router,
        $shopId,
        $entityType,
        $entityId,
        $eventType,
        $legacyDedupeKey,
        $insertOrLoad
    ) {
        // Prevent a future caller from persisting cart data while PSCart is
        // unapproved. Reject BEFORE invoking even a local store callback.
        if ($entityType === 'cart') {
            throw new RuntimeException('growth_outbox_cart_schema_unapproved');
        }
        $route = $router->plan($shopId, $entityType, $entityId);
        if ($route['provider'] !== ExplicitProviderRouter::GROWTH) {
            throw new RuntimeException('growth_outbox_provider_not_growth');
        }
        if ($route['status'] !== 'blocked' || $route['mode'] !== 'test'
            || $route['route_target'] !== 'growth_data_360_not_configured'
            || $route['data_360_ingestion_authorized'] !== false
            || $route['growth_flow_authorized'] !== false
            || $route['marketing_sends_authorized'] !== false
            || $route['outbound_worker_authorized'] !== false) {
            throw new RuntimeException('growth_outbox_route_not_blocked');
        }
        if (!is_callable($insertOrLoad)) {
            throw new RuntimeException('growth_outbox_atomic_store_required');
        }

        $out = StagedAtomicUtcAcceptance::accept(
            $route['source_namespace'], $eventType, $entityType,
            $shopId, $entityId, $legacyDedupeKey, $insertOrLoad
        );

        // No row, email, token, payload or shop/installation identity leaves
        // this boundary. UTC capture is NOT source Record Modified, and the
        // sequence is NOT an authorization to send or infer source order.
        return [
            'status' => 'blocked_local_only',
            'provider' => ExplicitProviderRouter::GROWTH,
            'inserted' => $out['inserted'],
            'accepted_sequence' => $out['accepted_sequence'],
            'captured_at_utc' => $out['captured_at_utc'],
            'captured_at_reused' => $out['captured_at_reused'],
            'evidence_type' => 'local_atomic_growth_outbox_candidate',
            'source_revision_verified' => false,
            'namespace_global_uniqueness_certified' => false,
            'pscart_schema_approved' => false,
            'data_360_ingestion_authorized' => false,
            'growth_flow_authorized' => false,
            'marketing_sends_authorized' => false,
            'outbound_worker_authorized' => false,
        ];
    }
}
