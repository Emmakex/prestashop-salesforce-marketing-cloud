<?php
/** Offline PHP 7.1+ contract for CHECK-enforcing SQL engine preflight. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Growth/GrowthSqlEngineGate.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthSqlEngineGate as Gate;

function growthEngineAssert($condition, $reason)
{
    if (!$condition) {
        fwrite(STDERR, 'Growth engine gate: ' . $reason . PHP_EOL);
        exit(1);
    }
}
foreach ([
    ['5.7.44', null, false],
    ['5.7.44-log', null, false],
    ['8.0.15', null, false],
    ['8.0.16', null, true],
    ['8.0.44', null, true],
    ['8.4.6', null, true],
    ['10.1.48-MariaDB', 'ON', false],
    ['10.11.13-MariaDB', 'OFF', false],
    ['10.11.13-MariaDB', '0', false],
    ['10.11.13-MariaDB', null, false],
    ['10.11.13-MariaDB', '1', true],
    ['10.11.13-MariaDB', 1, true],
    ['5.5.5-10.11.13-MariaDB', 'ON', true],
    ['10.6.22-MariaDB', 'ON', false],
    ['11.8.9-MariaDB-log', 'ON', true],
    ['11.8.9-MariaDB-log', '1', true],
    ['11.8.9-MariaDB-log', 1, true],
    ['11.8.9-MariaDB-log', 'OFF', false],
    ['11.8.9-MariaDB-log', null, false],
    ['11.6.2-MariaDB-log', 'ON', false],
    ['12.0.3-MariaDB-log', 'ON', false],
    ['8.0.44-TiDB', null, false],
    ['8.0.44-Vitess', null, false],
    ['unavailable', null, false],
    ['', null, false],
    [false, null, false],
] as $case) {
    $r = Gate::inspect($case[0], $case[1]);
    growthEngineAssert(is_array($r)
        && $r['check_constraints_enforced'] === $case[2]
        && $r['schema_migration_authorized'] === false
        && $r['growth_outbound_authorized'] === false,
        'incorrect_engine_decision');
    growthEngineAssert($r['status'] === (
        $case[2] ? 'eligible_for_sandbox_schema_preflight' : 'blocked'
    ), 'unsafe_engine_status');
}


// The PrestaShop Db adapter must use uncached SELECT only. It must never
// invoke execute/insert/update or create schema during the capability probe.
class GrowthEngineNativeDbDouble
{
    public $reads = [];
    public $writes = 0;
    private $version;
    private $checks;
    private $fail;

    public function __construct($version, $checks = null, $fail = false)
    {
        $this->version = $version;
        $this->checks = $checks;
        $this->fail = $fail;
    }
    public function getValue($sql, $cache = true)
    {
        if ($cache !== false) {
            throw new RuntimeException('growth_sql_engine_cached_read_forbidden');
        }
        $this->reads[] = $sql;
        if ($this->fail) {
            throw new RuntimeException('synthetic_query_failure');
        }
        if ($sql === 'SELECT VERSION()') {
            return $this->version;
        }
        if ($sql === 'SELECT @@check_constraint_checks') {
            return $this->checks;
        }
        throw new RuntimeException('unapproved_read');
    }
    public function execute($sql)
    {
        ++$this->writes;
        throw new RuntimeException('unapproved_write');
    }
}
foreach ([
    ['5.7.44', null, false, 1],
    ['8.0.15', null, false, 1],
    ['8.0.44', null, true, 1],
    ['10.11.13-MariaDB', 'ON', true, 2],
    ['10.11.13-MariaDB', 'OFF', false, 2],
    ['10.11.13-MariaDB', false, false, 2],
    ['11.8.9-MariaDB-log', 'ON', true, 2],
    ['11.8.9-MariaDB-log', 'OFF', false, 2],
    ['11.8.9-MariaDB-log', false, false, 2],
    ['bad-version', null, false, 1],
] as $case) {
    $db = new GrowthEngineNativeDbDouble($case[0], $case[1]);
    $report = Gate::inspectPrestaShop($db);
    growthEngineAssert($report['check_constraints_enforced'] === $case[2]
        && $report['schema_migration_authorized'] === false
        && $report['growth_outbound_authorized'] === false
        && count($db->reads) === $case[3]
        && $db->writes === 0, 'prestashop_native_probe_wrong_result');
}
foreach ([false, null, new stdClass()] as $invalid) {
    $r = Gate::inspectPrestaShop($invalid);
    growthEngineAssert($r['check_constraints_enforced'] === false
        && $r['schema_migration_authorized'] === false,
        'prestashop_native_invalid_db_accepted');
}
$broken = new GrowthEngineNativeDbDouble('8.0.44', null, true);
$report = Gate::inspectPrestaShop($broken);
growthEngineAssert($report['check_constraints_enforced'] === false
    && $broken->writes === 0, 'prestashop_native_failed_read_accepted');
echo "Growth native PrestaShop Db SQL preflight: PASS (unbuffered GET only).\n";

echo "Growth SQL engine CHECK gate offline: PASS (no SQL calls).\n";
