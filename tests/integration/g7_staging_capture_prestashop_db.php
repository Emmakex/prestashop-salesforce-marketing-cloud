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
    'Growth/GrowthShopSourceBinding.php',
    'Growth/GrowthAutomaticOneShotCoordinator.php',
    'Growth/GrowthG8DurableReceiptBridge.php',
    'Growth/GrowthG8StagingReadyGate.php',
    'Growth/GrowthG8StagingWorker.php',
    'Growth/GrowthLocalCustomerHoldReader.php',
    'Growth/GrowthG7NativePreflight.php',
    'Growth/GrowthG7CurrentEventBatch.php',
    'Growth/GrowthG8StagingCandidateAdapter.php',
    'Growth/GrowthG8ExactCaptureInspector.php',
    'Growth/GrowthCommunicationConsentHoldGate.php',
    'Growth/GrowthG7CscPreDispatchReview.php',
    'Growth/GrowthG7DryRunBatchOrchestrator.php',
    'Growth/GrowthSqlEngineGate.php',
    'Growth/GrowthStagingSchemaBootstrap.php',
    'Growth/GrowthStagingOutboxStore.php',
    'Growth/GrowthStagingHookCapture.php',
    'DataCloud/IngestionPayloadBuilder.php',
    'DataCloud/GrowthTypedPstPayloadBuilder.php',
    'DataCloud/GrowthNativeCustomerOrderDraft.php',
    'DataCloud/GrowthStreamingIngestionPlan.php',
    'DataCloud/GrowthCapturedEventPstPreview.php',
    'Growth/GrowthStagingNativeReadback.php',
    'Growth/GrowthSandboxMigrationExecutor.php'
] as $path)require_once $root.$path;

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture as Capture;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingSchemaBootstrap as Setup;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSandboxMigrationExecutor as SandMigration;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingNativeReadback as Readback;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7StagingDeliveryMigration as G7Migration;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPrestaShopDeliveryLease as NativeLease;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7NativePreflight as Preflight;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7CurrentEventBatch as Batch;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7CscPreDispatchReview as CscReview;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7DryRunBatchOrchestrator as DryRun;

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
// G8 exact capture inspector against disposable real MySQL/MariaDB,
// using the same customer/order event history, without SQL writes.
$exact=\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8ExactCaptureInspector::inspect(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,901,902
);
g7DbCheck($exact['status']==='both_events_captured'
    && $exact['customer_event_captured']
    && $exact['order_event_captured']
    && $exact['read_only']
    && !$exact['data_cloud_ingestion_authorized']
    && !$exact['database_write_performed'],
    'g8_exact_capture_not_confirmed');
$missing=\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8ExactCaptureInspector::inspect(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,901,99991
);
g7DbCheck($missing['status']==='capture_not_confirmed'
    && $missing['customer_event_captured']
    && !$missing['order_event_captured'],
    'g8_exact_capture_false_positive');
