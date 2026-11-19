<?php
/**
 * Real MySQL8/MariaDB10.11 test against the *same* G3 three-table outbox,
 * using a PrestaShop-compatible Db facade. Disposable g3_ci only.
 * No remote API, no real customers, no second queue, no live shop DDL.
 */
if (getenv('G3_CI_ENABLE')!=='yes_sandbox_only'
    || getenv('G3_CI_DSN')!=='mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4'
    || getenv('G3_CI_USER')!=='g3_ci_user'
    || getenv('G3_CI_PASSWORD')!=='g3_ci_pass_only') {
    fwrite(STDERR,"G7 stage capture CI requires disposable sandbox.\n");
    exit(2);
}
define('_PS_VERSION_','9.1.5');
define('_DB_PREFIX_','ci_');
class Configuration
{
    public static $values = [];
    public static function get($name) {
        return isset(self::$values[$name]) ? self::$values[$name] : false;
    }
    public static function updateValue($name,$value) {
        self::$values[$name]=$value;
        return true;
    }
}
class Db
{
    private static $instance;
    private $pdo;
    public function __construct(PDO $pdo) {$this->pdo=$pdo;}
    public static function getInstance() {return self::$instance;}
    public static function init(PDO $pdo) {self::$instance=new self($pdo);}
    public function getValue($sql,$cache=false) {
        return $this->pdo->query($sql)->fetchColumn();
    }
    public function executeS($sql,$array=true,$cache=false) {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function execute($sql) {
        return $this->pdo->exec($sql)!==false;
    }
    public function Insert_ID() {return $this->pdo->lastInsertId();}
}
$root=dirname(__DIR__,2).'/pssalesforcebridge/src/';
foreach([
    'Provider/ExplicitProviderRouter.php',
    'DataCloud/LiveEventUtcObservation.php',
    'DataCloud/StagedAtomicUtcAcceptance.php',
    'Growth/BlockedOutboxCoordinator.php',
    'Compatibility/CustomerHookAdapter.php',
    'Compatibility/OrderHookAdapter.php',
    'Growth/GrowthBlockedHookAdapter.php',
    'Growth/GrowthOutboxSchemaPlan.php',
    'Growth/GrowthG7DeliverySchemaPlan.php',
    'Growth/GrowthG7StagingDeliveryMigration.php',
    'Growth/GrowthPrestaShopDeliveryLease.php',
    'Growth/GrowthSqlEngineGate.php',
    'Growth/GrowthStagingSchemaBootstrap.php',
    'Growth/GrowthStagingOutboxStore.php',
    'Growth/GrowthStagingHookCapture.php',
    'DataCloud/IngestionPayloadBuilder.php',
    'DataCloud/GrowthTypedPstPayloadBuilder.php',
    'DataCloud/GrowthNativeCustomerOrderDraft.php',
    'Growth/GrowthStagingNativeReadback.php',
    'Growth/GrowthSandboxMigrationExecutor.php'
] as $path)require_once $root.$path;

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture as Capture;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingSchemaBootstrap as Setup;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSandboxMigrationExecutor as SandMigration;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingNativeReadback as Readback;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7StagingDeliveryMigration as G7Migration;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPrestaShopDeliveryLease as NativeLease;

function g7DbCheck($ok,$code){
    if(!$ok)throw new RuntimeException('g7_real_staging_ci_'.$code);
}
$pdo=new PDO(getenv('G3_CI_DSN'),getenv('G3_CI_USER'),getenv('G3_CI_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false
]);
g7DbCheck($pdo->query('SELECT DATABASE()')->fetchColumn()==='g3_ci'
    && strpos((string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn(),
        'g3_ci_user@')===0,'sandbox_binding');
Db::init($pdo);
$db=Db::getInstance();
$ns='growth_g7_stage_ci';
Configuration::updateValue('PS_SFMC_MODE','test');
Configuration::updateValue('PS_GROWTH_MERCHANT','ABH');
Configuration::updateValue('PS_GROWTH_NAMESPACE',$ns);
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
$customer=(object)[
    'id'=>901,'id_shop'=>1,'email'=>'ci-synthetic@example.invalid',
    'firstname'=>'Fixture','lastname'=>'Synthetic','newsletter'=>0,'optin'=>0,
    'active'=>1,'deleted'=>0,'is_guest'=>0,
    'date_upd'=>'2026-11-17 11:30:00'
];
$result=Capture::captureFromShop('actionCustomerAccountAdd',
    ['newCustomer'=>$customer]);
g7DbCheck($result['status']==='local_capture_disabled',
    'default_capture_not_disabled');
$denied=false;
try {
    Setup::install($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,'wrong_confirmation');
} catch(RuntimeException $e) {
    $denied=$e->getMessage()==='g7_capture_setup_not_approved';
}
g7DbCheck($denied,'setup_without_owner_accepted');
$ready=Setup::install($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,Setup::CONFIRM);
g7DbCheck($ready['schema_present'] && !$ready['outbound_authorized'],
    'setup_not_ready');
$again=Setup::install($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,Setup::CONFIRM);
g7DbCheck($again['schema_present'],'idempotent_setup_failed');
Configuration::updateValue('PS_GROWTH_CAPTURE_HMAC_KEY',str_repeat('c',64));
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','staging_armed');

$r1=Capture::captureFromShop('actionCustomerAccountAdd',['newCustomer'=>$customer]);
$r2=Capture::captureFromShop('actionCustomerAccountAdd',['newCustomer'=>$customer]);
$changed=clone $customer;$changed->newsletter=1;
$r3=Capture::captureFromShop('actionCustomerAccountUpdate',['customer'=>$changed]);
$order=(object)[
    'id'=>902,'id_shop'=>1,'id_customer'=>901,'id_cart'=>903,'id_currency'=>1,
    'total_paid'=>'74.00','current_state'=>2,
    'date_upd'=>'2026-11-17 11:32:00'
];
$r4=Capture::captureFromShop('actionValidateOrder',['order'=>$order]);
// Mirror PaymentModule::validateOrder hook before OrderHistory applies state.
$orderPending=clone $order;
$orderPending->id=905;
$orderPending->current_state=null;
$r4Pending=Capture::captureFromShop('actionValidateOrder',
    ['order'=>$orderPending]);
$created=clone $customer;
$created->id=904;
$created->newsletter=null;
$created->optin=null;
$created->date_upd=null;
$r5=Capture::captureFromShop('actionCustomerAccountAdd',['newCustomer'=>$created]);
$r6=Capture::captureFromShop('actionCustomerAccountAdd',['newCustomer'=>$created]);
g7DbCheck($r1['status']==='blocked_local_only' && $r1['inserted']
    && $r2['status']==='blocked_local_only' && !$r2['inserted']
    && $r3['inserted'] && $r4['inserted']
    && $r4Pending['status']==='blocked_local_only' && $r4Pending['inserted']
    && $r5['status']==='blocked_local_only' && $r5['inserted']
    && $r6['status']==='blocked_local_only' && !$r6['inserted'],
    'source_events_not_captured_or_deduped');
$tab='ci_growth_outbox_v1';
$ledger='ci_growth_entity_ledger_v1';
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM `$tab`")===5,
    'unexpected_rows');
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM `$ledger`")===4,
    'ledger_not_unique_by_entity');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE status='blocked'"
)===5,'status_not_blocked');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE entity_id=901"
)===2,'customer_did_not_update');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE entity_id=902 AND entity_type='order'"
)===1,'order_not_recorded');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE entity_id=905 AND entity_type='order'"
)===1,'order_pre_state_not_recorded');
// G7 0.8.5: read actual local outbox + ledger through PrestaShop Db API,
// then use injected native-object loaders (no real PII or external requests).
$createdFresh=clone $created;
$createdFresh->newsletter=0;
$createdFresh->optin=0;
$createdFresh->date_upd='2026-11-18 09:00:00';
$loadCustomer=function($id)use($createdFresh,$customer) {
    if ($id===904) return clone $createdFresh;
    if ($id===901) return clone $customer;
    throw new RuntimeException('fixture_customer_unknown');
};
$loadOrder=function($id)use($orderPending) {
    if ($id!==905) throw new RuntimeException('fixture_order_unknown');
    $loaded=clone $orderPending;
    $loaded->current_state=2;
    return $loaded;
};
$loadCurrency=function($id) {
    if ($id!==1) throw new RuntimeException('fixture_currency_unknown');
    return (object)['id'=>1,'iso_code'=>'EUR'];
};
$customerReport=Readback::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,'customer',
    $loadCustomer,$loadOrder,$loadCurrency
);
$orderReport=Readback::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,'order',
    $loadCustomer,$loadOrder,$loadCurrency
);
g7DbCheck($customerReport['status']==='native_pst_shape_verified_blocked'
    && $customerReport['pst_objects_validated']===1
    && $orderReport['status']==='native_pst_shape_verified_blocked'
    && $orderReport['pst_objects_validated']===2
    && $orderReport['latest_blocked_ledger_observed']===true
    && $orderReport['data_cloud_ingestion_authorized']===false
    && $orderReport['outbound_worker_authorized']===false
    && $orderReport['marketing_sends_authorized']===false,
    'native_readback_not_verified');
