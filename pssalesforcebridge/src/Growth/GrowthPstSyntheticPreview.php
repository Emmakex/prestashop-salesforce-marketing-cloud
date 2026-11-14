<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use DateTimeImmutable;
use DateTimeZone;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionPayloadBuilder;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use RuntimeException;

/**
 * OFFLINE CI-ONLY review of the existing PST v0.2 PSCustomer/PSOrder mapping.
 *
 * NOT loaded by the module entrypoint or wired to a hook, worker or API.
 * Never accepts real PrestaShop objects: the inputs must be minimal, exact
 * allowlisted synthetic fixtures with reserved names/email and an opt-in
 * disposable CLI test gate. No HTTP, SQL, config, logs or persistence.
 *
 * This is NOT a final source identity contract. The router uses typed
 * namespace:shop:entity_type:id keys while frozen PST v0.2 uses
 * namespace:ps_shop_id. The difference MUST be resolved at G3.
 */
final class GrowthPstSyntheticPreview
{
    const FIXTURE_MARKER = 'G3_PST_SYNTHETIC_ONLY';

    public static function build(
        ExplicitProviderRouter $router, $customerHook, $customerParams,
        $orderHook, $orderParams
    ) {
        if (PHP_SAPI !== 'cli'
            || getenv('G3_PST_SYNTHETIC_PREVIEW') !== 'yes_disposable_ci_only') {
            throw new RuntimeException('growth_pst_preview_ci_only');
        }
        if (!in_array($customerHook, [
            'actionCustomerAccountAdd', 'actionCustomerAccountUpdate'
        ], true) || $orderHook !== 'actionValidateOrder') {
            throw new RuntimeException('growth_pst_preview_hooks_not_allowed');
        }

        $customer = self::entity(
            $customerParams, $customerHook === 'actionCustomerAccountAdd'
                ? 'newCustomer' : 'customer',
            ['id', 'id_shop', 'email', 'firstname', 'lastname',
                'newsletter', 'optin', 'active', 'date_upd',
                '__growth_synthetic_fixture']
        );
        $order = self::entity(
            $orderParams, 'order',
            ['id', 'id_shop', 'id_customer', 'id_cart', 'total_paid',
                'current_state', 'date_upd', 'reference',
                '__growth_synthetic_fixture']
        );

        if ($customer['email'] !== 'synthetic@fixture.example.invalid'
            || $customer['firstname'] !== 'Synthetic'
            || $customer['lastname'] !== 'Fixture'
            || $order['reference'] !== 'SYNTHETIC_ORDER') {
            throw new RuntimeException('growth_pst_preview_not_synthetic');
        }

        $shopId = self::id($customer, 'id_shop');
        $customerId = self::id($customer, 'id');
        $orderShopId = self::id($order, 'id_shop');
        $orderId = self::id($order, 'id');
        $orderCustomerId = self::id($order, 'id_customer');
        $cartId = self::id($order, 'id_cart');
        $stateId = self::id($order, 'current_state');
        if ($shopId !== $orderShopId || $customerId !== $orderCustomerId) {
            throw new RuntimeException('growth_pst_preview_cross_shop_or_orphan');
        }
        foreach (['newsletter', 'optin', 'active'] as $flag) {
            if (!in_array($customer[$flag], [0, 1, false, true], true)) {
                throw new RuntimeException('growth_pst_preview_invalid_flag');
            }
        }
        if (!is_array($orderParams) || !isset($orderParams['currency'])
            || !is_object($orderParams['currency'])
            || array_keys(get_object_vars($orderParams['currency'])) !== ['iso_code']) {
            throw new RuntimeException('growth_pst_preview_currency_required');
        }
        $currency = $orderParams['currency']->iso_code;
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new RuntimeException('growth_pst_preview_invalid_currency');
        }
        $amount = $order['total_paid'];
        if ((!is_int($amount) && !is_float($amount) && !is_string($amount))
            || !preg_match('/^[0-9]{1,7}(\.[0-9]{1,2})?$/D', (string) $amount)) {
            throw new RuntimeException('growth_pst_preview_invalid_amount');
        }
        self::localDate($customer['date_upd']);
        self::localDate($order['date_upd']);

        $customerRoute = self::blockedRoute(
            $router->plan($shopId, 'customer', $customerId)
        );
        $orderRoute = self::blockedRoute(
            $router->plan($shopId, 'order', $orderId)
        );
        $namespace = $customerRoute['source_namespace'];
        if ($namespace !== $orderRoute['source_namespace']) {
            throw new RuntimeException('growth_pst_preview_namespace_mismatch');
        }

