<?php
/**
 * Executes NEW production-module classes ONLY against disposable g3_ci.
 * Never against PrestaShop, merchant tables, Salesforce or marketing flows.
 */
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthSqlEngineGate.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthSandboxMigrationExecutor.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOutboxSchemaPlan;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSandboxMigrationExecutor;

function migrationAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g3_growth_migration_test_' . $reason);
    }
}
function migrationReject($fn, $expected)
{
    try {
        call_user_func($fn);
    } catch (RuntimeException $e) {
        migrationAssert($e->getMessage() === $expected, 'wrong_error_' . $expected);
        return;
    }
    migrationAssert(false, 'unsafe_accepted_' . $expected);
}
function migrationConnection()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    migrationAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only',
        'sandbox_environment_required');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    new G3GrowthOutboxSandboxMigration($db); // independent database and user guard.
    return $db;
}
function migrationExecutor(PDO $db, $prefix, $namespace, $merchant = 'ABH')
{
    return new GrowthSandboxMigrationExecutor(
        $db, '8.2.8', $merchant, 'marketing_cloud_growth',
        $namespace, 'test', $prefix
    );
}
function migrationCount(PDO $db, $table)
{
    migrationAssert(is_string($table)
        && preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $table), 'unsafe_identifier');
    if (strpos($table, 'g3_sfmc_') === 0) {
        $q = $db->query("SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'");
        if ((int) $q->fetchColumn() === 0) {
            return null; // preserve absence as well as existing row count
        }
    }
    return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function migrationMustFail($fn, $reason)
{
    try {
        call_user_func($fn);
    } catch (Throwable $e) {
        return;
    }
    migrationAssert(false, $reason);
}
function migrationLegacyBaseline(PDO $db)
{
    $out = [];
    foreach (['g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'] as $table) {
        $out[$table] = migrationCount($db, $table);
    }
    return $out;
}
function migrationAssertLegacy(PDO $db, array $counts)
{
    foreach ($counts as $table => $count) {
        migrationAssert(migrationCount($db, $table) === $count,
            'legacy_mutation_' . $table);
    }
}
function migrationTest(PDO $db)
{
    $legacy = migrationLegacyBaseline($db);
    $plans = [];
    $executors = [];

    foreach ([
        ['ci_a_', 'growth_ci_a', 'ABH'],
        ['ci_b_', 'growth_ci_b', 'OTHER'],
    ] as $row) {
        $prefix = $row[0];
        $namespace = $row[1];
        $merchant = $row[2];
        $plan = GrowthOutboxSchemaPlan::preview(
            '8.2.8', $merchant, 'marketing_cloud_growth',
            $namespace, 'test', $prefix
        );
        $executor = migrationExecutor($db, $prefix, $namespace, $merchant);
        $inspect = $executor->inspect();
        migrationAssert($inspect['status'] === 'absent_review_only'
            && $inspect['installation_authorized'] === false,
            'preflight_not_absent_' . $prefix);
        migrationAssert($executor->install() === true, 'first_install_' . $prefix);
        migrationAssert($executor->install() === false, 'idempotent_install_' . $prefix);
        migrationAssert($executor->inspect()['status'] === 'installed_blocked_sandbox',
            'installed_schema_not_verified_' . $prefix);
        foreach (['upgrade_authorized', 'rollback_in_production_authorized',
            'data_360_ingestion_authorized', 'marketing_sends_authorized'] as $flag) {
            migrationAssert($executor->inspect()[$flag] === false,
                'capability_enabled_' . $flag);
        }
        migrationAssert(migrationCount($db, $plan['tables']['binding']) === 1,
            'binding_missing_' . $prefix);
        migrationMustFail(function () use ($db, $plan) {
            $db->exec('UPDATE ' . $plan['tables']['binding']
                . " SET provider='marketing_cloud_engagement'");
        }, 'provider_check_not_enforced');
        $plans[] = $plan;
        $executors[] = $executor;
    }
    // The two prefixed installations coexist on the same disposable schema
    // without table/FK name collisions; each requires its own binding.
    migrationAssert($plans[0]['tables']['outbox'] !== $plans[1]['tables']['outbox'],
        'prefix_collision');

    foreach ($plans as $i => $plan) {
        $executor = $executors[$i];
        $namespace = $plan['source_namespace'];
        $outbox = $plan['tables']['outbox'];
        $statement = $db->prepare(
            'INSERT INTO ' . $outbox
            . ' (source_namespace, shop_id, entity_type, entity_id,'
            . ' dedupe_fingerprint, original_event_type, captured_at_utc,'
            . ' captured_at_precision, clock_source, clock_sync_verified,'
            . ' source_modified_provenance, status)'
            . ' VALUES (?, 1, ?, 87, ?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $statement->execute([
            $namespace, 'customer', str_repeat('a', 64), 'CUSTOMER_CREATED',
            '2026-11-14T16:00:00.000Z', 'seconds', 'php_unix_epoch_seconds',
            'unverified', 'blocked'
        ]);
        migrationAssert(migrationCount($db, $outbox) === 1, 'test_row_not_saved');
        migrationReject(function () use ($executor) {
            $executor->rollbackIfEmpty();
        }, 'growth_migration_rollback_nonempty_forbidden');
        migrationAssert(migrationCount($db, $outbox) === 1,
            'failed_rollback_deleted_event');
        // Reinstall cannot mutate an occupied or independently bound schema.
        migrationAssert($executor->install() === false, 'reinstall_changed_records');
        migrationReject(function () use ($db, $i) {
            migrationExecutor($db, $i === 0 ? 'ci_a_' : 'ci_b_',
                'growth_ci_wrong', $i === 0 ? 'ABH' : 'OTHER')->install();
        }, 'growth_migration_binding_conflict');
    }

    // Clear only CI-owned event fixtures; no production migration has a DROP.
    foreach ($plans as $i => $plan) {
        $db->exec('DELETE FROM ' . $plan['tables']['outbox']);
        migrationAssert($executors[$i]->rollbackIfEmpty() === true,
            'empty_rollback_failed_' . $i);
        migrationAssert($executors[$i]->rollbackIfEmpty() === false,
            'empty_rollback_repeat_changed_' . $i);
        migrationAssert($executors[$i]->inspect()['status'] === 'absent_review_only',
            'post_rollback_not_absent_' . $i);
    }

    // DDL implicit commit: interrupted install must halt, not self-heal.
    $partial = GrowthOutboxSchemaPlan::preview(
        '8.2.8', 'ABH', 'marketing_cloud_growth',
        'growth_ci_partial', 'test', 'ci_part_'
    );
    $db->exec($partial['create_sql'][0]); // CI-only partial-install fixture.
    $blocked = migrationExecutor($db, 'ci_part_', 'growth_ci_partial');
    migrationAssert($blocked->inspect()['status'] === 'partial_install_manual_review',
        'partial_install_not_seen');
    migrationReject(function () use ($blocked) {
        $blocked->install();
    }, 'growth_migration_partial_or_unknown_schema');
    migrationReject(function () use ($blocked) {
        $blocked->rollbackIfEmpty();
    }, 'growth_migration_partial_or_unknown_schema');
    migrationAssert(migrationCount($db, $partial['tables']['binding']) === 0,
        'partial_fixture_modified');
    $db->exec('DROP TABLE ' . $partial['tables']['binding']); // CI fixture repair.

    // Existing full but structurally drifted schema must never be accepted
    // as an idempotent install or deleted by rollback.
    $drift = GrowthOutboxSchemaPlan::preview(
        '8.2.8', 'ABH', 'marketing_cloud_growth',
        'growth_ci_drift', 'test', 'ci_drift_'
    );
    foreach ($drift['create_sql'] as $ddl) {
        $db->exec($ddl);
    }
    $stmt = $db->prepare('INSERT INTO ' . $drift['tables']['binding']
        . ' (source_namespace, merchant, provider, prestashop_version, mode, status)'
        . ' VALUES (?,?,?,?,?,?)');
    $stmt->execute($drift['binding_values']);
    $db->exec('ALTER TABLE ' . $drift['tables']['ledger']
        . ' ADD COLUMN unexpected_field INT NULL');
    $rejectDrift = migrationExecutor($db, 'ci_drift_', 'growth_ci_drift');
    migrationReject(function () use ($rejectDrift) {
        $rejectDrift->inspect();
    }, 'growth_migration_column_drift');
    migrationReject(function () use ($rejectDrift) {
        $rejectDrift->install();
    }, 'growth_migration_column_drift');
    migrationReject(function () use ($rejectDrift) {
        $rejectDrift->rollbackIfEmpty();
    }, 'growth_migration_column_drift');
    // CI-only operator dismantles the known-empty, deliberately damaged fixture.
    foreach ($drift['drop_order_if_empty'] as $name) {
        migrationAssert(migrationCount($db, $name) === ($name === $drift['tables']['binding'] ? 1 : 0),
            'drift_fixture_not_empty');
        $db->exec('DROP TABLE ' . $name);
    }

    putenv('G3_CI_ENABLE=disabled');
    migrationReject(function () use ($db) {
        migrationExecutor($db, 'ci_deny_', 'growth_ci_deny');
    }, 'growth_migration_sandbox_only');
    putenv('G3_CI_ENABLE=yes_sandbox_only');
    migrationAssertLegacy($db, $legacy);
}
try {
    migrationTest(migrationConnection());
    echo "Growth guarded prefixed migration executor: CI MySQL/MariaDB PASS; no shop access.\n";
} catch (Throwable $e) {
    // Deliberately do not print DSN, SQL, credentials or database error text.
    fwrite(STDERR, "Growth sandbox migration executor rejected at a safety gate.\n");
    exit(1);
}
