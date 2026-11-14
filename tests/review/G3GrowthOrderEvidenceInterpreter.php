<?php
/**
 * G3/G7 offline review of proposed scoped read-only order existence results.
 * No PrestaShop queries or Salesforce calls. Failed queries are never empty.
 */
final class G3GrowthOrderEvidenceInterpreter
{
    public static function inspect($shopId, $cartId, $nativeRows, $ledgerRows)
    {
        if (!is_int($shopId) || $shopId < 1 || !is_int($cartId) || $cartId < 1) {
            throw new RuntimeException('g3_order_probe_scope_invalid');
        }
        $native = self::classify($nativeRows, $shopId, $cartId);
        $ledger = self::classify($ledgerRows, $shopId, $cartId);
        $found = $native === 'found' || $ledger === 'found';
        $unknown = $native === 'unknown' || $ledger === 'unknown';
        return [
            'status' => $found ? 'converted_locally' : ($unknown ? 'unknown_hold' : 'no_order_candidate_only'),
            'native_order_state' => $native,
            'connector_order_state' => $ledger,
            'evidence_type' => 'synthetic_db_result_classification_only',
            'absence_certified_in_live_prestashop' => false,
            'growth_consent_certified' => false,
            'data_360_ingestion_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function classify($rows, $shopId, $cartId)
    {
        // Contract for a future error-detecting scoped SELECT:
        // false => failed; [] => successful empty result; [row] => found.
        // Legacy scalar false/0/null is NOT evidence of absence.
        if ($rows === false || $rows === null || !is_array($rows)) {
            return 'unknown';
        }
        if ($rows === []) {
            return 'none';
        }
        if (count($rows) !== 1 || !isset($rows[0]) || !is_array($rows[0])) {
            return 'unknown';
        }
        $row = $rows[0];
        $names = array_keys($row);
        sort($names);
        if ($names !== ['id_cart', 'id_order', 'id_shop']) {
            return 'unknown';
        }
        $sourceShop = self::positiveId($row['id_shop']);
        $sourceCart = self::positiveId($row['id_cart']);
        $sourceOrder = self::positiveId($row['id_order']);
        if ($sourceShop === null || $sourceCart === null || $sourceOrder === null
            || $sourceShop !== $shopId || $sourceCart !== $cartId) {
            return 'unknown';
        }
        return 'found';
    }

    private static function positiveId($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $converted = (int) $value;
        return $converted > 0 && (string) $converted === $value ? $converted : null;
    }
}
