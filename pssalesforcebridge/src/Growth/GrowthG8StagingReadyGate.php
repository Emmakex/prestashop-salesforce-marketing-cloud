<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * G8 missing G3 bridge: approve a CURRENT blocked event as READY in staging.
 * No new table, queue, schedule, token, HTTP, or Marketing Cloud campaign.
 *
 * All three callbacks are supplied by a trusted internal staging worker:
 * fresh native contact-purpose eligibility, exact DLO absence and exact
 * parent DLO verification. They MUST be backed by real, independently
 * certified readers; synthetic true callbacks are test fixtures only.
 *
 * This class is not loaded by the installed module or cron until staging UAT.
 */
final class GrowthG8StagingReadyGate
{
    const OWNER_GATE='OWNER_APPROVED_BOUNDED_AUTOMATIC_DATA_CLOUD_INGESTION';

    public static function promote(
        $db,$version,array $scope,$prefix,array $candidate,
        $freshEligible,$exactDloStatus,$parentDloVerified
    ) {
        if (!is_object($db)
            || !is_callable([$db,'execute'])
            || !is_callable([$db,'getValue'])
            || !is_callable($freshEligible)
            || !is_callable($exactDloStatus)
            || !is_callable($parentDloVerified)
            || !self::scope($scope)
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1
            || !is_string($version) || $version===''
            || !self::candidate($candidate,$scope['shop'])) {
            return self::result('blocked_by_configuration');
        }
        try {
            // Reuse the existing native G3/G7 schema and guard, not a new
            // source identity or implicit global shop configuration.
            $lease=new GrowthPrestaShopDeliveryLease(
                $db,$version,$scope['merchant'],
                $scope['namespace'],$prefix
            );
            $source=GrowthShopSourceBinding::inspect(
                $scope['shop'],$scope['merchant'],$scope['namespace']
            );
            if (!isset($source['ready']) || $source['ready']!==true) {
                return self::result('blocked_source_binding');
            }
            $type=$candidate['type']==='PSCustomer'?'customer':'order';
            $current=$lease->inspect(
                $candidate['shop'],$type,
                $candidate['id'],$candidate['sequence']
            );
            if (!is_array($current)
                || $current['status']!=='staging_local_lease_only'
                || $current['state']!=='blocked'
                || $current['attempts']!==0
                || $current['ledger_current']!==true
                || $current['lease_active']!==false
                || !array_key_exists('last_http',$current)
                || $current['last_http']!==null) {
                return self::result('blocked_old_or_attempted');
            }

            // The owner gate alone is NOT contact approval; require three
            // fresh, exact checks even though CAS will recheck after claim.
            if (call_user_func($freshEligible,$candidate)!==true) {
                return self::result('blocked_contact_or_source');
            }
            if (call_user_func($exactDloStatus,$candidate)
                !=='absent_confirmed') {
                return self::result('blocked_remote_not_absent');
            }
            if ($candidate['type']==='PSOrder'
                && call_user_func($parentDloVerified,$candidate)!==true) {
                return self::result('blocked_parent_not_verified');
            }

            // A single atomic mutation on the ORIGINAL outbox row.
            // The ledger join ensures this event is still the current
            // accepted revision. Concurrent promotion affects zero rows.
            $plan=GrowthOutboxSchemaPlan::preview(
                $version,$scope['merchant'],'marketing_cloud_growth',
                $scope['namespace'],'test',$prefix
            );
            $outbox=self::q($plan['tables']['outbox']);
            $ledger=self::q($plan['tables']['ledger']);
            $namespace=$scope['namespace'];
            $shop=$candidate['shop'];
            $id=$candidate['id'];
            $sequence=$candidate['sequence'];
            $sql='UPDATE '.$outbox.' o INNER JOIN '.$ledger.' l'
                . ' ON l.source_namespace=o.source_namespace'
                . ' AND l.shop_id=o.shop_id'
                . ' AND l.entity_type=o.entity_type'
                . ' AND l.entity_id=o.entity_id'
                . " SET o.delivery_state='ready'"
                . " WHERE o.source_namespace='$namespace'"
                . " AND o.shop_id=$shop AND o.entity_type='$type'"
                . " AND o.entity_id=$id AND o.accepted_sequence=$sequence"
                . " AND o.status='blocked' AND l.status='blocked'"
                . ' AND l.latest_accepted_sequence=o.accepted_sequence'
                . " AND o.delivery_state='blocked'"
                . ' AND o.delivery_attempts=0'
                . ' AND o.lease_token_hash IS NULL'
                . ' AND o.lease_until_epoch IS NULL'
                . ' AND o.delivery_last_http IS NULL';
            if (!$db->execute($sql)) {
                return self::result('blocked_write_unverified');
            }
            $changed=$db->getValue('SELECT ROW_COUNT()',false);
            if ((string)$changed==='1') {
                return self::result('ready_for_one_cas_only');
            }
            return self::result('blocked_old_or_attempted');
        } catch (Throwable $ignored) {
            return self::result('blocked_review_unverified');
        }
    }

    private static function scope($s)
    {
        if (!isset($s['shop'],$s['merchant'],$s['namespace'],
            $s['mode'],$s['capture'],$s['owner_gate'])
            || !is_int($s['shop']) || $s['shop']<1
            || !GrowthShopSourceBinding::isMerchant($s['merchant'])
            || !GrowthShopSourceBinding::isNamespace($s['namespace'])
            || preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',
                $s['namespace'])!==1
            || $s['mode']!=='test' || $s['capture']!=='off'
            || $s['owner_gate']!==self::OWNER_GATE
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')
                !=='off'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')
                !==$s['merchant']
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')
                !==$s['namespace']) {
            return false;
        }
        return true;
    }

    private static function candidate($c,$shop)
    {
        return count($c)===4
            && isset($c['shop'],$c['type'],$c['id'],$c['sequence'])
            && $c['shop']===$shop
            && in_array($c['type'],['PSCustomer','PSOrder'],true)
            && is_int($c['id']) && $c['id']>0
            && is_int($c['sequence']) && $c['sequence']>0;
    }

    private static function q($name)
    {
        if (!is_string($name)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)!==1) {
            throw new RuntimeException('g8_ready_table_unverified');
        }
        return chr(96).$name.chr(96);
    }

    private static function result($status)
    {
        // This is safe even if a caller accidentally logs it.
        return [
            'status'=>$status,
            'record_identifiers_exposed'=>false,
            'delivery_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'http_request_performed'=>false
        ];
    }

    private function __construct(){}
}
