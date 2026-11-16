<?php
/**
 * ABH G6 read-only CSC Data Model Object metadata. No customer records
 * and no modification. Core OAuth is used, not PST ingestion.
 */
$root = dirname(dirname(__DIR__));
require_once $root.'/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root.'/tests/review/G6CscDmoMetadataProbe.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REPOSITORY')
        !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main'
    || getenv('G6_CSC_METADATA_MODE') !== 'metadata_get_no_records'
    || getenv('G6_CSC_METADATA_CONFIRM') !== 'metadata_get_no_records'
    || getenv('G6_CSC_METADATA_REPORT') !== 'g6-csc-dmo-metadata-sanitized.json') {
    fwrite(STDERR, "G6 CSC metadata denied: protected manual main workflow required.\n");
    exit(2);
}
$evidence = [
    'schema_version'=>1,
    'stage'=>'g6_csc_dmo_schema_metadata_get_only',
    'run_id'=>(string)(getenv('GITHUB_RUN_ID')?:''),
    'commit_sha'=>(string)(getenv('GITHUB_SHA')?:''),
    'status'=>'failed',
    'marketing_email_subscription_ui_verified'=>true,
    'personal_consent_records_read'=>false,
    'customer_data_read'=>false,
    'data_cloud_ingestion_attempted'=>false,
    'salesforce_mutations'=>false,
    'marketing_sends_authorized'=>false,
    'tokens_or_private_response_logged'=>false,
];
try {
    $auth=new AuthClient(
        (string)getenv('SF_GROWTH_ORG_URL'),
        (string)getenv('SF_GROWTH_CLIENT_ID'),
        (string)getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core=$auth->requestAccessToken();
    $out=G6CscDmoMetadataProbe::inspect($core['instance_url'],$core['access_token']);
    unset($auth,$core);
    $evidence['metadata']=$out;
    $evidence['status']=$out['status'];
} catch (Throwable $error) {
    $code=(string)$error->getMessage();
    if (!preg_match('/^(?:growth_oauth|g6_csc)_[a-z0-9_]{1,100}$/D',$code)) {
        $code='g6_csc_metadata_unknown_error';
    }
    $evidence['error_code']=$code;
    fwrite(STDERR, "G6 metadata probe: ".$code."\n");
}
$evidence['finished_at_utc']=gmdate('c');
$json=json_encode($evidence, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || file_put_contents('g6-csc-dmo-metadata-sanitized.json',$json."\n")===false) {
    fwrite(STDERR, "G6 metadata artifact failure.\n");
    exit(2);
}
if ($evidence['status'] !== 'csc_dmo_schema_candidate_seen') {
    fwrite(STDERR, "G6 CSC DMO metadata not fully verified; no consent decisions.\n");
    exit(1);
}
echo "G6 CSC DMO metadata visible: no records read, no writes or sends.\n";
