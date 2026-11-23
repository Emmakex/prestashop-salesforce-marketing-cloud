<?php
/**
 * Back Office stage capture UAT: source inspection, no live shop writes.
 * Native G3 migration and G7 extension are covered by MySQL/MariaDB CI.
 */
function stageCaptureAssert($yes,$label) {
    if (!$yes)throw new RuntimeException('g8_stage_capture_'.$label);
}
$root=dirname(__DIR__);
$module=file_get_contents(
    $root.'/pssalesforcebridge/pssalesforcebridge.php'
);
$template=file_get_contents(
    $root.'/pssalesforcebridge/views/templates/admin/console.tpl'
);
$start=strpos($module,'private function handleG8StageCapturePrepare()');
$end=strpos($module,'private function handleG8StagingUat(');
stageCaptureAssert($start!==false && $end>$start,
    'capture_admin_handler_missing');
$handler=substr($module,$start,$end-$start);
foreach ([
    'credentialContextShopId()',
    'isCredentialAdmin($shop)',
    'hash_equals($this->credentialCsrf($shop),$csrf)',
    'GrowthStagingSchemaBootstrap::CONFIRM',
    "'I_HAVE_A_VERIFIED_STAGING_BACKUP'",
    'GrowthShopSourceBinding::inspect(',
    'GrowthStagingSchemaBootstrap::install(',
    'GrowthG7StagingDeliveryMigration::apply(',
    'GrowthG7StagingDeliveryMigration::APPROVE,true',
    "self::CONFIG_LOCAL_CAPTURE,'staging_armed'",
    "self::CONFIG_LOCAL_CAPTURE,'off'",
    'GrowthG8StagingWorker::APPROVAL'
] as $required) {
    stageCaptureAssert(strpos($handler,$required)!==false,
        'safety_gate_missing');
}
$prepareAt=strpos($handler,'GrowthStagingSchemaBootstrap::install(');
$extendAt=strpos($handler,'GrowthG7StagingDeliveryMigration::apply(');
$armAt=strpos($handler,"self::CONFIG_LOCAL_CAPTURE,'staging_armed'");
stageCaptureAssert($prepareAt<$extendAt && $extendAt<$armAt,
    'ddl_after_capture_arm');
foreach ([
    'curl_','Mail::send(','GrowthDataCloudUpsertTransport',
    'GrowthAutomaticOneShotCoordinator::tick(', 'DROP TABLE',
    'DELETE FROM', 'TRUNCATE'
] as $forbidden) {
    stageCaptureAssert(strpos($handler,$forbidden)===false,
        'capture_preparer_does_other_work');
}
stageCaptureAssert(strpos($template,
        'name="submitPsGrowthG8CapturePrepare"')!==false
    && strpos($template,
        'name="PS_GROWTH_G8_CAPTURE_CONFIRM"')!==false
    && strpos($template,
        'name="PS_GROWTH_G8_BACKUP_CONFIRM"')!==false
    && strpos($template,
        'name="PS_GROWTH_G8_CSRF"')!==false
    && strpos($template,'{$sfmc_g8_capture_prepare_phrase|escape:')!==false
    && strpos($template,'{$sfmc_g8_backup_phrase|escape:')!==false
    && strpos($template,'{if $sfmc_g8_is_stage}')!==false
    && strpos($template,'{if !$sfmc_capture_armed}')!==false
    && strpos($template,"name=\"submitPsGrowthStagingCaptureStop\"")!==false,
    'staging_capture_ui_not_guided');
stageCaptureAssert(
    strpos($module,"Tools::isSubmit('submitPsGrowthG8CapturePrepare')")!==false
    && strpos($module,"$"."this->version = '0.9.8';")!==false,
    'release_version_changed_before_uat');
echo "G8 capture UAT admin contract PASS: owner/CSRF/backup/staging/DDLs before local capture; no sends.\n";
