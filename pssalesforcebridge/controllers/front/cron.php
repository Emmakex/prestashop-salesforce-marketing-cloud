<?php

/**
 * Growth-only protected cron. Default is blocked status.
 * "inspect" reads up to 10 staging-only G3 event references; "dispatch"
 * retains the prior explicit customer-only UAT behavior. "dispatch_pair"
 * opts into bounded customer->order UAT for exactly one approved pair.
 * Default "status" never initiates transport or marketing campaigns.
 */
class PsSalesforceBridgeCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow', true);

        $provided = (string) Tools::getValue('token');
        $expected = (string) Configuration::get(PsSalesforceBridge::CONFIG_CRON_TOKEN);
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            $this->respond(['ok' => false, 'error' => 'unauthorized'], 403);
        }
        $operation=Tools::getValue('operation','status');
        if (!is_string($operation)
            || !in_array($operation,['status','inspect','dispatch','dispatch_pair'],true)) {
            $this->respond(['ok'=>false,'error'=>'invalid_operation'],400);
        }
        try {
            // "inspect" is staging-only, bounded, read-only, PII-free.
            // The default status operation remains unchanged for BC/DO.
            $this->respond($this->module->cronReport($operation), 200);
        } catch (\Throwable $e) {
            if (class_exists('PrestaShopLogger')) {
                PrestaShopLogger::addLog(
                    'Growth bridge cron status unavailable',
                    3, null, 'PsSalesforceBridge'
                );
            }
            $this->respond(['ok' => false, 'error' => 'cron_unavailable'], 500);
        }
    }

    private function respond(array $data, $code)
    {
        http_response_code((int) $code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}
