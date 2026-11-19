<?php
/**
 * No network. Synthetic credentials for three independent PrestaShop shops,
 * exercising AES-256-GCM at rest, tampering, rotations, OAuth and revocation.
 */
if (!defined('_COOKIE_KEY_')) {
    define('_COOKIE_KEY_',str_repeat('K',64)); // CI-only, never a real key.
}
class Configuration
{
    public static $values=[];
    public static $writes=[];
    public static function get($key,$lang=null,$group=null,$shop=null)
    {
        if (!is_int($shop) || $shop<1 || $lang!==null || $group!==null) {
            throw new RuntimeException('vault_test_unscoped_read');
        }
        return isset(self::$values[$shop][$key])
            ?self::$values[$shop][$key]:false;
    }
    public static function updateValue($key,$value,$html=false,$group=null,$shop=null)
    {
        if ($html!==false || $group!==null || !is_int($shop) || $shop<1) {
            throw new RuntimeException('vault_test_unscoped_write');
        }
        self::$values[$shop][$key]=$value;
        self::$writes[]=['shop'=>$shop,'key'=>$key];
        return true;
    }
}
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/AuthClient.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/DataCloud/TokenExchangeClient.php';
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthShopCredentialVault.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthShopCredentialVault as Vault;

function vaultAssert($test,$reason) {
    if (!$test)throw new RuntimeException('oauth_vault_contract_'.$reason);
}
function vaultReject($call,$reason) {
    try {
        $call();
        vaultAssert(false,'accepted_'.$reason);
    } catch(RuntimeException $e) {
        vaultAssert($e->getMessage()===$reason,'wrong_reason_'.$e->getMessage());
    }
}
$orgA='https://store-a.my.salesforce.com';
$idA='3MVG9-NOTHING-REAL-APP';
$secretA='private_a_098769abc+x/y==';
$orgB='https://store-b.my.salesforce.com';
$idB='3MVG9-ANOTHER-FICTITIOUS-APP';
$secretB='private_b_abcdef012345';
vaultAssert(Vault::inspect(1)['status']==='not_configured','initial_none');
vaultAssert(Vault::save(1,$orgA,$idA,$secretA),'save_a');
vaultAssert(Vault::save(2,$orgB,$idB,$secretB),'save_b');
$encoded=Configuration::$values[1][Vault::CONFIG_KEY];
vaultAssert(strpos($encoded,'gcm1:')===0
    && strpos($encoded,$orgA)===false
    && strpos($encoded,$idA)===false
    && strpos($encoded,$secretA)===false
    && strlen($encoded)>100,'ciphertext_only');
vaultAssert($encoded!==Configuration::$values[2][Vault::CONFIG_KEY]
    && Vault::inspect(1)['ready']
    && Vault::inspect(2)['ready']
    && Vault::inspect(3)['status']==='not_configured',
    'shop_isolation');

$coreCalls=0;$cloudCalls=0;
$core=function($org,$id,$secret)use(&$coreCalls,$orgA,$idA,$secretA){
    $coreCalls++;
    vaultAssert($org===$orgA && $id===$idA && $secret===$secretA,
        'decrypted_core_tuple_mismatch');
    return ['instance_url'=>$orgA,'access_token'=>'core-secret-token'];
};
$cloud=function($instance,$subject)use(&$cloudCalls,$orgA){
    $cloudCalls++;
    vaultAssert($instance===$orgA && $subject==='core-secret-token',
        'wrong_core_token_exchange');
    return [
        'instance_url'=>'https://synthetic.c360a.salesforce.com',
        'access_token'=>'cloud-secret-token'
    ];
};
$report=Vault::testConnection(1,$core,$cloud);
vaultAssert($coreCalls===1 && $cloudCalls===1
    && $report['status']==='core_and_data_cloud_oauth_verified'
    && $report['records_sent']===0
    && $report['marketing_sends_authorized']===false
    && strpos(json_encode($report),$secretA)===false
    && strpos(json_encode($report),'core-secret')===false
    && strpos(json_encode($report),'cloud-secret')===false,
    'oauth_only_no_secrets');
$token=Vault::obtainCloudToken(1,$core,$cloud);
vaultAssert($token['instance_url']==='https://synthetic.c360a.salesforce.com'
    && $token['access_token']==='cloud-secret-token','token_for_private_sender');
unset($token);

// Wrong-shop ciphertext copied into another store fails authenticated decrypt.
Configuration::$values[3][Vault::CONFIG_KEY]=$encoded;
vaultAssert(Vault::inspect(3)['status']==='unavailable'
    && Vault::inspect(2)['ready'],'wrong_shop_ciphertext_refused');
$original=$encoded;
$decoded=base64_decode(substr($original,5),true);
$decoded[strlen($decoded)-1]=chr(ord($decoded[strlen($decoded)-1])^1);
Configuration::$values[1][Vault::CONFIG_KEY]='gcm1:'.base64_encode($decoded);
vaultAssert(Vault::inspect(1)['status']==='unavailable','tamper_fail_closed');
Configuration::$values[1][Vault::CONFIG_KEY]=$original;

vaultReject(function()use($orgA,$idA) {
    Vault::save(1,$orgA,$idA,"bad\nsecret");
},'growth_vault_input_invalid');
vaultReject(function()use($idA,$secretA) {
    Vault::save(1,'https://evil.example.net',$idA,$secretA);
},'growth_oauth_invalid_org_url');
vaultAssert(Configuration::$values[1][Vault::CONFIG_KEY]===$original,
    'invalid_save_overwrote_secret');

vaultReject(function()use($core){
    Vault::testConnection(1,$core,function(){
        throw new RuntimeException('raw:private person@example.invalid');
    });
},'growth_vault_cloud_exchange_failed');
vaultReject(function()use($cloud){
    Vault::testConnection(1,function(){
        throw new RuntimeException('my-secret-private');
    },$cloud);
},'growth_vault_core_auth_failed');
vaultReject(function()use($core){
    Vault::testConnection(1,$core,function(){
        return ['instance_url'=>'https://attacker.example.com',
            'access_token'=>'cloud-secret-token'];
    });
},'growth_vault_cloud_exchange_failed');

// Rotate only one shop and require all three fields again.
Vault::save(1,$orgA,$idA,'new-rotated-secret');
vaultAssert(Vault::inspect(1)['ready'] && Vault::inspect(2)['ready']
    && Configuration::$values[1][Vault::CONFIG_KEY]!==$original,
    'rotation_broke_shop');
vaultReject(function() {
    Vault::remove(1,'WRONG');
},'growth_vault_delete_confirmation_required');
vaultAssert(Vault::inspect(1)['ready'],'bad_revoke_wrote');
vaultAssert(Vault::remove(1,Vault::DELETE_CONFIRM)
    && Vault::inspect(1)['status']==='not_configured'
    && Vault::inspect(2)['ready']
    && Configuration::$values[1][Vault::CONFIG_KEY]==='revoked:v1',
    'revoke_is_scoped');
vaultAssert(count(Configuration::$writes)===4
    && array_unique(array_column(Configuration::$writes,'key'))
        ===[Vault::CONFIG_KEY], 'unexpected_config_keys');
echo "Growth per-shop encrypted credentials PASS: AES-GCM, AAD, rotation, OAuth-only, exact shop revoke; no customer egress.\n";
