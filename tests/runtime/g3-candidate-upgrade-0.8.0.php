<?php
/**
 * G3 - DANGEROUS TO SHIP: disposable CI fixture ONLY.
 *
 * This file is copied into a temporary 0.8.0 module copy inside GitHub
 * Actions' ephemeral PrestaShop container, NEVER the published module.
 * Do not copy into pssalesforcebridge/ for a release.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
function upgrade_module_0_8_0($module)
{
    if (getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop'
        || getenv('G3_080_UPGRADE_FIXTURE') !== 'candidate_ci_only'
        || getenv('EXPECTED_PS_VERSION') !== (string) _PS_VERSION_
        || !defined('_DB_PREFIX_') || _DB_PREFIX_ !== 'ps_'
        || !is_object($module) || $module->name !== 'pssalesforcebridge'
        || (string) $module->version !== '0.8.0'
        || (string) Configuration::get('PS_SFMC_MODE') !== 'test'
        || (string) Db::getInstance()->getValue('SELECT DATABASE()') !== 'prestashop') {
        return false;
    }

    // This mimics explicit BO merchant selection. No default route is inferred
    // from the prior Engagement-capable module or from any OAuth token.
    $merchant = getenv('G3_080_MERCHANT');
    $provider = getenv('G3_080_PROVIDER');
    $namespace = getenv('G3_080_NAMESPACE');
    if ($merchant !== 'ABH' || $provider !== 'marketing_cloud_growth'
        || $namespace !== 'growth_ci_abhtest') {
        return false;
    }
    try {
        require_once '/tmp/G3PrestaShopRuntimeLifecycleCandidate.php';
        $installer = new G3PrestaShopRuntimeLifecycleCandidate();
        $outcome = $installer->install($merchant, $provider, $namespace);
        return $outcome === 'installed' || $outcome === 'already_installed';
    } catch (Throwable $e) {
        // Never disclose DB payload, secrets or merchant records to CLI/logs.
        return false;
    }
}