// G7 0.8.5: read actual local outbox + ledger through PrestaShop Db API,
// then use injected native-object loaders (no real PII or external requests).
$createdFresh=clone $created;
$createdFresh->newsletter=0;
$createdFresh->optin=0;
$createdFresh->date_upd='2026-11-18 09:00:00';
$loadCustomer=function($id)use($createdFresh,$customer) {
    if ($id===904) {
        $c=clone $createdFresh;
        $c->newsletter=1;
        $c->optin=1;
        return $c;
    }
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

// G7 source/ledger/customer negative evidence on the extended G3 CI table.
// Customer source is synthetic and injected: g3_ci has no merchant PII.
$sourceReads=0;
$freshCustomer=clone $customer;
$freshCustomer->newsletter=1;
$freshCustomer->optin=1;
$sourceLoader=function($id)use($freshCustomer,$createdFresh,&$sourceReads) {
    $sourceReads++;
    if ($id===901) return clone $freshCustomer;
    if ($id===904) {
        $c=clone $createdFresh;
        $c->newsletter=1;
        $c->optin=1;
        return $c;
    }
    throw new RuntimeException('test_customer_unknown');
};
$nativeOrder=function($id)use($order,$orderPending) {
    if ($id===902) return clone $order;
    if ($id===905) {
        $result=clone $orderPending;
        $result->current_state=2;
        return $result;
    }
    throw new RuntimeException('test_order_unknown');
};
$nativeCurrency=function($id) {
    return $id===1?(object)['id'=>1,'iso_code'=>'EUR']:null;
};
$localRows=function($sql) {
    if (strpos($sql,'ci_customer')===false
        || !preg_match('/([0-9]+) LIMIT 2$/',$sql,$match)) {
        return false;
    }
    return [[
        'id_customer'=>$match[1],'id_shop'=>'1',
        'active'=>'1','deleted'=>'0','is_guest'=>'0',
        'newsletter'=>'1','optin'=>'1'
    ]];
};
$origin='https://credencial.c360a.salesforce.com';
$sourceName='ABH_PrestaShop_Data_Cloud';
$stableCustomer=Preflight::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
g7DbCheck($stableCustomer['status']==='hold_for_review'
    && $stableCustomer['reason']==='local_consent_unverified_hold'
    && $stableCustomer['local_customer_source_state']
        ==='no_local_negative_observed'
    && $stableCustomer['native_source_double_read_stable']
    && !$stableCustomer['marketing_sends_authorized']
    && !$stableCustomer['data_cloud_ingestion_authorized']
    && $sourceReads>=2,
    'g7_preflight_positive_local_is_not_growth_consent');
$stableOrder=Preflight::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'order',
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
g7DbCheck($stableOrder['status']==='hold_for_review'
    && $stableOrder['reason']==='local_consent_unverified_hold'
    && $stableOrder['native_source_double_read_stable'],
    'g7_preflight_order_not_projected');
$negativeRows=function($sql)use($localRows) {
    $rows=$localRows($sql);
    if ($rows) $rows[0]['newsletter']='0';
    return $rows;
};
$negative=Preflight::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $sourceLoader,$nativeOrder,$nativeCurrency,$negativeRows,$origin,$sourceName
);
g7DbCheck($negative['reason']==='local_negative_hold'
    && $negative['local_customer_source_state']==='known_negative'
    && !$negative['marketing_sends_authorized'],
    'g7_preflight_local_optout_not_blocked');
// Native object may go negative while a scoped SQL reader looks positive.
// Such a mismatch is a HOLD, never a clean local signal.
$nativeNegativeLoader=function($id)use($sourceLoader) {
    $c=$sourceLoader($id);
    $c->newsletter=0;
    return $c;
};
$nativeNegative=Preflight::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $nativeNegativeLoader,$nativeOrder,$nativeCurrency,$localRows,
    $origin,$sourceName
);
g7DbCheck($nativeNegative['reason']==='local_negative_hold'
    && $nativeNegative['local_customer_source_state']==='known_negative',
    'g7_preflight_native_negative_overridden_by_sql');

$changingCalls=0;
$changingLoader=function($id)use($sourceLoader,&$changingCalls) {
    $result=$sourceLoader($id);
    $changingCalls++;
    if ($changingCalls===2) $result->date_upd='2026-11-19 10:01:00';
    return $result;
};
$changed=Preflight::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $changingLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
g7DbCheck($changed['reason']==='native_source_changed_hold'
    && !$changed['native_source_double_read_stable'],
    'g7_preflight_double_read_missed_source_change');
$invalidType=false;
try {
    Preflight::inspectLatest(
        $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'cart',
        $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
    );
} catch(RuntimeException $e) {
    $invalidType=$e->getMessage()==='g7_preflight_input_invalid';
}
g7DbCheck($invalidType,'g7_preflight_cart_accepted');
$private=json_encode([$stableCustomer,$stableOrder,$negative,$changed]);
foreach(['ci-synthetic@example.invalid',$ns,'901','902','905',
    'Fixture','credencial','ABH_PrestaShop_Data_Cloud'] as $word) {
    g7DbCheck(strpos($private,$word)===false,'g7_preflight_leaked_private_data');
}
$stateCheck=(int)$db->getValue("SELECT COUNT(*) FROM $tab"
    . " WHERE status='blocked' AND delivery_state='blocked'"
    . " AND delivery_attempts=0");
g7DbCheck($stateCheck===5,'g7_preflight_mutated_outbox');

