<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Review;

use RuntimeException;

/**
 * Read-only verification of the PREVIOUSLY ingested G5 synthetic canaries.
 * This never creates new customers/orders, writes a row, or checks real
 * customer contactability. Metadata and query bodies are private memory.
 */
final class G7ExistingCanaryReadOnly
{
    const CUSTOMER_KEY='growth_g5_canary_20261117:2:customer:987654321';
    const ORDER_KEY='growth_g5_canary_20261117:2:order:987654321';

    public static function verify($entities,$loadMetadata,$querySql)
    {
        if (!is_array($entities) || !isset($entities['entities'])
            || !is_array($entities['entities'])
            || !is_callable($loadMetadata) || !is_callable($querySql)) {
            throw new RuntimeException('g7_live_metadata_unverified');
        }
        $customer=self::findEntity($entities['entities'],'pstpscustomer');
        $order=self::findEntity($entities['entities'],'pstpsorder');
        $cMeta=call_user_func($loadMetadata,$customer);
        $oMeta=call_user_func($loadMetadata,$order);
        $cPk=self::field($cMeta,'source_key');
        $oPk=self::field($oMeta,'source_key');
        $oFk=self::field($oMeta,'customer_source_key');

        // The only record values in these SQL statements are the fixed,
        // existing G5 canary keys, never a real customer or PrestaShop ID.
        $cSql='SELECT "'.$cPk.'" FROM "'.$customer.'"'
            .' WHERE "'.$cPk.'" = '.self::str(self::CUSTOMER_KEY).' LIMIT 2';
        $oSql='SELECT "'.$oPk.'","'.$oFk.'" FROM "'.$order.'"'
            .' WHERE "'.$oPk.'" = '.self::str(self::ORDER_KEY).' LIMIT 2';
        $cRows=self::rows(call_user_func($querySql,$cSql));
        $oRows=self::rows(call_user_func($querySql,$oSql));
        if (count($cRows)!==1 || count($oRows)!==1
            || !array_key_exists($cPk,$cRows[0])
            || !array_key_exists($oPk,$oRows[0])
            || !array_key_exists($oFk,$oRows[0])) {
            throw new RuntimeException('g7_live_existing_canary_not_verified');
        }
        if ($cRows[0][$cPk]!==self::CUSTOMER_KEY
            || $oRows[0][$oPk]!==self::ORDER_KEY
            || $oRows[0][$oFk]!==self::CUSTOMER_KEY) {
            throw new RuntimeException('g7_live_existing_canary_relation_unverified');
        }
        return [
            'status'=>'g7_existing_canary_dlo_verified_read_only',
            'customer_dlo_found'=>true,
            'order_dlo_found'=>true,
            'order_customer_source_fk_matches'=>true,
            'records_contain_synthetic_data_only'=>true,
            'real_prestashop_events_transferred'=>false,
            'dmo_materialization_certified_by_this_run'=>false,
            'campaign_sends_authorized'=>false,
            'live_ingestion_authorized'=>false,
            'salesforce_writes_performed'=>false,
        ];
    }

    private static function findEntity(array $rows,$expected)
    {
        $names=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['entityName'],$row['displayName'])
                || !is_string($row['entityName']) || !is_string($row['displayName'])) {
                continue;
            }
            $name=$row['entityName'];
            $display=strtolower(preg_replace('/[^a-z0-9]/i','',$row['displayName']));
            if ($display===$expected && self::identifier($name)) {
                $names[]=$name;
            }
        }
        $names=array_values(array_unique($names));
        if (count($names)!==1) {
            throw new RuntimeException('g7_live_dlo_identity_unverified');
        }
        return $names[0];
    }

    private static function field($meta,$base)
    {
        if (!is_array($meta) || !isset($meta['metadata'])
            || !is_array($meta['metadata']) || count($meta['metadata'])!==1
            || !isset($meta['metadata'][0]['fields'])
            || !is_array($meta['metadata'][0]['fields'])) {
            throw new RuntimeException('g7_live_field_metadata_unverified');
        }
        $matches=[];
        foreach ($meta['metadata'][0]['fields'] as $row) {
            if (!is_array($row) || !isset($row['name'])
                || !is_string($row['name']))continue;
            if (in_array(strtolower($row['name']),
                [$base,$base.'__c'],true)
                && self::identifier($row['name'])) {
                $matches[]=$row['name'];
            }
        }
        $matches=array_values(array_unique($matches));
        if (count($matches)!==1) {
            throw new RuntimeException('g7_live_field_identity_unverified');
        }
        return $matches[0];
    }

    private static function rows($response)
    {
        if (!is_array($response) || !isset($response['data'])
            || !is_array($response['data'])
            || !isset($response['done']) || $response['done']!==true
            || count($response['data'])>2) {
            throw new RuntimeException('g7_live_query_unverified');
        }
        foreach ($response['data'] as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('g7_live_query_unverified');
            }
        }
        return $response['data'];
    }

    private static function identifier($value)
    {
        return is_string($value)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D',$value)===1;
    }

    private static function str($value)
    {
        if (!in_array($value,[self::CUSTOMER_KEY,self::ORDER_KEY],true)) {
            throw new RuntimeException('g7_live_query_key_unverified');
        }
        return "'".$value."'";
    }

    private function __construct(){}
}
