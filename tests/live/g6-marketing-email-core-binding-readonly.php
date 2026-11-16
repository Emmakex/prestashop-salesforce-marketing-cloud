<?php
/**
 * Manual G6 Core configuration-only review. No contact/consent record query,
 * no CSV export, no full Salesforce ID and no module interaction.
 */
$root=dirname(dirname(__DIR__));
require_once $root.'/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root.'/tests/review/G6MarketingEmailCoreBindingReview.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;

if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
    || getenv('GITHUB_REPOSITORY')
        !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main'
    || getenv('G6_CORE_BINDING_MODE') !== 'marketing_email_config_get_only'
    || getenv('G6_CORE_BINDING_CONFIRM') !== 'marketing_email_config_get_only') {
    fwrite(STDERR,"G6 Core binding review requires protected manual main execution.\n");
    exit(2);
}
$result=[
    'schema_version'=>1,
    'run_id'=>(string)(getenv('GITHUB_RUN_ID')?:''),
    'commit_sha'=>(string)(getenv('GITHUB_SHA')?:''),
    'status'=>'diagnostic_unverified',
    'marketing_sends_authorized'=>false,
    'salesforce_mutations_attempted'=>false,
    'consent_records_read'=>false,
    'ids_or_token_exported'=>false
];
try {
    $auth=new AuthClient((string)getenv('SF_GROWTH_ORG_URL'),
        (string)getenv('SF_GROWTH_CLIENT_ID'),
        (string)getenv('SF_GROWTH_CLIENT_SECRET'));
    $core=$auth->requestAccessToken();
    $binding=G6MarketingEmailCoreBindingReview::inspect(
        $core['instance_url'], $core['access_token']
    );
    unset($core,$auth);
    $result['binding']=$binding;
    $result['status']=$binding['single_marketing_channel_link_observed']
        ? 'core_marketing_channel_link_candidate_observed'
        : 'diagnostic_unverified';
} catch (Throwable $error) {
    $result['error_code']='g6_core_binding_auth_or_metadata_unverified';
}
$result['finished_at_utc']=gmdate('c');
$json=json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents(
        'g6-core-marketing-email-sanitized.json', $json."\n"
    ) === false) {
    fwrite(STDERR,"G6 Core binding review could not save sanitized artifact.\n");
    exit(2);
}
if ($result['status'] !== 'core_marketing_channel_link_candidate_observed') {
    fwrite(STDERR,"G6 Core technical binding not certified; no further actions authorized.\n");
    exit(1);
}
echo "G6 Core Marketing subscription-channel association candidate observed; channel type and consent remain unverified.\n";
