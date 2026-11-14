<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use RuntimeException;

/**
 * G3-only, OFFLINE, NOT APPROVED: deterministic typed identity proposal.
 *
 * Accepts ONLY the gated, reserved synthetic PSCustomer/PSOrder v0.2
 * preview and produces a review-only v0.4 identity variant in memory.
 * No runtime hook calls this class. No transport, persistence or SQL.
 *
 * Frozen v0.2 mapper and its SHA remain unchanged. Typed keys are NOT
 * evidence of a Data Cloud DLO/DMO FK or permission to publish a schema.
 */
final class GrowthTypedIdentityCandidate
{
    const VERSION = 'PST_v0.4_typed_identity_proposal_only';

    public static function project(array $preview)
    {
        if (PHP_SAPI !== 'cli'
            || getenv('G3_PST_SYNTHETIC_PREVIEW') !== 'yes_disposable_ci_only') {
            throw new RuntimeException('growth_typed_candidate_ci_only');
        }
        if (!isset($preview['status'], $preview['provider'], $preview['schema_candidate'])
            || $preview['status'] !== 'blocked_synthetic_review'
            || $preview['provider'] !== ExplicitProviderRouter::GROWTH
            || $preview['schema_candidate'] !== 'PST_v0.2_review_only') {
            throw new RuntimeException('growth_typed_candidate_untrusted_preview');
        }
        foreach ([
            'identity_contract_approved', 'source_updated_utc_verified',
            'order_paid_semantics_approved', 'marketing_consent_approved',
            'schema_upload_authorized', 'data_cloud_ingestion_authorized',
            'marketing_sends_authorized'
        ] as $flag) {
            if (!array_key_exists($flag, $preview) || $preview[$flag] !== false) {
                throw new RuntimeException('growth_typed_candidate_authorization_not_blocked');
            }
        }

        $customer = self::baselineRow($preview, 'customer_payload', 'PSCustomer');
        $order = self::baselineRow($preview, 'order_payload', 'PSOrder');

        // Reserved fixture identities; never accept real data into the review.
        if ($customer['email'] !== 'synthetic@fixture.example.invalid'
            || $customer['first_name'] !== 'Synthetic'
            || $customer['last_name'] !== 'Fixture'
            || $order['paid_flag'] !== false) {
            throw new RuntimeException('growth_typed_candidate_non_synthetic');
        }

        $namespace = $customer['source_namespace'];
        $shop = $customer['shop_id'];
        $customerId = $customer['customer_id'];
        $orderId = $order['order_id'];
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
            || !is_int($shop) || $shop <= 0
            || !is_int($customerId) || $customerId <= 0
            || !is_int($orderId) || $orderId <= 0
            || $order['source_namespace'] !== $namespace
            || $order['shop_id'] !== $shop
            || $order['customer_source_key'] !== $customer['source_key']) {
            throw new RuntimeException('growth_typed_candidate_orphan_or_scope_mismatch');
        }

        $typedCustomer = $namespace . ':' . $shop . ':customer:' . $customerId;
        $typedOrder = $namespace . ':' . $shop . ':order:' . $orderId;
        if (!isset($preview['customer_router_key'], $preview['order_router_key'])
            || $preview['customer_router_key'] !== $typedCustomer
            || $preview['order_router_key'] !== $typedOrder) {
            throw new RuntimeException('growth_typed_candidate_router_identity_mismatch');
        }
        if (!isset($preview['cross_object_key_collision'])
            || !is_bool($preview['cross_object_key_collision'])
            || $preview['cross_object_key_collision']
                !== ($customer['source_key'] === $order['source_key'])) {
            throw new RuntimeException('growth_typed_candidate_collision_evidence_mismatch');
        }

        $cart = $order['cart_source_key'];
        $typedCart = '';
        if ($cart !== '') {
            $prefix = $namespace . ':ps_' . $shop . '_';
            if (strpos($cart, $prefix) !== 0
                || !preg_match('/^[1-9][0-9]*$/D', substr($cart, strlen($prefix)))) {
                throw new RuntimeException('growth_typed_candidate_cart_scope_mismatch');
            }
            $typedCart = $namespace . ':' . $shop . ':cart:'
                . substr($cart, strlen($prefix));
        }
        $customer['source_key'] = $typedCustomer;
        $order['source_key'] = $typedOrder;
        $order['customer_source_key'] = $typedCustomer;
        // Reference only. No PSCart DLO exists or is authorized by this proposal.
        $order['cart_source_key'] = $typedCart;

