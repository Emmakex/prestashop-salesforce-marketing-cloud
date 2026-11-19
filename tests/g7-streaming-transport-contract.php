<?php
/**
 * G7 HTTP transport contract. All records, tokens, endpoints and replies
 * are synthetic and the injected transport performs ZERO network calls.
 */
ob_start();
require __DIR__.'/g4-pst-typed-payload-fixture.php';
$fixture=json_decode(ob_get_clean(),true);
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/DataCloud/GrowthDataCloudUpsertTransport.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthDataCloudUpsertTransport as Client;

function g7TransportAssert($ok,$label) {
    if (!$ok) throw new RuntimeException('g7_transport_contract_'.$label);
}
function g7TransportReject($call,$reason) {
    try {$call();} catch (RuntimeException $e) {
        g7TransportAssert($e->getMessage()===$reason,'wrong_rejection_'.$e->getMessage());
        return;
    }
    g7TransportAssert(false,'missing_rejection_'.$reason);
}
$host='https://synthetic.c360a.salesforce.com';
$source='ABH_PrestaShop_Data_Cloud';
$namespace='growth_ci_abhtwo';
$rows=$fixture['PSCustomer']['data'];
$token='synthetic-secret-token-not-for-output';
$counter=0;
$permit=function($ns,$shop,$obj)use($namespace) {
    return $ns===$namespace && $shop===2 && $obj==='PSCustomer';
};
$http=function($url,$body,$gotToken)use(&$counter,$host,$source,$rows,$token) {
    $counter++;
    g7TransportAssert($url===$host.'/api/v1/ingest/sources/'
        .$source.'/PSCustomer','wrong_upsert_endpoint');
    g7TransportAssert($gotToken===$token,'token_changed');
    g7TransportAssert(json_decode($body,true)===['data'=>$rows],
        'invalid_typed_payload');
    return ['status'=>202,'response_received'=>true];
};
$result=Client::sendIfAuthorized(
    $host,$source,'PSCustomer',$rows,$namespace,2,$token,$permit,$http
);
g7TransportAssert($counter===1,'post_count');
g7TransportAssert($result['status']==='ingestion_transport_result_requires_reconciliation'
    && $result['outcome_state']==='accepted_asynchronously_unconfirmed'
    && $result['accepted_asynchronously_unconfirmed']
    && $result['reconciliation_required']
    && $result['http_attempted']
    && $result['record_persisted_certified']===false
    && $result['record_mapped_to_dmo_certified']===false
    && $result['automatic_retry_authorized']===false
    && $result['marketing_sends_authorized']===false,
    '202_misclassified');
g7TransportAssert(strpos(json_encode($result),$token)===false
    && strpos(json_encode($result),'synthetic.c360a')===false
    && strpos(json_encode($result),'source_key')===false
    && strpos(json_encode($result),'@')===false,
    'pii_token_or_host_leak');

$deny=function(){return false;};
g7TransportReject(function()use($host,$source,$rows,$namespace,$token,$deny,$http) {
    Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,
        $namespace,2,$token,$deny,$http);
},'g7_transport_not_authorized');
g7TransportAssert($counter===1,'network_on_denial');
g7TransportReject(function()use($host,$source,$rows,$namespace,$token,$http) {
    Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,
        $namespace,2,$token,function(){throw new RuntimeException('private');},$http);
},'g7_transport_not_authorized');
g7TransportAssert($counter===1,'network_on_gate_exception');
g7TransportReject(function()use($host,$source,$rows,$namespace,$token,$http) {
    Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,
        $namespace,2,$token,'not_a_callback',$http);
},'g7_transport_gate_required');
g7TransportReject(function()use($host,$source,$rows,$namespace,$permit,$http) {
    Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,
        $namespace,2,"bad\nbearer",$permit,$http);
},'g7_transport_invalid_token');
g7TransportReject(function()use($source,$rows,$namespace,$token,$permit,$http) {
    Client::sendIfAuthorized('https://attacker.com',$source,'PSCustomer',
        $rows,$namespace,2,$token,$permit,$http);
},'g7_streaming_origin_invalid');
g7TransportReject(function()use($host,$source,$rows,$namespace,$token,$http) {
    Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,$namespace,
        3,$token,function(){return true;},$http);
},'g7_streaming_cross_shop_or_namespace');
g7TransportAssert($counter===1,'network_after_invalid_plan');

foreach ([
    ['status'=>401,'response_received'=>true],
    ['status'=>429,'response_received'=>true],
    ['status'=>0,'response_received'=>false],
    ['status'=>202,'response_received'=>false]
] as $response) {
    $r=Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,
        $namespace,2,$token,$permit,function()use($response){return $response;});
    g7TransportAssert($r['reconciliation_required']
        && !$r['record_persisted_certified']
        && !$r['automatic_retry_authorized']
        && !$r['marketing_sends_authorized'],'unsafe_failure');
}
$broken=Client::sendIfAuthorized($host,$source,'PSCustomer',$rows,
    $namespace,2,$token,$permit,function(){throw new RuntimeException('pii');});
g7TransportAssert($broken['outcome_state']==='unknown_after_transport'
    && strpos(json_encode($broken),'pii')===false,'transport_error_leak');

$module=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__).'/pssalesforcebridge/controllers/front/cron.php');
g7TransportAssert(strpos($module,'GrowthDataCloudUpsertTransport')===false
    && strpos($cron,'GrowthDataCloudUpsertTransport')===false,
    'unapproved_live_wiring');
echo "G7 gated streaming transport PASS: 202, 401/429/timeout, no retry, no exposure, no unapproved runtime wiring.\n";
