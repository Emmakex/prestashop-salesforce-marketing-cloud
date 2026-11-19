<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthCapturedEventPstPreview;
use RuntimeException;
use Throwable;

/**
 * G7 read-only bridge: REAL PrestaShop Db G3/ledger -> native Customer/Order
 * -> existing PST v0.4 preview -> local negative-consent evidence.
 *
 * This DOES NOT claim/arm leases, set ready, send HTTP/email, write DLO,
 * persist a payload, or certify contact consent. Current shop rows remain
 * blocked. Two fresh reads reduce but cannot close a future send race.
 */
final class GrowthG7NativePreflight
{
    public static function inspectLatest(
        $db,$version,$merchant,$namespace,$prefix,$shop,$type,
        $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
        $origin,$sourceName
    ) {
        if (!is_int($shop) || $shop<1
            || !in_array($type,['customer','order'],true)
            || !is_callable($loadCustomer)
            || !is_callable($loadOrder)
            || !is_callable($loadCurrency)
            || !is_callable($selectLocalRows)) {
            throw new RuntimeException('g7_preflight_input_invalid');
        }

        // Reuse the exact Test+capture OFF+namespace and extended G3 guards.
        // This object has no sender or ready transition.
        $lease=new GrowthPrestaShopDeliveryLease(
            $db,$version,$merchant,$namespace,$prefix
        );
        $tables=GrowthOutboxSchemaPlan::preview(
            $version,$merchant,'marketing_cloud_growth',
            $namespace,'test',$prefix
        )['tables'];
        $outbox=self::quote($tables['outbox']);
        $ledger=self::quote($tables['ledger']);
        $sql='SELECT accepted_sequence,source_namespace,shop_id,entity_type,'
            . 'entity_id,original_event_type,status,delivery_state FROM '
            . $outbox." WHERE source_namespace='$namespace'"
            . " AND shop_id=$shop AND entity_type='$type'"
            . " AND status='blocked' ORDER BY accepted_sequence DESC LIMIT 1";
        try {
            $rows=$db->executeS($sql,true,false);
            if (!is_array($rows) || count($rows)>1) {
                return self::report('source_unverified_hold','unknown',false);
            }
            if (!$rows) {
                return self::report('no_local_event_hold','unknown',false);
            }
            $event=$rows[0];
            $id=self::id(isset($event['entity_id'])?$event['entity_id']:null);
            $sequence=self::id(isset($event['accepted_sequence'])
                ?$event['accepted_sequence']:null);
            if ($id===null || $sequence===null
                || $event['delivery_state']!=='blocked') {
                return self::report('delivery_state_hold','unknown',false);
            }
            $state=$lease->inspect($shop,$type,$id,$sequence);
            if ($state['state']!=='blocked' || !$state['ledger_current']
                || $state['attempts']!==0) {
                return self::report('not_current_blocked_hold','unknown',false);
            }

            $first=self::snapshot($db,$event,$ledger,$namespace,$shop,$type,
                $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
                $prefix,$origin,$sourceName);
            $second=self::snapshot($db,$event,$ledger,$namespace,$shop,$type,
                $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,
                $prefix,$origin,$sourceName);
            // One final current-ledger/lease read AFTER the second source read.
            // A new change after this instant is still possible.
            $after=$lease->inspect($shop,$type,$id,$sequence);
            if ($after['state']!=='blocked' || !$after['ledger_current']
                || $after['attempts']!==0) {
                return self::report('ledger_changed_hold','unknown',false);
            }
            if (!hash_equals($first['hash'],$second['hash'])) {
                return self::report('native_source_changed_hold',
                    $second['local_state'],false);
            }
            return self::report(
                $second['local_state']==='known_negative'
                    ?'local_negative_hold':'local_consent_unverified_hold',
                $second['local_state'],true
            );
        } catch (Throwable $error) {
            // No SQL, PHP exception text, email, source_key, token, URI,
            // hash, names or identifiers in operator-visible output.
            return self::report('source_unverified_hold','unknown',false);
        }
    }

