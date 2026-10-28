<?php

class PsSalesforceBridgeCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $provided = (string) Tools::getValue('token');
        $expected = (string) Configuration::get(PsSalesforceBridge::CONFIG_CRON_TOKEN);

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->respond(['ok' => false, 'error' => 'unauthorized'], 403);
        }

        try {
            $backfill = $this->module->processCustomerBackfill();
            $cartDetection = $this->module->processCartAbandonmentCandidates(100);
            $customerDelivery = $this->module->processCustomerQueue(50);
            $orderDelivery = $this->module->processOrderQueue(50);
            $journeyDelivery = $this->module->processAbandonedCartJourneyQueue(20);
            $retention = $this->processRetentionSafely();

            $this->respond([
                'ok' => true,
                'result' => [
                    'customer_backfill' => $backfill,
                    'cart_detection' => $cartDetection,
                    'customer_delivery' => $customerDelivery,
                    'order_delivery' => $orderDelivery,
                    'journey_delivery' => $journeyDelivery,
                    'retention' => $retention,
                ],
            ], 200);
        } catch (\Throwable $e) {
            $this->logOperationalFailure('cron_failed', $e);
            $this->respond(['ok' => false, 'error' => 'cron_failed'], 500);
        }
    }

    private function processRetentionSafely()
    {
        try {
            return $this->module->processRetention(false);
        } catch (\Throwable $e) {
            $this->logOperationalFailure('retention_failed', $e);

            return [
                'ok' => false,
                'error' => 'retention_failed',
            ];
        }
    }

    private function logOperationalFailure($event, \Throwable $error)
    {
        if (!class_exists('PrestaShopLogger')) {
            return;
        }

        PrestaShopLogger::addLog(
            'Salesforce bridge ' . (string) $event . ' [' . get_class($error) . ']',
            3,
            null,
            'PsSalesforceBridge'
        );
    }

    private function respond(array $payload, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
