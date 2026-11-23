<?php
/**
 * G8 UAT BO: preflight is read-only, customer/order runs are separate.
 * No actual HTTP transport, customer data, or database mutation here.
 */
function g8OperatorAssert($ok,$why)
{
    if (!$ok)throw new RuntimeException('g8_bo_operator_'.$why);
}
$root=dirname(__DIR__);
$module=file_get_contents($root.'/pssalesforcebridge/pssalesforcebridge.php');
$template=file_get_contents($root.'/pssalesforcebridge/views/templates/admin/console.tpl');
$native=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthG8NativeStagingServices.php');
$worker=file_get_contents($root
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingWorker.php');
$cron=file_get_contents($root.'/pssalesforcebridge/controllers/front/cron.php');

$start=strpos($module,'private function handleG8UatOperator($operation)');
$end=strpos($module,'private function handleG8UatTarget(');
g8OperatorAssert($start!==false && $end>$start,
    'handler_absent');
$handler=substr($module,$start,$end-$start);
foreach ([
    "in_array(\$operation,['preflight','customer','order'],true)",
    'isCredentialAdmin($shop)',
    'hash_equals($this->credentialCsrf($shop),$csrf)',
    "self::CONFIG_MODE)!=='test'",
    "self::CONFIG_LOCAL_CAPTURE)!=='off'",
    'GrowthG8UatPairScope::inspect(',
    'GrowthLocalCustomerHoldReader::inspectPrestaShop(',
    "'no_local_negative_observed'",
    "'EXECUTE_G8_CUSTOMER_ONCE'",
    "'EXECUTE_G8_ORDER_ONCE'",
    'GrowthG8NativeStagingServices::run(',
    "$operation==='customer'?'PSCustomer':'PSOrder'",
    "accepted_202_unconfirmed",
    "pending_reconciliation",
    "No repitas la ejecución",
    "\$phase='customer_local_review'",
    "\$phase='order_local_reader'",
    "'local_pre_dispatch_validated_no_send'",
    'GrowthG8LocalPreDispatchReview::inspect(',
    "\$preflightPhase='render'",
    "\$phase='confirmation'",
    "\$phase='worker_invoked'",
    "\$phase='receipt_reporting'",
    "No se ha ejecutado POST desde esta acción",
    "Sin POST en esta operación",
    "Puede haber existido un POST"
] as $required) {
    g8OperatorAssert(strpos($handler,$required)!==false,
        'missing_security_or_no_retry_guard');
}
$firstNetwork=strpos($handler,'GrowthG8NativeStagingServices::run(');
$localFirst=strpos($handler,'GrowthLocalCustomerHoldReader::inspectPrestaShop(');
$explicit=strpos($handler,'EXECUTE_G8_CUSTOMER_ONCE');
g8OperatorAssert($localFirst!==false && $localFirst<$explicit
    && $explicit<$firstNetwork,'local_or_confirmation_after_network');
$preflightStart=strpos($handler,"if (\$operation==='preflight') {");
$customerGateStart=strpos($handler,"\$phase='customer_local_review'");
g8OperatorAssert($preflightStart!==false
    && $customerGateStart!==false
    && $preflightStart<$customerGateStart
    && $customerGateStart<$firstNetwork,
    'preflight_after_worker');
$preflight=substr($handler,$preflightStart,
    $customerGateStart-$preflightStart);
g8OperatorAssert(strpos($preflight,'GrowthG8LocalPreDispatchReview::inspect(')!==false
    && strpos($preflight,"'local_pre_dispatch_validated_no_send'")!==false
    && strpos($preflight,"\$review['dispatch_authorized']!==false")!==false
    && strpos($preflight,"\$review['http_request_performed']!==false")!==false
    && strpos($preflight,"\$review['database_write_performed']!==false")!==false
    && strpos($preflight,"\$review['retry_authorized']!==false")!==false
    && strpos($preflight,"\$preflightPhase='render'")!==false
    && strpos($preflight,'return $this->displayConfirmation(')!==false
    && strpos($preflight,'GrowthG8NativeStagingServices::run(')===false
    && strpos($preflight,'curl_')===false
    && strpos($preflight,'Mail::send(')===false,
    'read_only_preflight_sends');
$customerSection=substr($handler,$customerGateStart,
    $firstNetwork-$customerGateStart);
g8OperatorAssert(strpos($customerSection,"if (\$operation==='customer')")!==false
    && strpos($customerSection,'GrowthG8LocalPreDispatchReview::inspect(')!==false
    && strpos($customerSection,"'local_pre_dispatch_validated_no_send'")!==false
    && strpos($customerSection,"\$review['read_only']===true")!==false
    && strpos($customerSection,"\$review['http_request_performed']===false")!==false
    && strpos($customerSection,"\$review['database_write_performed']===false")!==false
    && strpos($customerSection,"\$review['dispatch_authorized']===false")!==false
    && strpos($customerSection,"\$review['retry_authorized']===false")!==false
    && strpos($customerSection,"\$phase='order_local_reader'")!==false
    && strpos($customerSection,'GrowthLocalCustomerHoldReader::inspectPrestaShop(')!==false
    && strpos($customerSection,'GrowthG8NativeStagingServices::run(')===false,
    'customer_and_order_local_gates_not_isolated');
g8OperatorAssert(strpos($handler,"\$phase='worker_invoked'")
        <strpos($handler,'GrowthG8NativeStagingServices::run(')
    && strpos($handler,"\$phase='receipt_reporting'")
        >strpos($handler,'GrowthG8NativeStagingServices::run(')
    && strpos($handler,"if (\$phase==='customer_local_review')")!==false
    && strpos($handler,"if (\$phase==='order_local_reader')")!==false
    && strpos($handler,"if (\$phase==='confirmation')")!==false,
    'uncertain_send_guard_missing');
foreach ([
    'submitPsGrowthG8Preflight',
    'submitPsGrowthG8RunCustomer',
    'submitPsGrowthG8RunOrder'
] as $button) {
    g8OperatorAssert(strpos($module,"Tools::isSubmit('$button')")!==false
        && strpos($template,'name="'.$button.'"')!==false,
        'ui_action_missing');
}
g8OperatorAssert(substr_count($template,'name="PS_GROWTH_G8_RUN_CONFIRM"')===2
    && strpos($template,'EXECUTE_G8_CUSTOMER_ONCE')!==false
    && strpos($template,'EXECUTE_G8_ORDER_ONCE')!==false
    && strpos($template,'sfmc_g8_staging_approved')!==false
    && strpos($template,'sfmc_credentials_admin_allowed')!==false,
    'unsafe_bo_forms');
g8OperatorAssert(strpos($native,
    "$objectType='PSCustomer'")!==false
    && strpos($native,'], $objectType')===false
    && strpos($native,'], $objectType')===false
    && strpos($native,'],$objectType')!==false
    && strpos($worker,'$objectType=null')!==false
    && substr_count($worker,'array_filter(')===2
    && strpos($worker,'$row[\'type\']===$objectType')!==false,
    'object_scope_not_enforced');
g8OperatorAssert(strpos($cron,"'dispatch'")!==false
    && strpos($cron,"hash_equals(")!==false
    && strpos($module,"GrowthG8NativeStagingServices::run(")!==false,
    'protected_cron_or_bo_removed');
g8OperatorAssert(strpos($module,"$"."this->version = '0.9.8';")!==false,
    'premature_final_release');
echo "G8 BO one-shot PASS: local preflight, separate customer/order, CSRF and no retry.\n";