    private static function snapshot(
        $db,array $event,$ledgerTable,$namespace,$shop,$type,
        $loadCustomer,$loadOrder,$loadCurrency,$selectLocalRows,$prefix,
        $origin,$sourceName
    ) {
        $id=self::id($event['entity_id']);
        $seq=self::id($event['accepted_sequence']);
        if ($id===null || $seq===null) {
            throw new RuntimeException('g7_preflight_event_invalid');
        }
        $sql='SELECT source_namespace,shop_id,entity_type,entity_id,'
            . 'latest_accepted_sequence,status FROM '.$ledgerTable
            . " WHERE source_namespace='$namespace'"
            . " AND shop_id=$shop AND entity_type='$type'"
            . " AND entity_id=$id LIMIT 2";
        $rows=$db->executeS($sql,true,false);
        if (!is_array($rows) || count($rows)!==1) {
            throw new RuntimeException('g7_preflight_ledger_unverified');
        }
        $ledger=$rows[0];
        $customer=null;
        $order=null;
        $currency=null;
        if ($type==='customer') {
            $customer=call_user_func($loadCustomer,$id);
            $customerId=$id;
        } else {
            $order=call_user_func($loadOrder,$id);
            if (!is_object($order) || self::id(isset($order->id)?$order->id:null)!==$id
                || self::id(isset($order->id_shop)?$order->id_shop:null)!==$shop) {
                throw new RuntimeException('g7_preflight_order_scope_invalid');
            }
            $customerId=self::id(isset($order->id_customer)?$order->id_customer:null);
            $currencyId=self::id(isset($order->id_currency)?$order->id_currency:null);
            if ($customerId===null || $currencyId===null) {
                throw new RuntimeException('g7_preflight_relation_invalid');
            }
            $customer=call_user_func($loadCustomer,$customerId);
            $currency=call_user_func($loadCurrency,$currencyId);
        }
        // Verify canonical typed PST using the G5-proven preview, but
        // discard the payload. A clean shape does not imply permission.
        $preview=GrowthCapturedEventPstPreview::inspect(
            $event,$ledger,$namespace,$shop,$customer,$order,$currency,
            $origin,$sourceName
        );
        if ($preview['status']!=='blocked_pst_shape_preview_only'
            || $preview['http_request_performed']!==false
            || $preview['data_cloud_ingestion_authorized']!==false) {
            throw new RuntimeException('g7_preflight_shape_denied');
        }

        // Local opt-out/deletion/inactivity is negative evidence only.
        // Positive newsletter/optin NEVER grants Growth consent.
        $customerHold=GrowthLocalCustomerHoldReader::inspect(
            $shop,$customerId,$selectLocalRows,$prefix
        );
        if ($customerHold['status']!=='hold_for_review'
            || $customerHold['growth_purpose_consent_certified']!==false
            || $customerHold['marketing_sends_authorized']!==false
            || !in_array($customerHold['local_customer_source_state'],[
                'known_negative','no_local_negative_observed','unknown'
            ],true)) {
            throw new RuntimeException('g7_preflight_local_policy_denied');
        }
        $source=[
            'event'=>$event,'ledger'=>$ledger,
            'customer'=>get_object_vars($customer),
            'order'=>$order===null?null:get_object_vars($order),
            'currency'=>$currency===null?null:get_object_vars($currency),
            'local'=>$customerHold
        ];
        $hash=hash('sha256',serialize($source));
        $localState=$customerHold['local_customer_source_state'];
        unset($source,$event,$ledger,$customer,$order,$currency,$customerHold,$preview);
        return ['hash'=>$hash,'local_state'=>$localState];
    }

    private static function id($value)
    {
        if (is_int($value))return $value>0?$value:null;
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D',$value)) {
            $n=(int)$value;
            return $n>0 && (string)$n===$value?$n:null;
        }
        return null;
    }

    private static function quote($name)
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)) {
            throw new RuntimeException('g7_preflight_table_invalid');
        }
        return chr(96).$name.chr(96);
    }

    private static function report($reason,$localState,$twoPasses)
    {
        return [
            'status'=>'hold_for_review',
            'reason'=>$reason,
            'local_customer_source_state'=>$localState,
            'native_source_double_read_stable'=>$twoPasses,
            'ledger_fence_until_http_certified'=>false,
            'remote_growth_contact_consent_certified'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'http_request_performed'=>false,
            'new_queue_created'=>false,
        ];
    }

    private function __construct() {}
}