        $mapper = new IngestionRecordMapper($namespace);
        $customerRecord = $mapper->customer([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'contact_key' => 'ps_' . $shopId . '_' . $customerId,
            'email' => $customer['email'],
            'firstname' => $customer['firstname'],
            'lastname' => $customer['lastname'],
            'newsletter' => $customer['newsletter'],
            'optin' => $customer['optin'],
            'active' => $customer['active'],
            'date_upd' => $customer['date_upd'],
        ]);
        $orderRecord = $mapper->order([
            'shop_id' => $shopId, 'order_id' => $orderId,
            'order_key' => 'ps_' . $shopId . '_' . $orderId,
            'customer_id' => $orderCustomerId,
            'contact_key' => 'ps_' . $shopId . '_' . $customerId,
            'cart_id' => $cartId,
            'currency' => $currency, 'total_paid' => $amount,
            // No approved status→paid semantics: conservative preview only.
            'paid' => false, 'state_id' => $stateId,
            'date_upd' => $order['date_upd'],
        ]);
        if ($orderRecord['customer_source_key'] !== $customerRecord['source_key']) {
            throw new RuntimeException('growth_pst_preview_relation_mismatch');
        }
        $builder = new IngestionPayloadBuilder();
        $customerJson = $builder->encode('PSCustomer', [$customerRecord]);
        $orderJson = $builder->encode('PSOrder', [$orderRecord]);

        // The data fields are still an UNAPPROVED v0.2 preview, not a
        // sendable contract. Never claim UTC provenance or marketing consent.
        return [
            'status' => 'blocked_synthetic_review',
            'provider' => ExplicitProviderRouter::GROWTH,
            'schema_candidate' => 'PST_v0.2_review_only',
            'customer_payload' => $customerJson,
            'order_payload' => $orderJson,
            'customer_router_key' => $customerRoute['source_entity_key'],
            'order_router_key' => $orderRoute['source_entity_key'],
            'customer_pst_v02_key' => $customerRecord['source_key'],
            'order_pst_v02_key' => $orderRecord['source_key'],
            'cross_object_key_collision' =>
                $customerRecord['source_key'] === $orderRecord['source_key'],
            'identity_contract_approved' => false,
            'source_updated_utc_verified' => false,
            'order_paid_semantics_approved' => false,
            'marketing_consent_approved' => false,
            'schema_upload_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function entity($params, $name, array $allowed)
    {
        if (!is_array($params) || !isset($params[$name])
            || !is_object($params[$name])) {
            throw new RuntimeException('growth_pst_preview_source_missing');
        }
        $values = get_object_vars($params[$name]);
        if (count($values) !== count($allowed)
            || count(array_diff(array_keys($values), $allowed)) > 0
            || !isset($values['__growth_synthetic_fixture'])
            || $values['__growth_synthetic_fixture'] !== self::FIXTURE_MARKER) {
            throw new RuntimeException('growth_pst_preview_untrusted_source');
        }
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $values)) {
                throw new RuntimeException('growth_pst_preview_untrusted_source');
            }
        }
        return $values;
    }

    private static function id(array $values, $key)
    {
        $value = $values[$key];
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $number = (int) $value;
            if ($number > 0 && (string) $number === $value) {
                return $number;
            }
        }
        throw new RuntimeException('growth_pst_preview_invalid_id');
    }

    private static function localDate($value)
    {
        if (!is_string($value)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
            throw new RuntimeException('growth_pst_preview_invalid_local_time');
        }
        // UTC is used ONLY to validate the calendar, not to infer the source
        // timezone. The emitted source_updated_local is unchanged.
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s', $value, new DateTimeZone('UTC')
        );
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('growth_pst_preview_invalid_local_time');
        }
    }

    private static function blockedRoute(array $route)
    {
        if ($route['provider'] !== ExplicitProviderRouter::GROWTH
            || $route['status'] !== 'blocked'
            || $route['mode'] !== 'test'
            || $route['route_target'] !== 'growth_data_cloud_schema_pending'
            || $route['data_360_ingestion_authorized'] !== false
            || $route['marketing_sends_authorized'] !== false
            || $route['outbound_worker_authorized'] !== false) {
            throw new RuntimeException('growth_pst_preview_route_not_blocked');
        }
        return $route;
    }

    private function __construct()
    {
    }
}
