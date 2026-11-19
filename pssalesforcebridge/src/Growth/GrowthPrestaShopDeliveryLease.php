<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Native PrestaShop Db adapter for the SAME extended G3 outbox/ledger.
 *
 * Stage + Test + capture OFF only. This has NO 'ready' transition, no
 * external request, token exchange, cron, module hook or PII persistence.
 * Existing rows remain blocked: they cannot be claimed. Tests can inject
 * synthetic ready rows ONLY inside disposable g3_ci.
 *
 * A lease is not consent or delivery. HTTP 202 / lost response always
 * becomes reconcile, not completed; no automatic retry is implemented.
 */
final class GrowthPrestaShopDeliveryLease
{
    private $db;
    private $version;
    private $merchant;
    private $namespace;
    private $prefix;
    private $outbox;
    private $ledger;

    public function __construct($db, $version, $merchant, $namespace, $prefix)
    {
        if (!is_object($db) || !is_callable([$db,'execute'])
            || !is_callable([$db,'executeS'])
            || !is_callable([$db,'getValue'])) {
            throw new RuntimeException('g7_native_lease_db_required');
        }
        $plan=GrowthOutboxSchemaPlan::preview(
            $version,$merchant,'marketing_cloud_growth',$namespace,'test',$prefix
        );
        $this->db=$db;
        $this->version=$version;
        $this->merchant=$merchant;
        $this->namespace=$namespace;
        $this->prefix=$prefix;
        $this->outbox=self::quote($plan['tables']['outbox']);
        $this->ledger=self::quote($plan['tables']['ledger']);
        $this->guard();
    }

    /** Read-only current revision; return no ID, namespace, payload or PII. */
    public function inspect($shop,$type,$id,$sequence)
    {
        $this->scope($shop,$type,$id,$sequence);
        $this->guard();
        $sql='SELECT o.delivery_state,o.delivery_attempts,'
            . 'o.lease_until_epoch,o.delivery_last_http,o.status,'
            . 'l.status AS ledger_status,'
            . 'l.latest_accepted_sequence FROM '.$this->outbox.' o'
            . ' INNER JOIN '.$this->ledger.' l'
            . ' ON l.source_namespace=o.source_namespace'
            . ' AND l.shop_id=o.shop_id'
            . ' AND l.entity_type=o.entity_type'
            . ' AND l.entity_id=o.entity_id'
            . " WHERE o.accepted_sequence=$sequence"
            . " AND o.source_namespace='".$this->namespace."'"
            . " AND o.shop_id=$shop AND o.entity_type='$type'"
            . " AND o.entity_id=$id LIMIT 2";
        $rows=$this->db->executeS($sql,true,false);
        if (!is_array($rows) || count($rows)>1) {
            throw new RuntimeException('g7_native_lease_inspect_failed');
        }
        if (!$rows) {
            return self::report('not_found',null,0,null,false);
        }
        $r=$rows[0];
        if ($r['status']!=='blocked' || $r['ledger_status']!=='blocked'
            || !in_array($r['delivery_state'],[
                'blocked','ready','claimed','reconcile','held','cancelled'
            ],true)) {
            throw new RuntimeException('g7_native_lease_guard_drift');
        }
        $attempts=self::unsigned($r['delivery_attempts']);
        $latest=self::unsigned($r['latest_accepted_sequence']);
        if ($attempts===null || $latest===null) {
            throw new RuntimeException('g7_native_lease_value_drift');
        }
        $http=$r['delivery_last_http'];
        if ($http!==null) {
            $http=self::unsigned($http);
            if ($http===null || $http>599) {
                throw new RuntimeException('g7_native_lease_http_drift');
            }
        }
        return self::report(
            $r['delivery_state'],$http,$attempts,$latest=== $sequence,
            $r['lease_until_epoch']!==null
                && (int)$r['lease_until_epoch']>time()
        );
    }

    /**
     * Atomic CAS claim: only a separately authorized future producer could
     * set READY. This class never does that. An outdated ledger revision,
     * opt-out cancellation, prior attempt limit or second claimant is denied.
     * Claim does NOT authorize Salesforce IO.
     */
    public function claim($shop,$type,$id,$sequence,$token,$ttl)
    {
        $this->scope($shop,$type,$id,$sequence);
        self::token($token);
        if (!is_int($ttl) || $ttl<30 || $ttl>900) {
            throw new RuntimeException('g7_native_lease_ttl_invalid');
        }
        $this->guard();
        $sql='UPDATE '.$this->outbox.' o INNER JOIN '.$this->ledger.' l'
            . ' ON l.source_namespace=o.source_namespace'
            . ' AND l.shop_id=o.shop_id'
            . ' AND l.entity_type=o.entity_type'
            . ' AND l.entity_id=o.entity_id'
            . " SET o.delivery_state='claimed',"
            . "o.lease_token_hash='".hash('sha256',$token)."',"
            . "o.lease_until_epoch=UNIX_TIMESTAMP()+$ttl,"
            . 'o.delivery_attempts=o.delivery_attempts+1'
            . " WHERE o.accepted_sequence=$sequence"
            . " AND o.source_namespace='".$this->namespace."'"
            . " AND o.shop_id=$shop AND o.entity_type='$type'"
            . " AND o.entity_id=$id AND o.status='blocked'"
            . " AND l.status='blocked'"
            . ' AND l.latest_accepted_sequence=o.accepted_sequence'
            . " AND o.delivery_state='ready' AND o.delivery_attempts<3";
        return $this->changedOnce($sql);
    }

