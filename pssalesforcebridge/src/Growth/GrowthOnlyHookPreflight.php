<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

/**
 * Review-only Growth hook boundary. It does NOT persist an event or create
 * a DMO record. This lets PrestaShop hooks remain harmless during G3.
 */
final class GrowthOnlyHookPreflight
{
    public static function inspect($eventType, $params)
    {
        $allowed = [
            'CUSTOMER_CREATED' => ['newCustomer', 'customer', 'object'],
            'CUSTOMER_UPDATED' => ['customer', 'newCustomer', 'object'],
            'ORDER_CREATED' => ['order'],
        ];
        if (!is_string($eventType) || !isset($allowed[$eventType])
            || !is_array($params)) {
            return self::blocked('unsupported_event');
        }
        $entity = null;
        foreach ($allowed[$eventType] as $key) {
            if (isset($params[$key]) && is_object($params[$key])) {
                $entity = $params[$key];
                break;
            }
        }
        if (!is_object($entity)) {
            return self::blocked('missing_source_object');
        }
        $vars = get_object_vars($entity);
        if (!isset($vars['id'], $vars['id_shop'])) {
            return self::blocked('missing_explicit_scope');
        }
        $id = self::positiveInteger($vars['id']);
        $shop = self::positiveInteger($vars['id_shop']);
        if ($id === null || $shop === null) {
            return self::blocked('invalid_explicit_scope');
        }
        // Does not include entity IDs, PII, dates, revision or payload in
        // output. They must never enter a remote transport without G3+G4.
        return [
            'status' => 'review_only_not_captured',
            'event_type' => $eventType,
            'shop_scope_verified' => true,
            'entity_scope_verified' => true,
            'schema_approved' => false,
            'record_ingestion_allowed' => false,
            'marketing_sends_allowed' => false,
        ];
    }

    private static function positiveInteger($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $parsed = (int) $value;
        return $parsed > 0 && (string) $parsed === $value ? $parsed : null;
    }

    private static function blocked($reason)
    {
        return [
            'status' => 'blocked',
            'reason' => $reason,
            'schema_approved' => false,
            'record_ingestion_allowed' => false,
            'marketing_sends_allowed' => false,
        ];
    }

    private function __construct()
    {
    }
}
