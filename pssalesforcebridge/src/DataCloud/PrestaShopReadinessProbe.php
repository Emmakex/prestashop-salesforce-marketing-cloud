<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

/**
 * Admin-invoked, local-only metadata probe: no customer or order row SELECT,
 * no background processing, no HTTP requests, no remote Salesforce access.
 */
final class PrestaShopReadinessProbe
{
    public static function inspect(array $activeShopIds)
    {
        return InstallationReadinessInspector::inspect(
            $activeShopIds,
            function ($shopId) {
                return \Configuration::get('PS_TIMEZONE', null, null, $shopId);
            },
            function () {
                $prefix = defined('_DB_PREFIX_') ? (string) _DB_PREFIX_ : '';
                if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
                    return [];
                }

                $sql = "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE "
                    . "FROM information_schema.columns "
                    . "WHERE table_schema = database() "
                    . "AND TABLE_NAME IN ('" . $prefix . "customer', '"
                    . $prefix . "orders') "
                    . "AND COLUMN_NAME IN ('date_add', 'date_upd')";
                $rows = \Db::getInstance()->executeS($sql);
                if (!is_array($rows)) {
                    return [];
                }
                $result = ['customer' => [], 'orders' => []];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $table = isset($row['TABLE_NAME']) ? $row['TABLE_NAME'] : '';
                    $column = isset($row['COLUMN_NAME']) ? $row['COLUMN_NAME'] : '';
                    $kind = isset($row['DATA_TYPE']) ? $row['DATA_TYPE'] : '';
                    if ($column !== 'date_add' && $column !== 'date_upd') {
                        continue;
                    }
                    if ($table === $prefix . 'customer') {
                        $result['customer'][$column] = $kind;
                    }
                    if ($table === $prefix . 'orders') {
                        $result['orders'][$column] = $kind;
                    }
                }
                return $result;
            }
        );
    }
}
