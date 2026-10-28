<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Operations;

use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

final class HealthService
{
    const STATUS_HEALTHY = 'healthy';
    const STATUS_WARNING = 'warning';
    const STATUS_CRITICAL = 'critical';

    private $queue;

    public function __construct(QueueRepository $queue)
    {
        $this->queue = $queue;
    }

    public function report(array $configuration)
    {
        $mode = isset($configuration['mode']) && $configuration['mode'] === 'production'
            ? 'production'
            : 'test';
        $statusRows = $this->queue->statusSummary();
        $counts = [
            'pending' => 0,
            'processing' => 0,
            'retry' => 0,
            'failed' => 0,
            'processed' => 0,
        ];

        foreach ($statusRows as $row) {
            $status = isset($row['status']) ? (string) $row['status'] : '';
            if ($status !== '') {
                $counts[$status] = isset($row['total']) ? (int) $row['total'] : 0;
            }
        }

        $oldest = $this->queue->oldestOutstanding();
        $oldestAt = is_array($oldest) && !empty($oldest['created_at'])
            ? (string) $oldest['created_at']
            : null;
        $oldestAge = $this->ageSeconds($oldestAt);
        $staleProcessing = $this->queue->staleProcessingCount(600);
        $missing = $this->missingConfiguration($configuration, $mode);
        $issues = [];
        $severity = self::STATUS_HEALTHY;

        if (!empty($missing)) {
            $severity = self::STATUS_CRITICAL;
            $issues[] = [
                'code' => 'configuration_incomplete',
                'severity' => self::STATUS_CRITICAL,
                'details' => $missing,
            ];
        }

        if ($counts['failed'] > 0) {
            $severity = self::STATUS_CRITICAL;
            $issues[] = [
                'code' => 'terminal_queue_failures',
                'severity' => self::STATUS_CRITICAL,
                'count' => $counts['failed'],
            ];
        }

        if ($staleProcessing > 0) {
            if ($severity !== self::STATUS_CRITICAL) {
                $severity = self::STATUS_WARNING;
            }
            $issues[] = [
                'code' => 'stale_processing_locks',
                'severity' => self::STATUS_WARNING,
                'count' => $staleProcessing,
                'stale_after_seconds' => 600,
            ];
        }

        // Queue age only indicates delivery health while outbound processing is enabled.
        // In test mode the connector intentionally captures events without sending them.
        if ($mode === 'production' && $oldestAge !== null) {
            if ($oldestAge >= 3600) {
                $severity = self::STATUS_CRITICAL;
                $issues[] = [
                    'code' => 'queue_backlog_critical',
                    'severity' => self::STATUS_CRITICAL,
                    'oldest_age_seconds' => $oldestAge,
                ];
            } elseif ($oldestAge >= 900) {
                if ($severity !== self::STATUS_CRITICAL) {
                    $severity = self::STATUS_WARNING;
                }
                $issues[] = [
                    'code' => 'queue_backlog_warning',
                    'severity' => self::STATUS_WARNING,
                    'oldest_age_seconds' => $oldestAge,
                ];
            }
        }

        return [
            'status' => $severity,
            'ok' => $severity !== self::STATUS_CRITICAL,
            'generated_at' => gmdate('c'),
            'mode' => $mode,
            'configuration' => [
                'complete_for_mode' => empty($missing),
                'missing' => $missing,
            ],
            'queue' => [
                'counts' => $counts,
                'outstanding_total' => $counts['pending'] + $counts['processing'] + $counts['retry'],
                'oldest_outstanding_at' => $oldestAt,
                'oldest_outstanding_age_seconds' => $oldestAge,
                'stale_processing' => $staleProcessing,
                'by_event_type' => $this->queue->eventTypeSummary(),
            ],
            'issues' => $issues,
            'notes' => $mode === 'test'
                ? ['outbound_delivery_disabled_in_test_mode']
                : [],
        ];
    }

    private function missingConfiguration(array $configuration, $mode)
    {
        $required = ['cron_token'];

        if ($mode === 'production') {
            $required = array_merge($required, [
                'auth_base_uri',
                'rest_base_uri',
                'client_id',
                'client_secret',
                'customer_de_key',
                'order_de_key',
                'journey_event_definition_key',
            ]);
        }

        $missing = [];
        foreach ($required as $key) {
            if (empty($configuration[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function ageSeconds($dateTime)
    {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        $timestamp = strtotime((string) $dateTime);
        if ($timestamp === false) {
            return null;
        }

        return max(0, time() - $timestamp);
    }
}
