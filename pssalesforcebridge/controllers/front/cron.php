<?php

/**
 * Growth-only protected cron. Until G3/G4 and sender certification, the
 * endpoint only reports blocked status. No old queue or campaign is touched.
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
        try {
            $this->respond($this->module->cronReport(), 200);
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
