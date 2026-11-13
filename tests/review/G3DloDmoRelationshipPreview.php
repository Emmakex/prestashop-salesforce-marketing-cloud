<?php
/**
 * G3-05/G3-06: PURE, offline, aggregate-only DLO relationship preview.
 * NO EMAIL, no customer payload, no Data 360 calls, no DMO writes.
 *
 * Checks only SOURCE keys produced by the existing PrestaShop mapper.
 * A locally consistent join is NOT an approved Salesforce DMO relation.
 */
final class G3DloDmoRelationshipPreview
{
    const MAX_ROWS = 500;

    private static $customerFields = [
        'source_key', 'source_namespace', 'shop_id', 'customer_id',
    ];
    private static $orderFields = [
        'source_key', 'source_namespace', 'shop_id', 'order_id',
        'customer_source_key', 'cart_source_key',
    ];

    public static function inspect(array $customers, array $orders)
    {
        if (count($customers) > self::MAX_ROWS || count($orders) > self::MAX_ROWS) {
            throw new RuntimeException('g3_relation_sample_limit');
        }
        $parents = [];
        foreach ($customers as $row) {
            self::identity($row, self::$customerFields, 'customer_id');
            if (isset($parents[$row['source_key']])) {
                throw new RuntimeException('g3_relation_duplicate_customer_key');
            }
            $parents[$row['source_key']] = true;
        }

        $orderKeys = [];
        $counts = [
            'customers' => count($customers),
            'orders' => count($orders),
            'locally_consistent_orders' => 0,
            'orphan_orders_quarantined' => 0,
            'missing_customer_reference_quarantined' => 0,
            'cross_scope_orders_quarantined' => 0,
            'cart_references_unmapped' => 0,
        ];
        foreach ($orders as $row) {
            self::identity($row, self::$orderFields, 'order_id');
            if (isset($orderKeys[$row['source_key']])) {
                throw new RuntimeException('g3_relation_duplicate_order_key');
            }
            $orderKeys[$row['source_key']] = true;

            // PSCartEvent does not exist in approved G3 schema. A cart key is
            // always diagnostic only, even when local syntax is valid.
            $cartRef = $row['cart_source_key'];
            $cartBad = $cartRef !== '' &&
                !self::scopedReference($cartRef, $row['source_namespace'],
                    $row['shop_id']);
            if ($cartRef !== '') {
                ++$counts['cart_references_unmapped'];
            }

            $ref = $row['customer_source_key'];
            if ($cartBad) {
                ++$counts['cross_scope_orders_quarantined'];
            } elseif ($ref === '') {
                ++$counts['missing_customer_reference_quarantined'];
            } elseif (!self::scopedReference($ref, $row['source_namespace'],
                $row['shop_id'])) {
                // Also excludes references to another installation/shop.
                // We never fall back to email, raw customer_id or cart key.
                ++$counts['cross_scope_orders_quarantined'];
            } elseif (!isset($parents[$ref])) {
                ++$counts['orphan_orders_quarantined'];
            } else {
                ++$counts['locally_consistent_orders'];
            }
        }
        return [
            'status' => 'review_only',
            'counts' => $counts,
            'source_key_consistency_tested' => true,
            'tenant_dmo_catalog_verified' => false,
            'dlo_dmo_mapping_save_authorized' => false,
            'identity_resolution_authorized' => false,
            'order_customer_relationship_authorized' => false,
            'cart_relationship_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function identity($row, array $fields, $entityField)
    {
        if (!is_array($row) || array_keys($row) !== $fields) {
            // Strict input deliberately excludes email, names, amounts,
            // preference flags, timestamps and merchant record IDs in output.
            throw new RuntimeException('g3_relation_source_fields_only');
        }
        $ns = $row['source_namespace'];
        $shop = $row['shop_id'];
        $entity = $row[$entityField];
        if (!is_string($ns)
            || !preg_match('/^[A-Za-z0-9_-]{2,32}$/D', $ns)
            || !is_int($shop) || $shop < 1
            || !is_int($entity) || $entity < 1
            || !is_string($row['source_key'])
            || $row['source_key'] !== $ns . ':ps_' . $shop . '_' . $entity
            || !is_string($row['source_key'])) {
            throw new RuntimeException('g3_relation_source_identity_invalid');
        }
        if ($entityField === 'order_id'
            && (!is_string($row['customer_source_key'])
                || !is_string($row['cart_source_key']))) {
            throw new RuntimeException('g3_relation_reference_not_string');
        }
    }

    private static function scopedReference($ref, $ns, $shop)
    {
        if (!is_string($ref)) {
            return false;
        }
        $prefix = $ns . ':ps_' . $shop . '_';
        if (strpos($ref, $prefix) !== 0) {
            return false;
        }
        $suffix = substr($ref, strlen($prefix));
        return (bool) preg_match('/^[1-9][0-9]*$/D', $suffix);
    }
}
