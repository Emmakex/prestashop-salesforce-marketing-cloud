<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;
use RuntimeException;

/** Strict, offline Data Cloud payload packaging; no HTTP, data writes or emails. */
final class IngestionPayloadBuilder
{
    const MAX_BYTES = 204800;
    const MAX_RECORDS = 50;

    public static function fields()
    {
        return [
            'PSCustomer' => [
                'source_key' => 'string',
                'source_namespace' => 'string',
                'shop_id' => 'number',
                'customer_id' => 'number',
                'email' => 'string',
                'first_name' => 'string',
                'last_name' => 'string',
                'newsletter_flag' => 'boolean',
                'partner_optin_flag' => 'boolean',
                'active_flag' => 'boolean',
                'source_updated_local' => 'string',
            ],
            'PSOrder' => [
                'source_key' => 'string',
                'source_namespace' => 'string',
                'shop_id' => 'number',
                'order_id' => 'number',
                'customer_source_key' => 'string',
                'cart_source_key' => 'string',
                'currency' => 'string',
                'total_paid' => 'number',
                'paid_flag' => 'boolean',
                'state_id' => 'number',
                'source_updated_local' => 'string',
            ],
        ];
    }

    public function encode($objectName, array $records)
    {
        $objects = self::fields();
        if (!is_string($objectName) || !isset($objects[$objectName])) {
            throw new RuntimeException('data_cloud_payload_object_not_allowed');
        }
        if (count($records) < 1 || count($records) > self::MAX_RECORDS) {
            throw new RuntimeException('data_cloud_payload_invalid_count');
        }
        $expected = $objects[$objectName];
        $seen = [];
        foreach ($records as $record) {
            if (!is_array($record) || array_keys($record) !== array_keys($expected)) {
                throw new RuntimeException('data_cloud_payload_schema_drift');
            }
            foreach ($expected as $field => $kind) {
                $value = $record[$field];
                if (($kind === 'string' && !is_string($value))
                    || ($kind === 'boolean' && !is_bool($value))
                    || ($kind === 'number' && !(is_int($value) || is_float($value)))
                    || ($kind === 'number' && !is_finite((float) $value))) {
                    throw new RuntimeException('data_cloud_payload_invalid_type');
                }
            }
            $ns = $record['source_namespace'];
            $shop = $record['shop_id'];
            $entity = $record[$objectName === 'PSCustomer' ? 'customer_id' : 'order_id'];
            if (!preg_match('/^[A-Za-z0-9_-]{2,32}$/D', $ns)
                || !is_int($shop) || $shop < 1 || !is_int($entity) || $entity < 1
                || $record['source_key'] !== $ns . ':ps_' . $shop . '_' . $entity) {
                throw new RuntimeException('data_cloud_payload_invalid_identity');
            }
            if (isset($seen[$record['source_key']])) {
                throw new RuntimeException('data_cloud_payload_duplicate_identity');
            }
            $seen[$record['source_key']] = true;
            if ($objectName === 'PSCustomer') {
                if ($record['email'] !== '' && filter_var($record['email'], FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('data_cloud_payload_invalid_email');
                }
            } else {
                if (!preg_match('/^[A-Z]{3}$/D', $record['currency'])) {
                    throw new RuntimeException('data_cloud_payload_invalid_currency');
                }
                foreach (['customer_source_key', 'cart_source_key'] as $relation) {
                    if ($record[$relation] !== '' && !preg_match(
                        '/^' . preg_quote($ns . ':ps_' . $shop . '_', '/') . '[1-9][0-9]*$/D',
                        $record[$relation]
                    )) {
                        throw new RuntimeException('data_cloud_payload_cross_shop_relation');
                    }
                }
            }
        }
        // Data Cloud Ingestion API takes records in an object-specific data array.
        $json = json_encode(['data' => array_values($records)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('data_cloud_payload_json_error');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('data_cloud_payload_size_exceeded');
        }
        return $json;
    }
}