// G7 + G6 join, strictly on disposable g3_ci. A well-formed injected
// CSC opt-in is REVIEW ONLY; never remote adapter certification or sending.
$cscCalls=0;
$subscription='marketing_email_test';
$now='2026-11-19T10:00:00Z';
$readCsc=function($key,$sub,$channel)use($ns,$subscription,&$cscCalls) {
    ++$cscCalls;
    if ($sub!==$subscription || $channel!=='email'
        || !in_array($key,[
            $ns.':1:customer:901',
            $ns.':1:customer:904'
        ],true))throw new RuntimeException('csc_fixture_scope_invalid');
    return [[
        'channel'=>'email','contact_point_source_key'=>$key,
        'observed_at_utc'=>'2026-11-19T09:59:50Z',
        'source'=>'salesforce_communication_subscription_consent',
        'status'=>'opt_in','subscription_id'=>$sub
    ]];
};
$reviewCustomer=CscReview::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
$reviewOrder=CscReview::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'order',
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
g7DbCheck($reviewCustomer['status']==='hold_for_review'
    && $reviewCustomer['reason']==='csc_candidate_requires_sendtime_fence'
    && $reviewCustomer['source_and_consent_rechecked']
    && $reviewCustomer['csc_source_state']
        ==='recent_opt_in_observed_not_certified'
    && $reviewOrder['reason']==='csc_candidate_requires_sendtime_fence'
    && $cscCalls===2
    && !$reviewOrder['marketing_sends_authorized']
    && !$reviewOrder['data_cloud_ingestion_authorized'],
    'g7_csc_recent_optin_incorrectly_authorized_or_not_joined');

// Native negative must skip an externally injected CSC callback entirely.
$prev=$cscCalls;
$cscNegative=CscReview::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $nativeNegativeLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
g7DbCheck($cscNegative['reason']==='local_or_source_hold'
    && $cscCalls===$prev,
    'g7_csc_local_negative_bypassed');

// Remote opt-out must always override local positive evidence.
$readOptout=function($key,$sub,$channel)use($readCsc) {
    $rows=$readCsc($key,$sub,$channel);
    $rows[0]['status']='opt_out';
    return $rows;
};
$optout=CscReview::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'order',
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readOptout,$origin,$sourceName
);
g7DbCheck($optout['reason']==='csc_denied_or_unverified_hold'
    && $optout['csc_source_state']==='opt_out'
    && !$optout['marketing_sends_authorized'],
    'g7_csc_remote_optout_ignored');

// Missing/incorrect CSC and a reader exception are unknown HOLD, not optin.
foreach (['missing','throw','stale'] as $scenario) {
    $badCsc=function($key,$sub,$channel)use($readCsc,$scenario) {
        if ($scenario==='throw')throw new RuntimeException('private-error');
        if ($scenario==='missing')return [];
        $rows=$readCsc($key,$sub,$channel);
        $rows[0]['observed_at_utc']='2026-11-18T00:00:00Z';
        return $rows;
    };
    $bad=CscReview::inspectLatest(
        $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
        $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
        $subscription,$now,$badCsc,$origin,$sourceName
    );
    g7DbCheck($bad['reason']==='csc_denied_or_unverified_hold'
        && !$bad['marketing_sends_authorized'],
        'g7_csc_unverified_admitted');
}

// Flip one native field during CSC observation; the later source passes
// are themselves stable, but must not erase the before/after mismatch.
$flip=false;
$flippingCustomer=function($id)use($sourceLoader,&$flip) {
    $c=$sourceLoader($id);
    if ($flip)$c->date_upd='2026-11-19 10:00:01';
    return $c;
};
$flippingCsc=function($key,$sub,$channel)use($readCsc,&$flip) {
    $rows=$readCsc($key,$sub,$channel);
    $flip=true;
    return $rows;
};
$mutated=CscReview::inspectLatest(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $flippingCustomer,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$flippingCsc,$origin,$sourceName
);
g7DbCheck($mutated['reason']==='native_changed_during_consent_hold'
    && !$mutated['source_and_consent_rechecked'],
    'g7_csc_mid_review_native_change_admitted');

