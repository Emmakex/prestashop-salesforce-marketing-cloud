<?php
/**
 * Offline-only G3 key lifecycle: CI g3_ci, synthetic RSA private keys in memory.
 * A cryptographic key lifecycle does NOT authorize G3 or Salesforce ingestion.
 */
require_once __DIR__ . '/G3InstallationProofSandbox.php';

function g3LifeAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g3_key_lifecycle_' . $reason);
    }
}
function g3LifeReject($callback, $expected)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        g3LifeAssert($e->getMessage() === $expected, 'wrong_error_' . $expected);
        return;
    }
    g3LifeAssert(false, 'unsafe_success_' . $expected);
}
function g3LifeKeypair()
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    g3LifeAssert($key !== false, 'ephemeral_key');
    $private = '';
    g3LifeAssert(openssl_pkey_export($key, $private), 'ephemeral_export');
    $details = openssl_pkey_get_details($key);
    g3LifeAssert(is_array($details) && isset($details['key']), 'public_key');
    return [$private, $details['key']];
}
function g3LifeSign($private, $namespace, $installation, $merchant, $provider, $challenge)
{
    $message = G3InstallationProofSandbox::signingMessage($namespace,
        $installation, $merchant, $provider, $challenge['nonce'], $challenge['expires_epoch']);
    $sig = '';
    g3LifeAssert(openssl_sign($message, $sig, $private, OPENSSL_ALGO_SHA256),
        'signature_failed');
    return base64_encode($sig);
}
function g3LifeTest()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3LifeAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only', 'ci_required');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $registry = new G3NamespaceRegistrySandbox($db);
    $proof = new G3InstallationProofSandbox($db, $registry);
    $ns = 'growth_ci_abhtest';
    $id = '33c8fbd7-139d-46c9-a103-90e2e0a1c407';
    $otherNs = 'other_ci_install';
    $otherId = '8f223b19-7940-40da-bced-06568a136aa9';
    $provider = 'marketing_cloud_growth';
    $registry->createDisposableRegistry();
    $proof->createDisposableTables();
    $registry->reserve($ns, $id, 'ABH', $provider);
    $registry->reserve($otherNs, $otherId, 'OTHER', $provider);
    list($oldPrivate, $oldPublic) = g3LifeKeypair();
    list($newPrivate, $newPublic) = g3LifeKeypair();
    list($otherPrivate, $otherPublic) = g3LifeKeypair();
    $oldHash = hash('sha256', $oldPublic);
    $newHash = hash('sha256', $newPublic);

    putenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE=yes_manual_ci_only');
    g3LifeAssert($proof->enrollByExternalOperator($ns, $id, 'ABH',
        $provider, $oldPublic), 'enroll_old');
    g3LifeAssert($proof->enrollByExternalOperator($otherNs, $otherId, 'OTHER',
        $provider, $otherPublic), 'enroll_other');
    putenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE');

    $stale = $proof->issueChallenge($ns, $id, 'ABH', $provider);
    $staleOldSig = g3LifeSign($oldPrivate, $ns, $id, 'ABH', $provider, $stale);

    g3LifeReject(function () use ($proof, $ns, $id, $provider, $oldHash, $newPublic) {
        $proof->rotateByExternalOperator($ns, $id, 'ABH', $provider,
            $oldHash, $newPublic);
    }, 'g3_proof_external_operator_required');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $newHash) {
        $proof->revokeByExternalOperator($ns, $id, 'ABH', $provider, $newHash);
    }, 'g3_proof_external_operator_required');

    putenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE=yes_manual_ci_only');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $newPublic) {
        $proof->rotateByExternalOperator($ns, $id, 'ABH', $provider,
            str_repeat('f', 64), $newPublic);
    }, 'g3_proof_rotation_conflict_manual_review');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $oldHash, $oldPublic) {
        $proof->rotateByExternalOperator($ns, $id, 'ABH', $provider,
            $oldHash, $oldPublic);
    }, 'g3_proof_rotation_conflict_manual_review');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $oldHash, $newPublic) {
        $proof->rotateByExternalOperator($ns, $id, 'ABH', 'marketing_cloud_engagement',
            $oldHash, $newPublic);
    }, 'g3_namespace_invalid_or_provider_mismatch');

    $rotated = $proof->rotateByExternalOperator($ns, $id, 'ABH', $provider,
        $oldHash, $newPublic);
    g3LifeAssert($rotated === [
        'key_version' => 2, 'status' => 'blocked',
        'identity_owner_approved' => false,
        'data_360_ingestion_authorized' => false,
        'marketing_sends_authorized' => false,
    ], 'rotation_authorized_outbound');
    // Original challenge signed under version 1 MUST be refused, even
    // if an attacker re-signs the old nonce with the new key.
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $stale, $staleOldSig) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $stale['nonce'], $stale['expires_epoch'], $staleOldSig);
    }, 'g3_proof_invalid_signature');
    $staleNewSig = g3LifeSign($newPrivate, $ns, $id, 'ABH', $provider, $stale);
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $stale, $staleNewSig) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $stale['nonce'], $stale['expires_epoch'], $staleNewSig);
    }, 'g3_proof_missing_expired_or_replayed');

    g3LifeReject(function () use ($proof, $ns, $id, $provider, $oldHash, $newPublic) {
        $proof->rotateByExternalOperator($ns, $id, 'ABH', $provider,
            $oldHash, $newPublic);
    }, 'g3_proof_rotation_conflict_manual_review');

    $fresh = $proof->issueChallenge($ns, $id, 'ABH', $provider);
    $oldOnFresh = g3LifeSign($oldPrivate, $ns, $id, 'ABH', $provider, $fresh);
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $fresh, $oldOnFresh) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $fresh['nonce'], $fresh['expires_epoch'], $oldOnFresh);
    }, 'g3_proof_invalid_signature');
    $newOnFresh = g3LifeSign($newPrivate, $ns, $id, 'ABH', $provider, $fresh);
    $valid = $proof->verifyOnce($ns, $id, 'ABH', $provider,
        $fresh['nonce'], $fresh['expires_epoch'], $newOnFresh);
    g3LifeAssert($valid === [
        'proof_verified' => true, 'identity_owner_approved' => false,
        'schema_upload_authorized' => false,
        'data_360_ingestion_authorized' => false,
        'marketing_sends_authorized' => false,
    ], 'new_key_cannot_send');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $fresh, $newOnFresh) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $fresh['nonce'], $fresh['expires_epoch'], $newOnFresh);
    }, 'g3_proof_missing_expired_or_replayed');

    $revokedPending = $proof->issueChallenge($ns, $id, 'ABH', $provider);
    $revokedPendingSig = g3LifeSign($newPrivate, $ns, $id, 'ABH', $provider, $revokedPending);
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $oldHash) {
        $proof->revokeByExternalOperator($ns, $id, 'ABH', $provider, $oldHash);
    }, 'g3_proof_revocation_conflict_manual_review');
    $revoked = $proof->revokeByExternalOperator($ns, $id, 'ABH', $provider, $newHash);
    g3LifeAssert($revoked === [
        'key_version' => 3, 'key_state' => 'revoked',
        'identity_owner_approved' => false,
        'data_360_ingestion_authorized' => false,
        'marketing_sends_authorized' => false,
    ], 'revoked_outbound_gate');

    g3LifeReject(function () use ($proof, $ns, $id, $provider) {
        $proof->issueChallenge($ns, $id, 'ABH', $provider);
    }, 'g3_proof_key_unenrolled_or_tampered');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $revokedPending, $revokedPendingSig) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $revokedPending['nonce'], $revokedPending['expires_epoch'], $revokedPendingSig);
    }, 'g3_proof_key_unenrolled_or_tampered');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $newHash) {
        $proof->revokeByExternalOperator($ns, $id, 'ABH', $provider, $newHash);
    }, 'g3_proof_key_unenrolled_or_tampered');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $newHash, $oldPublic) {
        $proof->rotateByExternalOperator($ns, $id, 'ABH', $provider,
            $newHash, $oldPublic);
    }, 'g3_proof_key_unenrolled_or_tampered');
    g3LifeReject(function () use ($proof, $ns, $id, $provider, $oldPublic) {
        $proof->enrollByExternalOperator($ns, $id, 'ABH', $provider, $oldPublic);
    }, 'g3_proof_key_rotation_manual_review');
    putenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE');

    $row = $db->query("SELECT status, key_state, key_version, revoked_at_epoch,
        public_key_sha256 FROM g3_install_proof_key_ci_v1
        WHERE source_namespace = 'growth_ci_abhtest'")->fetch(PDO::FETCH_ASSOC);
    g3LifeAssert($row['status'] === 'blocked' && $row['key_state'] === 'revoked'
        && (int) $row['key_version'] === 3 && (int) $row['revoked_at_epoch'] > 0
        && $row['public_key_sha256'] === $newHash, 'revoked_record_retained');

    // Revoking one tenant never revokes a different merchant's key.
    $otherChallenge = $proof->issueChallenge($otherNs, $otherId, 'OTHER', $provider);
    $otherSig = g3LifeSign($otherPrivate, $otherNs, $otherId,
        'OTHER', $provider, $otherChallenge);
    g3LifeAssert($proof->verifyOnce($otherNs, $otherId, 'OTHER', $provider,
        $otherChallenge['nonce'], $otherChallenge['expires_epoch'],
        $otherSig)['proof_verified'] === true, 'other_merchant_impacted');

    // Cleanup fixture only; no real registry deletes or provider contact.
    $proof->removeSyntheticChallenges();
    $proof->removeSyntheticEnrollment($ns, $id);
    $proof->removeSyntheticEnrollment($otherNs, $otherId);
    $proof->destroyDisposableTables();
    $registry->removeSyntheticReservation($ns, $id);
    $registry->removeSyntheticReservation($otherNs, $otherId);
    $registry->destroyDisposableRegistry();
}

try {
    g3LifeTest();
    fwrite(STDOUT, "G3 key lifecycle CI PASS: atomic rotation/revoke, stale proof blocked, tenants isolated.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "G3 key lifecycle failed an isolated safety gate.\n");
    exit(1);
}
