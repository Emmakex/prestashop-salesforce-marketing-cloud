<?php
/** Growth schema preview contracts against real dormant module source; NO DB. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOutboxSchemaPlan as Schema;

function schemaCheck($ok, $message)
{
    if (!$ok) {
        fwrite(STDERR, 'Growth schema preview: ' . $message . PHP_EOL);
        exit(1);
    }
}
function schemaReject($callback, $expected)
{
    try {
        call_user_func($callback);
    } catch (RuntimeException $e) {
        schemaCheck($e->getMessage() === $expected, 'wrong_error_' . $expected);
        return;
    }
    schemaCheck(false, 'accepted_' . $expected);
}
function schemaPreview($prefix = 'ps_', $version = '8.2.8',
    $merchant = 'ABH', $provider = 'marketing_cloud_growth',
    $namespace = 'growth_ci_test', $mode = 'test')
{
    return Schema::preview($version, $merchant, $provider, $namespace, $mode, $prefix);
}
foreach (['1.7.8.11', '8.2.8', '9.1.5'] as $version) {
    $plan = schemaPreview('ps_', $version);
    schemaCheck($plan['tables'] === [
        'binding' => 'ps_growth_provider_binding_v1',
        'outbox' => 'ps_growth_outbox_v1',
        'ledger' => 'ps_growth_entity_ledger_v1',
    ], 'table_prefix_not_preserved');
    schemaCheck(count($plan['create_sql']) === 3
        && count($plan['drop_order_if_empty']) === 3
        && $plan['drop_order_if_empty'] === [
            'ps_growth_entity_ledger_v1', 'ps_growth_outbox_v1',
            'ps_growth_provider_binding_v1'
        ], 'installation_order_invalid');
    schemaCheck($plan['binding_values'] === [
        'growth_ci_test', 'ABH', 'marketing_cloud_growth',
        $version, 'test', 'blocked'
    ], 'binding_values_incorrect');
    foreach (['schema_approved', 'installation_authorized', 'upgrade_authorized',
        'rollback_authorized', 'data_360_ingestion_authorized',
        'marketing_sends_authorized'] as $flag) {
        schemaCheck($plan[$flag] === false, 'unauthorized_' . $flag);
    }
    schemaCheck(Schema::inventoryState($plan, []) === 'absent_review_only'
        && Schema::inventoryState($plan, array_values($plan['tables']))
            === 'present_requires_schema_integrity_review'
        && Schema::inventoryState($plan, [$plan['tables']['outbox']])
            === 'partial_install_manual_review'
        && Schema::inventoryState($plan, [$plan['tables']['binding'],
            $plan['tables']['outbox']]) === 'partial_install_manual_review'
        && Schema::inventoryState($plan, false) === 'unknown_manual_review'
        && Schema::inventoryState($plan, ['sfmc_queue'])
            === 'unknown_manual_review'
        && Schema::inventoryState($plan, [$plan['tables']['binding'],
            $plan['tables']['binding']]) === 'unknown_manual_review',
        'inventory_did_not_fail_closed');
}
$long = schemaPreview('pmlopdwb_');
schemaCheck(strpos($long['create_sql'][0], 'pmlopdwb_growth_provider_binding_v1') !== false,
    'realistic_prefix_not_supported');
$one = schemaPreview('ci_a_');
$two = schemaPreview('ci_b_');
schemaCheck($one['create_sql'] === schemaPreview('ci_a_')['create_sql']
    && $one['create_sql'] !== $two['create_sql']
    && $one['tables']['outbox'] !== $two['tables']['outbox'],
    'non_deterministic_or_colliding_prefixes');
foreach ($one['create_sql'] as $sql) {
    schemaCheck(strpos($sql, 'CREATE TABLE ') === 0
        && strpos($sql, 'ENGINE=InnoDB') !== false
        && strpos($sql, 'INSERT INTO ') === false
        && strpos($sql, 'DROP TABLE ') === false
        && strpos($sql, 'IF NOT EXISTS') === false
        && strpos($sql, 'sfmc_queue') === false
        && strpos($sql, 'sfmc_customer_sync') === false
        && strpos($sql, 'sfmc_order_sync') === false,
        'unsafe_sql_text');
}
schemaCheck(strpos($one['create_sql'][1], 'FOREIGN KEY') !== false
    && strpos($one['create_sql'][2], 'FOREIGN KEY') !== false
    && strpos($one['create_sql'][1], 'UNIQUE KEY') !== false
    && strpos($one['create_sql'][1], "'blocked'") !== false,
    'required_db_integrity_missing');
foreach (['', 'x-', '../', 'ps;DROP', str_repeat('p', 33), "\0bad"] as $prefix) {
    schemaReject(function () use ($prefix) { schemaPreview($prefix); },
        'growth_schema_prefix_invalid');
}
schemaReject(function () {
    schemaPreview('ps_', '8.2.8', 'OTHER_MERCHANT',
        'marketing_cloud_engagement', 'growth_ci_test');
}, 'growth_schema_growth_only');
schemaReject(function () {
    schemaPreview('ps_', '8.2.8', 'ABH',
        'marketing_cloud_engagement', 'growth_ci_test');
}, 'growth_router_abh_requires_growth');
schemaReject(function () {
    schemaPreview('ps_', '8.2.8', 'ABH',
        'marketing_cloud_growth', 'growth_ci_test', 'production');
}, 'growth_router_test_only');
$src = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php');
foreach (['Db::', 'PDO', 'curl_', 'file_get_contents(', '->execute(',
    '->query(', '->insert(', '->update(', '/api/v1/ingest/',
    '/interaction/v1/events'] as $bad) {
    schemaCheck(strpos($src, $bad) === false, 'planner_performs_io_' . $bad);
}
$module = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
schemaCheck(strpos($module, 'GrowthOutboxSchemaPlan') === false,
    'module_installer_must_not_execute_unapproved_preview');
echo "Growth three-table prefixed install/upgrade preview: PHP offline PASS (no SQL executed).\n";
