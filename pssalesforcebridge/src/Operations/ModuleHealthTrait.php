<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Operations;

use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerBackfillRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Journey\JourneyEventAuditRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Recovery\RecoveryTokenRepository;

trait ModuleHealthTrait
{
    public function healthReport()
    {
        $service = new HealthService(new QueueRepository());
        $report = $service->report($this->healthConfigurationState());
        $report['prestashop_version'] = defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown';
        $report['module_version'] = isset($this->version) ? (string) $this->version : 'unknown';

        return $report;
    }

    private function healthConfigurationState()
    {
        return [
            'mode' => (string) \Configuration::get(self::CONFIG_MODE),
            'cron_token' => trim((string) \Configuration::get(self::CONFIG_CRON_TOKEN)) !== '',
            'auth_base_uri' => trim((string) \Configuration::get(self::CONFIG_AUTH_BASE_URI)) !== '',
            'rest_base_uri' => trim((string) \Configuration::get(self::CONFIG_REST_BASE_URI)) !== '',
            'client_id' => trim((string) \Configuration::get(self::CONFIG_CLIENT_ID)) !== '',
            'client_secret' => trim((string) \Configuration::get(self::CONFIG_CLIENT_SECRET)) !== '',
            'customer_de_key' => trim((string) \Configuration::get(self::CONFIG_CUSTOMER_DE_KEY)) !== '',
            'order_de_key' => trim((string) \Configuration::get(self::CONFIG_ORDER_DE_KEY)) !== '',
            'journey_event_definition_key' => trim((string) \Configuration::get(self::CONFIG_JOURNEY_EVENT_DEFINITION_KEY)) !== '',
        ];
    }

    private function renderHealthDashboard()
    {
        try {
            $recovery = new RecoveryTokenRepository();
            $renderer = new BackOfficeHealthDashboard();

            return $renderer->render(
                $this->healthReport(),
                (new CustomerBackfillRepository())->summary(),
                (new CartRepository())->summary(),
                (new JourneyEventAuditRepository())->summary(),
                $recovery->summary(),
                $recovery->revenueByCurrency()
            );
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('Unable to build the connector health dashboard. Review the local database schema and logs.')
            );
        }
    }
}
