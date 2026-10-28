<?php

require_once __DIR__ . '/../pssalesforcebridge/src/Operations/HealthService.php';
require_once __DIR__ . '/../pssalesforcebridge/src/Operations/BackOfficeHealthDashboard.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Operations\BackOfficeHealthDashboard;

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: " . $needle . "\n");
        exit(1);
    }
}

function assertNotContainsValue($needle, $haystack, $message)
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected: " . $needle . "\n");
        exit(1);
    }
}

$renderer = new BackOfficeHealthDashboard();
$health = [
    'status' => 'critical',
    'mode' => 'production',
    'prestashop_version' => '8.1.7',
    'module_version' => '0.6.1',
    'configuration' => [
        'complete_for_mode' => false,
        'missing' => ['client_secret'],
    ],
    'queue' => [
        'counts' => [
            'pending' => 4,
            'processing' => 1,
            'retry' => 2,
            'failed' => 1,
            'processed' => 100,
        ],
        'outstanding_total' => 7,
        'oldest_outstanding_age_seconds' => 3720,
        'stale_processing' => 1,
        'by_event_type' => [
            ['event_type' => 'ORDER_CREATED', 'status' => 'retry', 'total' => 2],
            ['event_type' => '<script>alert(1)</script>', 'status' => 'failed', 'total' => 1],
        ],
    ],
    'issues' => [
        ['code' => 'terminal_queue_failures', 'severity' => 'critical', 'count' => 1],
        ['code' => 'configuration_incomplete', 'severity' => 'critical', 'details' => ['client_secret']],
    ],
];

$html = $renderer->render(
    $health,
    [
        ['id_shop' => 1, 'status' => 'running', 'scanned_count' => 500, 'queued_count' => 480, 'cursor_customer_id' => 900],
    ],
    [
        ['status' => 'abandoned', 'total' => 12],
        ['status' => 'recovered', 'total' => 3],
    ],
    [
        ['status' => 'sent', 'total' => 9],
        ['status' => 'suppressed', 'total' => 2],
    ],
    [
        'active_tokens' => 5,
        'used_tokens' => 3,
        'expired_tokens' => 1,
        'recovered_carts' => 3,
        'recovered_orders' => 4,
        'paid_recovered_orders' => 2,
    ],
    [
        ['currency' => 'EUR', 'paid_orders' => 2, 'revenue' => 349.9],
    ]
);

assertContainsValue('Connector health', $html, 'Dashboard heading missing.');
assertContainsValue('alert-danger', $html, 'Critical health must render a danger alert.');
assertContainsValue('Production', ucwords($html), 'Production mode missing.');
assertContainsValue('7', $html, 'Outstanding queue total missing.');
assertContainsValue('1h 2m', $html, 'Queue age formatting is incorrect.');
assertContainsValue('ORDER_CREATED', $html, 'Event breakdown missing.');
assertContainsValue('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Event names must be escaped.');
assertNotContainsValue('<script>alert(1)</script>', $html, 'Raw event HTML must never be rendered.');
assertContainsValue('client_secret', $html, 'Missing configuration key must be visible.');
assertContainsValue('EUR', $html, 'Recovery revenue currency missing.');
assertContainsValue('349.90', $html, 'Recovery revenue value missing.');
assertNotContainsValue('super-secret-value', $html, 'Dashboard must never contain secret values.');

fwrite(STDOUT, "Health dashboard contract: OK\n");
