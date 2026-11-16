<?php
/**
 * G7 version check/claim-denial contract against existing G3 MySQL/MariaDB.
 * Synthetic customer/order records only. No PSCart, worker or Salesforce.
 */
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxStore.php';
require_once __DIR__ . '/G3GrowthBlockedVersionClaimReview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedAtomicUtcAcceptance;

function g7ClaimAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g7_g3_' . $reason);
    }
}
function g7ClaimConnect()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g7ClaimAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only',
        'sandbox_environment_invalid');
    return new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function g7ClaimAccept(PDO $db, $entityId, $character)
{
    $store = new G3GrowthOutboxSandboxStore($db);
    return StagedAtomicUtcAcceptance::accept(
        'growth_g7_ci', 'CUSTOMER_UPDATED', 'customer', 1, $entityId,
        'customer:1:' . $entityId . ':' . str_repeat($character, 40),
        [$store, 'insertOrLoad']
    );
}
function g7ClaimSafe(array $result, $state)
{
    g7ClaimAssert($result['status'] === 'hold_for_review'
        && $result['claim_review_state'] === $state,
        'incorrect_review_state');
    foreach ([
        'claim_persisted', 'claim_authorized',
        'local_cancellation_persisted',
        'transactional_delivery_fence_certified',
        'growth_consent_certified', 'cart_outbox_supported',
        'outbound_worker_authorized', 'data_cloud_ingestion_authorized',
        'marketing_sends_authorized'
    ] as $flag) {
        g7ClaimAssert($result[$flag] === false, 'unsafe_' . $flag);
    }
    $json = json_encode($result);
    foreach (['growth_g7_ci', 'synthetic@example.invalid',
        'customer:1:', '741', '742', 'SELECT', 'salesforce_token'] as $secret) {
        g7ClaimAssert(strpos($json, $secret) === false,
            'source_identity_leaked');
    }
}
function g7ClaimRun(PDO $db)
{
    $migration = new G3GrowthOutboxSandboxMigration($db);
    $new = $migration->install();
    $first = g7ClaimAccept($db, 741, 'a');
    $review = new G3GrowthBlockedVersionClaimReview($db);

    $noNegative = $review->inspect('growth_g7_ci', 1, 'customer', 741,
        $first['accepted_sequence'], function () { return 'clear'; });
    g7ClaimSafe($noNegative, 'version_matches_but_claim_forbidden');
    g7ClaimAssert($noNegative['expected_version_matches_ledger'],
        'matching_ledger_version_not_observed');

    $newRevision = g7ClaimAccept($db, 741, 'b');
    g7ClaimAssert($newRevision['accepted_sequence'] > $first['accepted_sequence'],
        'version_did_not_increment');

    $called = false;
    $stale = $review->inspect('growth_g7_ci', 1, 'customer', 741,
        $first['accepted_sequence'], function () use (&$called) {
            $called = true;
            return 'clear';
        });
    g7ClaimSafe($stale, 'stale_version_blocked');
    g7ClaimAssert(!$called && !$stale['expected_version_matches_ledger'],
        'stale_revision_entered_local_check');

    $localOptout = $review->inspect('growth_g7_ci', 1, 'customer', 741,
        $newRevision['accepted_sequence'], function () { return 'optout'; });
    g7ClaimSafe($localOptout, 'optout_cancellation_observed');
    g7ClaimAssert($localOptout['local_negative_observed'],
        'optout_cancellation_not_observed');

    $localPurchase = $review->inspect('growth_g7_ci', 1, 'customer', 741,
        $newRevision['accepted_sequence'], function () {
            return 'purchase_found';
        });
    g7ClaimSafe($localPurchase, 'purchase_cancellation_observed');
    g7ClaimAssert($localPurchase['local_negative_observed'],
        'purchase_cancellation_not_observed');

    $sourceDown = $review->inspect('growth_g7_ci', 1, 'customer', 741,
        $newRevision['accepted_sequence'], function () {
            throw new RuntimeException('do_not_include_in_report');
        });
    g7ClaimSafe($sourceDown, 'local_exclusion_unverified');
    g7ClaimSafe($review->inspect('growth_g7_ci', 1, 'customer', 741,
        $newRevision['accepted_sequence'], function () {
            return ['malformed' => 'synthetic@example.invalid'];
        }), 'local_exclusion_unverified');

    $wrongScope = $review->inspect('growth_g7_ci', 2, 'customer', 741,
        $newRevision['accepted_sequence'], function () { return 'clear'; });
    g7ClaimSafe($wrongScope, 'source_unverified');

    try {
        $review->inspect('growth_g7_ci', 1, 'cart', 741,
            $newRevision['accepted_sequence'], function () { return 'clear'; });
        g7ClaimAssert(false, 'unapproved_cart_was_accepted');
    } catch (RuntimeException $error) {
        g7ClaimAssert($error->getMessage()
            === 'g7_g3_claim_scope_or_callback_invalid',
            'cart_rejection_reason');
    }

    // Query existing rows only. The review did not mutate blocked statuses
    // or ledger accepted version; it cannot create a durable claim.
    $q = $db->prepare('SELECT latest_accepted_sequence, status'
        . ' FROM g3_growth_entity_ledger_v1'
        . " WHERE source_namespace='growth_g7_ci'"
        . " AND shop_id=1 AND entity_type='customer' AND entity_id=741");
    $q->execute();
    $ledger = $q->fetch(PDO::FETCH_ASSOC);
    g7ClaimAssert((int) $ledger['latest_accepted_sequence']
        === $newRevision['accepted_sequence']
        && $ledger['status'] === 'blocked',
        'review_mutated_existing_ledger');
    $q = $db->query("SELECT COUNT(*) FROM g3_growth_outbox_v1 "
        . "WHERE source_namespace='growth_g7_ci' AND status <> 'blocked'");
    g7ClaimAssert((int) $q->fetchColumn() === 0,
        'review_changed_outbox_status');

    // Only disposable CI rows owned by this test namespace are removed.
    $db->exec("DELETE FROM g3_growth_entity_ledger_v1 "
        . "WHERE source_namespace='growth_g7_ci'");
    $db->exec("DELETE FROM g3_growth_outbox_v1 "
        . "WHERE source_namespace='growth_g7_ci'");
    if ($new) {
        g7ClaimAssert($migration->rollbackIfEmpty() === true,
            'empty_sandbox_rollback_failed');
    }
}
try {
    g7ClaimRun(g7ClaimConnect());
    echo "G7/G3 blocked version review PASS: version drift, optout, purchase, unknown, no claims, no sends.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "G7/G3 blocked version review failed a CI safety gate.\n");
    exit(1);
}
