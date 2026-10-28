<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Queue;

use Db;

final class QueueRepository
{
    public function enqueue($eventType, $entityType, $entityId, $dedupeKey, array $payload)
    {
        $now = date('Y-m-d H:i:s');
        $encoded = json_encode($payload);

        if ($encoded === false) {
            return false;
        }

        return (bool) Db::getInstance()->insert(
            'sfmc_queue',
            [
                'event_type' => (string) $eventType,
                'entity_type' => (string) $entityType,
                'entity_id' => (string) $entityId,
                'dedupe_key' => (string) $dedupeKey,
                'payload' => $encoded,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
            ],
            false,
            true,
            Db::INSERT_IGNORE
        );
    }

    public function claimCustomerBatch($limit, $lockToken)
    {
        return $this->claimBatchByEventTypes(['CUSTOMER_CREATED', 'CUSTOMER_UPDATED'], $limit, $lockToken);
    }

    public function claimOrderBatch($limit, $lockToken)
    {
        return $this->claimBatchByEventTypes(
            ['ORDER_CREATED', 'ORDER_UPDATED', 'ORDER_PAID', 'ORDER_CANCELLED'],
            $limit,
            $lockToken
        );
    }

    public function claimAbandonedCartBatch($limit, $lockToken)
    {
        return $this->claimBatchByEventTypes(['CART_ABANDONED'], $limit, $lockToken);
    }

    private function claimBatchByEventTypes(array $eventTypes, $limit, $lockToken)
    {
        $limit = max(1, min(100, (int) $limit));
        $lockToken = pSQL((string) $lockToken);
        $prefix = _DB_PREFIX_;
        $staleBefore = pSQL(date('Y-m-d H:i:s', time() - 600));
        $now = pSQL(date('Y-m-d H:i:s'));
        $quotedEvents = [];

        foreach ($eventTypes as $eventType) {
            $quotedEvents[] = "'" . pSQL((string) $eventType) . "'";
        }

        if (empty($quotedEvents)) {
            return [];
        }

        Db::getInstance()->execute(
            "UPDATE `{$prefix}sfmc_queue`
             SET `status` = 'retry', `lock_token` = NULL, `locked_at` = NULL
             WHERE `status` = 'processing' AND `locked_at` IS NOT NULL AND `locked_at` < '{$staleBefore}'"
        );

        $claimSql = "UPDATE `{$prefix}sfmc_queue`
            SET `status` = 'processing', `lock_token` = '{$lockToken}', `locked_at` = '{$now}'
            WHERE `id_sfmc_queue` IN (
                SELECT `id_sfmc_queue` FROM (
                    SELECT `id_sfmc_queue`
                    FROM `{$prefix}sfmc_queue`
                    WHERE `status` IN ('pending', 'retry')
                      AND `available_at` <= '{$now}'
                      AND `event_type` IN (" . implode(',', $quotedEvents) . ")
                    ORDER BY `id_sfmc_queue` ASC
                    LIMIT {$limit}
                ) AS `claimable`
            )";

        if (!Db::getInstance()->execute($claimSql)) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            "SELECT * FROM `{$prefix}sfmc_queue`
             WHERE `status` = 'processing' AND `lock_token` = '{$lockToken}'
             ORDER BY `id_sfmc_queue` ASC"
        );

        return is_array($rows) ? $rows : [];
    }

    public function markProcessed($id)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_queue',
            [
                'status' => 'processed',
                'processed_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
                'lock_token' => null,
                'locked_at' => null,
            ],
            '`id_sfmc_queue` = ' . (int) $id,
            1,
            true
        );
    }

    public function markFailure($id, $currentAttempts, $message, $delaySeconds, $maxAttempts)
    {
        $attempts = (int) $currentAttempts + 1;
        $status = $attempts >= (int) $maxAttempts ? 'failed' : 'retry';

        return (bool) Db::getInstance()->update(
            'sfmc_queue',
            [
                'status' => $status,
                'attempts' => $attempts,
                'available_at' => date('Y-m-d H:i:s', time() + max(30, (int) $delaySeconds)),
                'last_error' => substr((string) $message, 0, 2000),
                'lock_token' => null,
                'locked_at' => null,
            ],
            '`id_sfmc_queue` = ' . (int) $id,
            1,
            true
        );
    }

    public function statusSummary()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS `total`, MAX(`attempts`) AS `max_attempts` '
            . 'FROM `' . _DB_PREFIX_ . 'sfmc_queue` '
            . 'GROUP BY `status` ORDER BY `status` ASC'
        );

        return is_array($rows) ? $rows : [];
    }

    public function eventTypeSummary()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `event_type`, `status`, COUNT(*) AS `total` '
            . 'FROM `' . _DB_PREFIX_ . 'sfmc_queue` '
            . "WHERE `status` IN ('pending', 'processing', 'retry', 'failed') "
            . 'GROUP BY `event_type`, `status` '
            . 'ORDER BY `event_type` ASC, `status` ASC'
        );

        return is_array($rows) ? $rows : [];
    }

    public function oldestOutstanding()
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_sfmc_queue`, `event_type`, `entity_type`, `entity_id`, `status`, `attempts`, '
            . '`available_at`, `created_at`, `locked_at` '
            . 'FROM `' . _DB_PREFIX_ . 'sfmc_queue` '
            . "WHERE `status` IN ('pending', 'retry', 'processing') "
            . 'ORDER BY `created_at` ASC, `id_sfmc_queue` ASC LIMIT 1'
        );

        return is_array($row) ? $row : [];
    }

    public function staleProcessingCount($staleSeconds)
    {
        $staleSeconds = max(60, (int) $staleSeconds);
        $staleBefore = pSQL(date('Y-m-d H:i:s', time() - $staleSeconds));
        $value = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'sfmc_queue` '
            . "WHERE `status` = 'processing' "
            . "AND `locked_at` IS NOT NULL AND `locked_at` < '{$staleBefore}'"
        );

        return (int) $value;
    }
}
