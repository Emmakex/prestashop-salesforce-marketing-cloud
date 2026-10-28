<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Journey;

use Db;

final class JourneyEventAuditRepository
{
    public function findByQueueId($queueId)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'sfmc_journey_event` WHERE `id_sfmc_queue` = ' . (int) $queueId . ' LIMIT 1';
        $row = Db::getInstance()->getRow($sql);

        return is_array($row) ? $row : null;
    }

    public function observe($queueId, $shopId, $cartId, $contactKey, $eventDefinitionKey, $snapshotHash, $bridgeEventKey)
    {
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'sfmc_journey_event` '
            . '(`id_sfmc_queue`, `id_shop`, `id_cart`, `contact_key`, `event_definition_key`, `snapshot_hash`, `bridge_event_key`, `status`, `created_at`, `updated_at`) VALUES ('
            . (int) $queueId . ', '
            . (int) $shopId . ', '
            . (int) $cartId . ", '"
            . pSQL((string) $contactKey) . "', '"
            . pSQL((string) $eventDefinitionKey) . "', '"
            . pSQL((string) $snapshotHash) . "', '"
            . pSQL((string) $bridgeEventKey) . "', 'processing', '"
            . pSQL($now) . "', '"
            . pSQL($now) . "') "
            . 'ON DUPLICATE KEY UPDATE '
            . '`id_shop` = VALUES(`id_shop`), '
            . '`id_cart` = VALUES(`id_cart`), '
            . '`contact_key` = VALUES(`contact_key`), '
            . '`event_definition_key` = VALUES(`event_definition_key`), '
            . '`snapshot_hash` = VALUES(`snapshot_hash`), '
            . '`bridge_event_key` = VALUES(`bridge_event_key`), '
            . '`status` = IF(`status` = \'sent\', `status`, \'processing\'), '
            . '`updated_at` = VALUES(`updated_at`)';

        return (bool) Db::getInstance()->execute($sql);
    }

    public function markSent($queueId, $eventInstanceId)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_journey_event',
            [
                'status' => 'sent',
                'event_instance_id' => (string) $eventInstanceId,
                'last_error' => null,
                'sent_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_sfmc_queue` = ' . (int) $queueId
        );
    }

    public function markSuppressed($queueId, $reason)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_journey_event',
            [
                'status' => 'suppressed',
                'suppression_reason' => substr((string) $reason, 0, 191),
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_sfmc_queue` = ' . (int) $queueId
        );
    }

    public function markFailure($queueId, $message, $terminal)
    {
        $status = $terminal ? 'failed' : 'retry';
        $now = pSQL(date('Y-m-d H:i:s'));
        $message = pSQL(substr((string) $message, 0, 2000), true);
        $status = pSQL($status);

        $sql = 'UPDATE `' . _DB_PREFIX_ . 'sfmc_journey_event` SET '
            . "`status` = IF(`status` = 'sent', 'sent', '{$status}'), "
            . "`last_error` = '{$message}', "
            . "`updated_at` = '{$now}' "
            . 'WHERE `id_sfmc_queue` = ' . (int) $queueId;

        return (bool) Db::getInstance()->execute($sql);
    }

    public function summary()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS `total` FROM `' . _DB_PREFIX_ . 'sfmc_journey_event` GROUP BY `status` ORDER BY `status` ASC'
        );

        return is_array($rows) ? $rows : [];
    }
}
