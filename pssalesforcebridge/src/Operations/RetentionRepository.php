<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Operations;

use Db;
use RuntimeException;

final class RetentionRepository
{
    public function purgeProcessedQueue($cutoff)
    {
        return $this->executeAffected(
            'DELETE FROM `' . _DB_PREFIX_ . 'sfmc_queue` '
            . "WHERE `status` = 'processed' AND `processed_at` IS NOT NULL "
            . "AND `processed_at` < '" . pSQL((string) $cutoff) . "'"
        );
    }

    public function purgeLogs($cutoff)
    {
        return $this->executeAffected(
            'DELETE FROM `' . _DB_PREFIX_ . 'sfmc_log` '
            . "WHERE `created_at` < '" . pSQL((string) $cutoff) . "'"
        );
    }

    public function purgeJourneyAudit($cutoff)
    {
        return $this->executeAffected(
            'DELETE FROM `' . _DB_PREFIX_ . 'sfmc_journey_event` '
            . "WHERE `status` IN ('sent', 'suppressed') "
            . "AND `updated_at` < '" . pSQL((string) $cutoff) . "'"
        );
    }

    public function purgeTerminalCartSnapshots($cutoff)
    {
        $cutoff = pSQL((string) $cutoff);
        $now = pSQL(date('Y-m-d H:i:s'));
        $prefix = _DB_PREFIX_;
        $condition = $this->cartRetentionCondition('c', $cutoff, $now, $prefix);

        $items = $this->executeAffected(
            "DELETE ci FROM `{$prefix}sfmc_cart_item` ci "
            . "INNER JOIN `{$prefix}sfmc_cart` c ON c.`id_shop` = ci.`id_shop` AND c.`id_cart` = ci.`id_cart` "
            . "WHERE {$condition}"
        );

        $carts = $this->executeAffected(
            "DELETE c FROM `{$prefix}sfmc_cart` c WHERE {$condition}"
        );

        return [
            'cart_items_deleted' => $items,
            'cart_snapshots_deleted' => $carts,
        ];
    }

    public function pruneRecoveryTokens($cutoff)
    {
        $cutoff = pSQL((string) $cutoff);
        $prefix = _DB_PREFIX_;
        $zeroSnapshot = str_repeat('0', 40);

        $anonymized = $this->executeAffected(
            "UPDATE `{$prefix}sfmc_recovery_token` SET "
            . "`token_hash` = SHA2(CONCAT('retained:', `id_sfmc_recovery_token`), 256), "
            . "`id_customer` = 0, `contact_key` = '', `snapshot_hash` = '{$zeroSnapshot}' "
            . "WHERE `used_at` IS NOT NULL AND `used_at` < '{$cutoff}' "
            . "AND `recovered_cart_id` > 0 "
            . "AND (`id_customer` <> 0 OR `contact_key` <> '' OR `snapshot_hash` <> '{$zeroSnapshot}')"
        );

        $usedWithoutRecovery = $this->executeAffected(
            "DELETE FROM `{$prefix}sfmc_recovery_token` "
            . "WHERE `used_at` IS NOT NULL AND `used_at` < '{$cutoff}' AND `recovered_cart_id` = 0"
        );

        $expiredUnused = $this->executeAffected(
            "DELETE FROM `{$prefix}sfmc_recovery_token` "
            . "WHERE `used_at` IS NULL AND `expires_at` < '{$cutoff}'"
        );

        return [
            'recovered_tokens_anonymized' => $anonymized,
            'used_tokens_deleted' => $usedWithoutRecovery,
            'expired_tokens_deleted' => $expiredUnused,
        ];
    }

    private function cartRetentionCondition($alias, $cutoff, $now, $prefix)
    {
        $alias = (string) $alias;
        $terminal = "('empty', 'ineligible', 'converted', 'recovered')";
        $outstanding = "('pending', 'processing', 'retry', 'failed')";

        return "{$alias}.`updated_at` < '{$cutoff}' AND ("
            . "{$alias}.`status` IN {$terminal} OR ("
            . "{$alias}.`status` = 'abandoned' "
            . "AND NOT EXISTS (SELECT 1 FROM `{$prefix}sfmc_recovery_token` rt "
            . "WHERE rt.`id_shop` = {$alias}.`id_shop` AND rt.`id_cart` = {$alias}.`id_cart` "
            . "AND rt.`used_at` IS NULL AND rt.`expires_at` >= '{$now}') "
            . "AND NOT EXISTS (SELECT 1 FROM `{$prefix}sfmc_queue` q "
            . "WHERE q.`event_type` = 'CART_ABANDONED' "
            . "AND q.`entity_id` = CONCAT({$alias}.`id_shop`, ':', {$alias}.`id_cart`) "
            . "AND q.`status` IN {$outstanding})"
            . '))';
    }

    private function executeAffected($sql)
    {
        $db = Db::getInstance();
        if (!$db->execute((string) $sql)) {
            throw new RuntimeException('Retention database operation failed.');
        }

        return (int) $db->Affected_Rows();
    }
}
