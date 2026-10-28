<?php

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

function pSQL($value)
{
    return (string) $value;
}

class Db
{
    const INSERT_IGNORE = 1;

    public static $statusRows = [];
    public static $eventRows = [];
    public static $oldestRow = [];
    public static $staleCount = 0;
    public static $lastGetRowSql = '';
    private static $instance;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function executeS($sql)
    {
        if (strpos($sql, 'GROUP BY `event_type`, `status`') !== false) {
            return self::$eventRows;
        }

        if (strpos($sql, 'GROUP BY `status`') !== false) {
            return self::$statusRows;
        }

        return [];
    }

    public function getRow($sql)
    {
        self::$lastGetRowSql = (string) $sql;

        if (strpos($sql, 'ORDER BY `created_at` ASC') !== false) {
            return self::$oldestRow;
        }

        return [];
    }

    public function getValue($sql)
    {
        if (strpos($sql, "`status` = 'processing'") !== false) {
            return self::$staleCount;
        }

        return 0;
    }
}

require_once __DIR__ . '/../pssalesforcebridge/src/Queue/QueueRepository.php';
require_once __DIR__ . '/../pssalesforcebridge/src/Operations/HealthService.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Operations\HealthService;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue($value, $message)
{
    if (!$value) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function productionConfiguration()
{
    return [
        'mode' => 'production',
        'cron_token' => true,
        'auth_base_uri' => true,
        'rest_base_uri' => true,
        'client_id' => true,
        'client_secret' => true,
        'customer_de_key' => true,
        'order_de_key' => true,
        'journey_event_definition_key' => true,
    ];
}

$service = new HealthService(new QueueRepository());

// Test mode captures locally by design, so an old pending queue is informational.
Db::$statusRows = [['status' => 'pending', 'total' => 5, 'max_attempts' => 0]];
Db::$eventRows = [['event_type' => 'CUSTOMER_UPDATED', 'status' => 'pending', 'total' => 5]];
Db::$oldestRow = ['created_at' => date('Y-m-d H:i:s', time() - 7200)];
Db::$staleCount = 0;
$testReport = $service->report(['mode' => 'test', 'cron_token' => true]);
assertSameValue('healthy', $testReport['status'], 'Test mode backlog must not be reported as unhealthy.');
assertSameValue(5, $testReport['queue']['outstanding_total'], 'Outstanding queue count is incorrect.');
assertTrueValue(in_array('outbound_delivery_disabled_in_test_mode', $testReport['notes'], true), 'Test-mode note is missing.');
assertTrueValue(stripos(Db::$lastGetRowSql, ' limit ') === false, 'Queries passed to PrestaShop Db::getRow() must not include a manual LIMIT.');

// In production, 15+ minutes of outstanding work is a warning.
Db::$statusRows = [['status' => 'pending', 'total' => 2, 'max_attempts' => 0]];
Db::$eventRows = [['event_type' => 'ORDER_CREATED', 'status' => 'pending', 'total' => 2]];
Db::$oldestRow = ['created_at' => date('Y-m-d H:i:s', time() - 1200)];
Db::$staleCount = 0;
$warningReport = $service->report(productionConfiguration());
assertSameValue('warning', $warningReport['status'], 'Production backlog older than 15 minutes must warn.');
assertTrueValue($warningReport['ok'], 'Warning health must remain HTTP-200 eligible.');

// Terminal failures and incomplete production configuration are critical.
Db::$statusRows = [
    ['status' => 'failed', 'total' => 1, 'max_attempts' => 5],
    ['status' => 'processed', 'total' => 10, 'max_attempts' => 0],
];
Db::$eventRows = [['event_type' => 'CART_ABANDONED', 'status' => 'failed', 'total' => 1]];
Db::$oldestRow = [];
Db::$staleCount = 0;
$brokenConfig = productionConfiguration();
$brokenConfig['client_secret'] = false;
$criticalReport = $service->report($brokenConfig);
assertSameValue('critical', $criticalReport['status'], 'Terminal queue failures must be critical.');
assertTrueValue(!$criticalReport['ok'], 'Critical health must not be considered OK.');
assertTrueValue(in_array('client_secret', $criticalReport['configuration']['missing'], true), 'Missing production secret flag was not detected.');
assertTrueValue(!array_key_exists('client_secret', $criticalReport['configuration']), 'Health report must never expose a secret value.');

// Stale processing locks warn even if there is no terminal failure.
Db::$statusRows = [['status' => 'processing', 'total' => 1, 'max_attempts' => 0]];
Db::$eventRows = [['event_type' => 'CUSTOMER_CREATED', 'status' => 'processing', 'total' => 1]];
Db::$oldestRow = ['created_at' => date('Y-m-d H:i:s', time() - 60)];
Db::$staleCount = 1;
$staleReport = $service->report(productionConfiguration());
assertSameValue('warning', $staleReport['status'], 'Stale processing locks must warn.');

fwrite(STDOUT, "Health service contract: OK\n");