$private=json_encode([
    $reviewCustomer,$reviewOrder,$cscNegative,$optout,$bad,$mutated
]);
foreach (['ci-synthetic@example.invalid',$ns,'901','904','905',
    'Fixture','marketing_email_test','private-error','credencial',
    'ABH_PrestaShop_Data_Cloud'] as $word) {
    g7DbCheck(strpos($private,$word)===false,
        'g7_csc_review_leaked_private_data');
}
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM $tab"
    . " WHERE status='blocked' AND delivery_state='blocked'"
    . " AND delivery_attempts=0")===5,
    'g7_csc_review_modified_same_outbox');

// G7 multi-entity event scanner: exact latest ledger version per entity,
// bounded keyset pages on the SAME outbox. No READY/claim or outbound.
$page1=Batch::scan($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2);
$page2=Batch::scan($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
    1,$page1['next_cursor'],2);
$page3=Batch::scan($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
    1,$page2['next_cursor'],2);
$refs=array_merge($page1['candidate_refs_internal'],
    $page2['candidate_refs_internal']);
$summary=Batch::summarize($page1);
g7DbCheck(count($refs)===4
    && count($page1['candidate_refs_internal'])===2
    && count($page2['candidate_refs_internal'])===2
    && $page1['has_more'] && !$page2['has_more']
    && $page3['candidate_refs_internal']===[]
    && count(array_unique(array_column($refs,'accepted_sequence')))===4
    && $summary['candidate_count']===2
    && !isset($summary['next_cursor'])
    && !isset($summary['candidate_refs_internal'])
    && !$summary['data_cloud_ingestion_authorized'],
    'g7_batch_cursor_or_privacy_invalid');
$types=array_column($refs,'entity_type');
g7DbCheck(count(array_filter($types,function($x){return $x==='customer';}))===2
    && count(array_filter($types,function($x){return $x==='order';}))===2,
    'g7_batch_types_wrong');
$customerCandidate=null;
$orderCandidate=null;
foreach($refs as $ref) {
    if ($ref['entity_type']==='customer' && $ref['entity_id']===901)
        $customerCandidate=$ref;
    if ($ref['entity_type']==='order' && $ref['entity_id']===902)
        $orderCandidate=$ref;
}
g7DbCheck($customerCandidate!==null && $orderCandidate!==null,
    'g7_batch_missing_current_entities');
