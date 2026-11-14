<?php
/**
 * Real module classes + disposable MySQL 8 / MariaDB 10 Growth outbox.
 * The hard-coded ephemeral credentials are CI fixtures ONLY. No shop DB,
 * hook, Salesforce API, mail, worker, PST schema or campaign is accessed.
 */
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/BlockedOutboxCoordinator.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxStore.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\BlockedOutboxCoordinator;

function g3BridgeOk($yes, $reason)
{
    if (!$yes) {
        throw new RuntimeException('g3_bridge_' . $reason);
    }
}
function g3BridgeDb()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    g3BridgeOk(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only',
        'sandbox_only');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Constructor independently validates CURRENT_USER, DATABASE and CI env.
    new G3GrowthOutboxSandboxMigration($db);
    return $db;
}
function g3BridgeCount(PDO $db, $table)
{
    if (!in_array($table, [
        'g3_growth_outbox_v1', 'g3_growth_entity_ledger_v1',
        'g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'
    ], true)) {
        throw new RuntimeException('g3_bridge_unapproved_table');
    }
    // Preserve absence as null. Growth-only CI never recreates old tables.
    if (strpos($table, 'g3_sfmc_') === 0) {
        $check = $db->query("SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'");
        if ((int) $check->fetchColumn() === 0) {
            return null;
        }
    }
    return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function g3BridgeCapture($router, $store, $shop, $entity, $id, $event, $hash)
{
    return BlockedOutboxCoordinator::capture(
        $router, $shop, $entity, $id, $event,
        $entity . ':' . $shop . ':' . $id . ':' . str_repeat($hash, 40),
        [$store, 'insertOrLoad']
    );
}
function g3BridgeRejected($callback, $reason)
{
    $rejected = false;
    try {
        call_user_func($callback);
    } catch (Throwable $e) {
        $rejected = true;
    }
    g3BridgeOk($rejected, $reason);
}
function g3BridgeTest(PDO $db)
{
    $legacy = [];
    foreach (['g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'] as $t) {
        $legacy[$t] = g3BridgeCount($db, $t);
    }
    $migration = new G3GrowthOutboxSandboxMigration($db);
    g3BridgeOk($migration->install() === true, 'fresh_growth_outbox_install');
    $store = new G3GrowthOutboxSandboxStore($db);
    $router = new ExplicitProviderRouter(
        '8.2.8', 'ABH', ExplicitProviderRouter::GROWTH, 'growth_ci_shop_a', 'test'
    );
    $other = new ExplicitProviderRouter(
        '9.1.5', 'ABH', ExplicitProviderRouter::GROWTH, 'growth_ci_shop_b', 'test'
    );
    $otherMerchant = new ExplicitProviderRouter(
        '1.7.8.11', 'OTHER_MERCHANT', ExplicitProviderRouter::GROWTH,
        'growth_ci_shop_c', 'test'
    );

    foreach (['outbox', 'ledger'] as $failStage) {
        $brokenStore = new G3GrowthOutboxSandboxStore($db, $failStage);
        g3BridgeRejected(function () use ($router, $brokenStore) {
            g3BridgeCapture($router, $brokenStore, 1, 'customer', 44,
                'CUSTOMER_CREATED', 'd');
        }, 'fault_' . $failStage . '_not_rejected');
        g3BridgeOk(g3BridgeCount($db, 'g3_growth_outbox_v1') === 0
            && g3BridgeCount($db, 'g3_growth_entity_ledger_v1') === 0,
            'partial_commit_after_' . $failStage);
    }

    $first = g3BridgeCapture($router, $store, 1, 'customer', 44, 'CUSTOMER_CREATED', 'a');
    $retry = g3BridgeCapture($router, $store, 1, 'customer', 44, 'CUSTOMER_UPDATED', 'a');
    $later = g3BridgeCapture($router, $store, 1, 'customer', 44, 'CUSTOMER_UPDATED', 'b');
    $oldReplay = g3BridgeCapture($router, $store, 1, 'customer', 44, 'CUSTOMER_CREATED', 'a');
    $otherShop = g3BridgeCapture($router, $store, 2, 'customer', 44, 'CUSTOMER_CREATED', 'a');
    $order = g3BridgeCapture($router, $store, 1, 'order', 44, 'ORDER_PAID', 'a');
    $otherInstall = g3BridgeCapture($other, $store, 1, 'customer', 44, 'CUSTOMER_CREATED', 'a');
    $otherTenant = g3BridgeCapture($otherMerchant, $store, 1, 'customer', 44,
        'CUSTOMER_CREATED', 'a');

    g3BridgeOk($first['inserted'] && !$retry['inserted']
        && $retry['captured_at_reused']
        && $first['accepted_sequence'] === $retry['accepted_sequence']
        && $first['captured_at_utc'] === $retry['captured_at_utc'],
        'duplicate_changed_utc_or_sequence');
    g3BridgeOk($later['accepted_sequence'] > $first['accepted_sequence']
        && !$oldReplay['inserted']
        && $oldReplay['accepted_sequence'] === $first['accepted_sequence'],
        'late_replay_changed_acceptance');
    $latest = $db->prepare(
        'SELECT latest_accepted_sequence FROM g3_growth_entity_ledger_v1'
        . ' WHERE source_namespace=? AND shop_id=? AND entity_type=? AND entity_id=?'
    );
    $latest->execute(['growth_ci_shop_a', 1, 'customer', 44]);
    g3BridgeOk((int) $latest->fetchColumn() === $later['accepted_sequence'],
        'late_replay_regressed_ledger');
    g3BridgeOk($otherShop['inserted'] && $order['inserted']
        && $otherInstall['inserted'] && $otherTenant['inserted']
        && g3BridgeCount($db, 'g3_growth_outbox_v1') === 6
        && g3BridgeCount($db, 'g3_growth_entity_ledger_v1') === 5,
        'collision_across_shop_entity_or_namespace');
    foreach ([$first, $retry, $later, $oldReplay, $otherShop, $order,
        $otherInstall, $otherTenant] as $result) {
        g3BridgeOk($result['status'] === 'blocked_local_only'
            && $result['marketing_sends_authorized'] === false
            && $result['outbound_worker_authorized'] === false
            && $result['data_360_ingestion_authorized'] === false
            && !isset($result['row']), 'unexpected_capability_or_data');
    }
    g3BridgeOk((int) $db->query(
        "SELECT COUNT(*) FROM g3_growth_outbox_v1 WHERE status <> 'blocked'"
    )->fetchColumn() === 0, 'unblocked_persistent_row');

    $before = g3BridgeCount($db, 'g3_growth_outbox_v1');
    g3BridgeRejected(function () {
        new ExplicitProviderRouter('8.2.8', 'OTHER_MERCHANT',
            'unsupported_external_product', 'growth_ci_other', 'test');
    }, 'non_growth_provider_was_accepted');
    g3BridgeRejected(function () use ($router, $store) {
        g3BridgeCapture($router, $store, 1, 'cart', 44,
            'CART_ABANDONED', 'a');
    }, 'unapproved_cart_schema_was_accepted');
    g3BridgeOk(g3BridgeCount($db, 'g3_growth_outbox_v1') === $before,
        'failed_validation_wrote_growth_event');
    g3BridgeRejected(function () use ($db) {
        $db->exec("UPDATE g3_growth_outbox_v1 SET status='ready'");
    }, 'sql_status_guard_bypass');
    g3BridgeRejected(function () use ($migration) {
        $migration->rollbackIfEmpty();
    }, 'nonempty_destructive_rollback');

    // Cleanup is permitted solely inside verified disposable g3_ci.
    $db->exec('DELETE FROM g3_growth_entity_ledger_v1');
    $db->exec('DELETE FROM g3_growth_outbox_v1');
    g3BridgeOk($migration->rollbackIfEmpty() === true, 'rollback_empty_growth_tables');
    foreach ($legacy as $t => $count) {
        g3BridgeOk(g3BridgeCount($db, $t) === $count,
            'legacy_table_was_modified_' . $t);
    }
}
try {
    g3BridgeTest(g3BridgeDb());
    echo "Growth real module router->blocked outbox: sandbox MySQL/MariaDB PASS; no sends.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Growth provider->outbox isolated CI failed at a safety gate.\n");
    exit(1);
}
