<?php

$root = dirname(dirname(__DIR__));

require_once $root . '/pssalesforcebridge/src/Salesforce/AuthClient.php';
require_once $root . '/pssalesforcebridge/src/Salesforce/RestClient.php';
require_once $root . '/pssalesforcebridge/src/Salesforce/JourneyClient.php';
require_once $root . '/pssalesforcebridge/src/Customer/CustomerDataExtensionWriter.php';
require_once $root . '/pssalesforcebridge/src/Order/OrderDataExtensionWriter.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerDataExtensionWriter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderDataExtensionWriter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\JourneyClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\RestClient;

function cert_env($name, $required = true)
{
    $value = getenv($name);
    if ($value === false) {
        $value = '';
    }

    $value = trim((string) $value);
    if ($required && $value === '') {
        throw new RuntimeException($name . ' is required.');
    }

    return $value;
}

function cert_bool($value)
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'yes_i_understand'], true);
}

function cert_normalize_base_uri($value)
{
    return strtolower(rtrim(trim((string) $value), '/'));
}

function cert_redact($message, array $secrets)
{
    $message = (string) $message;
    foreach ($secrets as $secret) {
        $secret = (string) $secret;
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }

    return $message;
}

function cert_write_evidence(array $evidence)
{
    $path = getenv('SFMC_CERT_EVIDENCE_PATH');
    if ($path === false || trim((string) $path) === '') {
        $path = 'sfmc-live-certification.json';
    }

    $encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Unable to encode certification evidence.\n");
        return false;
    }

    return file_put_contents($path, $encoded . "\n") !== false;
}

$stage = cert_env('SFMC_CERT_STAGE', false);
if ($stage === '') {
    $stage = 'auth';
}

$allowedStages = ['auth', 'data_extensions', 'journey'];
if (!in_array($stage, $allowedStages, true)) {
    fwrite(STDERR, "Unsupported SFMC certification stage.\n");
    exit(2);
}

$evidence = [
    'schema_version' => 1,
    'status' => 'running',
    'stage' => $stage,
    'commit_sha' => cert_env('GITHUB_SHA', false),
    'run_id' => cert_env('GITHUB_RUN_ID', false),
    'run_attempt' => cert_env('GITHUB_RUN_ATTEMPT', false),
    'started_at_utc' => gmdate('c'),
    'auth' => ['attempted' => false, 'success' => false],
    'data_extensions' => ['attempted' => false, 'success' => false],
    'journey' => ['attempted' => false, 'success' => false],
];

$currentStep = 'preflight';
$secrets = [];

