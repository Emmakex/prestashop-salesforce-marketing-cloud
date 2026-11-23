<?php
/** G8 Data Cloud DLO exact-key query, synthetic, absolutely NO real HTTP. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthG8DloPresenceReader.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8DloPresenceReader as Dlo;

function dloAssert($ok,$why) {
    if (!$ok)throw new RuntimeException('g8_dlo_presence_contract_'.$why);
}
$origin='https://tenant.credencial.c360a.salesforce.com';
$ns='pssfmc_bc_prod_20261120';
$shop=1;
$expected=$ns.':1:customer:6031';
$calls=0;
$fixture=function($rows,$done=true,$count=null,$code=200,$other=[])
    use (&$calls,$expected) {
    return function($url,$payload,$token)use(
        &$calls,$rows,$done,$count,$code,$other,$expected
    ) {
        ++$calls;
        dloAssert(
            $url==='https://tenant.credencial.c360a.salesforce.com/api/v2/query'
            && $token==='test_oauth_token_not_real','endpoint_or_token');
        $q=json_decode($payload,true);
        dloAssert(
            is_array($q) && isset($q['sql'])
            && $q['sql']===
                'SELECT "source_key__c" FROM "PST_PSCustomer_Test__dll"'
                ." WHERE \"source_key__c\" = '".$expected."' LIMIT 2",
            'not_exact_readonly_key_sql');
        return [
            'status'=>$code,
            'body'=>json_encode(array_merge([
                'data'=>$rows,
                'done'=>$done,
                'rowCount'=>$count===null?count($rows):$count,
                'metadata'=>['source_key__c'=>['type'=>'VARCHAR']],
            ],$other))
        ];
    };
};
$read=function($http)use($origin,$ns,$shop) {
    return Dlo::inspect(
        $origin,'test_oauth_token_not_real',$ns,$shop,'PSCustomer',
        6031,'PST_PSCustomer_Test__dll','source_key__c',$http
    );
};
// G8 protected certification must distinguish unknown from confirmed absence
// without exposing a query, key, token, object name, response or exception.
$diagnose=function($http)use($origin,$ns,$shop) {
    return Dlo::inspectWithEvidence(
        $origin,'test_oauth_token_not_real',$ns,$shop,'PSCustomer',
        6031,'PST_PSCustomer_Test__dll','source_key__c',$http
    );
};
foreach ([
    [$fixture([['source_key__c'=>$expected]]),'present','exact_match'],
    [$fixture([]),'absent_confirmed','complete_empty'],
    [$fixture([],false),'unknown','batch_unverified'],
    [$fixture([],true,1),'unknown','batch_unverified'],
    [$fixture(['not_a_row_object']),'unknown','row_not_object'],
    [$fixture([['other_column'=>'value']]),'unknown','row_field_missing'],
    [$fixture([['source_key__c'=>123]]),'unknown','row_value_not_string'],
    [$fixture([['source_key__c'=>'wrong']]),'unknown','row_key_not_exact'],
    [$fixture([],true,0,400),'unknown','http_status_400'],
    [$fixture([],true,0,403),'unknown','http_status_403'],
    [$fixture([],true,0,404),'unknown','http_status_404'],
    [$fixture([],true,0,429),'unknown','http_status_429'],
    [$fixture([],true,0,503),'unknown','http_status_503'],
    [$fixture([],true,0,418),'unknown','http_status_other'],
    [$fixture([],true,0,200,['nextBatchId'=>'next']),'unknown','batch_unverified']
] as $case) {
    $diag=$diagnose($case[0]);
    dloAssert($diag===['result'=>$case[1],'reason'=>$case[2]],
        'restricted_result_reason');
}
dloAssert($diagnose(function() {
    return ['status'=>200,'body'=>'not-json'];
})===['result'=>'unknown','reason'=>'json_unverified'],
    'json_failure_reason');
$privateDiagnostic=$diagnose(function() {
    throw new RuntimeException('http://secret.invalid token=private email=x@y.test');
});
dloAssert($privateDiagnostic===[
    'result'=>'unknown','reason'=>'transport_exception'
] && strpos(json_encode($privateDiagnostic),'private')===false,
    'exception_diagnostic_leak');
dloAssert($diagnose(function() {
    return ['status'=>'400','body'=>'secret'];
})===['result'=>'unknown','reason'=>'transport_unverified'],
    'non_numeric_status_exposed');

dloAssert($read($fixture([['source_key__c'=>$expected]]))==='present',
    'present_exact_match');
dloAssert($read($fixture([]))==='absent_confirmed',
    'complete_empty_not_absent');
dloAssert($read($fixture([],false))==='unknown','partial_empty_claimed_absent');
dloAssert($read($fixture([],true,1))==='unknown','count_mismatch');
dloAssert($read($fixture([['source_key__c'=>'other']]))==='unknown',
    'wrong_key_claimed_present');
dloAssert($read($fixture([['other'=>'value']]))==='unknown',
    'missing_column_claimed_present');
dloAssert($read($fixture([['source_key__c'=>$expected]],true,1,401))
    ==='unknown','401_claimed_present');
dloAssert($read($fixture([],true,0,500))==='unknown','500_claimed_absent');
dloAssert($read($fixture([],true,0,200,['nextBatchId'=>'more']))
    ==='unknown','pagination_claimed_absent');
dloAssert($read($fixture([],true,0,200,['done'=>false]))
    ==='unknown','explicit_done_false_claimed_absent');
dloAssert($read($fixture([],true,0,200,['rowCount'=>'0']))
    ==='unknown','string_count_claimed_absent');
dloAssert($read($fixture(
    [['source_key__c'=>$expected],['source_key__c'=>$expected]]
))==='present','multi_matches_not_present');
$throws=function(){throw new RuntimeException(
    'sql body email=private@example.test access_token=private'
);};
dloAssert($read($throws)==='unknown','raw_transport_exception');
$bad=[
    'http://tenant.credencial.c360a.salesforce.com',
    'https://attacker.tld',
    'https://tenant.credencial.c360a.salesforce.com.evil.tld',
    'https://tenant.credencial.c360a.salesforce.com:443',
    'https://tenant.credencial.c360a.salesforce.com/api/v2/query',
    'https://name:pass@tenant.credencial.c360a.salesforce.com',
    'https://tenant.credencial.c360a.salesforce.com?x=1',
    'https://tenant.credencial.c360a.salesforce.com#fragment'
];
foreach($bad as $url) {
    dloAssert(Dlo::inspect(
        $url,'token',$ns,1,'PSCustomer',6031,
        'PST_PSCustomer_Test__dll','source_key__c',$fixture([])
    )==='unknown','unsafe_origin_accepted');
}
$after=$calls;
foreach([
    [6031,'PST_PSCustomer_Test__dll','source_key__c; DROP TABLE'],
    [6031,'PST_PSCustomer_Test__dll; DELETE', 'source_key__c'],
    [6031,'PST_PSCustomer_Test__dll','source key'],
    [0,'PST_PSCustomer_Test__dll','source_key__c']
] as $item) {
    dloAssert(Dlo::inspect(
        $origin,'token',$ns,1,'PSCustomer',
        $item[0],$item[1],$item[2],$fixture([])
    )==='unknown','invalid_identifier_not_rejected');
}
dloAssert($calls===$after,'invalid_input_triggered_network');
dloAssert(Dlo::inspect(
    $origin,'token',$ns,1,'PSCustomer',6031,
    'PST_PSCustomer_Test__dll','source_key__c',
    function(){return ['status'=>200,'body'=>'{"rowCount":0}'];}
)==='unknown','malformed_json_claimed_absent');

$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthG8DloPresenceReader.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
dloAssert(strpos($module,'GrowthG8DloPresenceReader')===false
    && strpos($cron,'GrowthG8DloPresenceReader')===false
    && strpos($source,"'/api/v2/query'")!==false
    && strpos($source,"LIMIT 2")!==false
    && strpos($source,"absent_confirmed")!==false
    && strpos($source,"CURLOPT_SSL_VERIFYPEER=>true")!==false
    && strpos($source,"CURLOPT_FOLLOWLOCATION=>false")!==false,
    'unapproved_live_wiring_or_tls_missing');

echo "G8 DLO presence PASS: exact key, absence only on complete HTTP 200, no injection/replay or raw PII.\n";
