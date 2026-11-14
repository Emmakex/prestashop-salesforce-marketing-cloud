<?php
/**
 * CI MySQL/MariaDB exercise the real PrestaShop Db-method Growth store through
 * a PDO-backed test double for Db's execute/executeS/getValue/Insert_ID API.
 * The module class itself remains sandbox-guarded and dormant.
 */
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/DataCloud/LiveEventUtcObservation.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/BlockedOutboxCoordinator.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthSqlEngineGate.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/GrowthSandboxMigrationExecutor.php';
require_once dirname(__DIR__, 2)
    . '/pssalesforcebridge/src/Growth/PrestaShopDbBlockedOutboxStore.php';
require_once __DIR__ . '/G3GrowthOutboxSandboxMigration.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter as Router;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOutboxSchemaPlan as Schema;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSandboxMigrationExecutor as Migration;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\PrestaShopDbBlockedOutboxStore as Store;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\BlockedOutboxCoordinator as Coordinator;

class PrestaShopDbFixture
{
    private $pdo;
    public $failSelect = false;

    public function __construct(PDO $db)
    {
        $this->pdo = $db;
    }

    public $uncachedCriticalReadCount = 0;
    private $cache = [];

    public function getValue($sql, $useCache = true)
    {
        // Reproduce the dangerous PrestaShop caching contract: cached results
        // are reused unless callers explicitly request fresh SQL execution.
        $critical = strpos($sql, 'GET_LOCK(') !== false
            || strpos($sql, 'RELEASE_LOCK(') !== false
            || strpos($sql, 'growth_provider_binding_v1') !== false;
        if ($critical && $useCache !== false) {
            throw new RuntimeException('g3_db_cache_sensitive_read_not_fresh');
        }
        if ($critical) {
            ++$this->uncachedCriticalReadCount;
        }
        if ($useCache && array_key_exists($sql, $this->cache)) {
            return $this->cache[$sql];
        }
        $value = $this->pdo->query($sql)->fetchColumn();
        if ($useCache) {
            $this->cache[$sql] = $value;
        }
        return $value;
    }

    public function execute($sql)
    {
        return $this->pdo->exec($sql) !== false;
    }

