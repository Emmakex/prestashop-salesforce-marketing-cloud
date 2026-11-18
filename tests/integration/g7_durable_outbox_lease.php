<?php
/**
 * G7 durable lease lifecycle against the EXISTING G3 outbox, isolated CI DB.
 * No second queue, no live PrestaShop tables, no external request or PII.
 *
 * The extra candidate columns are added temporarily to the disposable G3
 * table and removed before G3's original empty-only rollback. This is NOT
 * a merchant upgrade or migration; it is only a proof of the worker lease.
 */
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/Growth/GrowthOutboxDeliveryLease.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/Growth/GrowthBlockedDispatchRehearsal.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/GrowthNativeCustomerOrderDraft.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/DataCloud/GrowthCapturedEventPstPreview.php';
require_once dirname(__DIR__,2)
    .'/pssalesforcebridge/src/Growth/GrowthFreshSourceBlockedRehearsal.php';
require_once __DIR__.'/G3GrowthOutboxSandboxMigration.php';
require_once __DIR__.'/G3GrowthOutboxSandboxStore.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOutboxDeliveryLease;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthBlockedDispatchRehearsal as Rehearsal;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthFreshSourceBlockedRehearsal as Fresh;

function g7LeaseAssert($yes,$code)
{
    if (!$yes) {throw new RuntimeException('g7_ci_lease_'.$code);}
}
function g7LeaseDb()
{
    g7LeaseAssert(getenv('G3_CI_ENABLE')==='yes_sandbox_only'
        && getenv('G7_CI_LEASE_ENABLE')==='yes_synthetic_only'
        && getenv('G3_CI_USER')==='g3_ci_user'
        && getenv('G3_CI_PASSWORD')==='g3_ci_pass_only',
        'environment_denied');
    $db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4',
        'g3_ci_user','g3_ci_pass_only',[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    new GrowthOutboxDeliveryLease($db);
    return $db;
}
function g7LeaseAdd(PDO $db,$entityId,$hashChar,$type='customer',$shop=1)
{
    return StagedAtomicUtcAcceptance::accept(
        'growth_g7_lease_ci',$type==='customer'?'CUSTOMER_UPDATED':'ORDER_UPDATED',
        $type,$shop,$entityId,
        $type.':'.$shop.':'.$entityId.':'.str_repeat($hashChar,40),
        [new G3GrowthOutboxSandboxStore($db),'insertOrLoad']
    )['accepted_sequence'];
}
function g7LeaseReady(PDO $db,$sequence)
{
    // NEVER run outside CI: the module has no path for this transition.
    $q=$db->prepare(
        "UPDATE g3_growth_outbox_v1 SET delivery_state='ready' "
        . "WHERE accepted_sequence=? AND delivery_state='blocked'"
    );
    $q->execute([$sequence]);
    g7LeaseAssert($q->rowCount()===1,'fixture_not_ready');
}
function g7LeaseRace($sequence)
{
    $processes=[];
    for($i=0;$i<4;$i++){
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__)
            .' claim-worker '.(int)$sequence.' '.str_repeat(dechex($i+1),64);
        $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        g7LeaseAssert(is_resource($p),'worker_not_started');
        fclose($pipes[0]);
        $processes[]=[$p,$pipes];
    }
    $success=0;
    foreach($processes as $entry) {
        $out=trim(stream_get_contents($entry[1][1]));
        stream_get_contents($entry[1][2]); // no sensitive SQL/exceptions to stdout
        fclose($entry[1][1]);
        fclose($entry[1][2]);
        g7LeaseAssert(proc_close($entry[0])===0,'worker_failed');
        g7LeaseAssert(in_array($out,['0','1'],true),'worker_output_invalid');
        $success+=(int)$out;
    }
    g7LeaseAssert($success===1,'concurrent_double_claim');
}
function g7FreshReader(PDO $db,$sequence,$id,$customer,$changeOnSecond=null)
{
    $reads=0;
    return function()use($db,$sequence,$id,$customer,$changeOnSecond,&$reads) {
        $reads++;
        $q=$db->prepare("SELECT accepted_sequence,source_namespace,shop_id,"
            . "entity_type,entity_id,original_event_type,status "
            . "FROM g3_growth_outbox_v1 WHERE accepted_sequence=?");
        $q->execute([$sequence]);
        $event=$q->fetch(PDO::FETCH_ASSOC);
        $q=$db->prepare("SELECT source_namespace,shop_id,entity_type,entity_id,"
            . "latest_accepted_sequence,status FROM g3_growth_entity_ledger_v1 "
            . "WHERE source_namespace=? AND shop_id=? "
            . "AND entity_type='customer' AND entity_id=?");
        $q->execute(['growth_g7_lease_ci',1,$id]);
        $ledger=$q->fetch(PDO::FETCH_ASSOC);
        $current=clone $customer;
        if ($reads===2 && is_callable($changeOnSecond)) {
            $changeOnSecond($current,$ledger);
        }
        return [
            'event'=>$event,'ledger'=>$ledger,'customer'=>$current,
            'order'=>null,'currency'=>null
        ];
    };
}

