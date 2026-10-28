<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Customer;

use Db;

final class CustomerSyncLedger
{
    public function observe($shopId, $customerId, $contactKey, $payloadHash)
    {
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'sfmc_customer_sync` '
            . '(`id_shop`, `id_customer`, `contact_key`, `payload_hash`, `status`, `created_at`, `updated_at`) VALUES ('
            . (int) $shopId . ', '
            . (int) $customerId . ", '"
            . pSQL((string) $contactKey) . "', '"
            . pSQL((string) $payloadHash) . "', 'pending', '"
            . pSQL($now) . "', '"
            . pSQL($now) . "') "
            . 'ON DUPLICATE KEY UPDATE '
            . '`contact_key` = VALUES(`contact_key`), '
            . '`status` = IF(`payload_hash` <> VALUES(`payload_hash`), \'pending\', `status`), '
            . '`payload_hash` = VALUES(`payload_hash`), '
            . '`updated_at` = VALUES(`updated_at`)';

        return (bool) Db::getInstance()->execute($sql);
    }

    public function markSynced($shopId, $customerId, $payloadHash)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_customer_sync',
            [
                'status' => 'synced',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_shop` = ' . (int) $shopId
            . ' AND `id_customer` = ' . (int) $customerId
            . " AND `payload_hash` = '" . pSQL((string) $payloadHash) . "'"
        );
    }
}
