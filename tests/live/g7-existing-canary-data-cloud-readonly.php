<?php
/**
 * Real remote G7 read-only reconciliation of previously materialized G5
 * synthetic canaries. Protected main GitHub environment only, no NEW upsert.
 * Output contains only allowlisted result flags; never SQL/records/secrets.
 */
$root=dirname(__DIR__,2);
require_once $root.'/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root.'/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once $root.'/tests/review/G7ExistingCanaryReadOnly.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Review\G7ExistingCanaryReadOnly as Verifier;

if (PHP_SAPI!=='cli'
    || getenv('GITHUB_REPOSITORY')!=='Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF')!=='refs/heads/main'
    || !in_array(getenv('GITHUB_EVENT_NAME'),['push','workflow_dispatch'],true)
    || getenv('G7_EXISTING_CANARY_MODE')!=='READ_ONLY_EXISTING_CANARIES'
    || getenv('G7_EXISTING_CANARY_EVIDENCE')!=='g7-existing-canary-verified.json') {
    fwrite(STDERR,"G7 protected main verification denied.\n");
    exit(2);
}
function g7RemoteJson($url,$token,$sql=null)
{
    if (!is_string($token) || $token===''
        || !is_string($url) || strpos($url,'https://')!==0) {
        throw new RuntimeException('g7_live_request_unverified');
    }
    $method=$sql===null?'GET':'POST';
    $handle=curl_init($url);
    if ($handle===false)throw new RuntimeException('g7_live_transport_unavailable');
    $response='';
    try {
        $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
        $opts=[
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>25,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_WRITEFUNCTION=>function($h,$chunk)use(&$response) {
                if (strlen($response)+strlen($chunk)>2097152)return 0;
                $response.=$chunk;
                return strlen($chunk);
            }
        ];
        if ($sql!==null) {
            // POST /api/v2/query is a SQL SELECT, NOT Ingestion API upsert.
            $opts[CURLOPT_POST]=true;
            $opts[CURLOPT_POSTFIELDS]=json_encode(['sql'=>$sql]);
            $opts[CURLOPT_HTTPHEADER][]='Content-Type: application/json';
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        }
        curl_setopt_array($handle,$opts);
        $ok=curl_exec($handle);
        $http=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
        if ($ok===false || $http!==200) {
            throw new RuntimeException('g7_live_remote_http_unverified');
        }
        $parsed=json_decode($response,true);
        if (!is_array($parsed)) {
            throw new RuntimeException('g7_live_remote_json_unverified');
        }
        return $parsed;
    } finally {
        curl_close($handle);
        unset($response,$headers);
    }
}
$evidence=[
    'version'=>1,
    'status'=>'g7_live_readonly_unverified',
    'run_id'=>(string)(getenv('GITHUB_RUN_ID')?:''),
    'commit_sha'=>(string)(getenv('GITHUB_SHA')?:''),
    'existing_g5_canaries_only'=>true,
    'real_prestashop_data_queried'=>false,
    'new_canaries_inserted'=>false,
    'ingestion_requests_performed'=>false,
    'remote_queries_read_only'=>true,
    'salesforce_campaign_sends_performed'=>false,
    'live_ingestion_authorized'=>false,
];
try {
    $auth=new AuthClient(
        (string)getenv('SF_GROWTH_ORG_URL'),
        (string)getenv('SF_GROWTH_CLIENT_ID'),
        (string)getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core=$auth->requestAccessToken();
    $exchange=new TokenExchangeClient();
    $cloud=$exchange->exchange($core['instance_url'],$core['access_token']);
    unset($auth,$core,$exchange);
    $origin=$cloud['instance_url'];
    $token=$cloud['access_token'];
    // URL is the canonical Salesforce-owned c360a origin validated by token exchange.
    $entities=g7RemoteJson(
        $origin.'/api/v1/metadataEntities?entityType=DataLakeObject',$token
    );
    $status=Verifier::verify(
        $entities,
        function($entityName)use($origin,$token) {
            return g7RemoteJson($origin.'/api/v1/metadata/?entityType=DataLakeObject'
                .'&entityName='.rawurlencode($entityName),$token);
        },
        function($sql)use($origin,$token) {
            return g7RemoteJson($origin.'/api/v2/query',$token,$sql);
        }
    );
    unset($cloud,$origin,$token,$entities);
    $evidence=array_merge($evidence,$status);
} catch (Throwable $e) {
    $error=(string)$e->getMessage();
    // Never copy arbitrary exception content (SQL, URLs, token or contact).
    if (!preg_match('/^g7_live_[a-z0-9_]{1,80}$/D',$error)) {
        $error='g7_live_auth_or_unclassified_error';
    }
    $evidence['error_code']=$error;
}
$evidence['completed_utc']=gmdate('c');
$encoded=json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)
    || file_put_contents('g7-existing-canary-verified.json',$encoded."\n")===false) {
    fwrite(STDERR,"G7 sanitized evidence unavailable.\n");
    exit(2);
}
if ($evidence['status']==='g7_existing_canary_dlo_verified_read_only') {
    echo "G7 existing customer/order canary DLO and foreign key verified in live Data Cloud. No new data sent.\n";
    exit(0);
}
fwrite(STDERR,"G7 remote DLO verification UNVERIFIED (no writes).\n");
exit(1);