$reportSerialized=json_encode([$customerReport,$orderReport]);
g7DbCheck(strpos($reportSerialized,'ci-synthetic@example.invalid')===false
    && strpos($reportSerialized,$ns)===false
    && strpos($reportSerialized,'902')===false
    && strpos($reportSerialized,'905')===false
    && (int)$db->getValue("SELECT COUNT(*) FROM ci_growth_outbox_v1")===5,
    'readback_leaked_source_or_changed_outbox');
$denied=false;
try {
    Readback::inspectLatest($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        'cart',$loadCustomer,$loadOrder,$loadCurrency);
} catch (RuntimeException $e) {
    $denied=$e->getMessage()==='g7_readback_not_authorized';
}
g7DbCheck($denied,'unsupported_cart_inspection_accepted');

Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
$off=Capture::captureFromShop('actionValidateOrder',['order'=>$order]);
$readbackDisabled=false;
try {
    Readback::inspectLatest($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        'order',$loadCustomer,$loadOrder,$loadCurrency);
} catch (RuntimeException $e) {
    $readbackDisabled=$e->getMessage()==='g7_readback_not_authorized';
}
g7DbCheck($readbackDisabled,'readback_not_revoked');

g7DbCheck($off['status']==='local_capture_disabled'
    && (int)$db->getValue("SELECT COUNT(*) FROM `$tab`")===5,
    'revocation_failed');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM information_schema.TABLES "
    . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ci_sfmc_queue'"
)===0,'legacy_queue_touched');

