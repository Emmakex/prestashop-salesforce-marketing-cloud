<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\CustomerHookAdapter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\OrderHookAdapter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use RuntimeException;

/**
 * Dormant, locally blocked bridge from existing PrestaShop 0.7.1 hook shapes.
 *
 * This adapter is deliberately NOT included by pssalesforcebridge.php,
 * registered as a hook, or called by any sender. The caller must supply
 * a source dedupe fingerprint already produced by the current event service
 * and an explicit atomic store callback. This class NEVER re-hashes customer
 * PII, trusts the current shop context as fallback, reads the database, or
 * builds an outbound payload.
 *
 * Only hooks with a customer/order object directly present in hook params
 * are supported. Order status transitions are NOT inferred from a status
 * ID or request context; they need a separate certified source contract.
 */
final class GrowthBlockedHookAdapter
{
    public static function capture(
        ExplicitProviderRouter $router, $hookName, $params,
        $legacyDedupeKey, $insertOrLoad
    ) {
        if (!is_string($hookName) || !in_array($hookName, [
            'actionCustomerAccountAdd',
            'actionCustomerAccountUpdate',
            'actionValidateOrder',
        ], true)) {
            throw new RuntimeException('growth_hook_not_supported');
        }
        if (!is_array($params)) {
            throw new RuntimeException('growth_hook_params_invalid');
        }

        if ($hookName === 'actionValidateOrder') {
            $entity = OrderHookAdapter::orderFromParams($params);
            $entityType = 'order';
            $eventType = 'ORDER_CREATED';
        } else {
            $entity = CustomerHookAdapter::customerFromParams($params);
            $entityType = 'customer';
            $eventType = $hookName === 'actionCustomerAccountAdd'
                ? 'CUSTOMER_CREATED' : 'CUSTOMER_UPDATED';
        }

        // In legacy mode the compatibility adapters sometimes fall back to
        // Context::shop or shop 1. Growth requires explicit source ownership,
        // so neither fallback is acceptable for merchant isolation.
        if (!is_object($entity)) {
            throw new RuntimeException('growth_hook_entity_missing');
        }
        $data = get_object_vars($entity);
        if (!array_key_exists('id', $data) || !array_key_exists('id_shop', $data)) {
            throw new RuntimeException('growth_hook_explicit_scope_required');
        }
        $entityId = self::positiveId($data['id']);
        $shopId = self::positiveId($data['id_shop']);
        if ($entityId === null || $shopId === null) {
            throw new RuntimeException('growth_hook_explicit_scope_required');
        }

        // No raw object, email, dates, consent flags, order totals, recovery
        // URLs or any other personal data crosses this boundary.
        // The returned result contains only restricted local-only metadata.
        return BlockedOutboxCoordinator::capture(
            $router, $shopId, $entityType, $entityId, $eventType,
            $legacyDedupeKey, $insertOrLoad
        );
    }

    private static function positiveId($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 && (string) $id === $value ? $id : null;
    }
}
