<?php
/** Real 0.7.1 -> ephemeral 0.8.0 PrestaShop upgrade acceptance (CI only). */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop'
    || getenv('G3_080_UPGRADE_FIXTURE') !== 'candidate_ci_only'
    || getenv('PS_ROOT') !== '/var/www/html') {
    exit(1);
}
require_once '/var/www/html/config/config.inc.php';
require_once '/tmp/G3PrestaShopRuntimeLifecycleCandidate.php';

function g3UpgradeCheck($ok, $reason)
{
    if (!$ok) {
        throw new RuntimeException('g3_upgrade_' . $reason);
    }
}
function g3UpgradeCount($table)
{
    if (!in_array($table, [
        'sfmc_queue', 'sfmc_customer_sync', 'sfmc_order_sync',
    ], true)) {
        throw new RuntimeException('g3_upgrade_unapproved_legacy_table');
    }
    return (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $table
    );
}
function g3UpgradeSnapshot()
{
    return [
        'legacy_queue_rows' => g3UpgradeCount('sfmc_queue'),
        'legacy_customer_sync_rows' => g3UpgradeCount('sfmc_customer_sync'),
        'legacy_order_sync_rows' => g3UpgradeCount('sfmc_order_sync'),
        'mode' => (string) Configuration::get('PS_SFMC_MODE'),
        'cart_consent_mode' => (string) Configuration::get('PS_SFMC_CART_CONSENT_MODE'),
        'cart_abandon_minutes' => (string) Configuration::get('PS_SFMC_CART_ABANDON_MINUTES'),
        'retention_queue_days' => (string) Configuration::get('PS_SFMC_RETENTION_QUEUE_DAYS'),
    ];
}
function g3UpgradeRun()
{
    g3UpgradeCheck(defined('_DB_PREFIX_') && _DB_PREFIX_ === 'ps_'
        && (string) Db::getInstance()->getValue('SELECT DATABASE()') === 'prestashop'
        && (string) _PS_VERSION_ === getenv('EXPECTED_PS_VERSION')
        && getenv('G3_080_MERCHANT') === 'ABH'
        && getenv('G3_080_PROVIDER') === 'marketing_cloud_growth'
        && getenv('G3_080_NAMESPACE') === 'growth_ci_abhtest', 'unsafe_environment');

    $phase = isset($GLOBALS['argv'][1]) ? $GLOBALS['argv'][1] : '';
    $baselinePath = '/tmp/g3-upgrade-baseline.json';
    $snapshot = g3UpgradeSnapshot();
    if ($phase === 'removed') {
        $baseline = json_decode((string) file_get_contents($baselinePath), true);
        g3UpgradeCheck(!Module::isInstalled('pssalesforcebridge'), 'native_uninstall_failed');
        g3UpgradeCheck(is_array($baseline) && $baseline === $snapshot,
            'native_uninstall_deleted_legacy_data_or_configuration');
        $db = Db::getInstance();
        foreach (['g3_provider_binding_v1', 'g3_growth_outbox_v1',
            'g3_growth_entity_ledger_v1'] as $name) {
            g3UpgradeCheck((int) $db->getValue(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = '" . _DB_PREFIX_ . $name . "'"
            ) === 1, 'native_uninstall_deleted_growth_schema');
        }
        g3UpgradeCheck((int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . "g3_growth_outbox_v1
             WHERE source_namespace = 'growth_ci_abhtest'
             AND entity_id = 29001 AND status = 'blocked'"
        ) === 1, 'native_uninstall_deleted_growth_observation');
        g3UpgradeCheck((int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . "g3_growth_entity_ledger_v1
             WHERE source_namespace = 'growth_ci_abhtest'
             AND entity_id = 29001 AND status = 'blocked'"
        ) === 1, 'native_uninstall_deleted_growth_ledger');
        return;
    }
    $module = Module::getInstanceByName('pssalesforcebridge');
    g3UpgradeCheck(is_object($module), 'module_missing');
    if ($phase === 'before') {
        g3UpgradeCheck((string) $module->version === '0.7.1'
            && $snapshot['mode'] === 'test', 'before_version_or_mode');
        $candidate = new G3PrestaShopRuntimeLifecycleCandidate();
        g3UpgradeCheck(count($candidate->present()) === 0, 'stale_candidate_tables');
        g3UpgradeCheck(file_put_contents($baselinePath, json_encode($snapshot)) !== false,
            'cannot_write_fixture_baseline');
        return;
    }
    if ($phase !== 'after') {
        throw new RuntimeException('g3_upgrade_phase_required');
    }

    $baseline = json_decode((string) file_get_contents($baselinePath), true);
    g3UpgradeCheck(is_array($baseline) && $baseline === $snapshot,
        'legacy_data_or_settings_mutated');
    g3UpgradeCheck((string) $module->version === '0.8.0' && $snapshot['mode'] === 'test',
        'candidate_version_or_test_mode');
    $candidate = new G3PrestaShopRuntimeLifecycleCandidate();
    g3UpgradeCheck(count($candidate->present()) === 3, 'new_tables_missing');
    g3UpgradeCheck($candidate->install('ABH', 'marketing_cloud_growth', 'growth_ci_abhtest')
        === 'already_installed', 'versioned_upgrade_not_idempotent');

    // Even with a fixture 0.8.0, all legacy workers remain Test blocked.
    $customer = $module->processCustomerQueue(1);
    $order = $module->processOrderQueue(1);
    $cart = $module->processAbandonedCartJourneyQueue(1);
    g3UpgradeCheck(isset($customer['skipped'])
        && $customer['skipped'] === 'g3_growth_delivery_not_approved'
        && isset($order['skipped'])
        && $order['skipped'] === 'g3_growth_delivery_not_approved'
        && isset($cart['skipped'])
        && $cart['skipped'] === 'g3_growth_delivery_not_approved',
        'growth_binding_failed_to_block_engagement_senders');

    $db = Db::getInstance();
    $outbox = _DB_PREFIX_ . 'g3_growth_outbox_v1';
    $ledger = _DB_PREFIX_ . 'g3_growth_entity_ledger_v1';
    $inserted = $db->execute("INSERT INTO `{$outbox}`
        (source_namespace, shop_id, entity_type, entity_id,
         dedupe_fingerprint, original_event_type, captured_at_utc,
         captured_at_precision, clock_source, clock_sync_verified,
         source_modified_provenance, status)
        VALUES ('growth_ci_abhtest', 1, 'customer', 29001, '"
        . str_repeat('f', 64)
        . "', 'CUSTOMER_CREATED', '2026-11-13T15:00:00.000Z',
         'seconds', 'php_wall_clock', 0, 'unknown', 'blocked')");
    g3UpgradeCheck($inserted === true, 'synthetic_observation_write');
    $sequence = (int) $db->Insert_ID();
    g3UpgradeCheck($sequence > 0
        && $db->execute("INSERT INTO `{$ledger}` (source_namespace, shop_id,
         entity_type, entity_id, latest_accepted_sequence, status)
         VALUES ('growth_ci_abhtest', 1, 'customer', 29001, "
         . $sequence . ", 'blocked')") === true, 'synthetic_ledger');
    g3UpgradeCheck($candidate->uninstallCandidate(
        'ABH', 'marketing_cloud_growth', 'growth_ci_abhtest'
    ) === 'retained_nonempty', 'destructive_rollback_not_blocked');
    g3UpgradeCheck(count($candidate->present()) === 3
        && (int) $db->getValue("SELECT COUNT(*) FROM `{$outbox}`
            WHERE entity_id = 29001 AND status = 'blocked'") === 1,
        'rollback_lost_synthetic_observation');
    g3UpgradeCheck($baseline === g3UpgradeSnapshot(), 'post_uninstall_legacy_drift');
}

try {
    g3UpgradeRun();
    fwrite(STDOUT, "G3 versioned 0.8.0 CI upgrade acceptance PASS, stage "
        . (isset($argv[1]) ? $argv[1] : 'unknown') . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "G3 versioned upgrade fixture rejected at an integrity gate.\n");
    exit(1);
}
