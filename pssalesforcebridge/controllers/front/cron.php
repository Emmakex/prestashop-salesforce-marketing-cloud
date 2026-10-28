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
            $result = $this->module->processCustomerQueue(20);
            $this->respond(['ok' => true, 'result' => $result], 200);
        } catch (Exception $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function respond(array $payload, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
