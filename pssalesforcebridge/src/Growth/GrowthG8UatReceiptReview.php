<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * Purely local G8 UAT reconciliation reader for the two bound G3 records.
 * It never promotes, claims, retries, mutates, contacts Salesforce, or
 * exposes customer data or raw SQL.
 *
 * A 202 receipt indicates an HTTP acceptance, NOT a persisted DLO record.
 * A "blocked" G3 state is not permission to retry an ambiguous request.
 */
final class GrowthG8UatReceiptReview
{
    public static function inspect(
        $db,$version,$merchant,$namespace,$prefix,$shop
    ) {
        $unknown=self::item('unverified',null,null);
        $result=[
            'status'=>'g3_review_unavailable',
            'customer'=>$unknown,'order'=>$unknown,
            'read_only'=>true,
            'dispatch_authorized'=>false,
            'retry_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
        if (!is_object($db) || !is_callable([$db,'executeS'])
            || !is_string($version) || $version===''
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1
            || !is_int($shop) || $shop<1
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',
                $namespace)!==1
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
        )return $result;
        try {
            $target=GrowthG8UatPairScope::inspect($shop,$merchant,$namespace);
            if (!is_array($target))return $result;
            $source=GrowthShopSourceBinding::inspect($shop,$merchant,$namespace);
            if (!isset($source['ready']) || $source['ready']!==true)return $result;
            $plan=GrowthOutboxSchemaPlan::preview(
                $version,$merchant,'marketing_cloud_growth',
                $namespace,'test',$prefix
            );
            $out=self::q($plan['tables']['outbox']);
            $ledger=self::q($plan['tables']['ledger']);
            $result['customer']=self::readRow(
                $db,$out,$ledger,$namespace,$shop,'customer',
                $target['customer_id']
            );
            $result['order']=self::readRow(
                $db,$out,$ledger,$namespace,$shop,'order',
                $target['order_id']
            );
            $result['status']='g3_review_complete';
            return $result;
        } catch (Throwable $ignored) {
            return [
                'status'=>'g3_review_unavailable',
                'customer'=>$unknown,'order'=>$unknown,
                'read_only'=>true,
                'dispatch_authorized'=>false,
                'retry_authorized'=>false,
                'marketing_sends_authorized'=>false
            ];
        }
    }

    private static function readRow($db,$out,$ledger,$ns,$shop,$type,$id)
    {
        $sql='SELECT o.accepted_sequence,o.delivery_state,'
            .'o.delivery_attempts,o.delivery_last_http,'
            .'o.lease_until_epoch,o.status AS event_status,'
            .'l.status AS ledger_status,l.latest_accepted_sequence'
            .' FROM '.$ledger.' l INNER JOIN '.$out.' o'
            .' ON o.source_namespace=l.source_namespace'
            .' AND o.shop_id=l.shop_id'
            .' AND o.entity_type=l.entity_type'
            .' AND o.entity_id=l.entity_id'
            .' AND o.accepted_sequence=l.latest_accepted_sequence'
            ." WHERE l.source_namespace='$ns'"
            ." AND l.shop_id=$shop AND l.entity_type='$type'"
            ." AND l.entity_id=$id LIMIT 2";
        $rows=$db->executeS($sql,true,false);
        if (!is_array($rows) || count($rows)>1) {
            return self::item('unverified',null,null);
        }
        if ($rows===[])return self::item('not_found',null,null);
        $r=$rows[0];
        if (!is_array($r) || count($r)!==8) {
            return self::item('unverified',null,null);
        }
        foreach ([
            'accepted_sequence','delivery_state','delivery_attempts',
            'delivery_last_http','lease_until_epoch','event_status',
            'ledger_status','latest_accepted_sequence'
        ] as $k)if (!array_key_exists($k,$r)) {
            return self::item('unverified',null,null);
        }
        $sequence=self::uint($r['accepted_sequence']);
        $latest=self::uint($r['latest_accepted_sequence']);
        $attempts=self::uint($r['delivery_attempts']);
        $http=$r['delivery_last_http']===null
            ?null:self::uint($r['delivery_last_http']);
        if ($sequence===null || $sequence<1
            || $latest!==$sequence || $attempts===null
            || ($r['delivery_last_http']!==null
                && ($http===null || $http>599))
            || $r['event_status']!=='blocked'
            || $r['ledger_status']!=='blocked'
            || !in_array($r['delivery_state'],[
                'blocked','ready','claimed','reconcile','held','cancelled'
            ],true)) {
            return self::item('unverified',null,null);
        }
        $state=$r['delivery_state'];
        if ($state==='reconcile') {
            $kind=$http===202?'accepted_202_unconfirmed':'reconciliation_required';
        } elseif ($state==='claimed') {
            $kind='claimed_unconfirmed';
        } elseif ($state==='held') {
            $kind='held_requires_review';
        } elseif ($state==='cancelled') {
            $kind='cancelled';
        } elseif ($attempts===0 && $http===null) {
            $kind=$state==='blocked'?'blocked_no_attempt':'ready_no_attempt';
        } else {
            $kind='unverified';
        }
        return self::item($kind,$attempts,$http);
    }

    private static function item($state,$attempts,$http)
    {
        return [
            'state'=>$state,
            'attempts'=>$attempts,
            'http_status'=>$http
        ];
    }

    private static function uint($v)
    {
        if (is_int($v))return $v>=0?$v:null;
        if (!is_string($v)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D',$v)!==1)return null;
        $int=(int)$v;
        return $int>=0 && (string)$int===$v?$int:null;
    }

    private static function q($name)
    {
        if (!is_string($name)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)!==1) {
            throw new \RuntimeException('g8_review_table_unverified');
        }
        return chr(96).$name.chr(96);
    }

    private function __construct(){}
}
