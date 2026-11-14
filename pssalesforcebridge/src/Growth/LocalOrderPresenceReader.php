<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Local PrestaShop-only order evidence for a future Growth cart pre-send gate.
 *
 * This class has NO outbound capability, job, hook or sender. It is not wired
 * into 0.7.1, and "no order" never authorizes contact or a campaign.
 *
 * The two local sources are deliberately independent: native orders and the
 * existing connector order ledger. An SQL error is UNKNOWN, never "no order".
 */
final class LocalOrderPresenceReader
{
    /**
     * The injected callback receives ONLY a bounded, scoped SELECT query.
     * Its result must be exactly false (failure), [] (successful absence),
     * or [row] with id_shop, id_cart, id_order.
     *
     * @param int $shopId
     * @param int $cartId
     * @param callable $selectRows
     * @param string $tablePrefix Existing installation's PrestaShop DB prefix
     * @return array No order IDs, customer data or SQL in returned evidence
     */
    public static function inspect($shopId, $cartId, $selectRows, $tablePrefix)
    {
        if (!is_int($shopId) || $shopId <= 0
            || !is_int($cartId) || $cartId <= 0) {
            throw new RuntimeException('growth_order_scope_invalid');
        }
        if (!is_callable($selectRows)) {
            throw new RuntimeException('growth_order_reader_required');
        }
        if (!is_string($tablePrefix) || strlen($tablePrefix) < 1
            || strlen($tablePrefix) > 64
            || !preg_match('/^[A-Za-z0-9_]+$/D', $tablePrefix)) {
            throw new RuntimeException('growth_order_prefix_invalid');
        }

        $sources = [
            'native' => $tablePrefix . 'orders',
            'connector' => $tablePrefix . 'sfmc_order_sync',
        ];
        $states = [];
        foreach ($sources as $name => $table) {
            // Explicit shop AND cart scope on both tables, LIMIT 1.
            // SQL never includes customer identifiers, PII or payloads.
            $sql = 'SELECT `id_shop`, `id_cart`, `id_order` FROM `'
                . $table . '` WHERE `id_shop` = ' . $shopId
                . ' AND `id_cart` = ' . $cartId
                . ' ORDER BY `id_order` ASC LIMIT 1';
            try {
                $raw = call_user_func($selectRows, $sql);
            } catch (\Throwable $error) {
                // A missing legacy ledger, timeouts and malformed responses
                // must never silently become an empty successful SELECT.
                $raw = false;
            }
            $states[$name] = self::classify($raw, $shopId, $cartId);
        }

        $converted = $states['native'] === 'found'
            || $states['connector'] === 'found';
        $unknown = $states['native'] === 'unknown'
            || $states['connector'] === 'unknown';
        return [
            'status' => $converted ? 'converted_locally'
                : ($unknown ? 'unknown_hold' : 'no_order_candidate_only'),
            'native_order_state' => $states['native'],
            'connector_order_state' => $states['connector'],
            'evidence_type' => 'scoped_local_prestashop_order_reads',
            // Source read is necessary but not sufficient: cancellation
            // races, buyer identity, Growth consent, suppression and policy
            // approvals must be checked separately before any delivery.
            'growth_cart_send_authorized' => false,
            'absence_certified_for_campaign' => false,
            'growth_consent_certified' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    /** PrestaShop 1.7/8/9 adapter: only two local SQL SELECTs. */
    public static function inspectPrestaShop($shopId, $cartId)
    {
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new RuntimeException('growth_order_prestashop_unavailable');
        }
        return self::inspect($shopId, $cartId, function ($sql) {
            return \Db::getInstance()->executeS($sql);
        }, _DB_PREFIX_);
    }

    private static function classify($rows, $shopId, $cartId)
    {
        if ($rows === []) {
            return 'none';
        }
        if (!is_array($rows) || count($rows) !== 1
            || !isset($rows[0]) || !is_array($rows[0])) {
            return 'unknown';
        }
        $row = $rows[0];
        $names = array_keys($row);
        sort($names);
        if ($names !== ['id_cart', 'id_order', 'id_shop']) {
            return 'unknown';
        }
        $shop = self::positiveId($row['id_shop']);
        $cart = self::positiveId($row['id_cart']);
        $order = self::positiveId($row['id_order']);
        return $shop === $shopId && $cart === $cartId && $order !== null
            ? 'found' : 'unknown';
    }

    private static function positiveId($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 && (string) $id === $value ? $id : null;
    }
}
