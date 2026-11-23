<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * Internal G8 callbacks for the SAME G3 outbox/ledger CAS and durable receipt.
 *
 * No HTTP, READY producer, CRM writer, campaign sender or cron operation.
 * The caller MUST separately certify fresh native data, exact DLO absence,
 * consent and the final send-time fence. This bridge is NOT that permission.
 *
 * A successful claim is irreversible: failed/unknown receipts remain claimed
 * until the existing lease quarantine runs; neither case can be re-claimed.
 */
final class GrowthG8DurableReceiptBridge
{
    const DEFAULT_TTL=120;
    private $lease;
    private $scope;
    private $ttl;
    private $claims=[];

    public function __construct($lease,array $scope,$ttl=self::DEFAULT_TTL)
    {
        if (!is_object($lease)
            || !is_callable([$lease,'inspect'])
            || !is_callable([$lease,'claim'])
            || !is_callable([$lease,'markAcceptedUnconfirmed'])
            || !is_callable([$lease,'markUnknownUnconfirmed'])
            || !is_callable([$lease,'holdClaim'])
            || !is_callable([$lease,'cancel'])
            || !is_int($ttl) || $ttl<30 || $ttl>900
            || !self::scopeOk($scope)) {
            throw new \RuntimeException('g8_durable_bridge_scope_denied');
        }
        $this->lease=$lease;
        $this->scope=$scope;
        $this->ttl=$ttl;
    }

    /**
     * CAS claim of ONE current, synthetic or owner-approved staging event.
     * This never sets READY. An existing BLOCKED event is denied.
     */
    public function reserveOnce($candidate,$fingerprint)
    {
        try {
            if (!self::scopeOk($this->scope)
                || !is_string($fingerprint)
                || preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1) {
                return 'previous_attempt';
            }
            $ref=$this->reference($candidate);
            if ($ref===null || isset($this->claims[$ref['key']])) {
                return 'previous_attempt';
            }
            $before=$this->lease->inspect(
                $ref['shop'],$ref['type'],$ref['id'],$ref['sequence']
            );
            if (!self::beforeClaim($before)) {
                return 'previous_attempt';
            }
            // A 256-bit unpredictable token, retained ONLY in memory.
            $token=bin2hex(random_bytes(32));
            if ($this->lease->claim(
                $ref['shop'],$ref['type'],$ref['id'],$ref['sequence'],
                $token,$this->ttl
            )!==true) {
                return 'previous_attempt';
            }
            // Store token immediately after CAS: a later read failure must
            // NEVER allow a second reserve/POST in this process.
            $this->claims[$ref['key']]=[
                'token'=>$token,'fingerprint'=>$fingerprint,
                'finalized'=>false
            ];
            return 'reserved_once';
        } catch (Throwable $ignored) {
            return 'previous_attempt';
        }
    }

    /**
     * Local fence for the injected transport callback. It is NOT contact
     * consent or permission to transmit. Caller must separately verify CSC
     * at send time and all native data after the CAS.
     */
    public function claimedForPost($candidate,$fingerprint)
    {
        try {
            if (!self::scopeOk($this->scope)
                || !is_string($fingerprint)) {
                return false;
            }
            $ref=$this->reference($candidate);
            if ($ref===null || !isset($this->claims[$ref['key']])
                || $this->claims[$ref['key']]['finalized']
                || !hash_equals(
                    $this->claims[$ref['key']]['fingerprint'],
                    $fingerprint
                )) {
                return false;
            }
            $state=$this->lease->inspect(
                $ref['shop'],$ref['type'],$ref['id'],$ref['sequence']
            );
            return self::afterClaim($state);
        } catch (Throwable $ignored) {
            return false;
        }
    }