$customerCheck=Preflight::inspectSelected(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',
    $customerCandidate['entity_id'],$customerCandidate['accepted_sequence'],
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
$orderCheck=Preflight::inspectSelected(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'order',
    $orderCandidate['entity_id'],$orderCandidate['accepted_sequence'],
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
g7DbCheck($customerCheck['reason']==='local_consent_unverified_hold'
    && $customerCheck['native_source_double_read_stable']
    && $orderCheck['reason']==='local_consent_unverified_hold'
    && !$orderCheck['marketing_sends_authorized'],
    'g7_batch_exact_candidate_preflight_failed');
$oldSeq=(int)$db->getValue("SELECT MIN(accepted_sequence) FROM $tab"
    . " WHERE entity_type='customer' AND entity_id=901",false);
g7DbCheck($oldSeq<$customerCandidate['accepted_sequence'],
    'g7_batch_no_stale_revision_fixture');
$staleCheck=Preflight::inspectSelected(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,'customer',901,$oldSeq,
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
g7DbCheck($staleCheck['reason']==='not_current_blocked_hold',
    'g7_batch_stale_revision_accepted');
$crossShop=Preflight::inspectSelected(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,2,'customer',
    $customerCandidate['entity_id'],$customerCandidate['accepted_sequence'],
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,$origin,$sourceName
);
g7DbCheck($crossShop['reason']==='no_local_event_hold',
    'g7_batch_cross_shop_accepted');
$target=$customerCandidate['accepted_sequence'];
$db->execute("UPDATE $tab SET delivery_state='ready'"
    . " WHERE accepted_sequence=$target");
$omitted=Batch::scan($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,50);
g7DbCheck(count($omitted['candidate_refs_internal'])===3,
    'g7_batch_selected_ready_not_excluded');
$db->execute("UPDATE $tab SET delivery_state='blocked'"
    . " WHERE accepted_sequence=$target");
$invalid=false;
try {
    Batch::scan($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,51);
} catch(RuntimeException $e) {
    $invalid=$e->getMessage()==='g7_batch_input_invalid';
}
g7DbCheck($invalid,'g7_batch_unbounded_query_accepted');
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','staging_armed');
$revoked=false;
try {
    Batch::scan($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2);
} catch(RuntimeException $e) {
    $revoked=$e->getMessage()==='g7_native_lease_stage_gate_denied';
}
g7DbCheck($revoked,'g7_batch_open_when_capture_armed');
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
$public=json_encode([$summary,$customerCheck,$orderCheck,$staleCheck,$crossShop]);
foreach(['ci-synthetic@example.invalid',$ns,'901','902','905',
    'Fixture','credencial','ABH_PrestaShop_Data_Cloud'] as $secret) {
    g7DbCheck(strpos($public,$secret)===false,
        'g7_batch_summary_leaked_identifier');
}
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM $tab"
    . " WHERE status='blocked' AND delivery_state='blocked'"
    . " AND delivery_attempts=0")===5,'g7_batch_mutated_merchant_events');

// G7 unified bounded review: ALL four current synthetic events (not just
// last global customer/order), exact CSC/ledger, no ready/claim/HTTP.
$window=DryRun::reviewWindow(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2,3,
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
$publicWindow=DryRun::summarize($window);
g7DbCheck($publicWindow['status']==='batch_review_only_no_sends'
    && $publicWindow['reviewed_count']===4
    && $publicWindow['reason_counts']['csc_candidate_not_authorized']===4
    && !$publicWindow['more_pages_present']
    && !isset($publicWindow['next_cursor_internal'])
    && !$publicWindow['delivery_authorized']
    && !$window['http_request_performed'],
    'g7_dry_run_not_all_current_events');

// Same cursor can resume multiple bounded windows without missing a row.
$firstWindow=DryRun::reviewWindow(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2,1,
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
$secondWindow=DryRun::reviewWindow(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,
    $firstWindow['next_cursor_internal'],2,1,
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
g7DbCheck($firstWindow['reviewed_count']===2
    && $secondWindow['reviewed_count']===2
    && $firstWindow['more_pages_present']
    && !$secondWindow['more_pages_present']
    && $secondWindow['next_cursor_internal']
        >$firstWindow['next_cursor_internal'],
    'g7_dry_run_resume_window_not_bounded');

// Suppress one customer's CSC but retain others, never borrow optin.
$selectiveCsc=function($key,$sub,$channel)use($readCsc,$ns) {
    $rows=$readCsc($key,$sub,$channel);
    if ($key===$ns.':1:customer:904') {
        $rows[0]['status']='opt_out';
    }
    return $rows;
};
$withOptout=DryRun::reviewWindow(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2,3,
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$selectiveCsc,$origin,$sourceName
);
g7DbCheck($withOptout['status']==='batch_review_only_no_sends'
    && $withOptout['reason_counts']['csc_denied_or_unverified_hold']===1
    && $withOptout['reason_counts']['csc_candidate_not_authorized']===3
    && !$withOptout['marketing_sends_authorized'],
    'g7_dry_run_cross_customer_csc_leak');

// One native customer (#901) is negative, including both orders whose
// id_customer points to 901. The second customer (#904) stays reviewable.
$oneNativeNegative=function($id)use($sourceLoader) {
    $c=$sourceLoader($id);
    if ($id===901)$c->newsletter=0;
    return $c;
};
$withNativeNegative=DryRun::reviewWindow(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2,3,
    $oneNativeNegative,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
g7DbCheck($withNativeNegative['status']==='batch_review_only_no_sends'
    && $withNativeNegative['reason_counts']['local_or_source_hold']===3
    && $withNativeNegative['reason_counts']['csc_candidate_not_authorized']===1,
    'g7_dry_run_local_negative_not_applied_to_all_orders');

// A failed scanner/guard must not return a partial success or cursor.
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','staging_armed');
$deniedWindow=DryRun::reviewWindow(
    $db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,1,0,2,3,
    $sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
    $subscription,$now,$readCsc,$origin,$sourceName
);
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
$deniedSummary=DryRun::summarize($deniedWindow);
g7DbCheck($deniedSummary['status']==='batch_review_failed_closed'
    && $deniedSummary['reviewed_count']===null
    && $deniedWindow['next_cursor_internal']===null
    && !$deniedSummary['delivery_authorized'],
    'g7_dry_run_test_gate_leaked_partial_success');
$invalid=false;
try {
    DryRun::reviewWindow($db,_PS_VERSION_,'ABH',$ns,_DB_PREFIX_,
        1,0,50,6,$sourceLoader,$nativeOrder,$nativeCurrency,$localRows,
        $subscription,$now,$readCsc,$origin,$sourceName);
} catch(RuntimeException $e) {
    $invalid=$e->getMessage()==='g7_dry_run_window_invalid';
}
g7DbCheck($invalid,'g7_dry_run_unbounded_pages_accepted');
$redacted=json_encode([
    $publicWindow,DryRun::summarize($withOptout),
    DryRun::summarize($withNativeNegative),$deniedSummary
]);
foreach (['ci-synthetic@example.invalid',$ns,'901','902','904','905',
    'Fixture','marketing_email_test','credencial',
    'ABH_PrestaShop_Data_Cloud'] as $needle) {
    g7DbCheck(strpos($redacted,$needle)===false,
        'g7_dry_run_public_summary_contains_private_data');
}
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM $tab"
    . " WHERE status='blocked' AND delivery_state='blocked'"
    . " AND delivery_attempts=0")===5,
    'g7_dry_run_modified_outbox');

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

// G8 real PrestaShop Db facade → same G3 ledger/CAS → durable receipt.
//
// Use ONLY the last remaining synthetic BLOCKED customer from this sandbox,
// temporarily set READY in disposable g3_ci (never exposed by the module).
// This is a DB-level rehearsal, not consent, transmission or go-live.
$g8Sequence=(int)$db->getValue("SELECT accepted_sequence FROM $tab"
    . " WHERE entity_type='customer' AND entity_id=904 LIMIT 1",false);
g7DbCheck($g8Sequence>0,'g8_missing_synthetic_current_revision');
$g8RawBefore=$native->inspect(1,'customer',904,$g8Sequence);
g7DbCheck($g8RawBefore['state']==='blocked'
    && $g8RawBefore['attempts']===0
    && $g8RawBefore['ledger_current'],
    'g8_fixture_not_blocked');
$g8Scope=[
    'shop'=>1,'merchant'=>'ABH','namespace'=>$ns,
    'mode'=>'test','capture'=>'off',
    'owner_gate'=>\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthAutomaticOneShotCoordinator::OWNER_GATE
];
$g8Candidate=[
    'shop'=>1,'type'=>'PSCustomer','id'=>904,'sequence'=>$g8Sequence
];
$readyClass=\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingReadyGate::class;
$policy=function($candidate)use($g8Sequence) {
    return $candidate['shop']===1 && $candidate['id']===904
        && $candidate['sequence']===$g8Sequence;
};
$dloAbsent=function($candidate) {return 'absent_confirmed';};
$parentVerified=function($candidate) {return false;};
// No access to READY unless owner gate and current native evidence
// agree, even in disposable g3_ci.
$badScope=$g8Scope;
$badScope['owner_gate']='';
$beforeBlocked=$readyClass::promote(
    $db,_PS_VERSION_,$badScope,_DB_PREFIX_,
    $g8Candidate,$policy,$dloAbsent,$parentVerified
);
g7DbCheck($beforeBlocked['status']==='blocked_by_configuration'
    && $native->inspect(1,'customer',904,$g8Sequence)['state']
        ==='blocked','g8_ready_missing_owner_allowed');
$remotePresent=function($candidate) {return 'present';};
g7DbCheck($readyClass::promote(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,
    $g8Candidate,$policy,$remotePresent,$parentVerified
)['status']==='blocked_remote_not_absent',
    'g8_ready_remote_present_allowed');
$denyContact=function($candidate) {return false;};
g7DbCheck($readyClass::promote(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,
    $g8Candidate,$denyContact,$dloAbsent,$parentVerified
)['status']==='blocked_contact_or_source',
    'g8_ready_contact_denied_allowed');
$ready=$readyClass::promote(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,
    $g8Candidate,$policy,$dloAbsent,$parentVerified
);
g7DbCheck($ready['status']==='ready_for_one_cas_only'
    && $native->inspect(1,'customer',904,$g8Sequence)['state']
        ==='ready','g8_ready_promotion_failed');
$readyWindow=$readyClass::scanReady(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,0,10
);
g7DbCheck($readyWindow['status']==='ready_candidates_private'
    && $readyWindow['candidates_private']===[$g8Candidate]
    && $readyWindow['has_more']===false
    && !$readyWindow['data_cloud_ingestion_authorized'],
    'g8_ready_scanner_not_exact');
g7DbCheck($readyClass::promote(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,
    $g8Candidate,$policy,$dloAbsent,$parentVerified
)['status']==='blocked_old_or_attempted',
    'g8_ready_double_promotion_allowed');
$g8Bridge=new \Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8DurableReceiptBridge(
    $native,$g8Scope
);
$g8Fingerprint=hash('sha256','synthetic-native-904');
g7DbCheck($g8Bridge->reserveOnce($g8Candidate,$g8Fingerprint)
    ==='reserved_once'
    && $g8Bridge->claimedForPost($g8Candidate,$g8Fingerprint),
    'g8_native_cas_not_claimed');
$g8AfterClaimScan=$readyClass::scanReady(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,0,10
);
g7DbCheck($g8AfterClaimScan['status']==='ready_candidates_private'
    && $g8AfterClaimScan['candidates_private']===[],
    'g8_claimed_still_scannable');
$g8Claimed=$native->inspect(1,'customer',904,$g8Sequence);
g7DbCheck($g8Claimed['state']==='claimed'
    && $g8Claimed['attempts']===1 && $g8Claimed['lease_active'],
    'g8_native_claim_not_durable');
g7DbCheck($g8Bridge->persistOutcome(
    $g8Candidate,'reconcile',202,true
)===true,'g8_native_receipt_failed');
$g8Receipt=$native->inspect(1,'customer',904,$g8Sequence);
g7DbCheck($g8Receipt['state']==='reconcile'
    && $g8Receipt['last_http']===202
    && $g8Receipt['attempts']===1
    && !$g8Receipt['record_delivered_certified']
    && !$g8Bridge->claimedForPost($g8Candidate,$g8Fingerprint),
    'g8_native_202_overstated');
$g8OtherBridge=new \Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8DurableReceiptBridge(
    $native,$g8Scope
);
g7DbCheck($g8OtherBridge->reserveOnce(
    $g8Candidate,$g8Fingerprint
)==='previous_attempt'
    && $g8Bridge->reserveOnce(
        $g8Candidate,$g8Fingerprint
    )==='previous_attempt'
    && $g8Bridge->persistOutcome(
        $g8Candidate,'reconcile',202,true
    )===false
    && $native->inspect(1,'customer',904,$g8Sequence)['attempts']===1,
    'g8_native_replay_or_duplicate_receipt');
$g8Summary=json_encode([$g8RawBefore,$g8Claimed,$g8Receipt]);
g7DbCheck(is_string($g8Summary)
    && strpos($g8Summary,'ci-synthetic@example.invalid')===false
    && strpos($g8Summary,$ns)===false
    && strpos($g8Summary,'904')===false
    && strpos($g8Summary,'source_key')===false,
    'g8_native_receipt_leaked_pii');

// G8 full bounded worker with *real* G3 MySQL/MariaDB, one synthetic
// customer and an injected HTTP-202 response. No Salesforce traffic.
$workerClass=\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingWorker::class;
$postsFromWorker=0;$checksFromWorker=0;
$nativeSeq901=(int)$db->getValue("SELECT MAX(accepted_sequence)"
    . " FROM $tab WHERE entity_type='customer' AND entity_id=901",false);
g7DbCheck($nativeSeq901>0,'g8_worker_fixture_not_found');
// The disposable G3 CI fixture has already passed other lease tests.
// Reset ONLY this synthetic current revision inside g3_ci to exercise a
// brand-new G8 worker attempt. Never execute this in an actual shop.
$db->execute("UPDATE $tab SET delivery_state='blocked',"
    . "delivery_attempts=0,lease_token_hash=NULL,"
    . "lease_until_epoch=NULL,delivery_last_http=NULL"
    . " WHERE source_namespace='$ns'"
    . " AND accepted_sequence=$nativeSeq901"
    . " AND entity_type='customer' AND entity_id=901");
g7DbCheck($native->inspect(1,'customer',901,$nativeSeq901)['state']
    ==='blocked','g8_worker_fixture_not_blocked');
Configuration::updateValue(
    'PS_GROWTH_G8_STAGING_APPROVAL',$workerClass::APPROVAL
);
$services=[
    'loadFresh'=>function($candidate)use($ns,$nativeSeq901) {
        if ($candidate['type']!=='PSCustomer'
            || $candidate['id']!==901
            || $candidate['sequence']!==$nativeSeq901)return null;
        return [
            'shop'=>1,'type'=>'PSCustomer',
            'id'=>901,'sequence'=>$nativeSeq901,'customer_id'=>901,
            'fingerprint'=>hash('sha256','synthetic-g8-native-901'),
            'payload_private'=>[
                'source_key'=>$ns.':1:customer:901',
                'source_namespace'=>$ns,'shop_id'=>1,'customer_id'=>901
            ]
        ];
    },
    'locallyEligible'=>function($fresh) {
        return $fresh['customer_id']===901;
    },
    'contactEligibility'=>function($candidate,$customerId,$phase)
        use(&$checksFromWorker) {
        ++$checksFromWorker;
        return $customerId===901
            && in_array($phase,[
                'before_claim','after_claim','before_transport'
            ],true);
    },
    'recordDloStatus'=>function($candidate,$fresh) {
        return $fresh['id']===901?'absent_confirmed':'unknown';
    },
    'parentDloVerified'=>function(){return false;},
    'postOnce'=>function($source,$type,$payload,$candidate,$finalFence)
        use(&$postsFromWorker) {
        g7DbCheck(
            $source==='ABH_PrestaShop_Data_Cloud'
            && $type==='PSCustomer' && $payload['customer_id']===901
            && $finalFence()===true,'g8_worker_final_fence_denied'
        );
        ++$postsFromWorker;
        return [
            'http_status'=>202,
            'outcome'=>'accepted_asynchronously_unconfirmed'
        ];
    }
];
$runWorker=$workerClass::run(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,$services
);
$workerReceipt=$native->inspect(1,'customer',901,$nativeSeq901);
fwrite(STDERR,'G8 synthetic CI diagnosis: '.json_encode([
    'worker_status'=>$runWorker['status'],
    'counts'=>$runWorker['counts'],
    'lease_state'=>$workerReceipt['state'],
    'receipt_http'=>$workerReceipt['last_http'],
    'attempts'=>$workerReceipt['attempts'],
    'posts'=>$postsFromWorker,
    'checks'=>$checksFromWorker
],JSON_UNESCAPED_SLASHES)."\n");
g7DbCheck($runWorker['status']==='staging_tick_completed_unconfirmed'
    && $runWorker['counts']['accepted_202_unconfirmed']===1
    && $postsFromWorker===1
    && $checksFromWorker>=3
    && $workerReceipt['state']==='reconcile'
    && $workerReceipt['last_http']===202
    && $workerReceipt['attempts']===1,
    'g8_worker_real_db_end_to_end_failed');
$againWorker=$workerClass::run(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,$services
);
g7DbCheck($postsFromWorker===1
    && $againWorker['counts']['accepted_202_unconfirmed']===0,
    'g8_worker_replayed_claim');
Configuration::updateValue('PS_GROWTH_G8_STAGING_APPROVAL','off');
$blockedWorker=$workerClass::run(
    $db,_PS_VERSION_,$g8Scope,_DB_PREFIX_,$services
);
g7DbCheck($blockedWorker['status']==='staging_worker_blocked'
    && $postsFromWorker===1
    && strpos(json_encode($runWorker),$ns)===false
    && strpos(json_encode($runWorker),'901')===false,
    'g8_worker_approval_or_privacy_failed');

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
