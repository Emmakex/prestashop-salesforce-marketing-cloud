<?php
/**
 * ABH G3 tenant metadata ONLY.
 * Runs on main in protected GitHub environment; no records/secret logging.
 * Built-in AuthClient makes one Core OAuth POST. Probe sends only fixed
 * metadata GET requests for five DMOs and bounded relationship pages.
 * No record endpoints, email addresses, identities or mapping writes.
 */
$root = dirname(dirname(__DIR__));
require_once $root . '/pssalesforcebridge/src/Growth/AuthClient.php';
require_once $root . '/tests/review/G3TenantDmoCatalogProbe.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;

if (getenv('GITHUB_REPOSITORY') !== 'Emmakex/prestashop-salesforce-marketing-cloud'
    || getenv('GITHUB_REF') !== 'refs/heads/main'
    || getenv('G3_TENANT_DMO_READ_ONLY') !== 'yes_metadata_only') {
    fwrite(STDERR, "G3 DMO catalog requires protected main only.\n");
    exit(2);
}
$path = getenv('SF_G3_DMO_EVIDENCE_PATH');
if (!is_string($path) || $path !== 'g3-abh-dmo-catalog-sanitized.json') {
    fwrite(STDERR, "G3 DMO catalog evidence path must be fixed.\n");
    exit(2);
}

$evidence = [
    'schema_version' => 1,
    'merchant' => 'ABH',
    'product' => 'marketing_cloud_growth_data_360',
    'connector_alias' => 'PST',
    'stage' => 'g3_data_360_dmo_catalog_readonly',
    'status' => 'failed',
    'commit_sha' => (string) (getenv('GITHUB_SHA') ?: ''),
    'run_id' => (string) (getenv('GITHUB_RUN_ID') ?: ''),
    'credential_values_recorded' => false,
    'record_values_recorded' => false,
    'salesforce_record_reads' => false,
    'salesforce_data_writes' => false,
    'schema_uploaded' => false,
    'data_stream_created' => false,
    'dlo_dmo_mapping_saved' => false,
    'marketing_messages_sent' => false,
    'owner_g3_approval' => false,
];
try {
    $auth = new AuthClient(
        (string) getenv('SF_GROWTH_ORG_URL'),
        (string) getenv('SF_GROWTH_CLIENT_ID'),
        (string) getenv('SF_GROWTH_CLIENT_SECRET')
    );
    $core = $auth->requestAccessToken();
    $probe = new G3TenantDmoCatalogProbe(
        $core['instance_url'], $core['access_token']
    );
    $metadata = $probe->inspect();
    // Separate fixed GET catalog for DLO->DMO mapping metadata. Missing
    // permissions, unsupported list shapes or endpoint errors never imply
    // there are zero mappings, nor invalidate the existing DMO metadata.
    try {
        $metadata['object_mappings'] = $probe->inspectObjectMappings();
    } catch (Throwable $mappingError) {
        $metadata['object_mappings'] = [
            'status' => 'mapping_metadata_unverified',
            'objects' => [],
            'dlo_dmo_mapping_save_authorized' => false,
            'schema_upload_authorized' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }
    unset($core, $auth, $probe);
    $evidence['status'] = 'metadata_read_success_review_only';
    $evidence['catalog'] = $metadata;
} catch (Throwable $error) {
    $code = $error->getMessage();
    if (!is_string($code)
        || !preg_match('/^(?:growth_oauth|g3_dmo)_[a-z0-9_]{1,80}$/D', $code)) {
        $code = 'g3_dmo_unknown_error';
    }
    $evidence['error_code'] = $code;
    fwrite(STDERR, "G3 Data 360 metadata check failed with sanitized code: "
        . $code . "\n");
}
$evidence['finished_at_utc'] = gmdate('c');
$json = json_encode($evidence,
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
    fwrite(STDERR, "G3 unable to write sanitized evidence.\n");
    exit(2);
}
if ($evidence['status'] !== 'metadata_read_success_review_only') {
    exit(1);
}
fwrite(STDOUT,
    "G3 Data 360 DMO metadata read succeeded. No record reads, mutations, mappings, ingestion or sends.\n");