    /**
     * Coordinator callback:
     * persistOutcome($candidate,$state,$httpStatus,$transportStarted).
     *
     * - 202 after transport -> reconcile(202), never delivered.
     * - transport timeout/ambiguous status -> reconcile(NULL).
     * - known non-202 HTTP -> held(status).
     * - local exclusion/changed source BEFORE transport -> cancelled.
     * No retry queue, no second claim, no token or PII in return value.
     */
    public function persistOutcome(
        $candidate,$state,$httpStatus=null,$transportStarted=false
    ) {
        $ref=$this->reference($candidate);
        if ($ref===null || !isset($this->claims[$ref['key']])
            || $this->claims[$ref['key']]['finalized']) {
            return false;
        }
        // Consume the in-process receipt slot BEFORE any storage call:
        // a storage error cannot trigger automatic receipt replays.
        $this->claims[$ref['key']]['finalized']=true;
        try {
            if (!in_array($state,['held','reconcile'],true)
                || !is_bool($transportStarted)) {
                return false;
            }
            $token=$this->claims[$ref['key']]['token'];
            $args=[
                $ref['shop'],$ref['type'],$ref['id'],$ref['sequence'],$token
            ];
            if (!$transportStarted && $state==='held'
                && $httpStatus===null) {
                return $this->lease->cancel(
                    $ref['shop'],$ref['type'],$ref['id'],$ref['sequence']
                )===true;
            }
            if ($transportStarted
                && $state==='reconcile' && $httpStatus===202) {
                return call_user_func_array(
                    [$this->lease,'markAcceptedUnconfirmed'],$args
                )===true;
            }
            if ($transportStarted && $state==='held'
                && is_int($httpStatus) && $httpStatus>=100
                && $httpStatus<=599 && $httpStatus!==202) {
                $args[]=$httpStatus;
                return call_user_func_array(
                    [$this->lease,'holdClaim'],$args
                )===true;
            }
            // All other outcomes are uncertain, including missing HTTP,
            // invalid response, failed post callback and post-send drift.
            return call_user_func_array(
                [$this->lease,'markUnknownUnconfirmed'],$args
            )===true;
        } catch (Throwable $ignored) {
            return false;
        }
    }

    private static function scopeOk($s)
    {
        if (!is_array($s)
            || !isset($s['shop'],$s['merchant'],$s['namespace'],
                $s['mode'],$s['capture'],$s['owner_gate'])
            || !is_int($s['shop']) || $s['shop']<1
            || !GrowthShopSourceBinding::isMerchant($s['merchant'])
            || !GrowthShopSourceBinding::isNamespace($s['namespace'])
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$s['namespace']
            )!==1
            || $s['mode']!=='test' || $s['capture']!=='off'
            || $s['owner_gate']
                !==GrowthAutomaticOneShotCoordinator::OWNER_GATE
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')
                !==$s['merchant']
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')
                !==$s['namespace']
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')
                !=='off') {
            return false;
        }
        $binding=GrowthShopSourceBinding::inspect(
            $s['shop'],$s['merchant'],$s['namespace']
        );
        return isset($binding['ready']) && $binding['ready']===true;
    }

    private function reference($c)
    {
        if (!is_array($c) || count($c)!==4
            || !isset($c['shop'],$c['type'],$c['id'],$c['sequence'])
            || $c['shop']!==$this->scope['shop']
            || !in_array($c['type'],['PSCustomer','PSOrder'],true)
            || !is_int($c['id']) || $c['id']<1
            || !is_int($c['sequence']) || $c['sequence']<1) {
            return null;
        }
        return [
            'shop'=>$c['shop'],
            'type'=>$c['type']==='PSCustomer'?'customer':'order',
            'id'=>$c['id'],'sequence'=>$c['sequence'],
            'key'=>$c['type'].':'.$c['id'].':'.$c['sequence']
        ];
    }

    private static function beforeClaim($r)
    {
        return is_array($r)
            && isset($r['status'],$r['state'],$r['attempts'],
                $r['ledger_current'],$r['lease_active'])
            && $r['status']==='staging_local_lease_only'
            && $r['state']==='ready'
            && $r['attempts']===0
            && $r['ledger_current']===true
            && $r['lease_active']===false
            && array_key_exists('last_http',$r)
            && $r['last_http']===null;
    }

    private static function afterClaim($r)
    {
        return is_array($r)
            && isset($r['status'],$r['state'],$r['attempts'],
                $r['ledger_current'],$r['lease_active'])
            && $r['status']==='staging_local_lease_only'
            && $r['state']==='claimed'
            && $r['attempts']===1
            && $r['ledger_current']===true
            && $r['lease_active']===true;
    }
}
