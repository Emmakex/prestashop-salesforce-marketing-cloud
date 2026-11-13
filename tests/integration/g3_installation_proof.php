<?php
/** Disposable g3_ci only; ephemeral RSA private keys never leave this process. */
require_once __DIR__ . '/G3InstallationProofSandbox.php';

function g3ProofAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g3_proof_test_' . $reason);
    }
}

function g3ProofReject($callable, $code)
{
    try {
        call_user_func($callable);
    } catch (RuntimeException $e) {
        g3ProofAssert($e->getMessage() === $code, 'unexpected_failure_' . $code);
        return;
    }
    g3ProofAssert(false, 'unsafe_success_' . $code);
}

function g3ProofKeypair()
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    g3ProofAssert($key !== false, 'cannot_make_ephemeral_key');
    $secret = null;
    g3ProofAssert(openssl_pkey_export($key, $secret), 'cannot_export_ephemeral_fixture');
    $details = openssl_pkey_get_details($key);
    g3ProofAssert(is_array($details) && isset($details['key']), 'missing_public_key');
    return [$secret, $details['key']];
}

function g3ProofSign($privatePem, $namespace, $installId, $merchant, $provider, $challenge)
{
    $message = G3InstallationProofSandbox::signingMessage(
        $namespace, $installId, $merchant, $provider,
        $challenge['nonce'], $challenge['expires_epoch']
    );
    $signature = '';
    g3ProofAssert(openssl_sign($message, $signature, $privatePem, OPENSSL_ALGO_SHA256),
        'signing_fixture_failed');
    return base64_encode($signature);
}

