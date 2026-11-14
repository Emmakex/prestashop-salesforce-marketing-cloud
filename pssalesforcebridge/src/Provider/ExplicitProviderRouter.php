<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Provider;

use RuntimeException;

/**
 * Provider-neutral, dormant route planning for the future Growth adapter.
 *
 * No configuration writes, Salesforce clients, handlers, hooks or workers.
 * Not loaded by pssalesforcebridge 0.7.1 and cannot authorize outbound work.
 * Installation namespaces are supplied explicitly; syntax is NOT proof of
 * global uniqueness/ownership (G3-01 still requires human approval).
 */
final class ExplicitProviderRouter
{
    const GROWTH = 'marketing_cloud_growth';
    const ENGAGEMENT_LEGACY = 'marketing_cloud_engagement';

    private $merchant;
    private $namespace;
    private $provider;
    private $psVersion;
    private $psFamily;

    public function __construct($psVersion, $merchant, $provider, $namespace, $mode)
    {
        if (!is_string($psVersion) ||
            !preg_match('/^(1\.7\.8\.[0-9]+|[89]\.[0-9]+\.[0-9]+)$/D', $psVersion)) {
            throw new RuntimeException('growth_router_unsupported_prestashop_version');
        }
        if (!is_string($merchant) ||
            !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant)) {
            throw new RuntimeException('growth_router_merchant_required');
        }
        if ($provider !== self::GROWTH && $provider !== self::ENGAGEMENT_LEGACY) {
            throw new RuntimeException('growth_router_explicit_provider_required');
        }
        if ($merchant === 'ABH' && $provider !== self::GROWTH) {
            throw new RuntimeException('growth_router_abh_requires_growth');
        }
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)) {
            throw new RuntimeException('growth_router_namespace_required');
        }
        if ($mode !== 'test') {
            throw new RuntimeException('growth_router_test_only');
        }
        $this->psVersion = $psVersion;
        $this->psFamily = strpos($psVersion, '1.7.8.') === 0
            ? '1.7.8' : (strpos($psVersion, '8.') === 0 ? '8' : '9');
        $this->merchant = $merchant;
        $this->namespace = $namespace;
        $this->provider = $provider;
    }

    /**
     * Pure route PLAN for a local entity. Deliberately no payload/PII.
     * Future approved adapters must separately certify identity, consent,
     * source versions, retention, queue and network delivery.
     */
    public function plan($shopId, $entityType, $entityId)
    {
        if (!is_int($shopId) || $shopId < 1 ||
            !is_int($entityId) || $entityId < 1) {
            throw new RuntimeException('growth_router_entity_scope_invalid');
        }
        if (!is_string($entityType) || !in_array($entityType,
            ['customer', 'order', 'cart'], true)) {
            throw new RuntimeException('growth_router_entity_type_invalid');
        }
        $sourceKey = $this->namespace . ':' . $shopId
            . ':' . $entityType . ':' . $entityId;
        return [
            'merchant' => $this->merchant,
            'provider' => $this->provider,
            'prestashop_version' => $this->psVersion,
            'prestashop_family' => $this->psFamily,
            'source_namespace' => $this->namespace,
            'source_entity_key' => $sourceKey,
            'route_target' => $this->provider === self::GROWTH
                ? 'growth_data_360_not_configured'
                : 'engagement_legacy_not_certified',
            'mode' => 'test',
            'status' => 'blocked',
            'namespace_global_uniqueness_certified' => false,
            'tenant_data_contract_approved' => false,
            'external_transport_configured' => false,
            'data_360_ingestion_authorized' => false,
            'growth_flow_authorized' => false,
            'marketing_sends_authorized' => false,
            'outbound_worker_authorized' => false,
        ];
    }

    /**
     * This candidate is incapable of sending even if a caller tries to
     * force production by passing an existing plan.
     */
    public function dispatch($plan)
    {
        throw new RuntimeException('growth_router_outbound_not_authorized');
    }
}
