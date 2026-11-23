<?php
/**
 * Safe one-record diagnostic classes. No external network, PII, SQL or
 * authenticated customer record is ever returned to the Back Office.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOneRecordSafeDiagnosis.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOneRecordSafeDiagnosis as Diagnose;

function assertDiagnosis($ok,$why) {
    if (!$ok) throw new RuntimeException('local_diagnosis_contract_'.$why);
}
$local=[
    'g7_one_record_customer_not_in_shop'=>'customer_shop',
    'g7_one_record_order_not_in_shop'=>'order_shop',
    'g7_one_record_customer_order_mismatch'=>'customer_order_relation',
    'g7_one_record_currency_unverified'=>'currency',
    'g7_native_draft_customer_excluded'=>'customer_excluded',
    'g7_native_draft_customer_fields_missing'=>'customer_fields',
    'g7_native_draft_customer_flag_invalid'=>'customer_fields',
    'g7_native_draft_order_fields_missing'=>'order_fields',
    'g7_native_draft_total_unverified'=>'order_total',
    'g4_pst_invalid_email'=>'email',
    'g4_pst_invalid_local_date'=>'local_date',
    'g7_one_record_pst_unverified'=>'local_source',
    'g7_one_record_invalid_shop_context'=>'shop_context'
];
foreach($local as $code=>$expected) {
    assertDiagnosis(Diagnose::classify(
        new RuntimeException($code),'preview'
    )===$expected,'preview_'.$code);
}
assertDiagnosis(Diagnose::classify(
    new RuntimeException('previous sent data email=private@example.invalid 12345'),
    'preview'
)==='local_unknown','raw_preview_error_exposed');
assertDiagnosis(Diagnose::classify(
    new RuntimeException('previous sent data email=private@example.invalid 12345'),
    'customer'
)==='send_unknown','raw_send_error_exposed');
assertDiagnosis(Diagnose::classify(new RuntimeException(
    'g7_one_send_already_attempted'
),'preview')==='local_unknown','send_attempt_misattributed_to_preview');
assertDiagnosis(Diagnose::classify(new RuntimeException(
    'g7_one_send_already_attempted'
),'customer')==='send_attempt_exists','send_attempt_not_disambiguated');
assertDiagnosis(Diagnose::classify(new RuntimeException(
    'g7_one_send_customer_dlo_review_required'
),'order')==='send_dlo_required','dlo_attestation_missed');
assertDiagnosis(Diagnose::classify(new RuntimeException(
    'g7_one_send_wrong_customer_for_order'
),'order')==='send_order_customer','other_customer_not_blocked');
assertDiagnosis(Diagnose::classify(new RuntimeException(
    'growth_source_unverified_for_send'
),'customer')==='send_source','source_not_reported');
assertDiagnosis(Diagnose::classify(new RuntimeException(
    'growth_vault_cloud_exchange_failed'
),'customer')==='send_oauth','oauth_not_reported');
assertDiagnosis(Diagnose::classify(new Error('private data sql=secret'),
    'preview')==='local_unknown','error_object_leaks_data');
assertDiagnosis(Diagnose::classify(null,'order')==='send_unknown',
    'non_throwable_untrusted');

$module=file_get_contents(
    dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php'
);
assertDiagnosis(strpos($module,
    "GrowthOneRecordSafeDiagnosis::classify(")!==false
    && strpos($module,"safeOneRecordFailureMessage(")!==false
    && strpos($module,"$"."this->version = '0.9.8';")!==false
    && strpos($module,"Prueba bloqueada o ya intentada.")===false,
    'module_old_generic_error_still_reachable');
assertDiagnosis(strpos($module,
    "if ("."$"."action==='preview')")!==false
    && strpos($module,'No se ha enviado nada.')!==false
    && strpos($module,'No se permiten reintentos automáticos.')!==false,
    'local_and_send_not_differentiated');
echo "Local vs send diagnosis PASS: exact allowlist, no PII/SQL/OAuth leak, preview not mistaken for attempted POST.\n";