// 0.8.8 G7: actual PrestaShop Db staging-only additive migration
// after all original local events are captured. Never in merchant DB CI.
$original=G7Migration::inspect($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_);
g7DbCheck($original['status']==='original_g3_verified'
    && !$original['schema_write_authorized'],'original_inventory_failed');
$rejected=false;
try {
    G7Migration::apply($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        'wrong_phrase',true);
} catch(RuntimeException $e) {
    $rejected=$e->getMessage()==='g7_delivery_owner_approval_required';
}
g7DbCheck($rejected,'ddl_without_owner_accepted');
$rejected=false;
try {
    G7Migration::apply($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        G7Migration::APPROVE,false);
} catch(RuntimeException $e) {
    $rejected=$e->getMessage()==='g7_delivery_owner_approval_required';
}
g7DbCheck($rejected,'ddl_without_backup_accepted');
$applied=G7Migration::apply($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
    G7Migration::APPROVE,true);
$again=G7Migration::apply($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
    G7Migration::APPROVE,true);
$state=G7Migration::inspect($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_);
g7DbCheck($applied['status']==='extended_blocked_no_sends'
    && $again['status']==='already_extended_verified'
    && $state['status']==='extended_g7_verified'
    && !$applied['salesforce_requests_performed']
    && !$state['outbound_worker_authorized']
    && (int)$db->getValue("SELECT COUNT(*) FROM $tab")===5
    && (int)$db->getValue(
        "SELECT COUNT(*) FROM $tab WHERE delivery_state='blocked'"
        . " AND delivery_attempts=0"
    )===5,'migration_changed_local_events');

// Native PrestaShop Db CAS adapter on the same extended G3 outbox.
$native=new NativeLease($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_);
$current=$db->executeS("SELECT accepted_sequence FROM $tab"
    . " WHERE entity_type='customer' AND entity_id=901"
    . " ORDER BY accepted_sequence DESC LIMIT 1",true,false);
