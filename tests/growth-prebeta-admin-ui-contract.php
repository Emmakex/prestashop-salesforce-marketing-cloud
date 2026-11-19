<?php
/**
 * Pre-beta admin UX must be compact, accessible and safe.
 * This offline contract scans the real Smarty file and the BO controller.
 */
function adminUxAssert($condition,$reason) {
    if (!$condition) {
        throw new RuntimeException('admin_ux_contract_'.$reason);
    }
}
$root=dirname(__DIR__);
$module=file_get_contents($root.'/pssalesforcebridge/pssalesforcebridge.php');
$template=file_get_contents($root.'/pssalesforcebridge/views/templates/admin/console.tpl');
$sender=file_get_contents($root.'/pssalesforcebridge/src/Growth/GrowthOneRecordManualSender.php');

$getStart=strpos($module,'public function getContent()');
$getEnd=strpos($module,'private function renderClientConsole()');
adminUxAssert($getStart!==false && $getEnd>$getStart,
    'client_console_missing');
$get=substr($module,$getStart,$getEnd-$getStart);
foreach ([
    'renderCaptureForm()',
    'renderReadbackForm()',
    'renderG7DeliverySchemaForms()',
    'reviewG7DeliverySchema(',
    'verifyStagingNativeReadback(',
    'armStagingCapture(',
    'InstallationReadinessDashboard',
] as $obsolete) {
    adminUxAssert(strpos($get,$obsolete)===false,
        'legacy_debug_action_visible_or_reachable');
}
adminUxAssert(strpos($get,'renderClientConsole()')!==false
    && strpos($get,"submitPsGrowthOnePreview")!==false
    && strpos($get,"submitPsGrowthOneCustomerSend")!==false
    && strpos($get,"submitPsGrowthOneOrderSend")!==false
    && strpos($get,"submitPsGrowthStagingCaptureStop")!==false,
    'required_client_or_safe_stop_routing_missing');
adminUxAssert(strpos($module,
    "'views/templates/admin/console.tpl'")!==false
    && strpos($module,"$"."this->version = '0.9.4'")!==false
    && strpos($module,"$"."this->author = 'ALFABH'")!==false,
    'wrong_module_version_or_template');

foreach([
    'PS_GROWTH_ONE_CUSTOMER_ID','PS_GROWTH_ONE_ORDER_ID'
] as $input) {
    adminUxAssert(substr_count($template,'name="'.$input.'"')===1,
        'duplicate_customer_order_input_'.$input);
}
foreach ([
    'submitPsGrowthOnePreview',
    'submitPsGrowthOneCustomerSend',
    'submitPsGrowthOneOrderSend'
] as $action) {
    adminUxAssert(substr_count($template,'name="'.$action.'"')===1,
        'not_one_action_'.$action);
}
adminUxAssert(strpos($template,'<details>')!==false
    && strpos($template,'<summary>')!==false
    && strpos($template,'aria-label=')!==false
    && strpos($template,'input:focus-visible')!==false
    && strpos($template,'@media(max-width:760px)')!==false,
    'accessibility_or_responsive_missing');
foreach([
    'submitPsG7SchemaPrepare',
    'submitPsG7SchemaRollback',
    'name="submitPsGrowthStagingCapture"',
    'submitPsGrowthReadback',
    'G7 - Same outbox',
    'Data Cloud - installation preflight'
] as $obsolete) {
    adminUxAssert(strpos($template,$obsolete)===false,
        'obsolete_form_in_new_ui');
}
foreach ([
    'submitPsGrowthOauthSave',
    'submitPsGrowthOauthTest',
    'submitPsGrowthOauthRemove'
] as $action) {
    adminUxAssert(substr_count($template,'name="'.$action.'"')===1,
        'one_oauth_action_'.$action);
    adminUxAssert(strpos($get,$action)!==false,
        'oauth_action_not_guarded_in_bo_'.$action);
}
adminUxAssert(substr_count($template,'name="PS_GROWTH_OAUTH_CSRF"')===3
    && strpos($template,'type="password" id="sfmc-oauth-secret"')!==false
    && strpos($template,'name="PS_GROWTH_OAUTH_CLIENT_ID"')!==false
    && strpos($template,'name="PS_GROWTH_OAUTH_ORG"')!==false
    && strpos($template,'name="PS_GROWTH_OAUTH_CLIENT_SECRET"')!==false
    && strpos($template,'{$sfmc_credentials_csrf|escape:')!==false
    && strpos($template,'value="{$sfmc_oauth_')===false
    && strpos($module,'GrowthShopCredentialVault::save(')!==false
    && strpos($module,'GrowthShopCredentialVault::testConnection(')!==false
    && strpos($module,'GrowthShopCredentialVault::obtainCloudToken($shop)')!==false
    && strpos($module,'$this->isCredentialAdmin($shop)')!==false
    && strpos($module,'hash_equals($this->credentialCsrf($shop),$csrf)')!==false
    && strpos($module,"getenv('SF_GROWTH_CLIENT_SECRET')")===false,
    'oauth_credentials_must_be_encrypted_and_admin_guarded');
adminUxAssert(strpos($template,'Client Secret')!==false
    && strpos($template,'Datos de cliente')===false,
    'oauth_help_or_privacy');

adminUxAssert(strpos($template,'{$sfmc_credentials_ready}')===false
    && strpos($template,'{$sfmc_customer_status|escape:')!==false
    && strpos($template,'{$sfmc_order_status|escape:')!==false
    && strpos($template,'{$sfmc_action|escape:')!==false
    && strpos($template,'{$sfmc_customer_input|escape:')!==false
    && strpos($template,'{$sfmc_order_input|escape:')!==false
    && strpos($sender,'public static function statusForShop')!==false,
    'unescaped_dynamic_data_or_private_status_missing');

$routes=substr($module,$getStart,$getEnd-$getStart);
adminUxAssert(strpos($routes,'http')===false
    && strpos($routes,'curl_')===false
    && strpos($routes,'Db::')===false,
    'network_or_db_write_on_dashboard_route');
adminUxAssert(strpos($module,'GrowthShopSourceBinding::isMerchant($merchant)')!==false
    && strpos($module,'GrowthShopSourceBinding::inspect($shop,$merchant,$namespace)')!==false
    && strpos($module,'GrowthShopSourceBinding::bind(')!==false
    && strpos($sender,'GrowthShopSourceBinding::sourceForSend(')!==false
    && strpos($template,'name="PS_GROWTH_SOURCE_API"')!==false
    && strpos($template,'name="PS_GROWTH_SOURCE_ATTEST"')!==false
    && strpos($template,'{$sfmc_source_name|escape:')!==false,
    'merchant_source_became_bespoke_again');
echo "Pre-beta BO UX PASS: 1 client + 1 order, scoped forms, accessible layout, no legacy diagnostics or new sender.\n";
