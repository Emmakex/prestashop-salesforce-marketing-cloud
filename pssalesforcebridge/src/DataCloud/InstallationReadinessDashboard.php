<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

/** Escaped, read-only BO panel; never exposes DB credentials or data rows. */
final class InstallationReadinessDashboard
{
    public function render(array $report)
    {
        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-shield"></i> Data Cloud - installation preflight</div>';
        $html .= '<div class="alert alert-warning"><strong>BLOCKED - review only.</strong> '
            . 'This panel never enables PST schema uploads, record ingestion or marketing sends.</div>';
        $html .= '<p>Local checks use shop timezones and customer/order column metadata only. '
            . 'They do not establish historical date semantics or marketing permission.</p>';
        $checks = isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : [];
        $html .= '<h4>Local technical checks</h4><ul>';
        foreach (['shops_identified', 'shop_timezones_valid', 'date_columns_inspected'] as $key) {
            $html .= '<li>' . $this->escape($key) . ': <strong>'
                . (!empty($checks[$key]) ? 'PASS' : 'REVIEW REQUIRED')
                . '</strong></li>';
        }
        $html .= '</ul>';
        $html .= '<h4>Per-shop zone</h4><div class="table-responsive"><table class="table table-condensed table-bordered">';
        $html .= '<thead><tr><th>Shop ID</th><th>IANA timezone</th><th>Check</th></tr></thead><tbody>';
        foreach (isset($report['shops']) && is_array($report['shops']) ? $report['shops'] : [] as $row) {
            $html .= '<tr><td>' . (int) $row['shop_id'] . '</td><td>'
                . $this->escape($row['timezone']) . '</td><td>'
                . $this->escape($row['status']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<h4>SQL date column types (metadata only)</h4>';
        $html .= '<div class="table-responsive"><table class="table table-condensed table-bordered">';
        $html .= '<thead><tr><th>Object</th><th>date_add</th><th>date_upd</th></tr></thead><tbody>';
        foreach (['customer', 'orders'] as $object) {
            $dates = isset($report['date_columns'][$object]) ? $report['date_columns'][$object] : [];
            $html .= '<tr><td>' . $this->escape($object) . '</td><td>'
                . $this->escape(isset($dates['date_add']) ? $dates['date_add'] : 'unavailable')
                . '</td><td>'
                . $this->escape(isset($dates['date_upd']) ? $dates['date_upd'] : 'unavailable')
                . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<p><strong>Still requires separate approval:</strong> installation namespace uniqueness, '
            . 'historical date provenance, privacy/consent/retention, field dictionary, Data Stream '
            . 'refresh policy, DLO-to-DMO mapping, and final PST schema/stream activation.</p>';
        $html .= '<p>Existing Marketing Cloud Engagement health above is separate from Data Cloud readiness.</p>';
        return $html . '</div>';
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