    /**
     * A negative signal durably cancels a current row. This cannot retract
     * remote effects if a separate future transport had already started.
     */
    public function cancel($shop,$type,$id,$sequence)
    {
        $this->scope($shop,$type,$id,$sequence);
        $this->guard();
        $sql='UPDATE '.$this->outbox
            . " SET delivery_state='cancelled',lease_token_hash=NULL,"
            . 'lease_until_epoch=NULL'
            . " WHERE accepted_sequence=$sequence"
            . " AND source_namespace='".$this->namespace."'"
            . " AND shop_id=$shop AND entity_type='$type'"
            . " AND entity_id=$id AND status='blocked'"
            . " AND delivery_state IN ('blocked','ready','claimed',"
            . "'reconcile','held')";
        return $this->changedOnce($sql);
    }

    /** HTTP 202 is acceptance without destination verification. */
    public function markAcceptedUnconfirmed(
        $shop,$type,$id,$sequence,$token
    ) {
        return $this->endLease(
            $shop,$type,$id,$sequence,$token,'reconcile',202
        );
    }

    /** Unknown transport outcome: quarantine, NEVER automatic retry. */
    public function markUnknownUnconfirmed(
        $shop,$type,$id,$sequence,$token
    ) {
        return $this->endLease(
            $shop,$type,$id,$sequence,$token,'reconcile',null
        );
    }

    /** Known non-202 result: manual review, not a retry queue. */
    public function holdClaim(
        $shop,$type,$id,$sequence,$token,$httpStatus
    ) {
        if (!is_int($httpStatus) || $httpStatus<100
            || $httpStatus>599 || $httpStatus===202) {
            throw new RuntimeException('g7_native_lease_http_invalid');
        }
        return $this->endLease(
            $shop,$type,$id,$sequence,$token,'held',$httpStatus
        );
    }

    /** Expired claim -> reconcile (never READY again). */
    public function quarantineExpired($shop,$type,$id,$sequence)
    {
        $this->scope($shop,$type,$id,$sequence);
        $this->guard();
        $sql='UPDATE '.$this->outbox
            . " SET delivery_state='reconcile',lease_token_hash=NULL,"
            . 'lease_until_epoch=NULL'
            . " WHERE accepted_sequence=$sequence"
            . " AND source_namespace='".$this->namespace."'"
            . " AND shop_id=$shop AND entity_type='$type'"
            . " AND entity_id=$id AND delivery_state='claimed'"
            . ' AND lease_until_epoch<=UNIX_TIMESTAMP()';
        return $this->changedOnce($sql);
    }

    private function endLease(
        $shop,$type,$id,$sequence,$token,$state,$http
    ) {
        $this->scope($shop,$type,$id,$sequence);
        self::token($token);
        $this->guard();
        $httpSql=$http===null?'NULL':(string)$http;
        $sql='UPDATE '.$this->outbox
            . " SET delivery_state='$state',delivery_last_http=$httpSql,"
            . 'lease_token_hash=NULL,lease_until_epoch=NULL'
            . " WHERE accepted_sequence=$sequence"
            . " AND source_namespace='".$this->namespace."'"
            . " AND shop_id=$shop AND entity_type='$type'"
            . " AND entity_id=$id AND delivery_state='claimed'"
            . " AND lease_token_hash='".hash('sha256',$token)."'"
            . ' AND lease_until_epoch>UNIX_TIMESTAMP()';
        return $this->changedOnce($sql);
    }

    /**
     * Execute then query ROW_COUNT on the SAME PrestaShop Db connection,
     * with cache disabled and NO intervening SELECT. Never log SQL.
     */
    private function changedOnce($sql)
    {
        if (!$this->db->execute($sql)) {
            throw new RuntimeException('g7_native_lease_write_failed');
        }
        $count=$this->db->getValue('SELECT ROW_COUNT()',false);
        if (!is_numeric($count) || !in_array((string)$count,['0','1'],true)) {
            throw new RuntimeException('g7_native_lease_affected_rows_invalid');
        }
        return (string)$count==='1';
    }

    /** Recheck Test, capture OFF, owner identity and extended G3 inventory. */
    private function guard()
    {
        if (!class_exists('Configuration')
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$this->merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$this->namespace
            || !preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',$this->namespace)) {
            throw new RuntimeException('g7_native_lease_stage_gate_denied');
        }
        $inspected=GrowthG7StagingDeliveryMigration::inspect(
            $this->db,$this->version,$this->merchant,$this->namespace,$this->prefix
        );
        if ($inspected['status']!=='extended_g7_verified') {
            throw new RuntimeException('g7_native_lease_extended_schema_required');
        }
    }

    private function scope($shop,$type,$id,$sequence)
    {
        if (!is_int($shop) || $shop<1
            || !in_array($type,['customer','order'],true)
            || !is_int($id) || $id<1
            || !is_int($sequence) || $sequence<1) {
            throw new RuntimeException('g7_native_lease_scope_invalid');
        }
    }

    private static function token($token)
    {
        if (!is_string($token)
            || !preg_match('/^[a-f0-9]{64}$/D',$token)) {
            throw new RuntimeException('g7_native_lease_token_invalid');
        }
    }

    private static function unsigned($value)
    {
        if (is_int($value)) return $value>=0?$value:null;
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D',$value)) {
            $n=(int)$value;
            return $n>=0 && (string)$n===$value?$n:null;
        }
        return null;
    }

    private static function quote($identifier)
    {
        if (!is_string($identifier)
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$identifier)) {
            throw new RuntimeException('g7_native_lease_table_invalid');
        }
        return chr(96).$identifier.chr(96);
    }

    private static function report($state,$http,$attempts,$isLatest,$active)
    {
        return [
            'status'=>'staging_local_lease_only',
            'state'=>$state,
            'attempts'=>$attempts,
            'last_http'=>$http,
            'ledger_current'=>$isLatest,
            'lease_active'=>$active,
            'record_delivered_certified'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'http_request_performed'=>false,
        ];
    }
}
