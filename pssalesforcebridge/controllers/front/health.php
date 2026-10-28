<?php

$moduleRoot = dirname(dirname(dirname(__FILE__)));
require_once $moduleRoot . '/src/Queue/QueueRepository.php';
require_once $moduleRoot . '/src/Operations/HealthService.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Operations\HealthService;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

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
            $service = new HealthService(new QueueRepository());
            $report = $service->report([
                'mode' => (string) Configuration::get(PsSalesforceBridge::CONFIG_MODE),
                'cron_token' => $expected !== '',
                'auth_base_uri' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_AUTH_BASE_URI)) !== '',
                'rest_base_uri' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_REST_BASE_URI)) !== '',
                'client_id' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_CLIENT_ID)) !== '',
                'client_secret' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_CLIENT_SECRET)) !== '',
                'customer_de_key' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_CUSTOMER_DE_KEY)) !== '',
                'order_de_key' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_ORDER_DE_KEY)) !== '',
                'journey_event_definition_key' => trim((string) Configuration::get(PsSalesforceBridge::CONFIG_JOURNEY_EVENT_DEFINITION_KEY)) !== '',
            ]);

            $report['prestashop_version'] = defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown';
            $report['module_version'] = isset($this->module->version) ? (string) $this->module->version : 'unknown';

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
