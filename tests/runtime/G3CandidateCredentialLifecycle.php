<?php
/**
 * Candidate 0.8.0 credential lifecycle. CI fixture ONLY, not release code.
 *
 * Keep non-sensitive operator settings and local business history.
 * Remove legacy connection destinations, credentials and cron access token
 * when a module is uninstalled; regenerate a new cron token on reinstall.
 */
final class G3CandidateCredentialLifecycle
{
    public static function sensitiveKeys()
    {
        return [
            'PS_SFMC_AUTH_BASE_URI',
            'PS_SFMC_REST_BASE_URI',
            'PS_SFMC_CLIENT_ID',
            'PS_SFMC_CLIENT_SECRET',
            'PS_SFMC_ACCOUNT_ID',
            'PS_SFMC_CUSTOMER_DE_KEY',
            'PS_SFMC_ORDER_DE_KEY',
            'PS_SFMC_JOURNEY_EVENT_DEFINITION_KEY',
            'PS_SFMC_CRON_TOKEN',
        ];
    }

    public static function retainedDefaults()
    {
        return [
            'PS_SFMC_CART_ABANDON_MINUTES' => 60,
            'PS_SFMC_CART_CONSENT_MODE' => 'marketing_optin',
            'PS_SFMC_RECOVERY_TOKEN_TTL_HOURS' => 168,
            'PS_SFMC_CUSTOMER_BACKFILL_BATCH' => 50,
            'PS_SFMC_RETENTION_QUEUE_DAYS' => 30,
            'PS_SFMC_RETENTION_LOG_DAYS' => 30,
            'PS_SFMC_RETENTION_JOURNEY_DAYS' => 90,
            'PS_SFMC_RETENTION_CART_DAYS' => 30,
            'PS_SFMC_RETENTION_RECOVERY_DAYS' => 30,
            'PS_SFMC_RETENTION_LAST_RUN_AT' => '',
        ];
    }

    public static function assertDisposableRuntime()
    {
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop'
            || getenv('G3_080_UPGRADE_FIXTURE') !== 'candidate_ci_only'
            || !defined('_PS_VERSION_')
            || getenv('EXPECTED_PS_VERSION') !== (string) _PS_VERSION_
            || !defined('_DB_PREFIX_') || _DB_PREFIX_ !== 'ps_'
            || !class_exists('Db') || !class_exists('Configuration')
            || (string) Db::getInstance()->getValue('SELECT DATABASE()') !== 'prestashop') {
            return false;
        }
        return true;
    }

    public static function scrubLegacySecrets()
    {
        if (!self::assertDisposableRuntime()) {
            return false;
        }
        foreach (self::sensitiveKeys() as $key) {
            // Clearing even an inactive old provider prevents reuse of stale
            // routes or cron token after uninstall and on reinstallation.
            if (Configuration::get($key) !== false
                && !Configuration::deleteByName($key)) {
                return false;
            }
            if (Configuration::get($key) !== false) {
                return false;
            }
        }
        return true;
    }

    public static function installSafeDefaults()
    {
        if (!self::assertDisposableRuntime() || !self::scrubLegacySecrets()) {
            return false;
        }
        // Installation and reinstallation MUST NOT inherit Production.
        if (!Configuration::updateValue('PS_SFMC_MODE', 'test')) {
            return false;
        }
        foreach (self::retainedDefaults() as $key => $default) {
            if (Configuration::get($key) === false
                && !Configuration::updateValue($key, $default)) {
                return false;
            }
        }
        // No Engagement Data Extension/Journey default target on Growth.
        // A new local cron token is NOT a Salesforce delivery authorization.
        try {
            $cronToken = bin2hex(random_bytes(24));
        } catch (Throwable $e) {
            return false;
        }
        return Configuration::updateValue('PS_SFMC_CRON_TOKEN', $cronToken);
    }
}