g7DbCheck(count($current)===1,'native_current_event_missing');
$currentSeq=(int)$current[0]['accepted_sequence'];
$token=str_repeat('e',64);
$blockedLease=$native->inspect(1,'customer',901,$currentSeq);
g7DbCheck($blockedLease['state']==='blocked'
    && $blockedLease['ledger_current']
    && !$blockedLease['data_cloud_ingestion_authorized']
    && !$native->claim(1,'customer',901,$currentSeq,$token,60)
    && $native->inspect(1,'customer',901,$currentSeq)['attempts']===0,
    'native_adapter_claimed_blocked_row');

// ONLY CI may create a synthetic ready row: no module call does this.
$db->execute("UPDATE $tab SET delivery_state='ready'"
    . " WHERE accepted_sequence=$currentSeq");
$second=new NativeLease($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_);
g7DbCheck($native->claim(1,'customer',901,$currentSeq,$token,60)
    && !$second->claim(1,'customer',901,$currentSeq,str_repeat('f',64),60)
    && !$native->markAcceptedUnconfirmed(
        1,'customer',901,$currentSeq,str_repeat('a',64)
    ), 'native_lease_cas_or_wrong_token');
$claimed=$second->inspect(1,'customer',901,$currentSeq);
g7DbCheck($claimed['state']==='claimed'
    && $claimed['attempts']===1
    && $claimed['lease_active']
    && !$claimed['record_delivered_certified'],
    'native_lease_not_durable');
g7DbCheck($native->markAcceptedUnconfirmed(
        1,'customer',901,$currentSeq,$token
    ) && !$native->claim(1,'customer',901,$currentSeq,$token,60),
    'native_202_requeued');
$receipt=$native->inspect(1,'customer',901,$currentSeq);
g7DbCheck($receipt['state']==='reconcile'
    && $receipt['last_http']===202
    && !$receipt['record_delivered_certified']
    && !$receipt['http_request_performed'],
    'native_202_incorrectly_delivered');

// A row with a stale event/ledger version can never be claimed.
$oldest=$db->executeS("SELECT accepted_sequence FROM $tab"
    . " WHERE entity_type='customer' AND entity_id=901"
    . " ORDER BY accepted_sequence ASC LIMIT 1",true,false);
$oldSeq=(int)$oldest[0]['accepted_sequence'];
g7DbCheck($oldSeq!==$currentSeq,'fixture_has_no_revision');
$db->execute("UPDATE $tab SET delivery_state='ready'"
    . " WHERE accepted_sequence=$oldSeq");
g7DbCheck(!$native->claim(1,'customer',901,$oldSeq,$token,60)
    && !$native->inspect(1,'customer',901,$oldSeq)['ledger_current'],
    'stale_ledger_revision_claimed');

// Lost-response, cancellation and expired-lease quarantine.
$orderSeq=(int)$db->getValue("SELECT accepted_sequence FROM $tab"
    . " WHERE entity_type='order' AND entity_id=902 LIMIT 1",false);
g7DbCheck($orderSeq>0,'native_order_missing');
$db->execute("UPDATE $tab SET delivery_state='ready'"
    . " WHERE accepted_sequence=$orderSeq");
g7DbCheck($native->claim(1,'order',902,$orderSeq,$token,60)
    && $native->markUnknownUnconfirmed(1,'order',902,$orderSeq,$token)
    && $native->inspect(1,'order',902,$orderSeq)['state']==='reconcile',
    'native_timeout_not_quarantined');
$db->execute("UPDATE $tab SET delivery_state='ready'"
    . " WHERE accepted_sequence=$orderSeq");
g7DbCheck($native->cancel(1,'order',902,$orderSeq)
    && !$native->claim(1,'order',902,$orderSeq,$token,60)
    && $native->inspect(1,'order',902,$orderSeq)['state']==='cancelled',
    'native_cancelled_row_replayed');

$otherSeq=(int)$db->getValue("SELECT accepted_sequence FROM $tab"
    . " WHERE entity_type='order' AND entity_id=905 LIMIT 1",false);
g7DbCheck($otherSeq>0,'native_other_order_missing');
$db->execute("UPDATE $tab SET delivery_state='ready'"
    . " WHERE accepted_sequence=$otherSeq");
g7DbCheck($native->claim(1,'order',905,$otherSeq,$token,60),
    'native_expired_fixture_not_claimed');
