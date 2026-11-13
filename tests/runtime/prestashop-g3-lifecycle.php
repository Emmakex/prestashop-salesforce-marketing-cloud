<?php
/**
 * Real PrestaShop runtime G3 migration proof, CI fixture only.
 * The module remains the unchanged 0.7.1 test-mode release.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop') {
    fwrite(STDERR, "G3 PrestaShop lifecycle may run only inside disposable CI.\n");
    exit(1);
}
$root = getenv('PS_ROOT');
if ($root !== '/var/www/html') {
    exit(1);
}
chdir($root);
require_once $root . '/config/config.inc.php';
require_once __DIR__ . '/G3PrestaShopRuntimeLifecycleCandidate.php';

function g3RuntimeCheck($pass, $reason)
{
    if (!$pass) {
        throw new RuntimeException('g3_runtime_' . $reason);
    }
}
function g3RuntimeReject($callback, $reason, $expectedCode)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        g3RuntimeCheck($e->getMessage() === $expectedCode, $reason . '_unexpected_failure');
        return;
    }
    g3RuntimeCheck(false, $reason);
}
function g3RuntimeReadCount($table)
{
    return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `'
        . _DB_PREFIX_ . $table . '`');
}
function g3RuntimeSmoke()
{
    $module = Module::getInstanceByName('pssalesforcebridge');
    g3RuntimeCheck(is_object($module) && (string) $module->version === '0.7.1',
        'module_changed');
    g3RuntimeCheck((string) Configuration::get('PS_SFMC_MODE') === 'test', 'mode_changed');
    g3RuntimeCheck((string) _PS_VERSION_ === getenv('EXPECTED_PS_VERSION'), 'wrong_version');
    $db = Db::getInstance();
    $legacy = [];
    foreach (['sfmc_queue', 'sfmc_customer_sync', 'sfmc_order_sync'] as $name) {
        $legacy[$name] = g3RuntimeReadCount($name);
    }
    $namespace = 'growth_ci_abhtest';
    $adapter = new G3PrestaShopRuntimeLifecycleCandidate();
    g3RuntimeCheck(count($adapter->present()) === 0, 'dirty_fixture');
    g3RuntimeCheck($adapter->install('ABH', 'marketing_cloud_growth', $namespace)
        === 'installed', 'install');
    g3RuntimeCheck(count($adapter->present()) === 3, 'prefixed_tables_absent');
    g3RuntimeCheck($adapter->install('ABH', 'marketing_cloud_growth', $namespace)
        === 'already_installed', 'install_not_idempotent');
    g3RuntimeReject(function () use ($adapter, $namespace) {
        $adapter->install('ABH', 'marketing_cloud_engagement', $namespace);
    }, 'engagement_fallback_accepted', 'g3_adapter_abh_growth_only');
    g3RuntimeReject(function () use ($adapter) {
        $adapter->install('ABH', 'marketing_cloud_growth', 'growth_ci_other');
    }, 'namespace_switch_accepted', 'g3_ps_runtime_binding_conflict');
    g3RuntimeReject(function () use ($adapter, $namespace) {
        $adapter->install('OTHER_MERCHANT', 'marketing_cloud_growth', $namespace);
    }, 'merchant_switch_accepted', 'g3_ps_runtime_binding_conflict');

    $outbox = _DB_PREFIX_ . 'g3_growth_outbox_v1';
    $ledger = _DB_PREFIX_ . 'g3_growth_entity_ledger_v1';
    $insert = "INSERT INTO `{$outbox}`
        (source_namespace, shop_id, entity_type, entity_id,
         dedupe_fingerprint, original_event_type, captured_at_utc,
         captured_at_precision, clock_source, clock_sync_verified,
         source_modified_provenance, status)
        VALUES ('growth_ci_abhtest', 1, 'customer', 19001, '"
        . str_repeat('a', 64)
        . "', 'CUSTOMER_CREATED', '2026-11-13T15:00:00.000Z',
         'seconds', 'php_wall_clock', 0, 'unknown', 'blocked')";
    g3RuntimeCheck($db->execute($insert) === true, 'synthetic_event');
    $seq = (int) $db->Insert_ID();
    g3RuntimeCheck($seq > 0, 'accepted_sequence');
    g3RuntimeCheck($db->execute("INSERT INTO `{$ledger}`
        (source_namespace, shop_id, entity_type, entity_id,
         latest_accepted_sequence, status) VALUES
        ('growth_ci_abhtest', 1, 'customer', 19001, " . $seq . ", 'blocked')") === true,
        'synthetic_ledger');
    g3RuntimeCheck($adapter->uninstallCandidate('ABH', 'marketing_cloud_growth', $namespace)
        === 'retained_nonempty', 'uninstall_deleted_accepted_data');
    g3RuntimeCheck(count($adapter->present()) === 3, 'retained_schema_missing');
    g3RuntimeCheck((int) $db->getValue("SELECT COUNT(*) FROM `{$outbox}`
        WHERE source_namespace = 'growth_ci_abhtest' AND entity_id = 19001") === 1,
        'retained_event_missing');
    g3RuntimeCheck($adapter->install('ABH', 'marketing_cloud_growth', $namespace)
        === 'already_installed', 'resuming_retained_install');
    // Explicitly remove our OWN synthetic rows only, never user/legacy data.
    g3RuntimeCheck($db->execute("DELETE FROM `{$ledger}`
        WHERE source_namespace = 'growth_ci_abhtest' AND entity_id = 19001"),
        'cleanup_ledger');
    g3RuntimeCheck($db->execute("DELETE FROM `{$outbox}`
        WHERE source_namespace = 'growth_ci_abhtest' AND entity_id = 19001"),
        'cleanup_outbox');
    g3RuntimeCheck($adapter->uninstallCandidate('ABH', 'marketing_cloud_growth', $namespace)
        === 'removed_empty', 'remove_empty');
    g3RuntimeCheck($adapter->uninstallCandidate('ABH', 'marketing_cloud_growth', $namespace)
        === 'already_absent', 'uninstall_not_idempotent');
    g3RuntimeCheck(count($adapter->present()) === 0, 'tables_remain');

    // Simulate interrupted DDL; it must be diagnosed, not auto-repaired.
    $binding = _DB_PREFIX_ . 'g3_provider_binding_v1';
    g3RuntimeCheck($db->execute("CREATE TABLE `{$binding}` (incomplete INT)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"), 'partial_fixture_setup');
    g3RuntimeReject(function () use ($adapter, $namespace) {
        $adapter->install('ABH', 'marketing_cloud_growth', $namespace);
    }, 'partial_install_accepted', 'g3_ps_runtime_partial_schema_manual_review');
    g3RuntimeReject(function () use ($adapter, $namespace) {
        $adapter->uninstallCandidate('ABH', 'marketing_cloud_growth', $namespace);
    }, 'partial_uninstall_accepted', 'g3_ps_runtime_partial_schema_manual_review');
    g3RuntimeCheck($db->execute("DROP TABLE `{$binding}`"), 'manual_fixture_cleanup');
    g3RuntimeCheck(count($adapter->present()) === 0, 'partial_fixture_not_cleared');
    foreach ($legacy as $name => $count) {
        g3RuntimeCheck(g3RuntimeReadCount($name) === $count,
            'legacy_modified_' . $name);
    }
    g3RuntimeCheck((string) Configuration::get('PS_SFMC_MODE') === 'test'
        && (string) $module->version === '0.7.1', 'existing_install_changed');
}

try {
    g3RuntimeSmoke();
    fwrite(STDOUT, 'G3 real PrestaShop lifecycle candidate PASS: local-only, blocked, reversible or retained.' . PHP_EOL);
} catch (Throwable $e) {
    // No SQL error strings or customer data in CI logs.
    fwrite(STDERR, "G3 PrestaShop lifecycle candidate failed an isolated safety gate.\n");
    exit(1);
}
