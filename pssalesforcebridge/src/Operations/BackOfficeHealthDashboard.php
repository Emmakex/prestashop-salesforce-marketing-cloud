<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Operations;

final class BackOfficeHealthDashboard
{
    public function render(
        array $health,
        array $backfillRows,
        array $cartRows,
        array $journeyRows,
        array $recoverySummary,
        array $revenueRows
    ) {
        $status = isset($health['status']) ? (string) $health['status'] : HealthService::STATUS_CRITICAL;
        $mode = isset($health['mode']) ? (string) $health['mode'] : 'test';
        $queue = isset($health['queue']) && is_array($health['queue']) ? $health['queue'] : [];
        $counts = isset($queue['counts']) && is_array($queue['counts']) ? $queue['counts'] : [];
        $configuration = isset($health['configuration']) && is_array($health['configuration'])
            ? $health['configuration']
            : [];

        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-dashboard"></i> Connector health</div>';
        $html .= '<div class="alert ' . $this->statusAlertClass($status) . '">';
        $html .= '<strong>' . $this->escape(strtoupper($status)) . '</strong>';
        $html .= ' &middot; Mode: ' . $this->escape($mode);
        $html .= ' &middot; PrestaShop: ' . $this->escape(isset($health['prestashop_version']) ? $health['prestashop_version'] : 'unknown');
        $html .= ' &middot; Module: ' . $this->escape(isset($health['module_version']) ? $health['module_version'] : 'unknown');
        $html .= '</div>';

        $html .= '<div class="table-responsive"><table class="table table-bordered table-condensed">';
        $html .= '<thead><tr><th>Pending</th><th>Processing</th><th>Retry</th><th>Failed</th><th>Processed</th><th>Outstanding</th><th>Oldest outstanding</th><th>Stale locks</th></tr></thead><tbody><tr>';
        foreach (['pending', 'processing', 'retry', 'failed', 'processed'] as $queueStatus) {
            $html .= '<td>' . (int) (isset($counts[$queueStatus]) ? $counts[$queueStatus] : 0) . '</td>';
        }
        $html .= '<td>' . (int) (isset($queue['outstanding_total']) ? $queue['outstanding_total'] : 0) . '</td>';
        $html .= '<td>' . $this->escape($this->formatAge(isset($queue['oldest_outstanding_age_seconds']) ? $queue['oldest_outstanding_age_seconds'] : null)) . '</td>';
        $html .= '<td>' . (int) (isset($queue['stale_processing']) ? $queue['stale_processing'] : 0) . '</td>';
        $html .= '</tr></tbody></table></div>';

        $html .= $this->renderIssues(isset($health['issues']) && is_array($health['issues']) ? $health['issues'] : []);
        $html .= $this->renderEventBreakdown(isset($queue['by_event_type']) && is_array($queue['by_event_type']) ? $queue['by_event_type'] : []);
        $html .= $this->renderBackfill($backfillRows);
        $html .= $this->renderSimpleStatusTable('Cart snapshots', $cartRows);
        $html .= $this->renderSimpleStatusTable('Journey delivery', $journeyRows);
        $html .= $this->renderRecovery($recoverySummary, $revenueRows);
        $html .= $this->renderConfiguration($configuration);
        $html .= '</div>';

        return $html;
    }

