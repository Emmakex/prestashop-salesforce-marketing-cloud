<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * Two-record UAT allowlist on the existing G3 ledger, no new queue.
 * Exactly one customer and its order, bound to shop/merchant/namespace.
 * Not a Data Cloud delivery authorization or consent for campaigns.
 */
final class GrowthG8UatExactTarget
{
    const KEY='PS_GROWTH_G8_UAT_EXACT_TARGET';

    public static function inspect($shop,$merchant,$namespace)
    {
        if (!self::scope($shop,$merchant,$namespace))return null;
        $json=\Configuration::get(self::KEY,null,null,$shop);
        if (!is_string($json) || strlen($json)>512)return null;
        $t=json_decode($json,true);
        if (!is_array($t) || count($t)!==6
            || !isset($t['version'],$t['shop'],$t['merchant'],
                $t['namespace'],$t['customer_id'],$t['order_id'])
            || $t['version']!==1 || $t['shop']!==$shop
            || $t['merchant']!==$merchant
            || $t['namespace']!==$namespace
            || !is_int($t['customer_id']) || $t['customer_id']<1
            || !is_int($t['order_id']) || $t['order_id']<1) return null;
        return $t;
    }

    public static function bind($shop,$merchant,$namespace,$customerId,$orderId)
    {
        if (!self::scope($shop,$merchant,$namespace)
            || !is_int($customerId) || $customerId<1
            || !is_int($orderId) || $orderId<1)return false;
        $current=self::inspect($shop,$merchant,$namespace);
        if ($current!==null) {
            // No silently switching customers after potential CAS/HTTP.
            return $current['customer_id']===$customerId
                && $current['order_id']===$orderId;
        }
        $raw=\Configuration::get(self::KEY,null,null,$shop);
        if ($raw!==false && $raw!==null && $raw!=='')return false;
        return \Configuration::updateValue(self::KEY,json_encode([
            'version'=>1,'shop'=>$shop,'merchant'=>$merchant,
            'namespace'=>$namespace,'customer_id'=>$customerId,
            'order_id'=>$orderId
        ],JSON_UNESCAPED_SLASHES),false,null,$shop)===true;
    }

    public static function clear($shop,$merchant,$namespace)
    {
        if (self::inspect($shop,$merchant,$namespace)===null)return false;
        // Clearing disarms the worker even if the original G8 approval
        // remains present; cannot erase the G3 CAS attempt ledger.
        return \Configuration::updateValue(
            self::KEY,'',false,null,$shop
        )===true;
    }

    public static function candidates(
        $db,$version,$prefix,array $scope,$state
    ) {
        if (!in_array($state,['blocked','ready'],true)
            || !is_object($db) || !is_callable([$db,'executeS'])
            || !isset($scope['shop'],$scope['merchant'],$scope['namespace'])
            || !self::scope(
                $scope['shop'],$scope['merchant'],$scope['namespace']
            )
            || !is_string($version) || $version===''
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1) {
            throw new \RuntimeException('g8_exact_target_invalid');
        }
        $t=self::inspect(
            $scope['shop'],$scope['merchant'],$scope['namespace']
        );
        if ($t===null)throw new \RuntimeException('g8_exact_target_missing');
        $plan=GrowthOutboxSchemaPlan::preview(
            $version,$scope['merchant'],'marketing_cloud_growth',
            $scope['namespace'],'test',$prefix
        );
        $out=self::q($plan['tables']['outbox']);
        $ledger=self::q($plan['tables']['ledger']);
        $namespace=$scope['namespace'];
        $shop=$scope['shop'];
        $candidates=[];
        foreach ([
            ['customer',$t['customer_id'],'PSCustomer'],
            ['order',$t['order_id'],'PSOrder']
        ] as $row) {
            list($type,$id,$name)=$row;
            $sql='SELECT o.accepted_sequence,o.delivery_state,'
                .'o.delivery_attempts,o.lease_token_hash,'
                .'o.lease_until_epoch,o.delivery_last_http'
                .' FROM '.$ledger.' l INNER JOIN '.$out.' o'
                .' ON l.source_namespace=o.source_namespace'
                .' AND l.shop_id=o.shop_id'
                .' AND l.entity_type=o.entity_type'
                .' AND l.entity_id=o.entity_id'
                .' AND l.latest_accepted_sequence=o.accepted_sequence'
                ." WHERE l.source_namespace='$namespace'"
                ." AND l.shop_id=$shop AND l.entity_type='$type'"
                ." AND l.entity_id=$id AND l.status='blocked'"
                ." AND o.status='blocked' LIMIT 2";
            $rows=$db->executeS($sql,true,false);
            if (!is_array($rows) || count($rows)>1) {
                throw new \RuntimeException('g8_exact_target_row_invalid');
            }
            if (count($rows)===0)continue;
            $r=$rows[0];
            if (!is_array($r) || count($r)!==6
                || !isset($r['accepted_sequence'],$r['delivery_state'],
                    $r['delivery_attempts'])
                || !array_key_exists('lease_token_hash',$r)
                || !array_key_exists('lease_until_epoch',$r)
                || !array_key_exists('delivery_last_http',$r)) {
                throw new \RuntimeException('g8_exact_target_row_invalid');
            }
            $seq=self::positive($r['accepted_sequence']);
            $attempts=self::zero($r['delivery_attempts']);
            if ($seq===null || !$attempts) {
                // Prior attempts are never eligible for a replay.
                continue;
            }
            if ($r['delivery_state']!==$state
                || $r['lease_token_hash']!==null
                || $r['lease_until_epoch']!==null
                || $r['delivery_last_http']!==null) continue;
            $candidates[]=[
                'shop'=>$shop,'type'=>$name,'id'=>$id,'sequence'=>$seq
            ];
        }
        return $candidates;
    }

    private static function scope($shop,$merchant,$namespace)
    {
        return is_int($shop) && $shop>0
            && GrowthShopSourceBinding::isMerchant($merchant)
            && GrowthShopSourceBinding::isNamespace($namespace)
            && preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )===1;
    }

    private static function positive($v)
    {
        if (is_int($v))return $v>0?$v:null;
        if (!is_string($v)
            || preg_match('/^[1-9][0-9]*$/D',$v)!==1)return null;
        $i=(int)$v;
        return $i>0 && (string)$i===$v?$i:null;
    }

    private static function zero($v)
    {
        return $v===0 || $v==='0';
    }

    private static function q($name)
    {
        if (!is_string($name)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)!==1) {
            throw new \RuntimeException('g8_exact_target_identifier_invalid');
        }
        return chr(96).$name.chr(96);
    }

    private function __construct(){}
}
