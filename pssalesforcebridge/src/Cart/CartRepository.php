<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

use Db;

final class CartRepository
{
    public function observe(array $cart, $status, $eligible, $eligibilityReason, $consentMode, $abandonAfter, $snapshotHash)
    {
        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $payload = json_encode($cart);

        if ($payload === false) {
            return false;
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'sfmc_cart` '
            . '(`id_shop`, `id_cart`, `id_customer`, `contact_key`, `email`, `currency`, `total`, `product_count`, `item_count`, `consent_mode`, `eligible`, `eligibility_reason`, `status`, `last_activity`, `abandon_after`, `abandoned_at`, `snapshot_hash`, `payload`, `created_at`, `updated_at`) VALUES ('
            . (int) $cart['shop_id'] . ', '
            . (int) $cart['cart_id'] . ', '
            . (int) $cart['customer_id'] . ", '"
            . pSQL((string) $cart['contact_key']) . "', '"
            . pSQL((string) $cart['email']) . "', '"
            . pSQL((string) $cart['currency']) . "', "
            . (float) $cart['total'] . ', '
            . (int) $cart['product_count'] . ', '
            . (int) $cart['item_count'] . ", '"
            . pSQL((string) $consentMode) . "', "
            . ((bool) $eligible ? 1 : 0) . ", '"
            . pSQL((string) $eligibilityReason) . "', '"
            . pSQL((string) $status) . "', '"
            . pSQL((string) $cart['last_activity']) . "', '"
            . pSQL((string) $abandonAfter) . "', NULL, '"
            . pSQL((string) $snapshotHash) . "', '"
            . pSQL($payload, true) . "', '"
            . pSQL($now) . "', '"
            . pSQL($now) . "') "
            . 'ON DUPLICATE KEY UPDATE '
            . '`id_customer` = VALUES(`id_customer`), '
            . '`contact_key` = VALUES(`contact_key`), '
            . '`email` = VALUES(`email`), '
            . '`currency` = VALUES(`currency`), '
            . '`total` = VALUES(`total`), '
            . '`product_count` = VALUES(`product_count`), '
            . '`item_count` = VALUES(`item_count`), '
            . '`consent_mode` = VALUES(`consent_mode`), '
            . '`eligible` = VALUES(`eligible`), '
            . '`eligibility_reason` = VALUES(`eligibility_reason`), '
            . '`status` = VALUES(`status`), '
            . '`last_activity` = VALUES(`last_activity`), '
            . '`abandon_after` = VALUES(`abandon_after`), '
            . '`abandoned_at` = NULL, '
            . '`snapshot_hash` = VALUES(`snapshot_hash`), '
            . '`payload` = VALUES(`payload`), '
            . '`updated_at` = VALUES(`updated_at`)';

        if (!$db->execute($sql)) {
            return false;
        }

        return $this->replaceItems($cart);
    }

    private function replaceItems(array $cart)
    {
        $db = Db::getInstance();
        $shopId = (int) $cart['shop_id'];
        $cartId = (int) $cart['cart_id'];

        if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'sfmc_cart_item` WHERE `id_shop` = ' . $shopId . ' AND `id_cart` = ' . $cartId)) {
            return false;
        }

        $items = isset($cart['items']) && is_array($cart['items']) ? $cart['items'] : [];
        foreach ($items as $item) {
            $hash = sha1(json_encode($item));
            $ok = $db->insert('sfmc_cart_item', [
                'id_shop' => $shopId,
                'id_cart' => $cartId,
                'line_key' => (string) $item['line_key'],
                'id_product' => (int) $item['product_id'],
                'id_product_attribute' => (int) $item['product_attribute_id'],
                'id_customization' => (int) $item['customization_id'],
                'sku' => (string) $item['sku'],
                'name' => (string) $item['name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'line_total' => (float) $item['line_total'],
                'payload_hash' => $hash,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function candidates($limit)
    {
        $limit = max(1, min(500, (int) $limit));
        $now = pSQL(date('Y-m-d H:i:s'));
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'sfmc_cart` '
            . "WHERE `status` = 'active' AND `eligible` = 1 AND `product_count` > 0 AND `abandon_after` <= '{$now}' "
            . 'ORDER BY `abandon_after` ASC LIMIT ' . $limit;

        $rows = Db::getInstance()->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    public function markConverted($shopId, $cartId)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_cart',
            [
                'status' => 'converted',
                'eligible' => 0,
                'eligibility_reason' => 'converted_to_order',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_shop` = ' . (int) $shopId . ' AND `id_cart` = ' . (int) $cartId
        );
    }

    public function markAbandoned($shopId, $cartId)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_cart',
            [
                'status' => 'abandoned',
                'abandoned_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_shop` = ' . (int) $shopId . ' AND `id_cart` = ' . (int) $cartId
        );
    }

    public function summary()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS `total` FROM `' . _DB_PREFIX_ . 'sfmc_cart` GROUP BY `status` ORDER BY `status` ASC'
        );

        return is_array($rows) ? $rows : [];
    }
}
