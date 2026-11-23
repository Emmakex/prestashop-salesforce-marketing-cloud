<?php
/**
 * Exact runtime PrestaShop Db contract on disposable PrestaShop Docker.
 *
 * This tests real Db::getInstance() implementations, never a mock. All SQL
 * writes target one temporary table; no permanent Growth table, hook, module
 * upgrade, Salesforce credential, customer or order is touched.
 */
if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop'
    || getenv('PS_ROOT') !== '/var/www/html'
    || !in_array(getenv('EXPECTED_PS_VERSION'), ['1.7.8.11', '8.2.8', '9.1.5'], true)) {
    fwrite(STDERR, "PrestaShop Db Growth preflight requires disposable runtime CI.\n");
    exit(1);
}

chdir('/var/www/html');
require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Compatibility/CustomerHookAdapter.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Compatibility/OrderHookAdapter.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/BlockedOutboxCoordinator.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/GrowthBlockedHookAdapter.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/GrowthSqlEngineGate.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/PrestaShopDbBlockedOutboxStore.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\PrestaShopDbBlockedOutboxStore;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSqlEngineGate;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthBlockedHookAdapter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;

function growthDbRuntimeAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('growth_real_db_' . $reason);
    }
}
function growthDbRuntimeRun()
{
    growthDbRuntimeAssert((string) _PS_VERSION_ === getenv('EXPECTED_PS_VERSION')
        && defined('_DB_PREFIX_') && _DB_PREFIX_ === 'ps_'
        && Module::isInstalled('pssalesforcebridge')
        && (string) Module::getInstanceByName('pssalesforcebridge')->version === '0.9.7'
        && (string) Configuration::get('PS_SFMC_MODE') === 'test',
        'unexpected_shop_or_module_state');
    $db = Db::getInstance();
    growthDbRuntimeAssert((string) $db->getValue('SELECT DATABASE()', false)
        === 'prestashop', 'not_disposable_database');

    // Actual runtime Docker is pinned to MySQL 5.7 for all three PrestaShop
    // versions. This must be recognized as CHECK-incompatible, not silently
    // blessed because the PHP module runs on the shop.
    $nativeReport = GrowthSqlEngineGate::inspectPrestaShop($db);
    growthDbRuntimeAssert(
        $nativeReport['engine'] === 'mysql'
        && $nativeReport['status'] === 'blocked'
        && $nativeReport['check_constraints_enforced'] === false
        && $nativeReport['schema_migration_authorized'] === false
        && $nativeReport['growth_outbound_authorized'] === false,
        'mysql57_allowed_unsafe_growth_schema'
    );

    $legacy = [];
    foreach (['sfmc_queue', 'sfmc_customer_sync', 'sfmc_order_sync'] as $suffix) {
        $legacy[$suffix] = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $suffix, false
        );
    }

    // Verify Growth stores are still not installed by the legacy module.
    foreach (['growth_provider_binding_v1', 'growth_outbox_v1',
        'growth_entity_ledger_v1'] as $suffix) {
        $count = $db->getValue(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ps_" . $suffix . "'",
            false
        );
        growthDbRuntimeAssert((int) $count === 0, 'growth_auto_installed');
    }
    // The new module class must refuse a real PrestaShop DB regardless of
    // outside CI environment variables; G3 live migration is not approved.
    $blocked = false;
    try {
        new PrestaShopDbBlockedOutboxStore(
            $db, getenv('EXPECTED_PS_VERSION'), 'ABH',
            'marketing_cloud_growth', 'growth_ci_runtime',
            'test', _DB_PREFIX_
        );
    } catch (RuntimeException $e) {
        $blocked = $e->getMessage() === 'growth_db_store_sandbox_only';
    }
    growthDbRuntimeAssert($blocked, 'store_accessed_real_shop_database');

    // TEMPORARY creates no permanent schema and is bound to the DB session.
    $table = 'ps_g3_growth_db_api_contract';
    growthDbRuntimeAssert($db->execute(
        'CREATE TEMPORARY TABLE ' . $table . ' ('
        . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . ' fingerprint VARCHAR(64) NOT NULL,'
        . " status VARCHAR(16) NOT NULL DEFAULT 'blocked',"
        . ' UNIQUE KEY uq_fingerprint (fingerprint)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    ), 'create_temp_table');
    try {
        growthDbRuntimeAssert($db->execute(
            "INSERT INTO " . $table
            . " (fingerprint,status) VALUES ('runtime_a','blocked')"
        ), 'insert');
        $id = (int) $db->Insert_ID();
        growthDbRuntimeAssert($id > 0, 'insert_id');
        growthDbRuntimeAssert((int) $db->getValue(
            'SELECT COUNT(*) FROM ' . $table, false
        ) === 1, 'read_without_cache');

        $lock = hash('sha256', 'growth-runtime-db-api:' . getenv('EXPECTED_PS_VERSION'));
        growthDbRuntimeAssert((string) $db->getValue(
            "SELECT GET_LOCK('" . $lock . "', 10)", false
        ) === '1', 'get_lock');
        try {
            growthDbRuntimeAssert($db->execute('START TRANSACTION'), 'begin');
            $rows = $db->executeS(
                'SELECT id, fingerprint, status FROM ' . $table
                . " WHERE fingerprint='runtime_a' FOR UPDATE",
                true, false
            );
            growthDbRuntimeAssert(is_array($rows) && count($rows) === 1
                && (int) $rows[0]['id'] === $id
                && $rows[0]['status'] === 'blocked', 'for_update_no_cache');

            growthDbRuntimeAssert($db->execute(
                "INSERT INTO " . $table
                . " (fingerprint,status) VALUES ('runtime_rollback','blocked')"
            ), 'transaction_insert');
            growthDbRuntimeAssert((int) $db->getValue(
                'SELECT COUNT(*) FROM ' . $table, false
            ) === 2, 'transaction_read');
            growthDbRuntimeAssert($db->execute('ROLLBACK'), 'rollback');
            growthDbRuntimeAssert((int) $db->getValue(
                'SELECT COUNT(*) FROM ' . $table, false
            ) === 1, 'rollback_did_not_revert');

            growthDbRuntimeAssert($db->execute('START TRANSACTION'), 'begin_commit');
            growthDbRuntimeAssert($db->execute(
                "INSERT INTO " . $table
                . " (fingerprint,status) VALUES ('runtime_commit','blocked')"
            ), 'commit_insert');
            growthDbRuntimeAssert($db->execute('COMMIT'), 'commit');
            growthDbRuntimeAssert((int) $db->getValue(
                'SELECT COUNT(*) FROM ' . $table, false
            ) === 2, 'commit_not_durable_within_session');
        } finally {
            growthDbRuntimeAssert((string) $db->getValue(
                "SELECT RELEASE_LOCK('" . $lock . "')", false
            ) === '1', 'release_lock');
        }
        // Use the SAME named lock repeatedly; cached GET_LOCK/RELEASE_LOCK
        // values would be invalid evidence of exclusivity.
        growthDbRuntimeAssert((string) $db->getValue(
            "SELECT IS_FREE_LOCK('" . $lock . "')", false
        ) === '1', 'lock_not_released');
        growthDbRuntimeAssert((string) $db->getValue(
            "SELECT GET_LOCK('" . $lock . "', 10)", false
        ) === '1', 'reacquire_lock');
        growthDbRuntimeAssert((string) $db->getValue(
            "SELECT RELEASE_LOCK('" . $lock . "')", false
        ) === '1', 'release_second_lock');
        growthDbRuntimeAssert((string) $db->getValue(
            "SELECT IS_FREE_LOCK('" . $lock . "')", false
        ) === '1', 'second_lock_not_released');
    } finally {
        $db->execute('DROP TEMPORARY TABLE IF EXISTS ' . $table);
    }

    growthDbRuntimeAssert((string) Configuration::get('PS_SFMC_MODE') === 'test',
        'legacy_mode_modified');
    foreach ($legacy as $suffix => $count) {
        growthDbRuntimeAssert((int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $suffix, false
        ) === $count, 'legacy_table_modified');
    }
    // Three actual PrestaShop version runtimes must understand the existing
    // hook object shapes. An in-memory atomic callback proves the bridge can
    // call its Growth coordinator WITHOUT installing Growth tables or hooks.
    $router = new ExplicitProviderRouter(
        getenv('EXPECTED_PS_VERSION'), 'ABH',
        ExplicitProviderRouter::GROWTH, 'growth_ci_runtime', 'test'
    );
    $memoryRows = [];
    $nextSequence = 0;
    $memoryStore = function (array $identity, $factory)
        use (&$memoryRows, &$nextSequence) {
        $key = $identity['source_namespace'] . ':'
            . $identity['dedupe_fingerprint'];
        if (isset($memoryRows[$key])) {
            return ['row' => $memoryRows[$key], 'inserted' => false];
        }
        $row = $factory();
        $row['accepted_sequence'] = ++$nextSequence;
        $memoryRows[$key] = $row;
        return ['row' => $row, 'inserted' => true];
    };
    $syntheticCustomer = (object) [
        'id' => 701, 'id_shop' => 1,
        'email' => 'not-for-export@example.invalid'
    ];
    $firstHook = GrowthBlockedHookAdapter::capture(
        $router, 'actionCustomerAccountAdd',
        ['newCustomer' => $syntheticCustomer],
        'customer:1:701:' . str_repeat('a', 40), $memoryStore
    );
    $retryHook = GrowthBlockedHookAdapter::capture(
        $router, 'actionCustomerAccountUpdate',
        ['customer' => $syntheticCustomer],
        'customer:1:701:' . str_repeat('a', 40), $memoryStore
    );
    $orderHook = GrowthBlockedHookAdapter::capture(
        $router, 'actionValidateOrder',
        ['order' => (object)['id' => 702, 'id_shop' => 1]],
        'order:1:702:' . str_repeat('b', 40), $memoryStore
    );
    growthDbRuntimeAssert($firstHook['inserted'] === true
        && $retryHook['inserted'] === false
        && $orderHook['inserted'] === true
        && $retryHook['captured_at_reused'] === true
        && $nextSequence === 2
        && count($memoryRows) === 2
        && $firstHook['marketing_sends_authorized'] === false
        && $orderHook['data_360_ingestion_authorized'] === false
        && strpos(json_encode([$firstHook, $orderHook]),
            'not-for-export@example.invalid') === false,
        'native_hook_shapes_or_blocked_outbox_failed');

    // Unscoped PrestaShop customer fallback to Context shop 1 must NOT
    // be allowed to enter Growth, even with an otherwise valid dedupe key.
    $unscopedRejected = false;
    try {
        GrowthBlockedHookAdapter::capture(
            $router, 'actionCustomerAccountAdd',
            ['customer' => (object)['id' => 703]],
            'customer:1:703:' . str_repeat('c', 40), $memoryStore
        );
    } catch (RuntimeException $e) {
        $unscopedRejected = $e->getMessage()
            === 'growth_hook_explicit_scope_required';
    }
    growthDbRuntimeAssert($unscopedRejected
        && count($memoryRows) === 2
        && $nextSequence === 2
        && (string) Configuration::get('PS_SFMC_MODE') === 'test',
        'unscoped_hook_mutated_runtime');

    echo "Growth PrestaShop real Db contract passed (temporary table only).\n";
}
try {
    growthDbRuntimeRun();
} catch (Throwable $e) {
    fwrite(STDERR, "Growth real PrestaShop Db contract failed at a protected gate.\n");
    exit(1);
}
