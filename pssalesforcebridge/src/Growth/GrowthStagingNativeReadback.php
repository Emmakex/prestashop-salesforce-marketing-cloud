<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft;
use RuntimeException;

/**
 * Read-only G7 diagnostic for the EXISTING staging G3 outbox and live
 * PrestaShop Customer/Order/Currency source objects.
 *
 * This does not dispatch or stage delivery, call Salesforce, persist PII,
 * create a second queue, claim a lease, or certify a remote destination.
 * It is only invoked by the explicit Back Office diagnostic in Test.
 */
final class GrowthStagingNativeReadback
{
    public static function inspectLatest(
        $db, $version, $merchant, $namespace, $prefix, $type,
        $loadCustomer, $loadOrder, $loadCurrency
    ) {
        if (!class_exists('Configuration')
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='staging_armed'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
            || !in_array($type,['customer','order'],true)
            || !is_object($db) || !is_callable([$db,'executeS'])
            || !is_callable([$db,'getValue'])
            || !is_callable($loadCustomer) || !is_callable($loadOrder)
            || !is_callable($loadCurrency)) {
            throw new RuntimeException('g7_readback_not_authorized');
        }
        $plan=GrowthOutboxSchemaPlan::preview(
            $version,$merchant,'marketing_cloud_growth',$namespace,'test',$prefix
        );
        $engine=GrowthSqlEngineGate::inspectPrestaShop($db);
        if ($engine['check_constraints_enforced']!==true) {
            throw new RuntimeException('g7_readback_sql_engine_unverified');
        }
        $binding=self::quote($plan['tables']['binding']);
        $outbox=self::quote($plan['tables']['outbox']);
        $ledger=self::quote($plan['tables']['ledger']);
        $bindingCount=$db->getValue(
            "SELECT COUNT(*) FROM $binding WHERE source_namespace='$namespace'"
            . " AND merchant='$merchant'"
            . " AND prestashop_version='$version'"
            . " AND provider='marketing_cloud_growth' AND mode='test'"
            . " AND status='blocked'",false
        );
        if ((string)$bindingCount!=='1') {
            throw new RuntimeException('g7_readback_binding_unverified');
        }
        $events=$db->executeS(
            "SELECT accepted_sequence,source_namespace,shop_id,entity_type,"
            . "entity_id,original_event_type,status FROM $outbox"
            . " WHERE source_namespace='$namespace' AND entity_type='$type'"
            . " AND status='blocked' ORDER BY accepted_sequence DESC LIMIT 1",
            true,false
        );
        if (!is_array($events) || count($events)>1) {
            throw new RuntimeException('g7_readback_event_lookup_unverified');
        }
        if (!$events) {
            return self::report('no_blocked_event',$type,0,false);
        }
        $event=$events[0];
        $seq=self::positive($event['accepted_sequence']);
        $id=self::positive($event['entity_id']);
        $shop=self::positive($event['shop_id']);
        $expected=$type==='order'?'ORDER_CREATED':null;
        if ($seq===null || $id===null || $shop===null
            || $event['source_namespace']!==$namespace
            || $event['entity_type']!==$type
            || $event['status']!=='blocked'
            || ($expected!==null && $event['original_event_type']!==$expected)
            || ($type==='customer' && !in_array(
                $event['original_event_type'],
                ['CUSTOMER_CREATED','CUSTOMER_UPDATED'],true
            ))) {
            throw new RuntimeException('g7_readback_event_invalid');
        }
        $sqlLedger="SELECT source_namespace,shop_id,entity_type,entity_id,"
            . "latest_accepted_sequence,status FROM $ledger"
            . " WHERE source_namespace='$namespace' AND shop_id=$shop"
            . " AND entity_type='$type' AND entity_id=$id LIMIT 2";
        $before=self::ledger($db,$sqlLedger,$namespace,$shop,$type,$id,$seq);

        // Source loaders are injected for sandbox CI; on a real staging shop
        // the Back Office passes constructors for native PrestaShop objects.
        // No object or identifying source key is returned to the caller.
        if ($type==='customer') {
            $customer=call_user_func($loadCustomer,$id);
            $draft=GrowthNativeCustomerOrderDraft::buildCustomer(
                $namespace,$shop,$customer
            );
            $count=1;
        } else {
            $order=call_user_func($loadOrder,$id);
            if (!is_object($order)
                || self::positive(isset($order->id)?$order->id:null)!==$id
                || self::positive(isset($order->id_shop)
                    ?$order->id_shop:null)!==$shop) {
                throw new RuntimeException('g7_readback_order_scope_invalid');
            }
            $customerId=self::positive(isset($order->id_customer)
                ?$order->id_customer:null);
            $currencyId=self::positive(isset($order->id_currency)
                ?$order->id_currency:null);
            if ($customerId===null || $currencyId===null) {
                throw new RuntimeException('g7_readback_order_relationship_invalid');
            }
            $customer=call_user_func($loadCustomer,$customerId);
            $currency=call_user_func($loadCurrency,$currencyId);
            $draft=GrowthNativeCustomerOrderDraft::buildPair(
                $namespace,$shop,$customer,$order,$currency
            );
            $count=2;
        }
        // The canonical G4 encoder runs inside buildCustomer/buildPair.
        // Destroy the PII-bearing JSON before producing any admin output.
        unset($draft,$customer,$order,$currency);

        // Check the same original ledger again, with SELECT cache disabled.
        // This catches an intervening event, but does not close the race
        // between source changes and a future remote send.
        $after=self::ledger($db,$sqlLedger,$namespace,$shop,$type,$id,$seq);
        if ($before!==$after) {
            throw new RuntimeException('g7_readback_ledger_changed');
        }
        return self::report('native_pst_shape_verified_blocked',$type,$count,true);
    }

    private static function ledger(
        $db, $sql, $namespace, $shop, $type, $id, $seq
    ) {
        $rows=$db->executeS($sql,true,false);
        if (!is_array($rows) || count($rows)!==1) {
            throw new RuntimeException('g7_readback_ledger_unverified');
        }
        $row=$rows[0];
        if ($row['source_namespace']!==$namespace
            || self::positive($row['shop_id'])!==$shop
            || $row['entity_type']!==$type
            || self::positive($row['entity_id'])!==$id
            || self::positive($row['latest_accepted_sequence'])!==$seq
            || $row['status']!=='blocked') {
            throw new RuntimeException('g7_readback_ledger_outdated');
        }
        return $row;
    }

    private static function positive($value)
    {
        if (is_int($value)) {
            return $value>0?$value:null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D',$value)) {
            $n=(int)$value;
            return $n>0 && (string)$n===$value?$n:null;
        }
        return null;
    }

    private static function quote($table)
    {
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)) {
            throw new RuntimeException('g7_readback_table_invalid');
        }
        return chr(96).$table.chr(96);
    }

    private static function report($status,$type,$count,$verified)
    {
        return [
            'status'=>$status,
            'type'=>$type,
            'pst_objects_validated'=>$count,
            'latest_blocked_ledger_observed'=>$verified,
            'source_freshness_certified'=>false,
            'source_utc_provenance_certified'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'http_request_performed'=>false,
        ];
    }

    private function __construct() {}
}
