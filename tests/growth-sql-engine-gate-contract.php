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
echo "Growth SQL engine CHECK gate offline: PASS (no SQL calls).\n";
