<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * G8 Back Office UAT: exact, read-only proof for one customer + order.
 * Reads the SAME G3 ledger/outbox; no new queue or table, no external IO.
 * Only returns two booleans and a fixed status. No source IDs, row values,
 * customer PII, token, SQL or raw exception is ever displayed.
 */
final class GrowthG8ExactCaptureInspector
{
    public static function inspect(
        $db,$version,$merchant,$namespace,$prefix,
        $shop,$customerId,$orderId
    ) {
        if (!is_object($db) || !is_callable([$db,'executeS'])
            || !is_string($version) || $version===''
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace)!==1
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1
            || !is_int($shop) || $shop<1
            || !is_int($customerId) || $customerId<1
            || !is_int($orderId) || $orderId<1
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off') {
            return self::report('capture_check_not_allowed',false,false);
        }
        try {
            $binding=GrowthShopSourceBinding::inspect($shop,$merchant,$namespace);
            if (!isset($binding['ready']) || $binding['ready']!==true) {
                return self::report('capture_source_not_ready',false,false);
            }
            $plan=GrowthOutboxSchemaPlan::preview(
                $version,$merchant,'marketing_cloud_growth',
                $namespace,'test',$prefix
            );
            $ledger=self::q($plan['tables']['ledger']);
            $outbox=self::q($plan['tables']['outbox']);
            $customer=self::captured($db,$ledger,$outbox,
                $namespace,$shop,'customer',$customerId);
            $order=self::captured($db,$ledger,$outbox,
                $namespace,$shop,'order',$orderId);
            return self::report(
                $customer && $order
                    ?'both_events_captured':'capture_not_confirmed',
                $customer,$order
            );
        } catch (Throwable $ignored) {
            return self::report('capture_check_unavailable',false,false);
        }
    }

    private static function captured(
        $db,$ledger,$outbox,$namespace,$shop,$type,$id
    ) {
        $sql='SELECT o.accepted_sequence,o.delivery_state,'
            .'o.delivery_attempts,o.lease_token_hash,'
            .'o.lease_until_epoch,o.delivery_last_http,'
            .'o.status AS event_status,l.status AS ledger_status,'
            .'l.latest_accepted_sequence'
            .' FROM '.$ledger.' l INNER JOIN '.$outbox.' o'
            .' ON o.source_namespace=l.source_namespace'
            .' AND o.shop_id=l.shop_id'
            .' AND o.entity_type=l.entity_type'
            .' AND o.entity_id=l.entity_id'
            .' AND o.accepted_sequence=l.latest_accepted_sequence'
            ." WHERE l.source_namespace='$namespace'"
            ." AND l.shop_id=$shop AND l.entity_type='$type'"
            ." AND l.entity_id=$id LIMIT 2";
        $rows=$db->executeS($sql,true,false);
        if (!is_array($rows) || count($rows)!==1
            || !is_array($rows[0]) || count($rows[0])!==9) {
            return false;
        }
        $r=$rows[0];
        foreach ([
            'accepted_sequence','delivery_state','delivery_attempts',
            'lease_token_hash','lease_until_epoch','delivery_last_http',
            'event_status','ledger_status','latest_accepted_sequence'
        ] as $field) {
            if (!array_key_exists($field,$r))return false;
        }
        $sequence=self::uint($r['accepted_sequence']);
        $latest=self::uint($r['latest_accepted_sequence']);
        $attempts=self::uint($r['delivery_attempts']);
        return $sequence!==null && $sequence>0
            && $sequence===$latest && $attempts===0
            && $r['event_status']==='blocked'
            && $r['ledger_status']==='blocked'
            && $r['delivery_state']==='blocked'
            && $r['lease_token_hash']===null
            && $r['lease_until_epoch']===null
            && $r['delivery_last_http']===null;
    }

    private static function uint($value)
    {
        if (is_int($value))return $value>=0?$value:null;
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)!==1)return null;
        $num=(int)$value;
        return $num>=0 && (string)$num===$value?$num:null;
    }

    private static function q($name)
    {
        if (!is_string($name)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)!==1) {
            throw new \RuntimeException('g8_capture_table_invalid');
        }
        return chr(96).$name.chr(96);
    }

    private static function report($status,$customer,$order)
    {
        return [
            'status'=>$status,
            'customer_event_captured'=>$customer,
            'order_event_captured'=>$order,
            'read_only'=>true,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'record_identifiers_exposed'=>false,
            'http_request_performed'=>false,
            'database_write_performed'=>false
        ];
    }

    private function __construct(){}
}
