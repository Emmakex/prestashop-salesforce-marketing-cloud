<?php
/**
 * Real READ-ONLY G8 metadata and EXISTING synthetic canary check.
 * Runs only from a protected main GitHub workflow. It never reads live BC
 * customer/order keys, creates a canary, sends ingestion or enables cron.
 */
$root=dirname(__DIR__,2);
require_once $root.'/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root.'/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once $root.'/pssalesforcebridge/src/DataCloud/GrowthG8DloMetadataResolver.php';
require_once $root.'/pssalesforcebridge/src/DataCloud/GrowthG8DloPresenceReader.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8DloMetadataResolver as Metadata;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8DloPresenceReader as Presence;

if (PHP_SAPI!=='cli'
    || getenv('GITHUB_REPOSITORY')!=='Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF')!=='refs/heads/main'
    || !in_array(getenv('GITHUB_EVENT_NAME'),['push','workflow_dispatch'],true)
    || getenv('G8_DLO_READ_ONLY_MODE')!=='METADATA_AND_EXISTING_CANARY_ONLY'
    || getenv('G8_DLO_EVIDENCE_FILE')!=='g8-dlo-metadata-sanitized.json') {
    fwrite(STDERR,"G8 tenant read-only check refused.\n");
    exit(2);
}
/** All responses stay in memory; no URLs/tokens/SQL or body in logs. */
function g8MetadataGet($origin,$token,$path)
{
    if (!is_string($origin)
        || preg_match(
            '~^https://(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$~Di',
            $origin
        )!==1
        || !is_string($token) || $token==='' || strlen($token)>8192
        || !in_array($path,[
            '/api/v1/metadataEntities?entityType=DataLakeObject'
        ],true) && !preg_match(
            '~^/api/v1/metadata/\?entityType=DataLakeObject&entityName=[A-Za-z][A-Za-z0-9_]{2,95}$~D',
            $path
        )) {
        throw new RuntimeException('g8_metadata_http_invalid_request');
    }
    $ch=curl_init($origin.$path);
    if ($ch===false)throw new RuntimeException('g8_metadata_http_unavailable');
    $body='';
    try {
        curl_setopt_array($ch,[
            CURLOPT_HTTPGET=>true,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$token,'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>function($handle,$chunk)use(&$body) {
                $n=strlen($chunk);
                if (strlen($body)+$n>2097152)return 0;
                $body.=$chunk;
                return $n;
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
        }
        $ok=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if ($ok===false || $status!==200) {
            throw new RuntimeException('g8_metadata_http_unverified');
        }
        $answer=json_decode($body,true);
        if (!is_array($answer)) {
            throw new RuntimeException('g8_metadata_json_unverified');
        }
        return $answer;
    } finally {
        curl_close($ch);
    }
}

$evidence=[
    'version'=>1,
    'status'=>'metadata_unverified',
    'run_id'=>(string)(getenv('GITHUB_RUN_ID')?:''),
    'commit_sha'=>(string)(getenv('GITHUB_SHA')?:''),
    'tenant_metadata_read_only'=>true,
    'existing_synthetic_canary_only'=>true,
    'real_prestashop_data_queried'=>false,
    'dlo_api_names_exposed'=>false,
    'field_names_exposed'=>false,
    'records_exposed'=>false,
    'ingestion_requests_performed'=>false,
    'campaigns_performed'=>false,
    'automatic_sends_enabled'=>false,
    'exact_customer_metadata_verified'=>false,
    'exact_order_metadata_verified'=>false,
    'previous_customer_canary_found'=>false,
    'previous_order_canary_found'=>false,
];
try {
    $auth=new AuthClient(
        (string)getenv('SF_GROWTH_ORG_URL'),
        (string)getenv('SF_GROWTH_CLIENT_ID'),
        (string)getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core=$auth->requestAccessToken();
    $cloud=(new TokenExchangeClient())->exchange(
        $core['instance_url'],$core['access_token']
    );
    unset($auth,$core);
    $origin=$cloud['instance_url'];
    $token=$cloud['access_token'];
    $entities=g8MetadataGet(
        $origin,$token,'/api/v1/metadataEntities?entityType=DataLakeObject'
    );
    $resolved=Metadata::resolve(
        $entities,
        function($entityName)use($origin,$token) {
            return g8MetadataGet(
                $origin,$token,
                '/api/v1/metadata/?entityType=DataLakeObject&entityName='
                    .$entityName
            );
        }
    );
    $evidence['exact_customer_metadata_verified']=true;
    $evidence['exact_order_metadata_verified']=true;
    $evidence['status']='exact_metadata_verified';
    // The canaries are SYNTHETIC records that already exist. Query API V2
    // SELECT never creates/updates them. No real BC customer is accessed.
    $ns='growth_g5_canary_20261117';
    $id=987654321;
    $customer=Presence::inspect(
        $origin,$token,$ns,2,'PSCustomer',$id,
        $resolved['PSCustomer']['dlo'],$resolved['PSCustomer']['key']
    );
    $order=Presence::inspect(
        $origin,$token,$ns,2,'PSOrder',$id,
        $resolved['PSOrder']['dlo'],$resolved['PSOrder']['key']
    );
    $evidence['previous_customer_canary_found']=$customer==='present';
    $evidence['previous_order_canary_found']=$order==='present';
    if ($customer==='present' && $order==='present') {
        $evidence['status']='exact_metadata_and_existing_canaries_verified';
    } else {
        $evidence['status']='exact_metadata_canaries_unverified';
    }
    unset($origin,$token,$cloud,$entities,$resolved);
} catch (Throwable $ignored) {
    // No exception details: even a metadata failure may include tenant URL.
    $evidence['status']='metadata_unverified';
}
$evidence['completed_utc']=gmdate('c');
$out=json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if (!is_string($out)
    || file_put_contents('g8-dlo-metadata-sanitized.json',$out."\n")===false) {
    fwrite(STDERR,"G8 sanitized evidence unavailable.\n");
    exit(2);
}
if ($evidence['status']==='exact_metadata_and_existing_canaries_verified') {
    echo "G8 exact PST DLO metadata and existing synthetic records VERIFIED. No writes.\n";
    exit(0);
}
fwrite(STDERR,"G8 PST DLO metadata/canary not fully verified (no writes).\n");
exit(1);
