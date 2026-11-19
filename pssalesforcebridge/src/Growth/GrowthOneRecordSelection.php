<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft;
use RuntimeException;

/**
 * Explicitly select ONE existing customer and ONE linked order in the
 * current PrestaShop shop. Uses canonical G4 PST v0.4 serializer.
 * No SQL, no capture, no sender, no persistence and no consent inference.
 *
 * prepareInternal() returns PII IN MEMORY ONLY to the one-shot BO controller;
 * NEVER print, cache, serialize or log that return value.
 */
final class GrowthOneRecordSelection
{
    public static function prepareInternal(
        $namespace,$shop,$customerId,$orderId,
        $loadCustomer,$loadOrder,$loadCurrency
    ) {
        if (!is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$namespace)
            || !is_int($shop) || $shop<1
            || !is_int($customerId) || $customerId<1
            || !is_int($orderId) || $orderId<1
            || !is_callable($loadCustomer) || !is_callable($loadOrder)
            || !is_callable($loadCurrency)) {
            throw new RuntimeException('g7_one_record_invalid_scope');
        }
        $customer=call_user_func($loadCustomer,$customerId);
        $order=call_user_func($loadOrder,$orderId);
        if (!is_object($customer) || !is_object($order)
            || (int)(isset($customer->id)?$customer->id:0)!==$customerId
            || (int)(isset($customer->id_shop)?$customer->id_shop:0)!==$shop
            || (int)(isset($order->id)?$order->id:0)!==$orderId
            || (int)(isset($order->id_shop)?$order->id_shop:0)!==$shop
            || (int)(isset($order->id_customer)?$order->id_customer:0)!==$customerId) {
            throw new RuntimeException('g7_one_record_customer_order_mismatch');
        }
        $currencyId=isset($order->id_currency)?(int)$order->id_currency:0;
        if ($currencyId<1) {
            throw new RuntimeException('g7_one_record_currency_unverified');
        }
        $currency=call_user_func($loadCurrency,$currencyId);
        if (!is_object($currency)
            || (int)(isset($currency->id)?$currency->id:0)!==$currencyId) {
            throw new RuntimeException('g7_one_record_currency_unverified');
        }
        $draft=GrowthNativeCustomerOrderDraft::buildPair(
            $namespace,$shop,$customer,$order,$currency
        );
        $c=json_decode($draft['customer_json'],true);
        $o=json_decode($draft['order_json'],true);
        if (!is_array($c) || !isset($c['data'][0])
            || !is_array($c['data']) || count($c['data'])!==1
            || !is_array($o) || !isset($o['data'][0])
            || !is_array($o['data']) || count($o['data'])!==1
            || count($c['data'][0])!==11 || count($o['data'][0])!==11
            || $o['data'][0]['customer_source_key']!==$c['data'][0]['source_key']
            || $draft['marketing_sends_authorized']!==false
            || $draft['data_cloud_ingestion_authorized']!==false) {
            throw new RuntimeException('g7_one_record_pst_unverified');
        }

        $customerRecord=$c['data'][0];
        $orderRecord=$o['data'][0];
        unset($draft,$c,$o,$customer,$order,$currency);
        return [
            'customer_record_private'=>$customerRecord,
            'order_record_private'=>$orderRecord,
            'customer_order_relation_verified'=>true,
            'records'=>2,
            'marketing_sends_authorized'=>false,
            'automatic_dispatch_authorized'=>false
        ];
    }

    /** Sanitized projection: no source key, IDs, email, amount or PST JSON. */
    public static function summarize(array $prepared)
    {
        if (!isset($prepared['customer_record_private'],
            $prepared['order_record_private'])
            || !is_array($prepared['customer_record_private'])
            || !is_array($prepared['order_record_private'])
            || !isset($prepared['customer_order_relation_verified'])
            || $prepared['customer_order_relation_verified']!==true) {
            throw new RuntimeException('g7_one_record_preview_invalid');
        }
        return [
            'status'=>'one_customer_one_order_shape_verified_local_only',
            'customer_pst_shape_verified'=>true,
            'order_pst_shape_verified'=>true,
            'same_shop_customer_order_relation_verified'=>true,
            'records_sent'=>0,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
    }

    private function __construct() {}
}
