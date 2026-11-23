<?php
/**
 * G8 cron preflight contract: no shop SQL writes, OAuth, HTTP or PII.
 * Synthetic isolated test, PHP 7.1+.
 */
class Configuration
{
    public static $values=[
        'PS_GROWTH_MERCHANT'=>'BCABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_20261120',
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off'
    ];
    public static function get($key,$lang=null,$group=null,$shop=null)
    {
        return isset(self::$values[$key])?self::$values[$key]:false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingCandidateAdapter.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8CronReadOnly.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8CronReadOnly as Cron;
function cronAssert($ok,$label) {
    if (!$ok) throw new RuntimeException('g8_cron_contract_'.$label);
}
$db=new stdClass();
$calls=0;
$scanner=function($db,$ver,$merchant,$ns,$prefix,$shop,$after,$limit)
    use (&$calls)
{
    $calls++;
    cronAssert($merchant==='BCABH' && $shop===1
        && $ns==='pssfmc_stage_20261120'
        && $prefix==='ps_'
        && $after===0 && $limit===10,
        'scanner_broke_scope');
    return [
        'status'=>'bounded_review_candidates_only',
        'candidate_refs_internal'=>[
            ['shop_id'=>1,'entity_type'=>'customer','entity_id'=>555,
                'accepted_sequence'=>200],
            ['shop_id'=>1,'entity_type'=>'order','entity_id'=>889,
                'accepted_sequence'=>201],
        ],
        'has_more'=>true,'next_cursor'=>201,
        'can_set_ready'=>false,'can_claim'=>false,'http_request_performed'=>false,
        'data_cloud_ingestion_authorized'=>false,
        'marketing_sends_authorized'=>false,
    ];
};
$preview=Cron::inspect($db,1,'1.7.8.11','BCABH',
    'pssfmc_stage_20261120','ps_',10,$scanner);
cronAssert($preview['status']==='staging_events_review_only'
    && $preview['reviewed_candidates']===2
    && $preview['more_candidates_present']===true
    && $preview['read_only']===true
    && $preview['marketing_sends_authorized']===false
    && $preview['data_cloud_ingestion_authorized']===false
    && $preview['database_write_performed']===false
    && $preview['http_request_performed']===false
    && $calls===1,'staging_preview');
$encoded=json_encode($preview);
foreach(['private@example.test','do-not-expose','555','889','201',
    'source_key','entity_id','cursor','pssfmc_stage_20261120']
    as $private) {
    // "cursor_exposed":false is an allowed, PII-free safety assertion.
    if ($private==='cursor')continue;
    cronAssert(strpos($encoded,$private)===false,
        'private_data_appeared_'.$private);
}
// Ordinary production namespace must return BEFORE any scanner or DB query.
Configuration::$values['PS_GROWTH_NAMESPACE']='pssfmc_bc_prod_20261120';
$blocked=Cron::inspect($db,1,'1.7.8.11','BCABH',
    'pssfmc_bc_prod_20261120','ps_',10,$scanner);
cronAssert($blocked['status']==='blocked_non_staging' && $calls===1,
    'production_namespace_scanned');
Configuration::$values['PS_GROWTH_NAMESPACE']='pssfmc_stage_20261120';
Configuration::$values['PS_SFMC_MODE']='production';
$blocked=Cron::inspect($db,1,'1.7.8.11','BCABH',
    'pssfmc_stage_20261120','ps_',10,$scanner);
cronAssert($blocked['status']==='blocked_by_configuration' && $calls===1,
    'production_mode_scanned');
Configuration::$values['PS_SFMC_MODE']='test';
Configuration::$values['PS_GROWTH_LOCAL_CAPTURE']='staging_armed';
$blocked=Cron::inspect($db,1,'1.7.8.11','BCABH',
    'pssfmc_stage_20261120','ps_',10,$scanner);
cronAssert($blocked['status']==='blocked_by_configuration' && $calls===1,
    'armed_capture_scanned');
Configuration::$values['PS_GROWTH_LOCAL_CAPTURE']='off';
foreach([0,11] as $limit) {
    $blocked=Cron::inspect($db,1,'1.7.8.11','BCABH',
        'pssfmc_stage_20261120','ps_',$limit,$scanner);
    cronAssert($blocked['status']==='blocked_by_configuration' && $calls===1,
        'invalid_batch_limit_scanned');
}
$blocked=Cron::inspect($db,2,'1.7.8.11','DOABH',
    'pssfmc_stage_20261120','ps_',10,$scanner);
cronAssert($blocked['status']==='blocked_by_configuration' && $calls===1,
    'cross_shop_merchant_scanned');
Configuration::$values['PS_GROWTH_MERCHANT']='DOABH';
$blocked=Cron::inspect($db,2,'1.7.8.11','DOABH',
    'pssfmc_stage_20261120','ps_',10,$scanner);
cronAssert($blocked['status']==='blocked_source_binding' && $calls===1,
    'new_merchant_borrowed_bc_source');
Configuration::$values['PS_GROWTH_MERCHANT']='BCABH';
$badScanner=function(){return [
    'status'=>'bounded_review_candidates_only',
    'candidate_refs_internal'=>[],
    'has_more'=>false,'next_cursor'=>0,'can_claim'=>true,
    'http_request_performed'=>false,
    'data_cloud_ingestion_authorized'=>false,
    'marketing_sends_authorized'=>false,
];};
$blocked=Cron::inspect($db,1,'1.7.8.11','BCABH',
    'pssfmc_stage_20261120','ps_',10,$badScanner);
cronAssert($blocked['status']==='staging_schema_unavailable'
    && $blocked['reviewed_candidates']===0,
    'untrusted_scanner_was_accepted');
$throws=function(){throw new RuntimeException(
    'sensitive_sql: customer private@example.test token=secret'
);};
$failed=Cron::inspect($db,1,'1.7.8.11','BCABH',
    'pssfmc_stage_20261120','ps_',10,$throws);
cronAssert($failed['status']==='staging_schema_unavailable'
    && strpos(json_encode($failed),'sensitive_sql')===false,
    'raw_exception_leaked');

$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$controller=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
cronAssert(strpos($module,"$"."this->version = '0.9.6';")!==false
    && strpos($module,"GrowthG8CronReadOnly::inspect(")!==false
    && strpos($controller,"hash_equals(")!==false
    && strpos($controller,"$"."this->module->cronReport($"."operation)")!==false
    && strpos($controller,"'invalid_operation'")!==false
    && strpos($module,"Db::getInstance()")!==false,
    'cron_module_not_wired');
$auth=strpos($controller,"hash_equals(");
$operation=strpos($controller,"Tools::getValue('operation'");
cronAssert($auth!==false && $operation!==false && $auth<$operation,
    'inspect_unprotected_by_token');

echo "G8 cron read-only PASS: bounded staging scan, sanitized counts, prod/multishop gates, no outbound.\n";