function g7LeaseTest()
{
    $db=g7LeaseDb();
    $migration=new G3GrowthOutboxSandboxMigration($db);
    g7LeaseAssert($migration->install()===true,'migration_not_clean');
    // Candidate columns on the SAME outbox. The original CHECK status =
    // blocked and the G3 ledger constraints are untouched throughout.
    $db->exec("ALTER TABLE g3_growth_outbox_v1 "
        . "ADD COLUMN delivery_state VARCHAR(16) NOT NULL DEFAULT 'blocked',"
        . "ADD COLUMN lease_token_hash CHAR(64) CHARACTER SET ascii "
        . "COLLATE ascii_bin NULL,"
        . "ADD COLUMN lease_until_epoch BIGINT UNSIGNED NULL,"
        . "ADD COLUMN delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,"
        . "ADD COLUMN delivery_last_http SMALLINT UNSIGNED NULL,"
        . "ADD KEY idx_g7_lease_state (delivery_state,lease_until_epoch)"
    );
    $ns='growth_g7_lease_ci';
    $store=new GrowthOutboxDeliveryLease($db);
    $token=str_repeat('a',64);
    $customer=g7LeaseAdd($db,700,'a');
    g7LeaseAssert(!$store->claim($ns,1,'customer',700,$customer,$token,60),
        'blocked_claimed');
    g7LeaseReady($db,$customer);
    g7LeaseAssert($store->claim($ns,1,'customer',700,$customer,$token,60),
        'ready_not_claimed');
    g7LeaseAssert(!$store->claim($ns,1,'customer',700,$customer,
        str_repeat('b',64),60),'double_claim');
    g7LeaseAssert(!$store->markAcceptedUnconfirmed(
        $ns,1,'customer',700,$customer,str_repeat('c',64)),
        'wrong_token_accepted');
    $s=$store->inspect($ns,1,'customer',700,$customer);
    g7LeaseAssert($s['state']==='claimed' && $s['attempts']===1
        && $s['lease_active']===true && !$s['delivery_authorized'],
        'claim_not_durable');
    g7LeaseAssert($store->markAcceptedUnconfirmed(
        $ns,1,'customer',700,$customer,$token),'202_not_reconciled');
    $s=$store->inspect($ns,1,'customer',700,$customer);
    g7LeaseAssert($s['state']==='reconcile' && $s['last_http']===202
        && !$s['record_delivered_certified'] && !$s['lease_active'],
        '202_overstated_delivery');
    g7LeaseAssert(!$store->claim($ns,1,'customer',700,$customer,$token,60),
        '202_replayed');

    $cancel=g7LeaseAdd($db,701,'b');
    g7LeaseReady($db,$cancel);
    g7LeaseAssert($store->cancel($ns,1,'customer',701,$cancel),
        'cancel_not_persisted');
    g7LeaseAssert(!$store->claim($ns,1,'customer',701,$cancel,$token,60),
        'cancelled_claimed');
    $inFlight=g7LeaseAdd($db,702,'c');
    g7LeaseReady($db,$inFlight);
    g7LeaseAssert($store->claim($ns,1,'customer',702,$inFlight,$token,60),
        'inflight_not_claimed');
    g7LeaseAssert($store->cancel($ns,1,'customer',702,$inFlight),
        'inflight_cancel_denied');
    g7LeaseAssert(!$store->markAcceptedUnconfirmed(
        $ns,1,'customer',702,$inFlight,$token),'cancelled_marked_received');

    $expiry=g7LeaseAdd($db,703,'d');
    g7LeaseReady($db,$expiry);
    g7LeaseAssert($store->claim($ns,1,'customer',703,$expiry,$token,60),
        'expirable_not_claimed');
    $q=$db->prepare("UPDATE g3_growth_outbox_v1 "
        . "SET lease_until_epoch=UNIX_TIMESTAMP()-1 WHERE accepted_sequence=?");
    $q->execute([$expiry]);
    g7LeaseAssert($store->quarantineExpired($ns,1,'customer',703,$expiry),
        'expiry_not_quarantined');
    g7LeaseAssert($store->inspect($ns,1,'customer',703,$expiry)['state']
        ==='reconcile','expiry_not_reconciled');
    g7LeaseAssert(!$store->claim($ns,1,'customer',703,$expiry,$token,60),
        'expired_retried');

    $stale=g7LeaseAdd($db,704,'e');
    g7LeaseReady($db,$stale);
    $fresh=g7LeaseAdd($db,704,'f');
    g7LeaseReady($db,$fresh);
    g7LeaseAssert(!$store->claim($ns,1,'customer',704,$stale,$token,60),
        'older_revision_claimed');
    g7LeaseAssert($store->claim($ns,1,'customer',704,$fresh,$token,60),
        'current_revision_not_claimed');
    g7LeaseAssert($store->holdClaim($ns,1,'customer',704,$fresh,$token,503),
        'failed_to_hold');
    g7LeaseAssert($store->inspect($ns,1,'customer',704,$fresh)['state']
        ==='held','held_not_durable');
    g7LeaseAssert(!$store->claim($ns,1,'customer',704,$fresh,$token,60),
        'error_retry_without_reconciliation');
    $otherShop=g7LeaseAdd($db,700,'1','order',2);
    g7LeaseReady($db,$otherShop);
    g7LeaseAssert(!$store->claim($ns,1,'order',700,$otherShop,$token,60),
        'other_shop_claimed');

    $race=g7LeaseAdd($db,705,'0');
    g7LeaseReady($db,$race);
    g7LeaseRace($race);
    g7LeaseAssert($store->inspect($ns,1,'customer',705,$race)['attempts']===1,
        'concurrent_attempt_counter_invalid');


    // A complete synthetic worker rehearsal, using only the existing G3
    // outbox/ledger and a simulated HTTP result. No HTTP client or payload.
    $blocked=g7LeaseAdd($db,706,'2');
    $denied=Rehearsal::simulate(
        $store,$ns,1,'customer',706,$blocked,$token,true,202
    );
    g7LeaseAssert($denied['state']==='claim_denied'
        && !$denied['lease_claimed']
        && !$denied['http_request_performed'],
        'blocked_outbox_must_not_rehearse');

    $accepted=g7LeaseAdd($db,707,'3');
    g7LeaseReady($db,$accepted);
    $receipt=Rehearsal::simulate(
        $store,$ns,1,'customer',707,$accepted,$token,true,202
    );
    $inspect=$store->inspect($ns,1,'customer',707,$accepted);
    g7LeaseAssert($receipt['state']==='reconcile'
        && $receipt['outcome_state']==='accepted_asynchronously_unconfirmed'
        && $inspect['state']==='reconcile'
        && $inspect['last_http']===202
        && !$receipt['record_persisted_certified']
        && !$receipt['outbox_delivery_authorized']
        && !$store->claim($ns,1,'customer',707,$accepted,$token,60),
        '202_rehearsal_overstated_completion');

    $timeout=g7LeaseAdd($db,708,'4');
    g7LeaseReady($db,$timeout);
    $lost=Rehearsal::simulate(
        $store,$ns,1,'customer',708,$timeout,$token,false,null
    );
    $inspect=$store->inspect($ns,1,'customer',708,$timeout);
    g7LeaseAssert($lost['state']==='reconcile'
        && $lost['outcome_state']==='unknown_after_transport'
        && $inspect['state']==='reconcile'
        && $inspect['last_http']===null
        && !$inspect['lease_active']
        && !$store->claim($ns,1,'customer',708,$timeout,$token,60),
        'timeout_not_immediately_quarantined');

    foreach ([[709,'5',503],[710,'6',200],[711,'7',401]] as $test) {
        $seq=g7LeaseAdd($db,$test[0],$test[1]);
        g7LeaseReady($db,$seq);
        $bad=Rehearsal::simulate(
            $store,$ns,1,'customer',$test[0],$seq,$token,true,$test[2]
        );
        $inspect=$store->inspect($ns,1,'customer',$test[0],$seq);
        g7LeaseAssert($bad['state']==='held'
            && $inspect['state']==='held'
            && $inspect['last_http']===$test[2]
            && !$bad['automatic_retry_authorized']
            && !$bad['marketing_sends_authorized'],
            'non202_rehearsal_was_delivered');
    }

    $preCancelled=g7LeaseAdd($db,712,'8');
    g7LeaseReady($db,$preCancelled);
    g7LeaseAssert($store->cancel($ns,1,'customer',712,$preCancelled),
        'rehearsal_cancel_setup_failed');
    $skip=Rehearsal::simulate(
        $store,$ns,1,'customer',712,$preCancelled,$token,true,202
    );
    g7LeaseAssert($skip['state']==='claim_denied'
        && !$skip['lease_claimed'],'cancelled_rehearsal_claimed');

    $late=g7LeaseAdd($db,713,'9');
    g7LeaseReady($db,$late);
    g7LeaseAssert($store->claim($ns,1,'customer',713,$late,$token,60),
        'late_cancel_not_claimed');
    g7LeaseAssert($store->cancel($ns,1,'customer',713,$late),
        'late_cancel_not_cancelled');
    g7LeaseAssert(!$store->markUnknownUnconfirmed(
        $ns,1,'customer',713,$late,$token),
        'late_cancel_unknown_revived');

    $invalid=g7LeaseAdd($db,714,'a');
    g7LeaseReady($db,$invalid);
    $badInputRejected=false;
    try {
        Rehearsal::simulate(
            $store,$ns,1,'customer',714,$invalid,$token,false,202
        );
    } catch (RuntimeException $e) {
        $badInputRejected=$e->getMessage()
            ==='g7_rehearsal_simulated_outcome_invalid';
    }
    g7LeaseAssert($badInputRejected
        && $store->inspect($ns,1,'customer',714,$invalid)['state']==='ready',
        'invalid_fixture_claimed');

    // Fresh-native-source + current-ledger + *same* G3 lease rehearsal.
    // Both passes re-read real CI G3 rows and rebuild PSCustomer PST in
    // memory. No HTTP endpoint is called and no PII can leave the report.
    $origin='https://credencial.c360a.salesforce.com';
    $sourceName='ABH_PrestaShop_Data_Cloud';
    $freshCustomer=(object)[
        'id'=>715,'id_shop'=>1,'email'=>'PRIVATE_CANARY@example.invalid',
        'firstname'=>'PRIVATE_FIRST','lastname'=>'PRIVATE_LAST',
        'newsletter'=>1,'optin'=>0,'active'=>1,'deleted'=>0,'is_guest'=>0,
        'date_upd'=>'2026-11-18 10:00:00'
    ];
    $stable=g7LeaseAdd($db,715,'c');
    $stableReader=g7FreshReader($db,$stable,715,$freshCustomer);
    $blockedFresh=Fresh::simulate(
        $store,$ns,1,'customer',715,$stable,$token,
        $origin,$sourceName,$stableReader,true,202
    );
    g7LeaseAssert($blockedFresh['state']==='claim_denied'
        && !$blockedFresh['http_request_performed']
        && !$blockedFresh['outbound_worker_authorized'],
        'fresh_rehearsal_claimed_blocked_row');
    g7LeaseReady($db,$stable);
    $received=Fresh::simulate(
        $store,$ns,1,'customer',715,$stable,$token,
        $origin,$sourceName,g7FreshReader($db,$stable,715,$freshCustomer),
        true,202
    );
    $st=$store->inspect($ns,1,'customer',715,$stable);
    g7LeaseAssert($received['state']==='reconcile'
        && $received['fresh_source_checked_after_claim']
        && $received['simulated_outcome_state']
            ==='accepted_asynchronously_unconfirmed'
        && $st['last_http']===202 && $st['state']==='reconcile'
        && !$received['record_persisted_certified']
        && !$received['source_race_until_remote_io_closed']
        && !$received['marketing_sends_authorized']
        && !$store->claim($ns,1,'customer',715,$stable,$token,60),
        'fresh_rehearsal_202_was_delivered_or_retried');

    $optOut=g7LeaseAdd($db,716,'d');
    g7LeaseReady($db,$optOut);
    $negative=clone $freshCustomer;
    $negative->id=716;
    $negative->newsletter=0;
    $notAllowed=Fresh::simulate(
        $store,$ns,1,'customer',716,$optOut,$token,
        $origin,$sourceName,g7FreshReader($db,$optOut,716,$negative),
        true,202
    );
    g7LeaseAssert($notAllowed['state']==='source_hold_before_claim'
        && !$notAllowed['lease_claimed']
        && $store->inspect($ns,1,'customer',716,$optOut)['attempts']===0,
        'fresh_negative_newsletter_claimed');

    $changed=g7LeaseAdd($db,717,'e');
    g7LeaseReady($db,$changed);
    $becomesNegative=clone $freshCustomer;
    $becomesNegative->id=717;
    $changedReader=g7FreshReader(
        $db,$changed,717,$becomesNegative,
        function($current,&$ledger) { $current->active=0; }
    );
    $cancelled=Fresh::simulate(
        $store,$ns,1,'customer',717,$changed,$token,
        $origin,$sourceName,$changedReader,true,202
    );
    g7LeaseAssert($cancelled['state']==='source_changed_cancelled'
        && $cancelled['lease_claimed']
        && $store->inspect($ns,1,'customer',717,$changed)['state']
            ==='cancelled'
        && !$store->claim($ns,1,'customer',717,$changed,$token,60),
        'fresh_customer_deactivated_did_not_cancel');

    $modified=g7LeaseAdd($db,718,'f');
    g7LeaseReady($db,$modified);
    $dateChanged=clone $freshCustomer;
    $dateChanged->id=718;
    $dateReader=g7FreshReader(
        $db,$modified,718,$dateChanged,
        function($current,&$ledger) {
            $current->date_upd='2026-11-18 10:00:01';
        }
    );
    $newVersion=Fresh::simulate(
        $store,$ns,1,'customer',718,$modified,$token,
        $origin,$sourceName,$dateReader,false,null
    );
    g7LeaseAssert($newVersion['state']==='source_changed_cancelled'
        && $store->inspect($ns,1,'customer',718,$modified)['state']
            ==='cancelled',
        'fresh_source_mutation_did_not_cancel');

    $unknown=g7LeaseAdd($db,719,'1');
    g7LeaseReady($db,$unknown);
    $timeoutCustomer=clone $freshCustomer;
    $timeoutCustomer->id=719;
    $timeoutReport=Fresh::simulate(
        $store,$ns,1,'customer',719,$unknown,$token,
        $origin,$sourceName,g7FreshReader($db,$unknown,719,$timeoutCustomer),
        false,null
    );
    g7LeaseAssert($timeoutReport['state']==='reconcile'
        && $timeoutReport['simulated_outcome_state']
            ==='unknown_after_transport'
        && $store->inspect($ns,1,'customer',719,$unknown)['state']
            ==='reconcile',
        'fresh_timeout_not_quarantined');

    $serialized=json_encode([
        $blockedFresh,$received,$notAllowed,$cancelled,$newVersion,
        $timeoutReport
    ]);
    foreach([
        'PRIVATE_CANARY','PRIVATE_FIRST','PRIVATE_LAST',
        'example.invalid','growth_g7_lease_ci','credencial.c360a',
        'ABH_PrestaShop_Data_Cloud','715','717'
    ] as $secret) {
        g7LeaseAssert(strpos($serialized,$secret)===false,
            'fresh_rehearsal_leaked_private_data');
    }

    $safeReport=json_encode([$denied,$receipt,$lost,$bad,$skip]);
    g7LeaseAssert(is_string($safeReport)
        && strpos($safeReport,$token)===false
        && strpos($safeReport,$ns)===false
        && strpos($safeReport,'source_key')===false,
        'rehearsal_leaks_lease_or_namespace');

    $count=(int)$db->query(
        "SELECT COUNT(*) FROM g3_growth_outbox_v1 WHERE status <> 'blocked'"
    )->fetchColumn();
    g7LeaseAssert($count===0,'original_blocked_constraint_bypassed');
    // Candidate claim statuses never change the original ledger status.
    $count=(int)$db->query(
        "SELECT COUNT(*) FROM g3_growth_entity_ledger_v1 "
        . "WHERE status <> 'blocked'"
    )->fetchColumn();
    g7LeaseAssert($count===0,'ledger_constraint_bypassed');

    // Test-only cleanup after verifying every table belongs to g3_ci.
    $db->exec("DELETE FROM g3_growth_entity_ledger_v1 "
        . "WHERE source_namespace='growth_g7_lease_ci'");
    $db->exec("DELETE FROM g3_growth_outbox_v1 "
        . "WHERE source_namespace='growth_g7_lease_ci'");
    $db->exec("ALTER TABLE g3_growth_outbox_v1 "
        . "DROP KEY idx_g7_lease_state,"
        . "DROP COLUMN delivery_last_http,"
        . "DROP COLUMN delivery_attempts,"
        . "DROP COLUMN lease_until_epoch,"
        . "DROP COLUMN lease_token_hash,"
        . "DROP COLUMN delivery_state"
    );
    $migration->assertSchema();
    g7LeaseAssert($migration->rollbackIfEmpty()===true,
        'original_schema_rollback_failed');
}

try{
    if (isset($argv[1]) && $argv[1]==='claim-worker') {
        if (!isset($argv[2],$argv[3])
            || !ctype_digit($argv[2])) {exit(2);}
        $lease=new GrowthOutboxDeliveryLease(g7LeaseDb());
        echo $lease->claim('growth_g7_lease_ci',1,'customer',705,
            (int)$argv[2],$argv[3],60)?'1':'0';
        exit(0);
    }
    g7LeaseTest();
    echo "G7 durable lease PASS: original outbox, 4 worker race, simulated 202/503/200/timeout/cancellation, immediate quarantine, rollback, no sends.\n";
}catch(Throwable $e){
    // Safe diagnostic only. Never print SQL, exceptions, scope IDs or tokens.
    $code=(string)$e->getMessage();
    if (!preg_match('/^g7_ci_lease_[a-z0-9_]{1,80}$/D',$code)
        && !preg_match('/^g7_lease_[a-z0-9_]{1,80}$/D',$code)) {
        $code=($e instanceof PDOException) ? 'g7_ci_lease_database_error'
            : 'g7_ci_lease_unclassified_failure';
    }
    fwrite(STDERR,"G7 durable lease CI safety check failed: ".$code."\n");
    exit(1);
}
