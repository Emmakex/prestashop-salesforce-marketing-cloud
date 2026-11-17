<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only SQL engine capability preflight for Growth CHECK-enforced DDL.
 *
 * PrestaShop compatibility != database-engine safety. MySQL 5.7 / MySQL
 * 8.0.0-8.0.15 parse CHECK but do not enforce it. MariaDB can disable
 * enforcement with check_constraint_checks=OFF.
 *
 * This gate never authorizes installation, ingestion, hooks or campaigns.
 * It must not be used as a substitute for G3/data owner authorization.
 */
final class GrowthSqlEngineGate
{
    public static function inspect($serverVersion, $mariaCheckSetting = null)
    {
        $result = [
            'status' => 'blocked',
            'engine' => 'unverified',
            'check_constraints_enforced' => false,
            'schema_migration_authorized' => false,
            'growth_outbound_authorized' => false,
        ];
        if (!is_string($serverVersion) || strlen($serverVersion) > 150
            || preg_match('/\b(?:TiDB|Vitess|PlanetScale|Aurora)\b/i', $serverVersion)) {
            return $result;
        }
        $isMaria = stripos($serverVersion, 'MariaDB') !== false;
        // MariaDB clients may advertise a MySQL 5.5.5 compatibility prefix.
        $canonical = $isMaria
            ? preg_replace('/^5\.5\.5-/', '', $serverVersion)
            : $serverVersion;
        if (!preg_match('/^(\d{1,2})\.(\d{1,3})\.(\d{1,3})(?:[-.+]|$)/D', $canonical, $match)) {
            return $result;
        }
        $major = (int) $match[1];
        $minor = (int) $match[2];
        $patch = (int) $match[3];
        if ($isMaria) {
            $result['engine'] = 'mariadb';
            // Only certified MariaDB 10.11 and 11.8. Unsupported versions
            // must be tested before allowing staging schema setup.
            if ((($major === 10 && $minor === 11)
                || ($major === 11 && $minor === 8))
                && ($mariaCheckSetting === 1
                || $mariaCheckSetting === '1'
                || $mariaCheckSetting === 'ON')) {
                $result['status'] = 'eligible_for_sandbox_schema_preflight';
                $result['check_constraints_enforced'] = true;
            }
        } else {
            $result['engine'] = 'mysql';
            // CHECK became enforced in MySQL 8.0.16, not MySQL 5.7.
            // Bound to the tested MySQL 8 family pending explicit support
            // for other vendors or major releases.
            if ($major === 8 && ($minor > 0 || $patch >= 16)) {
                $result['status'] = 'eligible_for_sandbox_schema_preflight';
                $result['check_constraints_enforced'] = true;
            }
        }
        return $result;
    }

    /**
     * PrestaShop-native, read-only SQL engine preflight.
     *
     * Uses the same getValue(sql, useCache=false) API as the real module.
     * No SQL mutation, schema installation, Salesforce call, or privilege
     * escalation; unknown or failed reads are always ineligible.
     * A supported engine is NEVER an approval to migrate a merchant DB.
     */
    public static function inspectPrestaShop($db)
    {
        if (!is_object($db) || !is_callable([$db, 'getValue'])) {
            return self::inspect(false);
        }
        try {
            // Explicitly skip query cache, including after a DB migration.
            $version = $db->getValue('SELECT VERSION()', false);
            if (!is_string($version) || trim($version) === '') {
                return self::inspect(false);
            }
            $setting = null;
            if (stripos($version, 'MariaDB') !== false) {
                $setting = $db->getValue('SELECT @@check_constraint_checks', false);
                if ($setting === false || $setting === null) {
                    return self::inspect(false);
                }
            }
            return self::inspect($version, $setting);
        } catch (Throwable $e) {
            // Do not expose DB, SQL or credentials in a client-facing report.
            return self::inspect(false);
        }
    }

    /** Read only SELECT statements; always fail closed on unknown variables. */
    public static function inspectConnection(PDO $db)
    {
        try {
            $version = $db->query('SELECT VERSION()')->fetchColumn();
            if (!is_string($version)) {
                throw new RuntimeException('growth_sql_engine_unverified');
            }
            $setting = null;
            if (stripos($version, 'MariaDB') !== false) {
                $setting = $db->query('SELECT @@check_constraint_checks')->fetchColumn();
            }
            $report = self::inspect($version, $setting);
            if ($report['check_constraints_enforced'] !== true) {
                throw new RuntimeException('growth_sql_engine_check_not_enforced');
            }
            return $report;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // A broken/denied SELECT is never evidence of enforcement.
            throw new RuntimeException('growth_sql_engine_unverified');
        }
    }
}
