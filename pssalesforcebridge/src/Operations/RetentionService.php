<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Operations;

final class RetentionService
{
    const DEFAULT_QUEUE_DAYS = 30;
    const DEFAULT_LOG_DAYS = 30;
    const DEFAULT_JOURNEY_DAYS = 90;
    const DEFAULT_CART_DAYS = 30;
    const DEFAULT_RECOVERY_DAYS = 30;
    const MIN_DAYS = 1;
    const MAX_DAYS = 3650;

    private $repository;

    public function __construct(RetentionRepository $repository)
    {
        $this->repository = $repository;
    }

    public function run(array $configuration, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $days = [
            'queue' => $this->days(isset($configuration['queue_days']) ? $configuration['queue_days'] : self::DEFAULT_QUEUE_DAYS, self::DEFAULT_QUEUE_DAYS),
            'logs' => $this->days(isset($configuration['log_days']) ? $configuration['log_days'] : self::DEFAULT_LOG_DAYS, self::DEFAULT_LOG_DAYS),
            'journey' => $this->days(isset($configuration['journey_days']) ? $configuration['journey_days'] : self::DEFAULT_JOURNEY_DAYS, self::DEFAULT_JOURNEY_DAYS),
            'carts' => $this->days(isset($configuration['cart_days']) ? $configuration['cart_days'] : self::DEFAULT_CART_DAYS, self::DEFAULT_CART_DAYS),
            'recovery' => $this->days(isset($configuration['recovery_days']) ? $configuration['recovery_days'] : self::DEFAULT_RECOVERY_DAYS, self::DEFAULT_RECOVERY_DAYS),
        ];

        $cutoffs = [];
        foreach ($days as $key => $value) {
            $cutoffs[$key] = date('Y-m-d H:i:s', $now - ($value * 86400));
        }

        return [
            'ok' => true,
            'run_at' => date('Y-m-d H:i:s', $now),
            'retention_days' => $days,
            'cutoffs' => $cutoffs,
            'deleted' => [
                'processed_queue' => $this->repository->purgeProcessedQueue($cutoffs['queue']),
                'logs' => $this->repository->purgeLogs($cutoffs['logs']),
                'journey_audit' => $this->repository->purgeJourneyAudit($cutoffs['journey']),
                'cart_snapshots' => $this->repository->purgeTerminalCartSnapshots($cutoffs['carts']),
                'recovery_tokens' => $this->repository->pruneRecoveryTokens($cutoffs['recovery']),
            ],
        ];
    }

    private function days($value, $default)
    {
        $value = (int) $value;
        if ($value <= 0) {
            $value = (int) $default;
        }

        return max(self::MIN_DAYS, min(self::MAX_DAYS, $value));
    }
}