function g3ProofTest()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3ProofAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only', 'disposable_required');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $registry = new G3NamespaceRegistrySandbox($db);
    $proof = new G3InstallationProofSandbox($db, $registry);
    $ns = 'growth_ci_abhtest';
    $id = '33c8fbd7-139d-46c9-a103-90e2e0a1c407';
    $otherNs = 'another_ci_shop';
    $otherId = '8f223b19-7940-40da-bced-06568a136aa9';
    $cloneId = '44d9a1c3-2e8b-4a01-8c18-acc3f8c05150';
    $provider = 'marketing_cloud_growth';
    $registry->createDisposableRegistry();
    $proof->createDisposableTables();

    $registry->reserve($ns, $id, 'ABH', $provider);
    $registry->reserve($otherNs, $otherId, 'OTHER', $provider);
    list($private, $public) = g3ProofKeypair();
    list($clonePrivate, $clonePublic) = g3ProofKeypair();

    g3ProofReject(function () use ($proof, $ns, $id, $provider) {
        $proof->issueChallenge($ns, $id, 'ABH', $provider);
    }, 'g3_proof_key_unenrolled_or_tampered');
    g3ProofReject(function () use ($proof, $ns, $id, $provider, $public) {
        $proof->enrollByExternalOperator($ns, $id, 'ABH', $provider, $public);
    }, 'g3_proof_external_operator_required');

    // Simulated operator-held key registration; no shop can call this route.
    putenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE=yes_manual_ci_only');
    g3ProofAssert($proof->enrollByExternalOperator($ns, $id, 'ABH',
        $provider, $public) === true, 'operator_enrollment_failed');
    g3ProofAssert($proof->enrollByExternalOperator($ns, $id, 'ABH',
        $provider, $public) === false, 'same_key_not_idempotent');
    g3ProofReject(function () use ($proof, $ns, $id, $provider, $clonePublic) {
        $proof->enrollByExternalOperator($ns, $id, 'ABH', $provider, $clonePublic);
    }, 'g3_proof_key_rotation_manual_review');
    putenv('G3_CI_EXTERNAL_OPERATOR_FIXTURE');

    $challenge = $proof->issueChallenge($ns, $id, 'ABH', $provider);
    g3ProofAssert((bool) preg_match('/^[0-9a-f]{64}$/D', $challenge['nonce'])
        && $challenge['expires_epoch'] > time(), 'challenge_invalid');
    $signature = g3ProofSign($private, $ns, $id, 'ABH', $provider, $challenge);
    $wrong = g3ProofSign($clonePrivate, $ns, $id, 'ABH', $provider, $challenge);

    // A clone with the same database/namespace/UUID cannot produce a
    // signature using a distinct externally held key.
    g3ProofReject(function () use ($proof, $ns, $id, $provider, $challenge, $wrong) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $challenge['nonce'], $challenge['expires_epoch'], $wrong);
    }, 'g3_proof_invalid_signature');

    g3ProofReject(function () use ($proof, $ns, $id, $provider, $challenge, $signature) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $challenge['nonce'], $challenge['expires_epoch'] + 30, $signature);
    }, 'g3_proof_invalid_signature');

    g3ProofReject(function () use ($proof, $ns, $cloneId, $provider, $challenge, $signature) {
        $proof->verifyOnce($ns, $cloneId, 'ABH', $provider,
            $challenge['nonce'], $challenge['expires_epoch'], $signature);
    }, 'g3_namespace_attestation_mismatch');

    g3ProofReject(function () use ($proof, $ns, $id, $challenge, $signature) {
        $proof->verifyOnce($ns, $id, 'ABH', 'marketing_cloud_engagement',
            $challenge['nonce'], $challenge['expires_epoch'], $signature);
    }, 'g3_namespace_invalid_or_provider_mismatch');

    $good = $proof->verifyOnce($ns, $id, 'ABH', $provider,
        $challenge['nonce'], $challenge['expires_epoch'], $signature);
    g3ProofAssert($good === [
        'proof_verified' => true,
        'identity_owner_approved' => false,
        'schema_upload_authorized' => false,
        'data_360_ingestion_authorized' => false,
        'marketing_sends_authorized' => false,
    ], 'proof_unexpectedly_authorized_data');

    g3ProofReject(function () use ($proof, $ns, $id, $provider, $challenge, $signature) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $challenge['nonce'], $challenge['expires_epoch'], $signature);
    }, 'g3_proof_missing_expired_or_replayed');

    // A missing nonce cannot be forged, even with valid credentials.
    $missing = ['nonce' => str_repeat('f', 64), 'expires_epoch' => $challenge['expires_epoch']];
    $missingSig = g3ProofSign($private, $ns, $id, 'ABH', $provider, $missing);
    g3ProofReject(function () use ($proof, $ns, $id, $provider, $missing, $missingSig) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $missing['nonce'], $missing['expires_epoch'], $missingSig);
    }, 'g3_proof_missing_expired_or_replayed');

    // Time expiry, not just a tampered expiry argument.
    $expired = $proof->issueChallenge($ns, $id, 'ABH', $provider);
    $past = time() - 1;
    $update = $db->prepare('UPDATE g3_install_proof_challenge_ci_v1
        SET expires_epoch = ? WHERE nonce_sha256 = ?');
    $update->execute([$past, hash('sha256', $expired['nonce'])]);
    g3ProofAssert($update->rowCount() === 1, 'cannot_inject_expired_ci_challenge');
    $expired['expires_epoch'] = $past;
    $expiredSig = g3ProofSign($private, $ns, $id, 'ABH', $provider, $expired);
    g3ProofReject(function () use ($proof, $ns, $id, $provider, $expired, $expiredSig) {
        $proof->verifyOnce($ns, $id, 'ABH', $provider,
            $expired['nonce'], $expired['expires_epoch'], $expiredSig);
    }, 'g3_proof_missing_expired_or_replayed');

    // The other registered tenant is independent; it cannot borrow ABH's key.
    g3ProofReject(function () use ($proof, $otherNs, $otherId, $provider) {
        $proof->issueChallenge($otherNs, $otherId, 'OTHER', $provider);
    }, 'g3_proof_key_unenrolled_or_tampered');

    g3ProofReject(function () use ($proof) {
        $proof->destroyDisposableTables();
    }, 'g3_proof_nonempty_drop_forbidden');

    $proof->removeSyntheticChallenges();
    $proof->removeSyntheticEnrollment($ns, $id);
    $proof->destroyDisposableTables();
    $registry->removeSyntheticReservation($otherNs, $otherId);
    $registry->removeSyntheticReservation($ns, $id);
    $registry->destroyDisposableRegistry();
}

try {
    g3ProofTest();
    fwrite(STDOUT, "G3 external-key proof CI PASS: signed challenge, expiry, replay, clone isolation, all sends blocked.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "G3 external-key proof rejected at an isolated safety gate.\n");
    exit(1);
}
