<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * PST v0.4 typed-source-key payload contract. Pure in-memory encoding only.
 *
 * This does NOT grant G4 schema upload, Data Cloud ingestion, identity
 * matching, opt-in or marketing permissions. No IO, DB, queue, token or sender.
 * Unlike the frozen v0.2 builder, this requires typed per-entity keys.
 */
final class GrowthTypedPstPayloadBuilder
{
    const MAX_RECORDS = 50;
    const MAX_BYTES = 204800;

    public static function encode($objectName, array $rows, $provider, $mode)
    {
        if ($provider !== 'marketing_cloud_growth' || $mode !== 'test') {
            throw new RuntimeException('g4_pst_test_growth_only');
        }
        $fields = IngestionPayloadBuilder::fields();
        if (!is_string($objectName) || !isset($fields[$objectName])) {
            throw new RuntimeException('g4_pst_unsupported_object');
        }
        if (count($rows) < 1 || count($rows) > self::MAX_RECORDS) {
            throw new RuntimeException('g4_pst_batch_count_invalid');
        }
        $names = array_keys($fields[$objectName]);
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $names) {
                throw new RuntimeException('g4_pst_field_inventory_invalid');
            }
            foreach ($fields[$objectName] as $field => $type) {
                $value = $row[$field];
                if (($type === 'string' && !is_string($value))
                    || ($type === 'boolean' && !is_bool($value))
                    || ($type === 'number' && !(is_int($value) || is_float($value))
                        || is_float($value) && !is_finite($value))) {
                    throw new RuntimeException('g4_pst_invalid_value_type');
                }
            }
            $namespace = $row['source_namespace'];
            $shop = $row['shop_id'];
            $entityId = $objectName === 'PSCustomer'
                ? $row['customer_id'] : $row['order_id'];
            $entityType = $objectName === 'PSCustomer' ? 'customer' : 'order';
            if (!preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
                || !is_int($shop) || $shop < 1
                || !is_int($entityId) || $entityId < 1) {
                throw new RuntimeException('g4_pst_invalid_scope');
            }
            $prefix = $namespace . ':' . $shop . ':';
            if ($row['source_key'] !== $prefix . $entityType . ':' . $entityId) {
                throw new RuntimeException('g4_pst_invalid_typed_pk');
            }
            if (isset($seen[$row['source_key']])) {
                throw new RuntimeException('g4_pst_duplicate_typed_pk');
            }
            $seen[$row['source_key']] = true;
            self::localDate($row['source_updated_local']);
            if ($objectName === 'PSCustomer') {
                if ($row['email'] !== ''
                    && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('g4_pst_invalid_email');
                }
            } else {
                if (!preg_match('/^[A-Z]{3}$/D', $row['currency'])
                    || !is_finite((float) $row['total_paid'])
                    || (float) $row['total_paid'] < 0
                    || !is_int($row['state_id']) || $row['state_id'] < 0) {
                    throw new RuntimeException('g4_pst_invalid_order_values');
                }
                foreach (['customer_source_key' => 'customer',
                    'cart_source_key' => 'cart'] as $field => $expectedType) {
                    $value = $row[$field];
                    if ($value !== '' && !preg_match(
                        '/^' . preg_quote($prefix . $expectedType . ':', '/')
                        . '[1-9][0-9]*$/D', $value
                    )) {
                        throw new RuntimeException('g4_pst_cross_scope_fk');
                    }
                }
                // Until mapped paid-order semantics are certified, a local
                // order state cannot be promoted to a confirmed purchase.
                if ($row['paid_flag'] !== false) {
                    throw new RuntimeException('g4_pst_paid_state_unverified');
                }
            }
        }
        $out = json_encode(['data' => array_values($rows)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($out) || strlen($out) > self::MAX_BYTES) {
            throw new RuntimeException('g4_pst_payload_encoding_or_size');
        }
        return $out;
    }

    private static function localDate($value)
    {
        if (!is_string($value)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
            throw new RuntimeException('g4_pst_invalid_local_date');
        }
        // Validate only the calendar. We NEVER assume local source
        // timestamps were written in UTC or use them as Record Modified.
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', $value, new DateTimeZone('UTC')
        );
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('g4_pst_invalid_local_date');
        }
    }

    private function __construct()
    {
    }
}
