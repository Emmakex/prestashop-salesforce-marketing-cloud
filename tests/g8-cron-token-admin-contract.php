<?php
/** G8: BO token handoff stays POST-only, privilege-gated and never sends. */
function assertCronToken($ok,$why) {
    if (!$ok)throw new RuntimeException('g8_cron_token_'.$why);
}
$root=dirname(__DIR__).'/pssalesforcebridge';
$module=file_get_contents($root.'/pssalesforcebridge.php');
$template=file_get_contents($root.'/views/templates/admin/console.tpl');
$upgrade=file_get_contents($root.'/upgrade/upgrade-0.9.7.php');

$entry=strpos($module,"private function handleG8CronTokenReveal()");
$end=strpos($module,"private function reviewG7DeliverySchema(",$entry);
assertCronToken($entry!==false && $end!==false && $entry<$end,
    'missing_admin_action');
$action=substr($module,$entry,$end-$entry);

assertCronToken(
    strpos($module,"Tools::isSubmit('submitPsGrowthG8CronTokenReveal')")!==false
    && strpos($module,'$this->handleG8CronTokenReveal()')!==false
    && strpos($template,'name="submitPsGrowthG8CronTokenReveal"')!==false
    && strpos($template,'name="PS_GROWTH_G8_CSRF"')!==false
    && strpos($template,'!$sfmc_g8_staging_approved')!==false,
    'bo_form_not_wired'
);

foreach([
    '$this->isCredentialAdmin($shop)',
    'hash_equals($this->credentialCsrf($shop),$csrf)',
    'Shop::isFeatureActive()',
    "Configuration::get(self::CONFIG_MODE)!=='test'",
    "Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'",
    "PS_GROWTH_G8_STAGING_APPROVAL",
    "Configuration::get(self::CONFIG_CRON_TOKEN)",
    "/^[a-f0-9]{48}$/D",
    "Cache-Control: private, no-store",
    'type="password" readonly',
    '$this->escape($token)',
    '$this->displayInformation($html)',
    'unset($token);',
] as $required){
    assertCronToken(strpos($action,$required)!==false,
        'missing_fail_closed_gate');
}
foreach([
    'Configuration::updateValue(',
    'GrowthG8NativeStagingServices::run(',
    'GrowthG8StagingWorker::run(',
    'Tools::getValue(self::CONFIG_CRON_TOKEN)',
    'PrestaShopLogger::addLog(',
    'curl_exec(',
    'file_put_contents(',
    'header("Location:',
] as $forbidden){
    assertCronToken(strpos($action,$forbidden)===false,
        'credential_write_or_network');
}
assertCronToken(
    strpos($module,"$"."this->version = '0.9.7';")!==false
    && strpos($upgrade,'function upgrade_module_0_9_7(')!==false
    && strpos($upgrade,'return true;')!==false
    && strpos($upgrade,'Configuration::updateValue(')===false
    && strpos($upgrade,'Db::')===false,
    'update_would_activate_sender'
);
echo "G8 cron credential handoff PASS: super-admin staging Test-only, OFF, CSRF, masked and no sends.\n";
