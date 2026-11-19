<?php
/**
 * Disposable PrestaShop 1.7 / 8 / 9 real Configuration database runtime.
 * Synthetic credentials only; no OAuth calls, no customer/order access,
 * no production DB. Writes only the current shop's temporary vault record.
 */
if (PHP_SAPI!=='cli'
    || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('GROWTH_CRED_RUNTIME_DISPOSABLE')!=='yes'
    || getenv('PS_ROOT')!=='/var/www/html'
    || !in_array(getenv('EXPECTED_PS_VERSION'),
        ['1.7.8.11','8.2.8','9.1.5'],true)) {
    fwrite(STDERR,"Credential runtime requires isolated PrestaShop CI.\n");
    exit(1);
}
chdir('/var/www/html');
require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/AuthClient.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once '/var/www/html/modules/pssalesforcebridge/src/Growth/GrowthShopCredentialVault.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthShopCredentialVault as Vault;

function vaultRuntimeAssert($condition,$reason) {
    if (!$condition)throw new RuntimeException('credential_runtime_'.$reason);
}
try {
    vaultRuntimeAssert((string)_PS_VERSION_===getenv('EXPECTED_PS_VERSION')
        && Module::isInstalled('pssalesforcebridge')
        && (string)Configuration::get('PS_SFMC_MODE')==='test'
        && defined('_COOKIE_KEY_')
        && strlen((string)_COOKIE_KEY_)>=32,
        'shop_state_or_server_key_invalid');
    $shop=1; // Disposable CI fixtures contain one PrestaShop shop.
    vaultRuntimeAssert(Vault::inspect($shop)['status']==='not_configured'
        || Vault::inspect($shop)['status']==='unavailable',
        'fixture_vault_unexpectedly_populated');
    $fakeHost='https://synthetic-fixture.my.salesforce.com';
    $fakeId='3MVG9-FIXTURE-ONLY';
    $fakeSecret='ci-only-never-real-secret-123456';
    vaultRuntimeAssert(Vault::save($shop,$fakeHost,$fakeId,$fakeSecret)
        && Vault::inspect($shop)['ready'],'scoped_write_read');
    $stored=Configuration::get(Vault::CONFIG_KEY,null,null,$shop);
    vaultRuntimeAssert(is_string($stored)
        && strpos($stored,'gcm1:')===0
        && strpos($stored,$fakeSecret)===false
        && strpos($stored,$fakeId)===false,
        'real_db_plaintext');
    vaultRuntimeAssert(Vault::remove($shop,Vault::DELETE_CONFIRM)
        && Vault::inspect($shop)['status']==='not_configured'
        && Configuration::get(Vault::CONFIG_KEY,null,null,$shop)==='revoked:v1',
        'real_db_shop_revoke');
    echo "Growth encrypted Core OAuth vault real PrestaShop Configuration PASS: scoped save/decrypt/revoke; no network.\n";
} catch (Throwable $ignored) {
    // Never print SQL, credentials, keys, or raw exception messages.
    fwrite(STDERR,"Growth encrypted credential real PrestaShop runtime gate failed.\n");
    exit(1);
}
