<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Operations;

trait ModuleRetentionTrait
{
    public function processRetention($force = false)
    {
        $lastRun = (string) \Configuration::get(self::CONFIG_RETENTION_LAST_RUN_AT);
        $lastRunTs = $lastRun !== '' ? strtotime($lastRun) : false;

        if (!$force && $lastRunTs !== false && $lastRunTs >= (time() - 86400)) {
            return [
                'ok' => true,
                'skipped' => 'interval_not_elapsed',
                'last_run_at' => $lastRun,
            ];
        }

        $service = new RetentionService(new RetentionRepository());
        $result = $service->run($this->retentionConfiguration());
        \Configuration::updateValue(self::CONFIG_RETENTION_LAST_RUN_AT, isset($result['run_at']) ? (string) $result['run_at'] : date('Y-m-d H:i:s'));

        return $result;
    }

    private function retentionConfiguration()
    {
        return [
            'queue_days' => $this->retentionValue(self::CONFIG_RETENTION_QUEUE_DAYS, RetentionService::DEFAULT_QUEUE_DAYS),
            'log_days' => $this->retentionValue(self::CONFIG_RETENTION_LOG_DAYS, RetentionService::DEFAULT_LOG_DAYS),
            'journey_days' => $this->retentionValue(self::CONFIG_RETENTION_JOURNEY_DAYS, RetentionService::DEFAULT_JOURNEY_DAYS),
            'cart_days' => $this->retentionValue(self::CONFIG_RETENTION_CART_DAYS, RetentionService::DEFAULT_CART_DAYS),
            'recovery_days' => $this->retentionValue(self::CONFIG_RETENTION_RECOVERY_DAYS, RetentionService::DEFAULT_RECOVERY_DAYS),
        ];
    }

    private function retentionValue($key, $default)
    {
        $value = (int) \Configuration::get($key);
        if ($value <= 0) {
            $value = (int) $default;
        }

        return max(RetentionService::MIN_DAYS, min(RetentionService::MAX_DAYS, $value));
    }

    private function renderRetentionStatus()
    {
        $lastRun = trim((string) \Configuration::get(self::CONFIG_RETENTION_LAST_RUN_AT));
        if ($lastRun === '') {
            return $this->displayInformation($this->l('Privacy retention has not run yet. It runs automatically at most once per day from the protected cron.'));
        }

        return $this->displayInformation($this->l('Privacy retention last completed at: ') . htmlspecialchars($lastRun, ENT_QUOTES, 'UTF-8'));
    }

    private function runRetentionNowAction()
    {
        try {
            $result = $this->processRetention(true);
            $deleted = isset($result['deleted']) && is_array($result['deleted']) ? $result['deleted'] : [];
            $queue = isset($deleted['processed_queue']) ? (int) $deleted['processed_queue'] : 0;
            $logs = isset($deleted['logs']) ? (int) $deleted['logs'] : 0;
            $journey = isset($deleted['journey_audit']) ? (int) $deleted['journey_audit'] : 0;

            return $this->displayConfirmation(sprintf(
                $this->l('Privacy retention completed. Processed queue: %d, logs: %d, Journey audit: %d. Cart and recovery cleanup also applied according to policy.'),
                $queue,
                $logs,
                $journey
            ));
        } catch (\Throwable $e) {
            return $this->displayError($this->l('Privacy retention failed. Review the local database schema and server logs.'));
        }
    }
}
