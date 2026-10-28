<?php

class PsSalesforceBridgeHealthModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $this->setNoCacheHeaders();

        $provided = (string) Tools::getValue('token');
        $expected = (string) Configuration::get(PsSalesforceBridge::CONFIG_CRON_TOKEN);

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->respond(['ok' => false, 'status' => 'critical', 'error' => 'unauthorized'], 403);
        }

        try {
            $report = $this->module->healthReport();
            $this->respond($report, !empty($report['ok']) ? 200 : 503);
        } catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'status' => 'critical',
                'error' => 'health_report_failed',
            ], 500);
        }
    }

    private function setNoCacheHeaders()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    private function respond(array $payload, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
