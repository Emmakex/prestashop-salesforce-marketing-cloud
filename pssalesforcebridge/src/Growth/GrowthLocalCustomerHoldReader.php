<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * Read-only local customer HOLD evidence. Works with PrestaShop 1.7/8/9
 * standard customer columns without reading email, names, passwords or PII.
 *
 * A positive optin/newsletter flag is NEVER proof of lawful consent for the
 * separate Marketing Cloud Growth purpose. Negative flags, deleted/guest/
 * inactive accounts, foreign shop rows, missing sources and read failures
 * always hold. This reader is NOT a sender and is not wired into hooks.
 */
final class GrowthLocalCustomerHoldReader
{
    public static function inspect($shopId, $customerId, $selectRows, $prefix)
    {
        if (!is_int($shopId) || $shopId < 1
            || !is_int($customerId) || $customerId < 1) {
            throw new RuntimeException('growth_customer_scope_invalid');
        }
        if (!is_callable($selectRows)) {
            throw new RuntimeException('growth_customer_reader_required');
        }
        if (!is_string($prefix) || strlen($prefix) < 1
            || strlen($prefix) > 64
            || !preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new RuntimeException('growth_customer_prefix_invalid');
        }

        // Never query email/firstname/lastname. The explicit shop scope
        // prevents treating a different shop's customer as the same contact.
        // Limit 2 detects malformed duplicate source evidence (PK expected).
        $sql = 'SELECT `id_customer`, `id_shop`, `active`, `deleted`,'
            . ' `is_guest`, `newsletter`, `optin` FROM `' . $prefix
            . 'customer` WHERE `id_shop` = ' . $shopId
            . ' AND `id_customer` = ' . $customerId . ' LIMIT 2';
        try {
            $rows = call_user_func($selectRows, $sql);
        } catch (Throwable $error) {
            return self::hold(['local_customer_read_unverified'], 'unknown');
        }
        if ($rows === []) {
            return self::hold(['local_customer_not_found'], 'unknown');
        }
        if (!is_array($rows) || count($rows) !== 1
            || !isset($rows[0]) || !is_array($rows[0])) {
            return self::hold(['local_customer_read_unverified'], 'unknown');
        }
        $row = $rows[0];
        $fields = array_keys($row);
        sort($fields);
        if ($fields !== ['active', 'deleted', 'id_customer',
            'id_shop', 'is_guest', 'newsletter', 'optin']) {
            return self::hold(['local_customer_read_unverified'], 'unknown');
        }
        if (self::id($row['id_shop']) !== $shopId
            || self::id($row['id_customer']) !== $customerId) {
            return self::hold(['local_customer_scope_mismatch'], 'unknown');
        }
        foreach (['active', 'deleted', 'is_guest', 'newsletter', 'optin'] as $key) {
            if (self::bit($row[$key]) === null) {
                return self::hold(['local_customer_read_unverified'], 'unknown');
            }
        }

        $reasons = [];
        if (self::bit($row['deleted']) === 1) {
            $reasons[] = 'local_customer_deleted';
        }
        if (self::bit($row['active']) !== 1) {
            $reasons[] = 'local_customer_inactive';
        }
        if (self::bit($row['is_guest']) === 1) {
            $reasons[] = 'local_customer_guest';
        }
        if (self::bit($row['newsletter']) !== 1) {
            $reasons[] = 'local_newsletter_negative';
        }
        if (self::bit($row['optin']) !== 1) {
            $reasons[] = 'local_optin_negative';
        }
        if ($reasons) {
            return self::hold($reasons, 'known_negative');
        }
        return self::hold(['growth_purpose_consent_not_certified'],
            'no_local_negative_observed');
    }

    /**
     * Restricted PrestaShop adaptation for local, non-exporting review.
     * The module does not call it until a later approved source gate.
     */
    public static function inspectPrestaShop($shopId, $customerId)
    {
        if (!defined('_DB_PREFIX_') || !class_exists('Db')) {
            throw new RuntimeException('growth_customer_prestashop_unavailable');
        }
        return self::inspect($shopId, $customerId, function ($sql) {
            return \Db::getInstance()->executeS($sql);
        }, _DB_PREFIX_);
    }

    private static function hold(array $reasons, $sourceState)
    {
        return [
            'status' => 'hold_for_review',
            'local_customer_source_state' => $sourceState,
            'reasons' => $reasons,
            'source_customer_policy_certified' => false,
            'growth_purpose_consent_certified' => false,
            'other_suppression_sources_checked' => false,
            'customer_contact_authorized' => false,
            'data_cloud_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function bit($value)
    {
        if ($value === 1 || $value === '1') {
            return 1;
        }
        if ($value === 0 || $value === '0') {
            return 0;
        }
        return null;
    }

    private static function id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $n = (int) $value;
        return $n > 0 && (string) $n === $value ? $n : null;
    }

    private function __construct()
    {
    }
}
