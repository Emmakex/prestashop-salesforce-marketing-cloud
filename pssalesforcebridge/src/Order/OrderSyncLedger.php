<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Order;

use Db;

final class OrderSyncLedger
{
    public function observe($shopId, $orderId, $cartId, $contactKey, $payloadHash)
    {
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'sfmc_order_sync` '
            . '(`id_shop`, `id_order`, `id_cart`, `contact_key`, `payload_hash`, `status`, `created_at`, `updated_at`) VALUES ('
            . (int) $shopId . ', '
            . (int) $orderId . ', '
            . (int) $cartId . ", '"
            . pSQL((string) $contactKey) . "', '"
            . pSQL((string) $payloadHash) . "', 'pending', '"
            . pSQL($now) . "', '"
            . pSQL($now) . "') "
            . 'ON DUPLICATE KEY UPDATE '
            . '`id_cart` = VALUES(`id_cart`), '
            . '`contact_key` = VALUES(`contact_key`), '
            . '`status` = IF(`payload_hash` <> VALUES(`payload_hash`), \'pending\', `status`), '
            . '`payload_hash` = VALUES(`payload_hash`), '
            . '`updated_at` = VALUES(`updated_at`)';

        return (bool) Db::getInstance()->execute($sql);
    }

    public function markSynced($shopId, $orderId, $payloadHash)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_order_sync',
            [
                'status' => 'synced',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_shop` = ' . (int) $shopId
            . ' AND `id_order` = ' . (int) $orderId
            . " AND `payload_hash` = '" . pSQL((string) $payloadHash) . "'"
        );
    }

    public function hasOrderForCart($shopId, $cartId)
    {
        if ((int) $shopId <= 0 || (int) $cartId <= 0) {
            return false;
        }

        $localSql = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'sfmc_order_sync`'
            . ' WHERE `id_shop` = ' . (int) $shopId
            . ' AND `id_cart` = ' . (int) $cartId
            . ' LIMIT 1';

        if ((int) Db::getInstance()->getValue($localSql) > 0) {
            return true;
        }

        $coreSql = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `id_shop` = ' . (int) $shopId
            . ' AND `id_cart` = ' . (int) $cartId
            . ' LIMIT 1';

        return (int) Db::getInstance()->getValue($coreSql) > 0;
    }
}
