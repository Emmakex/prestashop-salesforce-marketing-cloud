<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * G7/G8 native PrestaShop customer+order to exact deployed PST v0.4 draft.
 *
 * Pure memory: requires caller-provided source objects; no database, hook,
 * scheduler, credential, outbound HTTP, or storage. Not wired to the module.
 * Only a controlled future dispatcher may handle the resulting PII-bearing
 * JSON, after G5/G6 and processing permissions are certified. NEVER log it.
 *
 * The source local dates do not claim UTC provenance. The local newsletter
 * flags never certify Marketing Cloud Growth consent. Paid state is ALWAYS
 * false until a source-specific payment state contract is approved.
 */
final class GrowthNativeCustomerOrderDraft
{
    public static function buildPair(
        $namespace, $shopId, $customer, $order, $currency,
        $provider = 'marketing_cloud_growth', $mode = 'test'
    ) {
        // Validate policy and ALL scopes before reading potentially
        // identifying properties or constructing a payload.
        if ($provider !== 'marketing_cloud_growth' || $mode !== 'test') {
            throw new RuntimeException('g7_native_draft_test_growth_only');
        }
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)
            || !is_int($shopId) || $shopId < 1) {
            throw new RuntimeException('g7_native_draft_namespace_or_shop_invalid');
        }
        if (!is_object($customer) || !is_object($order)
            || !is_object($currency)) {
            throw new RuntimeException('g7_native_draft_objects_required');
        }

        $c = get_object_vars($customer);
        $o = get_object_vars($order);
        $money = get_object_vars($currency);
        foreach ([
            'id','id_shop','email','firstname','lastname','newsletter',
            'optin','active','deleted','is_guest','date_upd'
        ] as $key) {
            if (!array_key_exists($key,$c)) {
                throw new RuntimeException('g7_native_draft_customer_fields_missing');
            }
        }
        foreach ([
            'id','id_shop','id_customer','id_cart','id_currency',
            'total_paid','current_state','date_upd'
        ] as $key) {
            if (!array_key_exists($key,$o)) {
                throw new RuntimeException('g7_native_draft_order_fields_missing');
            }
        }
        if (!array_key_exists('id',$money)
            || !array_key_exists('iso_code',$money)) {
            throw new RuntimeException('g7_native_draft_currency_fields_missing');
        }

        $customerId = self::positiveId($c['id']);
        $orderId = self::positiveId($o['id']);
        $shopCustomer = self::positiveId($c['id_shop']);
        $shopOrder = self::positiveId($o['id_shop']);
        $orderCustomer = self::positiveId($o['id_customer']);
        $orderCurrency = self::positiveId($o['id_currency']);
        $currencyId = self::positiveId($money['id']);
        $cartId = self::zeroOrPositiveId($o['id_cart']);
        $stateId = self::zeroOrPositiveId($o['current_state']);
        if ($customerId === null || $orderId === null
            || $shopCustomer !== $shopId || $shopOrder !== $shopId
            || $customerId !== $orderCustomer
            || $orderCurrency === null || $currencyId !== $orderCurrency
            || $cartId === null || $stateId === null) {
            throw new RuntimeException('g7_native_draft_cross_scope_or_orphan');
        }

        foreach (['newsletter','optin','active','deleted','is_guest'] as $flag) {
            if (!in_array($c[$flag],[true,false,0,1,'0','1'],true)) {
                throw new RuntimeException('g7_native_draft_customer_flag_invalid');
            }
        }
        // A deleted or guest customer must not get a candidate export.
        // A historical checkout involving one cannot be used to infer
        // contactability or an affirmative purpose-specific consent.
        if (self::boolFlag($c['deleted']) || self::boolFlag($c['is_guest'])) {
            throw new RuntimeException('g7_native_draft_customer_excluded');
        }
        if (!is_string($c['email']) || !is_string($c['firstname'])
            || !is_string($c['lastname'])
            || !is_string($c['date_upd']) || !is_string($o['date_upd'])
            || !is_string($money['iso_code'])) {
            throw new RuntimeException('g7_native_draft_source_field_invalid');
        }
        if (!(is_int($o['total_paid']) || is_float($o['total_paid'])
            || is_string($o['total_paid']))
            || !is_numeric($o['total_paid'])
            || !is_finite((float)$o['total_paid'])
            || (float)$o['total_paid'] < 0) {
            throw new RuntimeException('g7_native_draft_total_unverified');
        }

        $base = $namespace . ':' . $shopId . ':';
        $customerKey = $base . 'customer:' . $customerId;
        $orderKey = $base . 'order:' . $orderId;
        $customerRecord = [
            'source_key'=>$customerKey,
            'source_namespace'=>$namespace,
            'shop_id'=>$shopId,
            'customer_id'=>$customerId,
            'email'=>trim($c['email']),
            'first_name'=>$c['firstname'],
            'last_name'=>$c['lastname'],
            'newsletter_flag'=>self::boolFlag($c['newsletter']),
            'partner_optin_flag'=>self::boolFlag($c['optin']),
            'active_flag'=>self::boolFlag($c['active']),
            'source_updated_local'=>$c['date_upd'],
        ];
        $orderRecord = [
            'source_key'=>$orderKey,
            'source_namespace'=>$namespace,
            'shop_id'=>$shopId,
            'order_id'=>$orderId,
            'customer_source_key'=>$customerKey,
            'cart_source_key'=>$cartId > 0 ? $base.'cart:'.$cartId : '',
            'currency'=>$money['iso_code'],
            'total_paid'=>(float)$o['total_paid'],
            'paid_flag'=>false,
            'state_id'=>$stateId,
            'source_updated_local'=>$o['date_upd'],
        ];
        // Reuse canonical G4 v0.4 field order and source identity policy.
        // Validation happens before either payload is returned.
        $customerJson = GrowthTypedPstPayloadBuilder::encode(
            'PSCustomer',[$customerRecord],$provider,$mode
        );
        $orderJson = GrowthTypedPstPayloadBuilder::encode(
            'PSOrder',[$orderRecord],$provider,$mode
        );

        return [
            'status'=>'native_local_draft_only',
            'schema'=>'PST_v0.4_customer_order',
            // These two JSON values contain synthetic/test or real PII
            // from the caller. Keep IN MEMORY. Never write to artifacts.
            'customer_json'=>$customerJson,
            'order_json'=>$orderJson,
            'source_utc_provenance_certified'=>false,
            'marketing_consent_certified'=>false,
            'payment_state_certified'=>false,
            'namespace_globally_certified'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'outbound_worker_authorized'=>false,
        ];
    }

    private static function positiveId($value)
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D',$value)) {
            $id=(int)$value;
            return $id>0 && (string)$id===$value ? $id : null;
        }
        return null;
    }

    private static function zeroOrPositiveId($value)
    {
        if ($value === 0 || $value === '0') {
            return 0;
        }
        return self::positiveId($value);
    }

    private static function boolFlag($value)
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function __construct() {}
}