$db->execute("UPDATE $tab SET lease_until_epoch=UNIX_TIMESTAMP()-1"
    . " WHERE accepted_sequence=$otherSeq");
g7DbCheck($native->quarantineExpired(1,'order',905,$otherSeq)
    && !$native->markAcceptedUnconfirmed(
        1,'order',905,$otherSeq,$token
    ) && $native->inspect(1,'order',905,$otherSeq)['state']==='reconcile',
    'native_expired_lease_resumed');

// Stage Test revocation must deny all DB access and writes.
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','staging_armed');
$revoked=false;
try {
    $native->inspect(1,'customer',901,$currentSeq);
} catch(RuntimeException $e) {
    $revoked=$e->getMessage()==='g7_native_lease_stage_gate_denied';
}
g7DbCheck($revoked,'native_test_gate_not_revoked');
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');

// No customer IDs, source keys, email, token or payload in diagnostics.
$report=json_encode([$blockedLease,$claimed,$receipt]);
foreach(['ci-synthetic@example.invalid',$ns,'901',$token,'growth_g5_canary']
    as $secret) {
    g7DbCheck(strpos($report,$secret)===false,
        'native_lease_report_leaked_private_identity');
}

// Restore *disposable CI fixtures* to all-blocked and zero attempts
// before testing the existing safety constraints and 0.8.8 rollback.
$db->execute("UPDATE $tab SET delivery_state='blocked',"
    . "delivery_attempts=0,lease_token_hash=NULL,"
    . "lease_until_epoch=NULL,delivery_last_http=NULL");
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM $tab WHERE delivery_state='blocked'"
    . " AND delivery_attempts=0 AND lease_token_hash IS NULL"
    . " AND delivery_last_http IS NULL"
)===5,'native_adapter_fixture_not_restored');

$rearmed=Setup::install(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,Setup::CONFIRM
);
g7DbCheck($rearmed['schema_present'] && !$rearmed['outbound_authorized'],
    'g3_rearm_rejected_g7_additive_columns');
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','staging_armed');
$denied=false;
try {
    G7Migration::rollback($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        G7Migration::UNDO,true);
} catch(RuntimeException $e) {
    $denied=$e->getMessage()==='g7_delivery_test_gate_denied';
}
g7DbCheck($denied,'rollback_during_capture_accepted');
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');

$db->execute("UPDATE $tab SET delivery_attempts=1 WHERE accepted_sequence=("
    . "SELECT current_seq FROM (SELECT MAX(accepted_sequence) AS current_seq"
    . " FROM $tab) q)");
$denied=false;
try {
    G7Migration::rollback($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        G7Migration::UNDO,true);
} catch(RuntimeException $e) {
    $denied=$e->getMessage()==='g7_delivery_history_prevents_rollback';
}
g7DbCheck($denied,'rollback_dropped_attempt_history');
$db->execute("UPDATE $tab SET delivery_attempts=0");
$rolled=G7Migration::rollback(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,G7Migration::UNDO,true
);
g7DbCheck($rolled['status']==='rolled_back_no_sends'
    && (int)$db->getValue("SELECT COUNT(*) FROM $tab")===5
    && G7Migration::inspect($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_)
        ['status']==='original_g3_verified',
    'rollback_did_not_restore_g3');
$diagnostic=json_encode([$original,$applied,$again,$state,$rolled]);
g7DbCheck(strpos($diagnostic,'ci-synthetic@example.invalid')===false
    && strpos($diagnostic,$ns)===false
    && strpos($diagnostic,'901')===false,
    'g7_migration_report_leaked_identity');

$pdo->exec("DELETE FROM `$ledger` WHERE source_namespace='$ns'");
$pdo->exec("DELETE FROM `$tab` WHERE source_namespace='$ns'");
$rollback=new SandMigration(
    $pdo,_PS_VERSION_,'ABH','marketing_cloud_growth',$ns,'test',_DB_PREFIX_
);
g7DbCheck($rollback->rollbackIfEmpty(),'empty_schema_cleanup_failed');
echo "G7 real staging capture MySQL/MariaDB PASS: explicit setup, native hooks, HMAC dedupe, atomic same G3 outbox/ledger, safe stop/rollback.\n";