        return [
            'status' => 'blocked_typed_identity_review',
            'schema_candidate' => self::VERSION,
            'provider' => ExplicitProviderRouter::GROWTH,
            'customer_payload' => self::encodeCandidate('PSCustomer', $customer),
            'order_payload' => self::encodeCandidate('PSOrder', $order),
            'customer_source_key' => $typedCustomer,
            'order_source_key' => $typedOrder,
            'order_customer_fk' => $order['customer_source_key'],
            'cart_reference_candidate' => $typedCart,
            'cross_object_key_collision' => $typedCustomer === $typedOrder,
            'same_shop_customer_fk_verified_locally' => true,
            'dmo_pk_fk_mapping_approved' => false,
            'identity_contract_approved' => false,
            'global_namespace_uniqueness_approved' => false,
            'cart_stream_approved' => false,
            'source_updated_utc_verified' => false,
            'marketing_consent_approved' => false,
            'schema_upload_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function baselineRow(array $preview, $key, $objectName)
    {
        if (!isset($preview[$key]) || !is_string($preview[$key])) {
            throw new RuntimeException('growth_typed_candidate_payload_missing');
        }
        $body = json_decode($preview[$key], true);
        if (!is_array($body) || array_keys($body) !== ['data']
            || !is_array($body['data']) || count($body['data']) !== 1
            || !isset($body['data'][0]) || !is_array($body['data'][0])) {
            throw new RuntimeException('growth_typed_candidate_payload_invalid');
        }
        $record = $body['data'][0];
        // Reuse the frozen contract to reject malformed identities and fields
        // BEFORE changing the key format. The frozen builder is unmodified.
        (new IngestionPayloadBuilder())->encode($objectName, [$record]);
        return $record;
    }

    private static function encodeCandidate($objectName, array $row)
    {
        $fields = IngestionPayloadBuilder::fields();
        if (!isset($fields[$objectName])
            || array_keys($row) !== array_keys($fields[$objectName])) {
            throw new RuntimeException('growth_typed_candidate_field_drift');
        }
        foreach ($fields[$objectName] as $field => $type) {
            $value = $row[$field];
            if (($type === 'string' && !is_string($value))
                || ($type === 'boolean' && !is_bool($value))
                || ($type === 'number' && !(is_int($value) || is_float($value)))
                || ($type === 'number' && !is_finite((float) $value))) {
                throw new RuntimeException('growth_typed_candidate_field_type');
            }
        }
        $ns = $row['source_namespace'];
        $shop = $row['shop_id'];
        $type = $objectName === 'PSCustomer' ? 'customer' : 'order';
        $id = $row[$objectName === 'PSCustomer' ? 'customer_id' : 'order_id'];
        if ($row['source_key'] !== $ns . ':' . $shop . ':' . $type . ':' . $id) {
            throw new RuntimeException('growth_typed_candidate_invalid_pk');
        }
        if ($objectName === 'PSOrder') {
            if (!preg_match('/^' . preg_quote($ns . ':' . $shop . ':customer:', '/')
                . '[1-9][0-9]*$/D', $row['customer_source_key'])) {
                throw new RuntimeException('growth_typed_candidate_invalid_fk');
            }
            if ($row['cart_source_key'] !== ''
                && !preg_match('/^' . preg_quote($ns . ':' . $shop . ':cart:', '/')
                    . '[1-9][0-9]*$/D', $row['cart_source_key'])) {
                throw new RuntimeException('growth_typed_candidate_invalid_cart_ref');
            }
        }
        $json = json_encode(['data' => [$row]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > IngestionPayloadBuilder::MAX_BYTES) {
            throw new RuntimeException('growth_typed_candidate_json_failed');
        }
        return $json;
    }

    private function __construct()
    {
    }
}