try {
    if ($stage !== 'auth' && !cert_bool(cert_env('SFMC_CERT_CONFIRM_LIVE_WRITE', false))) {
        throw new RuntimeException('Live write confirmation is required for this certification stage.');
    }

    $authBaseUri = cert_env('SFMC_AUTH_BASE_URI');
    $restBaseUri = cert_env('SFMC_REST_BASE_URI');
    $clientId = cert_env('SFMC_CLIENT_ID');
    $clientSecret = cert_env('SFMC_CLIENT_SECRET');
    $accountId = cert_env('SFMC_ACCOUNT_ID', false);
    $secrets = [$clientId, $clientSecret, $accountId];

    $currentStep = 'auth';
    $evidence['auth']['attempted'] = true;

    $authClient = new AuthClient($authBaseUri, $clientId, $clientSecret, $accountId);
    $token = $authClient->requestAccessToken();

    $accessToken = isset($token['access_token']) ? trim((string) $token['access_token']) : '';
    if ($accessToken === '') {
        throw new RuntimeException('Salesforce authentication succeeded without an access token.');
    }
    $secrets[] = $accessToken;

    $returnedRestBaseUri = isset($token['rest_instance_url']) ? trim((string) $token['rest_instance_url']) : '';
    if ($returnedRestBaseUri === '') {
        throw new RuntimeException('Salesforce token response does not contain rest_instance_url.');
    }

    if (cert_normalize_base_uri($restBaseUri) !== cert_normalize_base_uri($returnedRestBaseUri)) {
        throw new RuntimeException('Configured REST Base URI does not match the tenant rest_instance_url returned by Salesforce.');
    }

    $scopeString = isset($token['scope']) ? trim((string) $token['scope']) : '';
    $scopes = $scopeString === '' ? [] : preg_split('/\s+/', $scopeString);
    if (!is_array($scopes)) {
        $scopes = [];
    }

    $evidence['auth'] = [
        'attempted' => true,
        'success' => true,
        'rest_instance_url_matches_config' => true,
        'expires_in' => isset($token['expires_in']) ? (int) $token['expires_in'] : null,
        'scope_flags' => [
            'data_extensions_write' => in_array('data_extensions_write', $scopes, true),
            'journeys_read' => in_array('journeys_read', $scopes, true),
            'list_and_subscribers_read' => in_array('list_and_subscribers_read', $scopes, true),
        ],
    ];

    if ($stage === 'auth') {
        $evidence['status'] = 'success';
        $evidence['finished_at_utc'] = gmdate('c');
        cert_write_evidence($evidence);
        fwrite(STDOUT, "SFMC auth certification passed.\n");
        exit(0);
    }

    $restClient = new RestClient($restBaseUri, $accessToken);
    $customerDeKey = cert_env('SFMC_CUSTOMER_DE_KEY');
    $orderDeKey = cert_env('SFMC_ORDER_DE_KEY');
    $certEmail = cert_env('SFMC_CERT_EMAIL');
    if (filter_var($certEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('SFMC_CERT_EMAIL is not a valid email address.');
    }

    $runKey = cert_env('SFMC_CERT_RUN_KEY', false);
    if ($runKey === '') {
        $runKey = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    }
    $runHash = hash('sha256', $runKey);

    $explicitContactKey = cert_env('SFMC_CERT_CONTACT_KEY', false);
    $contactKey = $explicitContactKey !== '' ? $explicitContactKey : 'ps_cert_' . substr($runHash, 0, 24);
    $customerId = 900000000 + (hexdec(substr($runHash, 0, 5)) % 999999);
    $cartId = 910000000 + (hexdec(substr($runHash, 5, 5)) % 999999);
    $orderId = 920000000 + (hexdec(substr($runHash, 10, 5)) % 999999);
    $shopId = 999999;
    $timestamp = gmdate('Y-m-d H:i:s');

    $currentStep = 'data_extensions';
    $evidence['data_extensions']['attempted'] = true;

    $customerWriter = new CustomerDataExtensionWriter($restClient, $customerDeKey);
    $customerWriter->upsert([
        'contact_key' => $contactKey,
        'customer_id' => $customerId,
        'shop_id' => $shopId,
        'email' => $certEmail,
        'firstname' => 'SFMC',
        'lastname' => 'Certification',
        'newsletter' => true,
        'optin' => true,
        'active' => true,
        'language_id' => 1,
        'date_add' => $timestamp,
        'date_upd' => $timestamp,
    ]);

    $orderWriter = new OrderDataExtensionWriter($restClient, $orderDeKey);
    $orderWriter->upsert([
        'order_key' => 'ps_cert_order_' . substr($runHash, 0, 20),
        'order_id' => $orderId,
        'shop_id' => $shopId,
        'cart_id' => $cartId,
        'customer_id' => $customerId,
        'contact_key' => $contactKey,
        'reference' => 'SFMC-CERT-' . substr($runHash, 0, 8),
        'currency_id' => 1,
        'currency' => 'EUR',
        'total_paid' => 0.01,
        'total_products' => 0.01,
        'total_shipping' => 0.0,
        'total_discounts' => 0.0,
        'payment' => 'SFMC certification',
        'module' => 'pssalesforcebridge',
        'state_id' => 0,
        'paid' => false,
        'valid' => false,
        'event_type' => 'ORDER_CREATED',
        'date_add' => $timestamp,
        'date_upd' => $timestamp,
    ]);

    $evidence['data_extensions'] = [
        'attempted' => true,
        'success' => true,
        'customer_upsert' => true,
        'order_upsert' => true,
        'test_contact_key_sha256' => hash('sha256', $contactKey),
    ];

    if ($stage === 'data_extensions') {
        $evidence['status'] = 'success';
        $evidence['finished_at_utc'] = gmdate('c');
        cert_write_evidence($evidence);
        fwrite(STDOUT, "SFMC Data Extension certification passed.\n");
        exit(0);
    }

    if ($explicitContactKey === '') {
        throw new RuntimeException('SFMC_CERT_CONTACT_KEY is required for Journey certification and must identify a dedicated active test contact.');
    }

    $eventDefinitionKey = cert_env('SFMC_JOURNEY_EVENT_DEFINITION_KEY');
    $recoveryUrl = cert_env('SFMC_CERT_RECOVERY_URL', false);
    if ($recoveryUrl === '') {
        $recoveryUrl = 'https://example.invalid/sfmc-certification/' . substr($runHash, 0, 16);
    }

    $currentStep = 'journey';
    $evidence['journey']['attempted'] = true;

    $journeyClient = new JourneyClient($restClient, $eventDefinitionKey);
    $journeyResult = $journeyClient->fire($contactKey, [
        'BridgeEventKey' => 'sfmc_cert_' . substr($runHash, 0, 24),
        'CartKey' => 'ps_cert_cart_' . substr($runHash, 0, 20),
        'CartId' => $cartId,
        'ShopId' => $shopId,
        'CustomerId' => $customerId,
        'ContactKey' => $contactKey,
        'Email' => $certEmail,
        'Firstname' => 'SFMC',
        'Lastname' => 'Certification',
        'Currency' => 'EUR',
        'Total' => 0.01,
        'ProductCount' => 1,
        'ItemCount' => 1,
        'LastActivity' => $timestamp,
        'AbandonedAt' => $timestamp,
        'SnapshotHash' => sha1($runKey),
        'RecoveryUrl' => $recoveryUrl,
    ]);

    $evidence['journey'] = [
        'attempted' => true,
        'success' => true,
        'event_instance_id' => isset($journeyResult['event_instance_id']) ? (string) $journeyResult['event_instance_id'] : null,
        'test_contact_key_sha256' => hash('sha256', $contactKey),
    ];

    $evidence['status'] = 'success';
    $evidence['finished_at_utc'] = gmdate('c');
    cert_write_evidence($evidence);
    fwrite(STDOUT, "SFMC Journey API certification passed.\n");
    exit(0);
} catch (Throwable $e) {
    $evidence['status'] = 'failed';
    $evidence['failure_stage'] = $currentStep;
    $evidence['error'] = cert_redact($e->getMessage(), $secrets);
    $evidence['finished_at_utc'] = gmdate('c');
    cert_write_evidence($evidence);

    fwrite(STDERR, 'SFMC live certification failed at ' . $currentStep . ': ' . cert_redact($e->getMessage(), $secrets) . "\n");
    exit(1);
}
