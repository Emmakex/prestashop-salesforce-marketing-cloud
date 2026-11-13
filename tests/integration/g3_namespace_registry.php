<?php
/**
 * G3 external namespace reservation simulation in disposable g3_ci only.
 * No production registry, credentials, Salesforce calls, or merchant records.
 */
require_once __DIR__ . '/G3NamespaceRegistrySandbox.php';

function g3NamespaceAssert($condition, $reason)
{
    if (!$condition) {
        throw new RuntimeException('g3_namespace_test_' . $reason);
    }
}

function g3NamespaceReject($action, $expected)
{
    try {
        call_user_func($action);
    } catch (RuntimeException $e) {
        g3NamespaceAssert($e->getMessage() === $expected,
            'wrong_error_' . $expected);
        return;
    }
    g3NamespaceAssert(false, 'unsafe_operation_allowed_' . $expected);
}

function g3NamespaceDb()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3NamespaceAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only',
        'not_disposable');
    return new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function g3NamespaceTest()
{
    $db = g3NamespaceDb();
    $registry = new G3NamespaceRegistrySandbox($db);
    g3NamespaceReject(function () use ($registry) {
        $registry->inspect('growth_ci_abhtest',
            '33c8fbd7-139d-46c9-a103-90e2e0a1c407',
            'ABH', 'marketing_cloud_growth');
    }, 'g3_namespace_registry_missing');

    $registry->createDisposableRegistry();
    g3NamespaceAssert($registry->countReservations() === 0,
        'registry_not_empty_initially');
    $a = '33c8fbd7-139d-46c9-a103-90e2e0a1c407';
    $clone = '44d9a1c3-2e8b-4a01-8c18-acc3f8c05150';
    $other = '8f223b19-7940-40da-bced-06568a136aa9';
    $first = $registry->reserve('growth_ci_abhtest', $a,
        'ABH', 'marketing_cloud_growth');
    g3NamespaceAssert($first === ['created' => true, 'blocked' => true, 'approved' => false],
        'new_reservation_must_be_pending');
    $same = $registry->reserve('growth_ci_abhtest', $a,
        'ABH', 'marketing_cloud_growth');
    g3NamespaceAssert($same === ['created' => false, 'blocked' => true, 'approved' => false],
        'idempotent_reservation_mismatch');

    // A copied store cannot claim the same namespace for a different
    // independently identified physical installation.
    g3NamespaceReject(function () use ($registry, $clone) {
        $registry->reserve('growth_ci_abhtest', $clone, 'ABH', 'marketing_cloud_growth');
    }, 'g3_namespace_collision_manual_review');
    g3NamespaceReject(function () use ($registry, $clone) {
        $registry->inspect('growth_ci_abhtest', $clone, 'ABH', 'marketing_cloud_growth');
    }, 'g3_namespace_attestation_mismatch');

    // Even an approved namespace must not be silently renamed on the
    // same external installation identity (migration requires review).
    g3NamespaceReject(function () use ($registry, $a) {
        $registry->reserve('growth_ci_newsite', $a, 'ABH', 'marketing_cloud_growth');
    }, 'g3_namespace_collision_manual_review');
    g3NamespaceReject(function () use ($registry, $a) {
        $registry->reserve('growth_ci_abhtest', $a, 'OTHER', 'marketing_cloud_growth');
    }, 'g3_namespace_collision_manual_review');
    g3NamespaceReject(function () use ($registry, $a) {
        $registry->reserve('growth_ci_abhtest', $a, 'ABH', 'marketing_cloud_engagement');
    }, 'g3_namespace_invalid_or_provider_mismatch');
    g3NamespaceReject(function () use ($registry, $a) {
        $registry->reserve('growth_ci_abhtest', $a, 'ABH', 'wrong_product');
    }, 'g3_namespace_invalid_or_provider_mismatch');

    foreach (['', 'abc', 'growth:bad', 'GROWTH_CI_ABHTEST', str_repeat('a', 33)]
        as $badNamespace) {
        g3NamespaceReject(function () use ($registry, $a, $badNamespace) {
            $registry->reserve($badNamespace, $a, 'ABH', 'marketing_cloud_growth');
        }, 'g3_namespace_invalid_or_provider_mismatch');
    }
    foreach (['not-a-uuid', '33c8fbd7-139d-16c9-a103-90e2e0a1c407',
        '33c8fbd7-139d-46c9-c103-90e2e0a1c407'] as $badId) {
        g3NamespaceReject(function () use ($registry, $badId) {
            $registry->reserve('growth_ci_other', $badId,
                'ABH', 'marketing_cloud_growth');
        }, 'g3_namespace_invalid_or_provider_mismatch');
    }

    // This registry is *global* to simulated installations rather than
    // a single shop: one namespace and one externally issued ID each.
    $otherRow = $registry->reserve('other_ci_install', $other,
        'OTHER', 'marketing_cloud_engagement');
    g3NamespaceAssert($otherRow['created'] === true
        && $registry->countReservations() === 2, 'separate_tenants_not_isolated');

    foreach ([
        ['growth_ci_abhtest', $a, 'ABH', 'marketing_cloud_growth'],
        ['other_ci_install', $other, 'OTHER', 'marketing_cloud_engagement'],
    ] as $args) {
        $result = call_user_func_array([$registry, 'inspect'], $args);
        g3NamespaceAssert($result === [
            'binding_consistent' => true,
            'owner_approved' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ], 'registry_attestation_cannot_authorize');
    }

    // MySQL 8/MariaDB 10 CHECK must reject direct attempts to mark ready.
    try {
        $db->exec("UPDATE g3_namespace_registry_ci_v1 SET status = 'ready'");
    } catch (PDOException $e) {
        // Expected in enforced CHECK engines.
    }
    g3NamespaceReject(function () use ($registry, $a) {
        $registry->removeSyntheticReservation('growth_ci_abhtest',
            '44d9a1c3-2e8b-4a01-8c18-acc3f8c05150');
    }, 'g3_namespace_cleanup_identity_mismatch');
    g3NamespaceReject(function () use ($registry) {
        $registry->destroyDisposableRegistry();
    }, 'g3_namespace_registry_nonempty_drop_refused');

    g3NamespaceAssert($registry->countReservations() === 2,
        'collision_or_tamper_mutated_records');
    $registry->removeSyntheticReservation('other_ci_install', $other);
    $registry->removeSyntheticReservation('growth_ci_abhtest', $a);
    $registry->destroyDisposableRegistry();
    g3NamespaceReject(function () use ($registry, $a) {
        $registry->inspect('growth_ci_abhtest', $a, 'ABH', 'marketing_cloud_growth');
    }, 'g3_namespace_registry_missing');
}

try {
    g3NamespaceTest();
    fwrite(STDOUT, "G3 CI-only independent namespace registry PASS: cloned installation rejected; send blocked.\n");
} catch (Throwable $e) {
    // Never output PDO exceptions, credentials or source data.
    fwrite(STDERR, "G3 namespace registry failed a disposable integrity gate.\n");
    exit(1);
}
