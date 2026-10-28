<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Recovery;

use Db;
use RuntimeException;

final class RecoveryTokenRepository
{
    public function issue($shopId, $cartId, $customerId, $contactKey, $snapshotHash, $ttlHours)
    {
        $shopId = (int) $shopId;
        $cartId = (int) $cartId;
        $customerId = (int) $customerId;
        $ttlHours = max(1, min(720, (int) $ttlHours));
        $snapshotHash = trim((string) $snapshotHash);
        $contactKey = trim((string) $contactKey);

        if ($shopId <= 0 || $cartId <= 0 || $snapshotHash === '' || $contactKey === '') {
            throw new RuntimeException('Recovery token context is incomplete.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
        $db = Db::getInstance();

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'sfmc_recovery_token` '
            . "SET `expires_at` = '" . pSQL(date('Y-m-d H:i:s', time() - 1)) . "' "
            . 'WHERE `id_shop` = ' . $shopId
            . ' AND `id_cart` = ' . $cartId
            . " AND `snapshot_hash` = '" . pSQL($snapshotHash) . "'"
            . ' AND `used_at` IS NULL'
        );

        $ok = $db->insert(
            'sfmc_recovery_token',
            [
                'token_hash' => $tokenHash,
                'id_shop' => $shopId,
                'id_cart' => $cartId,
                'id_customer' => $customerId,
                'contact_key' => $contactKey,
                'snapshot_hash' => $snapshotHash,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'recovered_cart_id' => 0,
                'recovered_order_id' => 0,
                'recovered_order_total' => 0,
                'recovered_currency' => '',
                'recovered_order_state_id' => 0,
                'recovered_order_paid' => 0,
                'recovered_order_valid' => 0,
                'converted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            true
        );

        if (!$ok) {
            throw new RuntimeException('Unable to persist recovery token.');
        }

        return $rawToken;
    }

    public function claim($rawToken)
    {
        $rawToken = trim((string) $rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
            return null;
        }

        $hash = hash('sha256', $rawToken);
        $now = date('Y-m-d H:i:s');
        $db = Db::getInstance();

        $sql = 'UPDATE `' . _DB_PREFIX_ . 'sfmc_recovery_token` '
            . "SET `used_at` = '" . pSQL($now) . "', `updated_at` = '" . pSQL($now) . "' "
            . "WHERE `token_hash` = '" . pSQL($hash) . "'"
            . ' AND `used_at` IS NULL'
            . " AND `expires_at` >= '" . pSQL($now) . "'"
            . ' LIMIT 1';

        if (!$db->execute($sql) || (int) $db->Affected_Rows() !== 1) {
            return null;
        }

        $row = $db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'sfmc_recovery_token`'
            . " WHERE `token_hash` = '" . pSQL($hash) . "' LIMIT 1"
        );

        return is_array($row) ? $row : null;
    }

    public function attachRecoveredCart($tokenId, $recoveredCartId)
    {
        return (bool) Db::getInstance()->update(
            'sfmc_recovery_token',
            [
                'recovered_cart_id' => (int) $recoveredCartId,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_sfmc_recovery_token` = ' . (int) $tokenId
        );
    }

    public function attributeOrder($shopId, $recoveredCartId, $orderId, $orderTotal, $currency, $stateId, $paid, $valid)
    {
        $shopId = (int) $shopId;
        $recoveredCartId = (int) $recoveredCartId;
        $orderId = (int) $orderId;

        if ($shopId <= 0 || $recoveredCartId <= 0 || $orderId <= 0) {
            return true;
        }

        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `id_sfmc_recovery_token`, `converted_at` FROM `' . _DB_PREFIX_ . 'sfmc_recovery_token`'
            . ' WHERE `id_shop` = ' . $shopId
            . ' AND `recovered_cart_id` = ' . $recoveredCartId
            . ' AND `used_at` IS NOT NULL'
            . ' ORDER BY `id_sfmc_recovery_token` DESC LIMIT 1'
        );

        if (!is_array($row) || empty($row['id_sfmc_recovery_token'])) {
            return true;
        }

        $now = date('Y-m-d H:i:s');
        $convertedAt = !empty($row['converted_at']) ? (string) $row['converted_at'] : $now;

        return (bool) $db->update(
            'sfmc_recovery_token',
            [
                'recovered_order_id' => $orderId,
                'recovered_order_total' => (float) $orderTotal,
                'recovered_currency' => substr(trim((string) $currency), 0, 8),
                'recovered_order_state_id' => (int) $stateId,
                'recovered_order_paid' => (bool) $paid ? 1 : 0,
                'recovered_order_valid' => (bool) $valid ? 1 : 0,
                'converted_at' => $convertedAt,
                'updated_at' => $now,
            ],
            '`id_sfmc_recovery_token` = ' . (int) $row['id_sfmc_recovery_token']
        );
    }

    public function summary()
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        $sql = 'SELECT '
            . "SUM(CASE WHEN `used_at` IS NULL AND `expires_at` >= '{$now}' THEN 1 ELSE 0 END) AS active_tokens, "
            . 'SUM(CASE WHEN `used_at` IS NOT NULL THEN 1 ELSE 0 END) AS used_tokens, '
            . "SUM(CASE WHEN `used_at` IS NULL AND `expires_at` < '{$now}' THEN 1 ELSE 0 END) AS expired_tokens, "
            . 'SUM(CASE WHEN `recovered_cart_id` > 0 THEN 1 ELSE 0 END) AS recovered_carts, '
            . 'SUM(CASE WHEN `recovered_order_id` > 0 THEN 1 ELSE 0 END) AS recovered_orders, '
            . 'SUM(CASE WHEN `recovered_order_id` > 0 AND `recovered_order_paid` = 1 THEN 1 ELSE 0 END) AS paid_recovered_orders '
            . 'FROM `' . _DB_PREFIX_ . 'sfmc_recovery_token`';
        $row = Db::getInstance()->getRow($sql);

        return is_array($row) ? $row : [];
    }

    public function revenueByCurrency()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `recovered_currency` AS `currency`, COUNT(*) AS `paid_orders`, '
            . 'SUM(`recovered_order_total`) AS `revenue` '
            . 'FROM `' . _DB_PREFIX_ . 'sfmc_recovery_token` '
            . 'WHERE `recovered_order_id` > 0 AND `recovered_order_paid` = 1 '
            . 'GROUP BY `recovered_currency` ORDER BY `recovered_currency` ASC'
        );

        return is_array($rows) ? $rows : [];
    }
}
