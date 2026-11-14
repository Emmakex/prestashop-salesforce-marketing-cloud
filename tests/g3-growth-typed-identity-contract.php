<?php
/** G3: isolated, offline, fail-closed typed identity proposal for two synthetic PST objects. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionPayloadBuilder.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthPstSyntheticPreview.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/GrowthTypedIdentityCandidate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter as Router;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionPayloadBuilder as Builder;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthTypedIdentityCandidate as Candidate;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPstSyntheticPreview as Preview;

function typedAssert($ok, $message)
{
    if (!$ok) {
        fwrite(STDERR, 'G3 typed identity FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}
function typedReject($callback, $reason)
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        typedAssert($error->getMessage() === $reason,
            'wrong rejection: ' . $error->getMessage() . ' (expected ' . $reason . ')');
        return;
    }
    typedAssert(false, 'unsafe input was accepted: ' . $reason);
}
function typedRejectAny($callback, $reason)
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        return;
    }
    typedAssert(false, 'unsafe input was accepted: ' . $reason);
}
function typedFixture($version, $namespace, $shop, $customerId, $orderId, $cartId)
{
    $customer = (object) [
        'id' => $customerId, 'id_shop' => $shop,
        'email' => 'synthetic@fixture.example.invalid',
        'firstname' => 'Synthetic', 'lastname' => 'Fixture',
        'newsletter' => 1, 'optin' => 0, 'active' => 1,
        'date_upd' => '2026-11-10 14:00:00',
        '__growth_synthetic_fixture' => Preview::FIXTURE_MARKER,
    ];
    $order = (object) [
        'id' => $orderId, 'id_shop' => $shop, 'id_customer' => $customerId,
        'id_cart' => $cartId, 'total_paid' => '42.40', 'current_state' => 2,
        'date_upd' => '2026-07-10 14:00:00',
        'reference' => 'SYNTHETIC_ORDER',
        '__growth_synthetic_fixture' => Preview::FIXTURE_MARKER,
    ];
    $router = new Router($version, 'ABH', Router::GROWTH, $namespace, 'test');
    return Preview::build(
        $router, 'actionCustomerAccountAdd', ['newCustomer' => $customer],
        'actionValidateOrder', ['order' => $order, 'currency' => (object)['iso_code' => 'EUR']]
    );
}

putenv('G3_PST_SYNTHETIC_PREVIEW');
typedReject(function () {
    Candidate::project([]);
}, 'growth_typed_candidate_ci_only');
putenv('G3_PST_SYNTHETIC_PREVIEW=yes_disposable_ci_only');
$baseline = typedFixture('8.2.8', 'growth_ci_abhtwo', 2, 44, 44, 91);
$result = Candidate::project($baseline);
$c = json_decode($result['customer_payload'], true);
$o = json_decode($result['order_payload'], true);
typedAssert($result['status'] === 'blocked_typed_identity_review'
    && $result['schema_candidate'] === Candidate::VERSION,
    'incorrect candidate status');
typedAssert(array_keys($c) === ['data'] && array_keys($o) === ['data']
    && count($c['data']) === 1 && count($o['data']) === 1,
    'invalid object envelopes');
$c = $c['data'][0];
$o = $o['data'][0];
$fields = Builder::fields();
typedAssert(array_keys($c) === array_keys($fields['PSCustomer'])
    && array_keys($o) === array_keys($fields['PSOrder'])
    && count($c) + count($o) === 22, 'frozen 22-field shape changed');
typedAssert($baseline['cross_object_key_collision'] === true
    && $result['cross_object_key_collision'] === false
    && $c['source_key'] === 'growth_ci_abhtwo:2:customer:44'
    && $o['source_key'] === 'growth_ci_abhtwo:2:order:44'
    && $o['customer_source_key'] === $c['source_key']
    && $result['order_customer_fk'] === $result['customer_source_key']
    && $o['cart_source_key'] === 'growth_ci_abhtwo:2:cart:91',
    'typed primary/foreign keys not isolated');
typedAssert($o['paid_flag'] === false
    && $c['newsletter_flag'] === true
    && !isset($c['marketing_consent'])
    && !isset($c['source_updated_utc'])
    && $c['source_updated_local'] === '2026-11-10 14:00:00'
    && $result['cart_stream_approved'] === false
    && $result['same_shop_customer_fk_verified_locally'] === true,
    'invented paid, consent, UTC or cart ingest');
foreach ([
    'dmo_pk_fk_mapping_approved', 'identity_contract_approved',
    'global_namespace_uniqueness_approved', 'cart_stream_approved',
    'source_updated_utc_verified', 'marketing_consent_approved',
    'schema_upload_authorized', 'data_cloud_ingestion_authorized',
    'marketing_sends_authorized'
] as $blocked) {
    typedAssert($result[$blocked] === false, 'authorization leak: ' . $blocked);
}
typedRejectAny(function () use ($c) {
    (new Builder())->encode('PSCustomer', [$c]);
}, 'unapproved typed format passed frozen v0.2 validator');
typedAssert(Candidate::project($baseline) === $result, 'non-deterministic replay');

foreach (['1.7.8.11', '8.2.8', '9.1.5'] as $version) {
    $v = Candidate::project(typedFixture($version, 'growth_ci_abhtwo', 2, 44, 44, 91));
    typedAssert($v['customer_payload'] === $result['customer_payload']
        && $v['order_payload'] === $result['order_payload'],
        'prestashop identity drift: ' . $version);
}
foreach ([
    ['growth_ci_shopone', 1, 44, 44, 91],
    ['growth_ci_abhtwo', 3, 44, 44, 91],
    ['growth_ci_abhtwo', 2, 44, 45, 92],
] as $scope) {
    $v = Candidate::project(typedFixture('9.1.5', $scope[0], $scope[1],
        $scope[2], $scope[3], $scope[4]));
    typedAssert($v['customer_source_key'] !== $result['customer_source_key']
        || $v['order_source_key'] !== $result['order_source_key'],
        'independent installation/shop/entity collision');
    typedAssert($v['order_customer_fk'] === $v['customer_source_key'],
        'scoped customer fk not preserved');
}
$otherId = Candidate::project(typedFixture(
    '8.2.8', 'growth_ci_abhtwo', 2, 44, 45, 91
));
typedAssert($otherId['cross_object_key_collision'] === false
    && $otherId['order_source_key'] === 'growth_ci_abhtwo:2:order:45',
    'different IDs broke ordering');

$bad = $baseline;
$bad['schema_upload_authorized'] = true;
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_authorization_not_blocked');
$bad = $baseline;
$bad['customer_router_key'] = 'growth_ci_abhtwo:3:customer:44';
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_router_identity_mismatch');
$bad = $baseline;
$bad['cross_object_key_collision'] = false;
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_collision_evidence_mismatch');
$bad = $baseline;
$bad['provider'] = 'unsupported_external_product';
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_untrusted_preview');
$bad = $baseline;
$bad['customer_payload'] = 'invalid';
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_payload_invalid');
$bad = $baseline;
$row = json_decode($bad['customer_payload'], true);
$row['data'][0]['first_name'] = 'PRIVATE_CUSTOMER';
$bad['customer_payload'] = json_encode($row);
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_non_synthetic');
$bad = $baseline;
$row = json_decode($bad['customer_payload'], true);
$row['data'][0]['phone'] = 'PRIVATE_PHONE';
$bad['customer_payload'] = json_encode($row);
typedRejectAny(function () use ($bad) {
    Candidate::project($bad);
}, 'extra unapproved customer field');
$bad = $baseline;
$row = json_decode($bad['order_payload'], true);
$row['data'][0]['customer_source_key'] = 'growth_ci_abhtwo:ps_2_45';
$bad['order_payload'] = json_encode($row);
typedReject(function () use ($bad) {
    Candidate::project($bad);
}, 'growth_typed_candidate_orphan_or_scope_mismatch');
$bad = $baseline;
$row = json_decode($bad['order_payload'], true);
$row['data'][0]['cart_source_key'] = 'growth_ci_shopone:ps_1_91';
$bad['order_payload'] = json_encode($row);
typedRejectAny(function () use ($bad) {
    Candidate::project($bad);
}, 'cross-shop cart FK');
$bad = $baseline;
$row = json_decode($bad['order_payload'], true);
$row['data'][0]['source_updated_local'] = 'not-a-date';
$bad['order_payload'] = json_encode($row);
typedAssert(Candidate::project($bad)['source_updated_utc_verified'] === false,
    'local time wrongly interpreted as UTC');

$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
$candidateSource = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/DataCloud/GrowthTypedIdentityCandidate.php');
typedAssert(strpos($module, 'GrowthTypedIdentityCandidate') === false,
    'review code incorrectly wired to module runtime');
foreach (['curl_', 'file_get_contents(', 'Db::', 'Configuration::',
    '->execute(', '->insert(', '/api/v1/ingest/'] as $unsafe) {
    typedAssert(strpos($candidateSource, $unsafe) === false,
        'candidate has side effects: ' . $unsafe);
}
echo "G3 typed PST identity candidate PASS: 22 fields, unique PKs, same-shop FK, 3 PS versions, no sends.\n";
