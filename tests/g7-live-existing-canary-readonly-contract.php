<?php
/** G7 synthetic-only read-only existing canary verification contract. */
require_once dirname(__DIR__).'/tests/review/G7ExistingCanaryReadOnly.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G7ExistingCanaryReadOnly as Check;

function g7LiveAssert($ok,$label) {
    if (!$ok)throw new RuntimeException('g7_live_contract_'.$label);
}
$entities=['entities'=>[
    ['entityName'=>'PST_PSCustomer__dll','displayName'=>'PST-PSCustomer'],
    ['entityName'=>'PST_PSOrder__dll','displayName'=>'PST-PSOrder']
]];
$cKey=Check::CUSTOMER_KEY;
$oKey=Check::ORDER_KEY;
$meta=function($name) {
    if ($name==='PST_PSCustomer__dll') {
        return ['metadata'=>[['fields'=>[['name'=>'source_key__c']]]]];
    }
    return ['metadata'=>[['fields'=>[
        ['name'=>'source_key__c'],['name'=>'customer_source_key__c']
    ]]]];
};
$calls=0;
$query=function($sql)use(&$calls,$cKey,$oKey) {
    $calls++;
    g7LiveAssert(substr_count($sql,'SELECT')===1
        && strpos($sql,'LIMIT 2')!==false
        && strpos($sql,'DELETE')===false
        && strpos($sql,'UPDATE')===false
        && strpos($sql,'INSERT')===false,
        'sql_not_fixed_select');
    if (strpos($sql,'PST_PSCustomer__dll')!==false) {
        g7LiveAssert(strpos($sql,$cKey)!==false,'customer_key_not_fixed');
        return ['data'=>[['source_key__c'=>$cKey]],'done'=>true];
    }
    g7LiveAssert(strpos($sql,$oKey)!==false,'order_key_not_fixed');
    return ['data'=>[['source_key__c'=>$oKey,
        'customer_source_key__c'=>$cKey]],'done'=>true];
};
$result=Check::verify($entities,$meta,$query);
g7LiveAssert($calls===2 && $result['customer_dlo_found']
    && $result['order_dlo_found']
    && $result['order_customer_source_fk_matches']
    && $result['salesforce_writes_performed']===false
    && $result['real_prestashop_events_transferred']===false,
    'positive_record_verification');
g7LiveAssert(strpos(json_encode($result),$cKey)===false
    && strpos(json_encode($result),'PST_')===false,'leak');

$wrongQuery=function($sql)use($cKey,$oKey) {
    if (strpos($sql,'PST_PSCustomer__dll')!==false) {
        return ['data'=>[['source_key__c'=>$cKey]],'done'=>true];
    }
    return ['data'=>[['source_key__c'=>$oKey,
        'customer_source_key__c'=>'foreign_merchant:1:customer:1']],'done'=>true];
};
try {
    Check::verify($entities,$meta,$wrongQuery);
    g7LiveAssert(false,'cross_tenant_fk_accepted');
} catch (RuntimeException $e) {
    g7LiveAssert($e->getMessage()==='g7_live_existing_canary_relation_unverified',
        'unexpected_fk_failure');
}
try {
    Check::verify(['entities'=>[]],$meta,$query);
    g7LiveAssert(false,'unknown_dlo_accepted');
} catch (RuntimeException $e) {
    g7LiveAssert($e->getMessage()==='g7_live_dlo_identity_unverified',
        'unexpected_no_dlo_error');
}
$bad=$entities;
$bad['entities'][]=$bad['entities'][0];
$bad['entities'][2]['entityName']='PST_PSCustomerAlt__dll';
try {
    Check::verify($bad,$meta,$query);
    g7LiveAssert(false,'ambiguous_display_accepted');
} catch (RuntimeException $e) {
    g7LiveAssert($e->getMessage()==='g7_live_dlo_identity_unverified',
        'unexpected_ambiguity_error');
}
$runner=file_get_contents(dirname(__DIR__)
    .'/tests/live/g7-existing-canary-data-cloud-readonly.php');
$workflow=file_get_contents(dirname(__DIR__)
    .'/.github/workflows/g7-existing-canary-readonly.yml');
g7LiveAssert(strpos($runner,'/api/v2/query')!==false
    && strpos($runner,'/api/v1/metadataEntities')!==false
    && strpos($workflow,'salesforce-growth-certification')!==false
    && strpos($runner,'/api/v1/ingest/')===false
    && strpos($runner,'GrowthDataCloudUpsertTransport')===false
    && strpos($workflow,'workflow_dispatch:')!==false,
    'remote_probe_boundary');
echo "G7 live canary read-only contract PASS: exact DLOs, FK, no ingress, no PII output.\n";
