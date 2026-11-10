<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/InstallationReadinessInspector.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/InstallationReadinessDashboard.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\InstallationReadinessInspector;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\InstallationReadinessDashboard;

function probe_check($yes, $message) {
    if (!$yes) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$readCalls = [];
$report = InstallationReadinessInspector::inspect(
    [3, 2, 3],
    function ($id) use (&$readCalls) {
        $readCalls[] = $id;
        return $id === 2 ? 'Europe/Madrid' : 'America/Lima';
    },
    function () {
        return [
            'customer' => ['date_add' => 'DATETIME', 'date_upd' => 'DATETIME'],
            'orders' => ['date_add' => 'DATETIME', 'date_upd' => 'DATETIME'],
        ];
    }
);
probe_check($readCalls === [2, 3], 'Expected sorted distinct shop reads.');
probe_check($report['checks']['shops_identified'] === true
    && $report['checks']['shop_timezones_valid'] === true
    && $report['checks']['date_columns_inspected'] === true,
    'All local synthetic checks should pass.');
probe_check($report['status'] === 'blocked'
    && $report['schema_upload_allowed'] === false
    && $report['record_ingestion_allowed'] === false
    && $report['marketing_sends_allowed'] === false,
    'Local checks must never authorize Data Cloud side effects.');
probe_check($report['date_columns']['customer']['date_upd'] === 'datetime',
    'Synthetic DATETIME metadata not normalized.');
$html = (new InstallationReadinessDashboard())->render($report);
probe_check(strpos($html, 'BLOCKED') !== false
    && strpos($html, 'Europe/Madrid') !== false
    && strpos($html, 'America/Lima') !== false,
    'Read-only admin panel is incomplete.');
probe_check(strpos($html, 'client_secret') === false && strpos($html, 'database_name') === false,
    'Private configuration should not be displayed.');

$bad = InstallationReadinessInspector::inspect(
    [1, 2],
    function ($id) { return $id === 1 ? 'UTC' : '<script>alert(1)</script>'; },
    function () { return ['customer' => ['date_upd' => 'varchar']]; }
);
probe_check($bad['status'] === 'blocked' && !$bad['checks']['shop_timezones_valid']
    && !$bad['checks']['date_columns_inspected'], 'Invalid evidence must fail closed.');
$unsafe = (new InstallationReadinessDashboard())->render($bad);
probe_check(strpos($unsafe, '<script>') === false
    && strpos($unsafe, 'alert(1)') === false,
    'Unsafe timezone strings must not leak to admin HTML.');
$missing = InstallationReadinessInspector::inspect([], function () { return 'UTC'; },
    function () { throw new RuntimeException('database password in error!'); });
probe_check(!$missing['checks']['shops_identified']
    && !$missing['checks']['date_columns_inspected']
    && $missing['status'] === 'blocked',
    'Unreachable metadata should yield a safe blocked snapshot.');
probe_check(strpos(json_encode($missing), 'password') === false,
    'Exception text must not be propagated.');

$probeSource = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/PrestaShopReadinessProbe.php');
probe_check(strpos($probeSource, 'information_schema.columns') !== false
    && strpos($probeSource, 'executeS(') !== false
    && strpos($probeSource, 'date_add') !== false,
    'Adapter must only read metadata.');
probe_check(strpos($probeSource, 'SELECT *') === false && strpos($probeSource, 'INSERT ') === false
    && strpos($probeSource, 'UPDATE ') === false && strpos($probeSource, 'DELETE ') === false
    && strpos($probeSource, 'curl_') === false,
    'Preflight must not access customer rows, write or call HTTP.');
$moduleSrc = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/pssalesforcebridge.php');
probe_check(strpos($moduleSrc, 'renderDataCloudReadiness()') !== false,
    'Back Office must display the readiness panel.');
$ci = file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');
probe_check(strpos($ci, 'php tests/data-cloud-installation-readiness-contract.php') !== false,
    'Readiness contract must be gated on CI.');
fwrite(STDOUT, "Offline Data Cloud shop readiness panel contract passed: permanently blocked.\n");
