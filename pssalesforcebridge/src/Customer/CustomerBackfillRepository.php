<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Customer;

final class CustomerBackfillRepository
{
    public function startOrResume(array $shopIds)
    {
        $now = date('Y-m-d H:i:s');
        $table = _DB_PREFIX_ . 'sfmc_customer_backfill';

        foreach ($shopIds as $shopId) {
            $shopId = (int) $shopId;
            if ($shopId <= 0) {
                continue;
            }

            $sql = "INSERT INTO `{$table}` (`id_shop`, `cursor_customer_id`, `status`, `scanned_count`, `queued_count`, `started_at`, `updated_at`, `completed_at`)
                VALUES ({$shopId}, 0, 'running', 0, 0, '" . pSQL($now) . "', '" . pSQL($now) . "', NULL)
                ON DUPLICATE KEY UPDATE
                    `status` = IF(`status` = 'completed', 'completed', 'running'),
                    `updated_at` = VALUES(`updated_at`)";

            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    public function runningJobs()
    {
        $table = _DB_PREFIX_ . 'sfmc_customer_backfill';

        $rows = Db::getInstance()->executeS(
            "SELECT * FROM `{$table}` WHERE `status` = 'running' ORDER BY `id_shop` ASC"
        );

        return is_array($rows) ? $rows : [];
    }

    public function advance($shopId, $cursorCustomerId, $scanned, $queued)
    {
        $table = _DB_PREFIX_ . 'sfmc_customer_backfill';
        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->execute(
            "UPDATE `{$table}` SET
                `cursor_customer_id` = " . (int) $cursorCustomerId . ",
                `scanned_count` = `scanned_count` + " . (int) $scanned . ",
                `queued_count` = `queued_count` + " . (int) $queued . ",
                `updated_at` = '" . pSQL($now) . "'
             WHERE `id_shop` = " . (int) $shopId
        );
    }

    public function markCompleted($shopId)
    {
        $table = _DB_PREFIX_ . 'sfmc_customer_backfill';
        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->execute(
            "UPDATE `{$table}` SET
                `status` = 'completed',
                `updated_at` = '" . pSQL($now) . "',
                `completed_at` = '" . pSQL($now) . "'
             WHERE `id_shop` = " . (int) $shopId
        );
    }

    public function summary()
    {
        $table = _DB_PREFIX_ . 'sfmc_customer_backfill';
        $rows = Db::getInstance()->executeS(
            "SELECT `id_shop`, `cursor_customer_id`, `status`, `scanned_count`, `queued_count`, `started_at`, `updated_at`, `completed_at`
             FROM `{$table}` ORDER BY `id_shop` ASC"
        );

        return is_array($rows) ? $rows : [];
    }
}
