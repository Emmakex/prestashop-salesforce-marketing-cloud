<?php
/** G8 protected staging safety gate: synthetic G3/CSC/DLO evidence only. */
class Configuration {
    public static $data=[
        'PS_GROWTH_MERCHANT'=>'BCABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_20261123',
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off'
    ];
    public static function get($key,$lang=null,$group=null,$shop=null) {
        return isset(self::$data[$key])?self::$data[$key]:false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingSafetyReview.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingSafetyReview as Review;

function srAssert($ok,$name) {
    if (!$ok)throw new RuntimeException('g8_staging_safety_'.$name);
}
$scope=[
    'shop'=>1,'merchant'=>'BCABH','namespace'=>'pssfmc_stage_20261123',
    'mode'=>'test','capture'=>'off'
];
$c=[
    ['shop'=>1,'type'=>'PSCustomer','id'=>99111,'sequence'=>10],
    ['shop'=>1,'type'=>'PSOrder','id'=>99222,'sequence'=>11]
];
$lease=new class {
    public $state='blocked';
    public $calls=0;
    public function inspect($shop,$type,$id,$sequence) {
        ++$this->calls;
        srAssert($shop===1 && $sequence>0
            && in_array($type,['customer','order'],true),'lease_scope');
        return [
            'status'=>'staging_local_lease_only','state'=>$this->state,
            'attempts'=>0,'last_http'=>null,'ledger_current'=>true,
            'lease_active'=>false,'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
    }
};
$csc=[
    'status'=>'hold_for_review',
    'reason'=>'csc_candidate_requires_sendtime_fence',
    'csc_source_state'=>'recent_opt_in_observed_not_certified',
    'source_and_consent_rechecked'=>true,
    'send_time_consent_certified'=>false,
    'final_dispatch_fence_certified'=>false,
    'data_cloud_ingestion_authorized'=>false,
    'marketing_sends_authorized'=>false,
];
$consent=function($candidate)use(&$csc) {
    srAssert($candidate['shop']===1,'csc_shop');
    return $csc;
};
$remote=[
    'PSCustomer:99111'=>'absent_confirmed',
    'PSOrder:99222'=>'absent_confirmed',
    'PSCustomer:99333'=>'present'
];
$lookups=0;
$presence=function($shop,$namespace,$type,$id)
    use (&$remote,&$lookups) {
    ++$lookups;
    srAssert($shop===1 && $namespace==='pssfmc_stage_20261123',
        'remote_scope');
    $key=$type.':'.$id;
    return isset($remote[$key])?$remote[$key]:'unknown';
};
$parent=function($candidate){
    srAssert($candidate['type']==='PSOrder','not_order_parent');
    return 99333;
};
$run=function($scope,$candidates)use($lease,$consent,$presence,$parent) {
    return Review::inspect(
        $scope,$candidates,$lease,$consent,$presence,$parent
    );
};
$r=$run($scope,$c);
srAssert($r['status']==='staging_review_only_no_sends'
    && $r['counts']['examined']===2
    && $r['counts']['remote_absent_review_only']===2
    && $r['counts']['parent_not_verified']===0
    && $r['data_cloud_ingestion_authorized']===false
    && $r['marketing_sends_authorized']===false
    && $r['lease_claimed']===false
    && $r['ready_transition_performed']===false
    && $r['send_time_consent_certified']===false
    && $lookups===3,'positive_still_no_go');
srAssert(strpos(json_encode($r),'99111')===false
    && strpos(json_encode($r),'99333')===false
    && strpos(json_encode($r),$scope['namespace'])===false,
    'private_identifiers_exposed');

$before=$lookups;
$csc['reason']='csc_denied_or_unverified_hold';
$blocked=$run($scope,[$c[0]]);
srAssert($blocked['counts']['consent_not_certified']===1
    && $lookups===$before,'csc_hold_not_respected');
$csc['reason']='csc_candidate_requires_sendtime_fence';
$csc['send_time_consent_certified']=true; // forged authorization
$blocked=$run($scope,[$c[0]]);
srAssert($blocked['counts']['consent_not_certified']===1
    && $lookups===$before,'forged_csc_acceptance');
$csc['send_time_consent_certified']=false;
$lease->state='reconcile';
$blocked=$run($scope,[$c[0]]);
srAssert($blocked['counts']['current_lease_blocked']===1
    && $lookups===$before,'reconcile_lease_replayed');
$lease->state='blocked';
$remote['PSCustomer:99111']='present';
$existing=$run($scope,[$c[0]]);
srAssert($existing['counts']['remote_already_present']===1
    && $existing['counts']['remote_absent_review_only']===0,
    'existing_remote_not_blocked');
$remote['PSCustomer:99111']='unknown';
$unknown=$run($scope,[$c[0]]);
srAssert($unknown['counts']['remote_unknown']===1,
    'unknown_remote_unblocked');
$remote['PSCustomer:99111']='absent_confirmed';
$remote['PSCustomer:99333']='unknown';
$noParent=$run($scope,[$c[1]]);
srAssert($noParent['counts']['parent_not_verified']===1
    && $noParent['counts']['remote_absent_review_only']===0,
    'missing_parent_accepted');
$remote['PSCustomer:99333']='present';

$bad=$scope;$bad['namespace']='pssfmc_bc_prod_20261120';
srAssert($run($bad,$c)['status']==='blocked_by_configuration',
    'production_namespace_allowed');
$bad=$scope;$bad['shop']=2;
srAssert($run($bad,$c)['status']==='staging_review_failed_closed',
    'shop_drift_allowed');
$bad=$scope;$bad['mode']='production';
srAssert($run($bad,$c)['status']==='blocked_by_configuration',
    'production_mode_allowed');
$bad=$scope;$bad['capture']='staging_armed';
srAssert($run($bad,$c)['status']==='blocked_by_configuration',
    'active_capture_allowed');
$bad=$c;$bad[1]['sequence']=10;
srAssert($run($scope,$bad)['status']==='staging_review_failed_closed',
    'sequence_reuse_allowed');
$bad=$c;$bad[1]=$c[0];
srAssert($run($scope,$bad)['status']==='staging_review_failed_closed',
    'duplicate_candidate_allowed');
$bad=$c;$bad[1]['shop']=2;
$beforeLease=$lease->calls;
srAssert($run($scope,$bad)['status']==='staging_review_failed_closed'
    && $lease->calls===$beforeLease,
    'cross_shop_candidate_allowed');
$lots=array_fill(0,11,$c[0]);
srAssert($run($scope,$lots)['status']==='blocked_by_configuration',
    'unbounded_batch_allowed');
$thrown=Review::inspect(
    $scope,[$c[0]],$lease,function(){throw new RuntimeException(
        'secret_email=private@example.test'
    );},$presence,$parent
);
srAssert($thrown['status']==='staging_review_failed_closed'
    && $thrown['counts']===null
    && strpos(json_encode($thrown),'private@example.test')===false,
    'unsafe_exception_leaked');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingSafetyReview.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
srAssert(strpos($module,'GrowthG8StagingSafetyReview')===false
    && strpos($cron,'GrowthG8StagingSafetyReview')===false,
    'premature_sender_integration');
foreach (['->claim(', '->execute(', 'Mail::send(', '->post(',
    'curl_', "delivery_state='ready'",'GrowthDataCloudUpsertTransport'] as $forbidden) {
    srAssert(strpos($source,$forbidden)===false,'unexpected_mutation');
}
echo "G8 staging safety PASS: G3 current lease, G7 CSC hold, DLO/parent evidence; no claims/sends.\n";