    private function renderIssues(array $issues)
    {
        $html = '<h4>Operational issues</h4>';
        if (empty($issues)) {
            return $html . '<p>No active health issues.</p>';
        }

        $html .= '<div class="table-responsive"><table class="table table-striped table-condensed">';
        $html .= '<thead><tr><th>Severity</th><th>Code</th><th>Details</th></tr></thead><tbody>';
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $details = [];
            if (isset($issue['count'])) {
                $details[] = 'count=' . (int) $issue['count'];
            }
            if (isset($issue['oldest_age_seconds'])) {
                $details[] = 'age=' . $this->formatAge($issue['oldest_age_seconds']);
            }
            if (isset($issue['details']) && is_array($issue['details'])) {
                $details[] = implode(', ', array_map('strval', $issue['details']));
            }
            $html .= '<tr><td>' . $this->escape(isset($issue['severity']) ? $issue['severity'] : 'unknown') . '</td>';
            $html .= '<td>' . $this->escape(isset($issue['code']) ? $issue['code'] : 'unknown') . '</td>';
            $html .= '<td>' . $this->escape(implode(' | ', $details)) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderEventBreakdown(array $rows)
    {
        $html = '<h4>Active queue by event</h4>';
        if (empty($rows)) {
            return $html . '<p>No active queue rows.</p>';
        }

        $html .= '<div class="table-responsive"><table class="table table-striped table-condensed">';
        $html .= '<thead><tr><th>Event</th><th>Status</th><th>Total</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape(isset($row['event_type']) ? $row['event_type'] : 'unknown') . '</td>';
            $html .= '<td>' . $this->escape(isset($row['status']) ? $row['status'] : 'unknown') . '</td>';
            $html .= '<td>' . (int) (isset($row['total']) ? $row['total'] : 0) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderBackfill(array $rows)
    {
        $html = '<h4>Customer backfill</h4>';
        if (empty($rows)) {
            return $html . '<p>Not started.</p>';
        }

        $html .= '<div class="table-responsive"><table class="table table-striped table-condensed">';
        $html .= '<thead><tr><th>Shop</th><th>Status</th><th>Scanned</th><th>Queued</th><th>Cursor</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr><td>' . (int) (isset($row['id_shop']) ? $row['id_shop'] : 0) . '</td>';
            $html .= '<td>' . $this->escape(isset($row['status']) ? $row['status'] : 'unknown') . '</td>';
            $html .= '<td>' . (int) (isset($row['scanned_count']) ? $row['scanned_count'] : 0) . '</td>';
            $html .= '<td>' . (int) (isset($row['queued_count']) ? $row['queued_count'] : 0) . '</td>';
            $html .= '<td>' . (int) (isset($row['cursor_customer_id']) ? $row['cursor_customer_id'] : 0) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderSimpleStatusTable($title, array $rows)
    {
        $html = '<h4>' . $this->escape($title) . '</h4>';
        if (empty($rows)) {
            return $html . '<p>No data recorded yet.</p>';
        }

        $html .= '<div class="table-responsive"><table class="table table-striped table-condensed">';
        $html .= '<thead><tr><th>Status</th><th>Total</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape(isset($row['status']) ? $row['status'] : 'unknown') . '</td>';
            $html .= '<td>' . (int) (isset($row['total']) ? $row['total'] : 0) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderRecovery(array $summary, array $revenueRows)
    {
        $html = '<h4>Recovery attribution</h4>';
        $html .= '<div class="table-responsive"><table class="table table-bordered table-condensed">';
        $html .= '<thead><tr><th>Active links</th><th>Used links</th><th>Expired links</th><th>Recovered carts</th><th>Attributed orders</th><th>Paid recovered orders</th></tr></thead><tbody><tr>';
        foreach (['active_tokens', 'used_tokens', 'expired_tokens', 'recovered_carts', 'recovered_orders', 'paid_recovered_orders'] as $key) {
            $html .= '<td>' . (int) (isset($summary[$key]) ? $summary[$key] : 0) . '</td>';
        }
        $html .= '</tr></tbody></table></div>';

        if (empty($revenueRows)) {
            return $html . '<p>No paid recovered revenue recorded yet.</p>';
        }

        $html .= '<div class="table-responsive"><table class="table table-striped table-condensed">';
        $html .= '<thead><tr><th>Currency</th><th>Paid orders</th><th>Recovered revenue</th></tr></thead><tbody>';
        foreach ($revenueRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape(isset($row['currency']) && $row['currency'] !== '' ? $row['currency'] : 'unknown') . '</td>';
            $html .= '<td>' . (int) (isset($row['paid_orders']) ? $row['paid_orders'] : 0) . '</td>';
            $html .= '<td>' . number_format((float) (isset($row['revenue']) ? $row['revenue'] : 0), 2, '.', '') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function renderConfiguration(array $configuration)
    {
        $complete = !empty($configuration['complete_for_mode']);
        $html = '<h4>Configuration readiness</h4>';
        $html .= '<p><strong>' . ($complete ? 'Complete for current mode' : 'Incomplete for current mode') . '</strong></p>';

        $missing = isset($configuration['missing']) && is_array($configuration['missing'])
            ? $configuration['missing']
            : [];
        if (!empty($missing)) {
            $html .= '<p>Missing: ' . $this->escape(implode(', ', array_map('strval', $missing))) . '</p>';
        }

        return $html;
    }

    private function statusAlertClass($status)
    {
        if ($status === HealthService::STATUS_HEALTHY) {
            return 'alert-success';
        }
        if ($status === HealthService::STATUS_WARNING) {
            return 'alert-warning';
        }

        return 'alert-danger';
    }

    private function formatAge($seconds)
    {
        if ($seconds === null || $seconds === '') {
            return 'none';
        }

        $seconds = max(0, (int) $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = (int) floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0
            ? $hours . 'h ' . $remainingMinutes . 'm'
            : $hours . 'h';
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
