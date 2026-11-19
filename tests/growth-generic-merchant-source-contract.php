<?php
/** No-network test: merchants BCABH and DOABH with isolated source bindings. */
class Configuration {
    public static $global=[];
    public static $shop=[];
    public static function get($key,$lang=null,$group=null,$shop=null) {
        if ($shop!==null && isset(self::$shop[$shop][$key]))
            return self::$shop[$shop][$key];
        return isset(self::$global[$key])?self::$global[$key]:false;
    }
    public static function updateValue($key,$value,$html=false,$group=null,$shop=null) {
        if ($shop===null)self::$global[$key]=$value;
        else self::$shop[$shop][$key]=$value;
        return true;
    }
}
require_once dirname(__DIR__).'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthShopSourceBinding as Source;

function sourceAssert($ok,$why) {
    if (!$ok)throw new RuntimeException('merchant_source_contract_'.$why);
}
function sourceReject($f,$expected) {
    try {$f();}catch(RuntimeException $e){
        sourceAssert($e->getMessage()===$expected,'unexpected_'.$e->getMessage());
        return;
    }
    sourceAssert(false,'unblocked_'.$expected);
}
$bc=Source::inspect(1,'BCABH','abh_bc_prod_20261119');
sourceAssert($bc['ready'] && $bc['source']===Source::BC_SOURCE,
    'bc_existing_source_changed');
sourceAssert(Source::inspect(1,'ABH','abh_bc_prod_20261119')['ready'],
    'legacy_abh_broken');
sourceAssert(!Source::inspect(2,'DOABH','do_online_prod_20261119')['ready'],
    'do_implicitly_reused_bc');
sourceAssert(!Source::inspect(1,'DOABH','abh_bc_prod_20261119')['ready'],
    'merchant_mixed_in_same_shop');
sourceReject(function(){
    Source::sourceForSend(2,'DOABH','do_online_prod_20261119');
},'growth_source_unverified_for_send');
sourceReject(function(){
    Source::bind(2,'DOABH','do_online_prod_20261119',Source::BC_SOURCE,
        Source::ATTEST);
},'growth_source_cross_merchant');
sourceReject(function(){
    Source::bind(2,'DOABH','do_online_prod_20261119','DO_PrestaShop_Data_Cloud',
        'FALSE_CONFIRMATION');
},'growth_source_binding_invalid');
$doSource='DO_PrestaShop_Data_Cloud';
sourceAssert(Source::bind(2,'DOABH','do_online_prod_20261119',
    $doSource,Source::ATTEST),'do_manual_binding');
sourceAssert(Source::inspect(2,'DOABH','do_online_prod_20261119')['ready']
    && Source::sourceForSend(2,'DOABH','do_online_prod_20261119')===$doSource
    && Source::inspect(1,'BCABH','abh_bc_prod_20261119')['source']===Source::BC_SOURCE,
    'do_bc_independent');
sourceAssert(Source::bind(2,'DOABH','do_online_prod_20261119',
    $doSource,Source::ATTEST),'do_idempotence');
sourceReject(function(){
    Source::bind(2,'DOABH','do_online_prod_20261119',
        'new_source',Source::ATTEST);
},'growth_source_immutable');
sourceAssert(!Source::inspect(3,'DOABH','do_online_prod_20261119')['ready'],
    'cross_shop_source_reused');
sourceAssert(!Source::inspect(2,'DOABH','other_install_20261119')['ready'],
    'cross_namespace_source_reused');
sourceAssert(!Source::inspect(2,'ANOTHER','do_online_prod_20261119')['ready'],
    'cross_merchant_source_reused');
sourceReject(function(){
    Source::sourceForSend(2,'ANOTHER','do_online_prod_20261119');
},'growth_source_unverified_for_send');
sourceAssert(Source::isMerchant('DOABH')
    && Source::isMerchant('BCABH')
    && Source::isMerchant('OTHER')
    && !Source::isMerchant('DO-ABH')
    && !Source::isMerchant('doabh')
    && Source::isNamespace('do_online_prod_20261119')
    && !Source::isNamespace('DO_online_prod')
    && Source::isSource($doSource)
    && !Source::isSource('https://attacker.invalid')
    && !Source::isSource('../ABH_PrestaShop_Data_Cloud'),
    'identity_source_format');
Configuration::$shop[2][Source::CONFIG_KEY]='{not_json';
sourceAssert(!Source::inspect(2,'DOABH','do_online_prod_20261119')['ready'],
    'corrupt_binding_not_blocked');
sourceReject(function()use($doSource){
    Source::bind(2,'DOABH','do_online_prod_20261119',$doSource,Source::ATTEST);
},'growth_source_existing_invalid');
echo "Generic merchant source PASS: ABH/BCABH retained, DOABH separate, attestation, immutable binding, no cross-shop or network.\n";
