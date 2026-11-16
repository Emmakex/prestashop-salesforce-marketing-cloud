<?php
/**
 * ABH G6 read-only consent topology. 1 Core OAuth + exactly 2 metadata GETs.
 * No customers, consent rows, stream writes, PST validation, or campaigns.
 */
$root=dirname(dirname(__DIR__));
require_once $root.'/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root.'/tests/review/G6ConsentSourceTopologyProbe.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;

if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REPOSITORY')
        !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main'
    || getenv('G6_TOPOLOGY_MODE') !== 'two_metadata_gets_no_records'
    || getenv('G6_TOPOLOGY_CONFIRM') !== 'two_metadata_gets_no_records'
    || getenv('G6_TOPOLOGY_EVIDENCE') !== 'g6-consent-topology-sanitized.json') {
    fwrite(STDERR,"G6 topology requires protected manual main execution.\n");
    exit(2);
}
$evidence=[
    'schema_version'=>1,
    'stage'=>'g6_consent_topology_readonly',
    'run_id'=>(string)(getenv('GITHUB_RUN_ID')?:''),
    'commit_sha'=>(string)(getenv('GITHUB_SHA')?:''),
    'status'=>'failed',
    'csc_dmo_schema_already_verified'=>true,
    'marketing_email_subscription_ui_verified'=>true,
    'consent_records_read'=>false,
    'customer_data_read'=>false,
    'salesforce_writes_performed'=>false,
    'marketing_sends_authorized'=>false,
    'tokens_urls_raw_responses_exported'=>false,
];
try {
    $client=new AuthClient(
        (string)getenv('SF_GROWTH_ORG_URL'),
        (string)getenv('SF_GROWTH_CLIENT_ID'),
        (string)getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core=$client->requestAccessToken();
    $result=G6ConsentSourceTopologyProbe::inspect(
        $core['instance_url'],$core['access_token']
    );
    unset($core,$client);
    $evidence['metadata']=$result;
    $mappingOK = $result['csc_mapping']['state']
        === 'mapping_metadata_observed_review_only';
    $streamsOK = $result['consent_related_streams']['state']
        === 'first_page_observed_review_only';
    $evidence['status'] = $mappingOK && $streamsOK
        ? 'diagnostic_observed_review_only'
        : ($mappingOK || $streamsOK
            ? 'diagnostic_partial_review_only'
            : 'diagnostic_unverified');
} catch (Throwable $error) {
    $code=(string)$error->getMessage();
    if (!preg_match('/^(?:growth_oauth|g6_topology)_[a-z0-9_]{1,100}$/D',$code)) {
        $code='g6_topology_unknown_failure';
    }
    $evidence['error_code']=$code;
    fwrite(STDERR,"G6 topology error: ".$code."\n");
}
$evidence['finished_at_utc']=gmdate('c');
$json=json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || file_put_contents('g6-consent-topology-sanitized.json',$json."\n")===false) {
    fwrite(STDERR,"G6 topology could not save sanitized artifact.\n");
    exit(2);
}
if (!in_array($evidence['status'],[
    'diagnostic_observed_review_only','diagnostic_partial_review_only'
],true)) {
    fwrite(STDERR,"G6 topology unavailable: both metadata reads unverified; see sanitized artifact.\n");
    exit(1);
}
fwrite(STDOUT,"G6 consent topology recorded with sanitized partial/complete evidence; no consent or sends.\n");
