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
    'Growth/GrowthSqlEngineGate.php',
    'Growth/GrowthStagingSchemaBootstrap.php',
    'Growth/GrowthStagingOutboxStore.php',
    'Growth/GrowthStagingHookCapture.php',
    'Growth/GrowthSandboxMigrationExecutor.php'
] as $path)require_once $root.$path;

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture as Capture;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingSchemaBootstrap as Setup;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSandboxMigrationExecutor as SandMigration;

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
g7DbCheck($r1['status']==='blocked_local_only' && $r1['inserted']
    && $r2['status']==='blocked_local_only' && !$r2['inserted']
    && $r3['inserted'] && $r4['inserted'],
    'source_events_not_captured_or_deduped');
$tab='ci_growth_outbox_v1';
$ledger='ci_growth_entity_ledger_v1';
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM `$tab`")===3,
    'unexpected_rows');
g7DbCheck((int)$db->getValue("SELECT COUNT(*) FROM `$ledger`")===2,
    'ledger_not_unique_by_entity');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE status='blocked'"
)===3,'status_not_blocked');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE entity_id=901"
)===2,'customer_did_not_update');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM `$tab` WHERE entity_id=902 AND entity_type='order'"
)===1,'order_not_recorded');
Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
$off=Capture::captureFromShop('actionValidateOrder',['order'=>$order]);
g7DbCheck($off['status']==='local_capture_disabled'
    && (int)$db->getValue("SELECT COUNT(*) FROM `$tab`")===3,
    'revocation_failed');
g7DbCheck((int)$db->getValue(
    "SELECT COUNT(*) FROM information_schema.TABLES "
    . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ci_sfmc_queue'"
)===0,'legacy_queue_touched');

$pdo->exec("DELETE FROM `$ledger` WHERE source_namespace='$ns'");
$pdo->exec("DELETE FROM `$tab` WHERE source_namespace='$ns'");
$rollback=new SandMigration(
    $pdo,_PS_VERSION_,'ABH','marketing_cloud_growth',$ns,'test',_DB_PREFIX_
);
g7DbCheck($rollback->rollbackIfEmpty(),'empty_schema_cleanup_failed');
echo "G7 real staging capture MySQL/MariaDB PASS: explicit setup, native hooks, HMAC dedupe, atomic same G3 outbox/ledger, safe stop/rollback.\n";