    public function executeS($sql, $array = true, $useCache = true)
    {
        if ($this->failSelect) {
            return false;
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function Insert_ID()
    {
        return $this->pdo->lastInsertId();
    }
}
function psStoreAssert($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g3_ps_db_store_' . $reason);
    }
}
function psStoreReject($callback, $reason)
{
    try {
        call_user_func($callback);
    } catch (Throwable $e) {
        return;
    }
    psStoreAssert(false, $reason);
}
function psStoreConnect()
{
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4';
    psStoreAssert(getenv('G3_CI_ENABLE') === 'yes_sandbox_only'
        && getenv('G3_CI_DSN') === $dsn
        && getenv('G3_CI_USER') === 'g3_ci_user'
        && getenv('G3_CI_PASSWORD') === 'g3_ci_pass_only', 'sandbox_env_mismatch');
    $db = new PDO($dsn, 'g3_ci_user', 'g3_ci_pass_only', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    new G3GrowthOutboxSandboxMigration($db); // confirm exact disposable schema and SQL user.
    return $db;
}
function psStoreCount(PDO $db, $table)
{
    psStoreAssert(preg_match('/^[a-z0-9_]{1,64}$/D', $table) === 1,
        'table_identifier_invalid');
    return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function psStoreCreate(PDO $db, $prefix, $namespace, $merchant = 'ABH')
{
    $migration = new Migration(
        $db, '8.2.8', $merchant, Router::GROWTH,
        $namespace, 'test', $prefix
    );
    psStoreAssert($migration->install() === true, 'growth_fixture_install');
    $store = new Store(new PrestaShopDbFixture($db),
        '8.2.8', $merchant, Router::GROWTH, $namespace, 'test', $prefix);
    $router = new Router('8.2.8', $merchant, Router::GROWTH, $namespace, 'test');
    $tables = Schema::preview('8.2.8', $merchant, Router::GROWTH,
        $namespace, 'test', $prefix)['tables'];
    return [$migration, $store, $router, $tables];
}
function psStoreCapture($router, $store, $id, $hash, $shop = 1, $entity = 'customer',
    $event = 'CUSTOMER_CREATED')
{
    $fingerprint = $entity . ':' . $shop . ':' . $id . ':' . str_repeat($hash, 40);
    return Coordinator::capture($router, $shop, $entity, $id, $event,
        $fingerprint, [$store, 'insertOrLoad']);
}
function psStoreWorker()
{
    $pdo = psStoreConnect();
    $fixture = new PrestaShopDbFixture($pdo);
    $store = new Store($fixture,
        '8.2.8', 'ABH', Router::GROWTH, 'growth_ci_ps_store_a',
        'test', 'ci_psa_');
    $router = new Router('8.2.8', 'ABH', Router::GROWTH,
        'growth_ci_ps_store_a', 'test');
    return psStoreCapture($router, $store, 87, 'c');
}
function psStoreParallel()
{
    $processes = [];
    for ($i = 0; $i < 4; ++$i) {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__FILE__) . ' worker';
        $handle = proc_open($cmd, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
        ], $pipes);
        psStoreAssert(is_resource($handle), 'worker_start');
        fclose($pipes[0]);
        $processes[] = [$handle, $pipes];
    }
    $first = 0;
    $sequences = [];
    foreach ($processes as $group) {
        $output = stream_get_contents($group[1][1]);
        $error = stream_get_contents($group[1][2]);
        fclose($group[1][1]);
        fclose($group[1][2]);
        psStoreAssert(proc_close($group[0]) === 0, 'worker_failed');
        $data = json_decode($output, true);
        psStoreAssert(is_array($data) && isset($data['accepted_sequence'])
            && $data['marketing_sends_authorized'] === false
            && $data['data_360_ingestion_authorized'] === false,
            'worker_invalid_result');
        $first += $data['inserted'] ? 1 : 0;
        $sequences[$data['accepted_sequence']] = true;
    }
    psStoreAssert($first === 1 && count($sequences) === 1,
        'concurrent_duplicate_or_lost_lock');
}
function psStoreRun(PDO $pdo)
{
    $legacy = [];
    foreach (['g3_sfmc_queue', 'g3_sfmc_customer_sync', 'g3_sfmc_order_sync'] as $name) {
        $legacy[$name] = psStoreCount($pdo, $name);
    }
    list($migration, $store, $router, $tables) = psStoreCreate(
        $pdo, 'ci_psa_', 'growth_ci_ps_store_a');
    list($migrationB, $storeB, $routerB, $tablesB) = psStoreCreate(
        $pdo, 'ci_psb_', 'growth_ci_ps_store_b', 'OTHER');

    $first = psStoreCapture($router, $store, 44, 'a');
    $retry = psStoreCapture($router, $store, 44, 'a', 1, 'customer', 'CUSTOMER_UPDATED');
    $updated = psStoreCapture($router, $store, 44, 'b', 1, 'customer', 'CUSTOMER_UPDATED');
    $old = psStoreCapture($router, $store, 44, 'a');
    $shopB = psStoreCapture($router, $store, 44, 'a', 2);
    $order = psStoreCapture($router, $store, 44, 'a', 1, 'order', 'ORDER_PAID');
    $separate = psStoreCapture($routerB, $storeB, 44, 'a');
    psStoreAssert($first['inserted'] && !$retry['inserted']
        && $retry['captured_at_reused']
        && $first['accepted_sequence'] === $retry['accepted_sequence']
        && $first['captured_at_utc'] === $retry['captured_at_utc']
        && $updated['accepted_sequence'] > $first['accepted_sequence']
        && !$old['inserted'] && $old['accepted_sequence'] === $first['accepted_sequence'],
        'idempotency_or_timestamp_violation');
    psStoreAssert($shopB['inserted'] && $order['inserted'] && $separate['inserted'],
        'cross_shop_entity_install_collision');
    psStoreAssert(psStoreCount($pdo, $tables['outbox']) === 4
        && psStoreCount($pdo, $tablesB['outbox']) === 1
        && psStoreCount($pdo, $tables['ledger']) === 3,
        'incorrect_number_of_events');

    // An existing in-memory store cannot trust a binding COUNT cached by Db.
    // CI-only mutation changes the merchant on the synthetic binding, while
    // keeping provider, status and FK intact. Capture must fail closed.
    $binding = $tables['binding'];
    psStoreAssert($pdo->exec("UPDATE " . $binding
        . " SET merchant='TEMP_CI' WHERE merchant='ABH'") === 1,
        'test_binding_change_not_applied');
    psStoreReject(function () use ($router, $store) {
        psStoreCapture($router, $store, 61, 'd');
    }, 'stale_binding_was_accepted');
    psStoreAssert($pdo->exec("UPDATE " . $binding
        . " SET merchant='ABH' WHERE merchant='TEMP_CI'") === 1,
        'test_binding_restore_failed');
    psStoreAssert(psStoreCount($pdo, $tables['outbox']) === 4
        && psStoreCount($pdo, $tables['ledger']) === 3,
        'revoked_binding_created_event');
    $q = $pdo->prepare('SELECT latest_accepted_sequence FROM '
        . $tables['ledger'] . ' WHERE source_namespace=? AND shop_id=1 '
        . "AND entity_type='customer' AND entity_id=44");
    $q->execute(['growth_ci_ps_store_a']);
    psStoreAssert((int) $q->fetchColumn() === $updated['accepted_sequence'],
        'old_replay_regressed_latest_ledger');
    foreach ([$first, $retry, $updated, $old, $shopB, $order, $separate] as $r) {
        psStoreAssert($r['status'] === 'blocked_local_only'
            && $r['marketing_sends_authorized'] === false
            && $r['data_360_ingestion_authorized'] === false
            && $r['outbound_worker_authorized'] === false
            && !isset($r['row']), 'unapproved_outbound_or_pii');
    }

    foreach (['outbox', 'ledger'] as $failpoint) {
        $broken = new Store(new PrestaShopDbFixture($pdo),
            '8.2.8', 'ABH', Router::GROWTH, 'growth_ci_ps_store_a',
            'test', 'ci_psa_', $failpoint);
        $baseline = psStoreCount($pdo, $tables['outbox']);
        psStoreReject(function () use ($router, $broken) {
            psStoreCapture($router, $broken, 50, 'd');
        }, 'fault_not_rejected_' . $failpoint);
        psStoreAssert(psStoreCount($pdo, $tables['outbox']) === $baseline
            && psStoreCount($pdo, $tables['ledger']) === 3,
            'partial_commit_' . $failpoint);
    }
    $failedSelectDb = new PrestaShopDbFixture($pdo);
    $failedSelectDb->failSelect = true;
    $failedSelectStore = new Store($failedSelectDb,
        '8.2.8', 'ABH', Router::GROWTH,
        'growth_ci_ps_store_a', 'test', 'ci_psa_');
    psStoreReject(function () use ($router, $failedSelectStore) {
        psStoreCapture($router, $failedSelectStore, 55, 'd');
    }, 'failed_select_treated_as_absence');
    psStoreAssert(psStoreCount($pdo, $tables['outbox']) === 4,
        'failed_select_saved_event');

    psStoreParallel();
    psStoreAssert(psStoreCount($pdo, $tables['outbox']) === 5,
        'parallel_events_missing_or_duplicate');

    psStoreReject(function () use ($pdo) {
        new Store(new PrestaShopDbFixture($pdo), '8.2.8',
            'ABH', 'marketing_cloud_engagement', 'growth_ci_ps_store_a',
            'test', 'ci_psa_');
    }, 'engagement_store_accepted');
    psStoreReject(function () use ($router, $store) {
        psStoreCapture($router, $store, 44, 'a', 1, 'cart', 'CART_ABANDONED');
    }, 'unapproved_cart_saved');
    psStoreReject(function () use ($migration) {
        $migration->rollbackIfEmpty();
    }, 'nonempty_rollback_not_blocked');

    putenv('G3_CI_ENABLE=disabled');
    psStoreReject(function () use ($pdo) {
        new Store(new PrestaShopDbFixture($pdo), '8.2.8',
            'ABH', Router::GROWTH, 'growth_ci_ps_store_a', 'test', 'ci_psa_');
    }, 'sandbox_denial_not_enforced');
    putenv('G3_CI_ENABLE=yes_sandbox_only');

    // Only CI-owned synthetic rows. Never a deletion function in the module.
    foreach ([[$migration, $tables], [$migrationB, $tablesB]] as $fixture) {
        $pdo->exec('DELETE FROM ' . $fixture[1]['ledger']);
        $pdo->exec('DELETE FROM ' . $fixture[1]['outbox']);
        psStoreAssert($fixture[0]->rollbackIfEmpty() === true,
            'empty_rollback_failed');
    }
    foreach ($legacy as $name => $count) {
        psStoreAssert(psStoreCount($pdo, $name) === $count,
            'legacy_mutation_' . $name);
    }
}
try {
    if (isset($argv[1]) && $argv[1] === 'worker') {
        $worker = psStoreWorker();
        echo json_encode($worker);
        exit(0);
    }
    psStoreRun(psStoreConnect());
    echo "Growth PrestaShop Db-compatible atomic store: MySQL/MariaDB sandbox PASS.\n";
} catch (Throwable $e) {
    // Never expose raw SQL, PII, token, DSN or database passwords in CI.
    fwrite(STDERR, "Growth PrestaShop Db store failed a safety gate.\n");
    exit(1);
}
