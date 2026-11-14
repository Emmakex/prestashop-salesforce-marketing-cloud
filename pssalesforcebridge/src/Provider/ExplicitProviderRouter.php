<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Provider;

use RuntimeException;

/**
 * Growth-only route planner for any supported PrestaShop 1.7/8/9 shop.
 * No other provider exists. This planner never sends, reads PII or writes.
 */
final class ExplicitProviderRouter
{
    const GROWTH = 'marketing_cloud_growth';

    private $merchant;
    private $namespace;
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
        if ($provider !== self::GROWTH) {
            throw new RuntimeException('growth_router_growth_only');
        }
        if (!is_string($namespace) ||
            !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)) {
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
    }

    public function plan($shopId, $entityType, $entityId)
    {
        if (!is_int($shopId) || $shopId <= 0
            || !is_int($entityId) || $entityId <= 0) {
            throw new RuntimeException('growth_router_entity_scope_invalid');
        }
        if (!is_string($entityType) ||
            !in_array($entityType, ['customer', 'order', 'cart'], true)) {
            throw new RuntimeException('growth_router_entity_type_invalid');
        }
        return [
            'merchant' => $this->merchant,
            'provider' => self::GROWTH,
            'prestashop_version' => $this->psVersion,
            'prestashop_family' => $this->psFamily,
            'source_namespace' => $this->namespace,
            'source_entity_key' => $this->namespace . ':' . $shopId
                . ':' . $entityType . ':' . $entityId,
            'route_target' => 'growth_data_cloud_schema_pending',
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

    public function dispatch($plan)
    {
        throw new RuntimeException('growth_router_outbound_not_authorized');
    }
}
