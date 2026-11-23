<?php
/** G8 exact staging UAT capture proof: synthetic SQL read, zero network. */
class Configuration {
    public static $values=[
        'PS_GROWTH_MERCHANT'=>'ABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_20261123',
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off'
    ];
    public static function get($key,$lang=null,$group=null,$shop=null) {
        return isset(self::$values[$key])?self::$values[$key]:false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8ExactCaptureInspector.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8ExactCaptureInspector as Capture;

function captureAssert($value,$why) {
    if (!$value)throw new RuntimeException('g8_capture_'.$why);
}
$db=new class {
    public $calls=0;
    public $orderPresent=true;
    public $attemptedCustomer=false;
    public $lastSql=[];
    public function executeS($sql,$array=true,$cache=false) {
        $this->calls++;$this->lastSql[]=$sql;
        captureAssert(
            $cache===false
            && strpos($sql,"l.source_namespace='pssfmc_stage_20261123'")!==false
            && strpos($sql,"l.shop_id=1")!==false
            && strpos($sql,"INNER JOIN")!==false
            && strpos($sql,"o.accepted_sequence=l.latest_accepted_sequence")!==false
            && strpos($sql," LIMIT 2")!==false
            && strpos($sql,"UPDATE")===false,
            'unscoped_or_mutating_read'
        );
        $customer=strpos($sql,"l.entity_type='customer'")!==false;
        $order=strpos($sql,"l.entity_type='order'")!==false;
        captureAssert($customer || $order,'unexpected_type');
        if ($order && !$this->orderPresent)return [];
        $id=$customer?8:11;
        captureAssert(strpos($sql,"l.entity_id=$id")!==false,
            'cross_record_read');
        return [[
            'accepted_sequence'=>$customer?'21':'22',
            'delivery_state'=>'blocked',
            'delivery_attempts'=>($customer && $this->attemptedCustomer)?'1':'0',
            'lease_token_hash'=>null,
            'lease_until_epoch'=>null,
            'delivery_last_http'=>null,
            'event_status'=>'blocked',
            'ledger_status'=>'blocked',
            'latest_accepted_sequence'=>$customer?'21':'22'
        ]];
    }
};
$run=function($shop=1,$customer=8,$order=11,$ns='pssfmc_stage_20261123')
    use($db) {
    return Capture::inspect(
        $db,'9.1.5','ABH',$ns,'ci_',$shop,$customer,$order
    );
};
$good=$run();
captureAssert($good['status']==='both_events_captured'
    && $good['customer_event_captured']
    && $good['order_event_captured']
    && $good['read_only']
    && !$good['data_cloud_ingestion_authorized']
    && !$good['http_request_performed']
    && $db->calls===2,'two_events_not_confirmed');
$json=json_encode($good);
captureAssert(strpos($json,'pssfmc_stage')===false
    && strpos($json,'example.invalid')===false
    && strpos($json,'QEUOXGXAF')===false,
    'private_details_exposed');
$db->orderPresent=false;
$absent=$run();
captureAssert($absent['status']==='capture_not_confirmed'
    && $absent['customer_event_captured']
    && !$absent['order_event_captured'],
    'missing_order_accepted');
$db->orderPresent=true;$db->attemptedCustomer=true;
$used=$run();
captureAssert($used['status']==='capture_not_confirmed'
    && !$used['customer_event_captured'],
    'attempted_customer_reaccepted');
$before=$db->calls;
captureAssert($run(2)['status']==='capture_check_unavailable'
    || $run(2)['status']==='capture_check_not_allowed',
    'cross_shop_not_blocked');
captureAssert($run(1,8,11,'pssfmc_production')['status']
    ==='capture_check_not_allowed','production_namespace_not_blocked');
Configuration::$values['PS_GROWTH_LOCAL_CAPTURE']='staging_armed';
captureAssert($run()['status']==='capture_check_not_allowed',
    'capture_armed_not_blocked');
Configuration::$values['PS_GROWTH_LOCAL_CAPTURE']='off';

$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$tpl=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/views/templates/admin/console.tpl');
$start=strpos($module,'private function handleG8ExactCaptureInspect()');
$end=strpos($module,'private function handleG8StageCapturePrepare()');
captureAssert($start!==false && $end>$start,'admin_action_missing');
$admin=substr($module,$start,$end-$start);
captureAssert(
    strpos($module,"Tools::isSubmit('submitPsGrowthG8CaptureInspect')")!==false
    && strpos($admin,'isCredentialAdmin($shop)')!==false
    && strpos($admin,'hash_equals(')!==false
    && strpos($admin,'GrowthG8ExactCaptureInspector::inspect(')!==false
    && strpos($tpl,'name="submitPsGrowthG8CaptureInspect"')!==false
    && strpos($tpl,'name="PS_GROWTH_G8_CSRF"')!==false
    && strpos($tpl,'Comprobar captura G3 sin enviar')!==false,
    'bo_review_missing_or_unprotected'
);
foreach (['curl_','Mail::send(', '->post(', '->claim(',
    'INSERT INTO','DELETE FROM','UPDATE '] as $forbidden) {
    captureAssert(strpos($admin,$forbidden)===false,
        'bo_action_mutating_or_network');
}
echo "G8 exact capture BO PASS: two current G3 rows, strict stage, read-only, no PII.\n";
