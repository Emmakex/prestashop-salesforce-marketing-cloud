<?php
/** G8 tenant-scoped DLO callback contract. Strictly synthetic, no network. */
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
    .'/pssalesforcebridge/src/DataCloud/GrowthG8DloPresenceReader.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8ScopedDloRead.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8ScopedDloRead as Reader;

function sdAssert($ok,$name) {
    if (!$ok)throw new RuntimeException('g8_scoped_dlo_'.$name);
}
$origin='https://tenant.credencial.c360a.salesforce.com';
$ns='pssfmc_stage_20261123';
$descriptor=[
    'shop'=>1,'merchant'=>'BCABH','namespace'=>$ns,
    'source'=>'ABH_PrestaShop_Data_Cloud',
    'origin'=>$origin,
    'resolved'=>[
        'PSCustomer'=>[
            'dlo'=>'PST_PSCustomer_Synthetic__dll',
            'key'=>'source_key__c'
        ],
        'PSOrder'=>[
            'dlo'=>'PST_PSOrder_Synthetic__dll',
            'key'=>'source_key__c',
            'customer_key'=>'customer_source_key__c'
        ]
    ]
];
$requests=0;$remote='present';
$http=function($url,$json,$token)use(&$requests,&$remote,$origin,$ns) {
    ++$requests;
    sdAssert($url===$origin.'/api/v2/query'
        && $token==='token_synthetic_not_private','query_endpoint');
    $body=json_decode($json,true);
    sdAssert(isset($body['sql']) && is_string($body['sql'])
        && strpos($body['sql'],' LIMIT 2')!==false
        && strpos($body['sql'],' AS "g8_exact_source_key"')!==false
        && strpos($body['sql'],'UPDATE')===false
        && strpos($body['sql'],'DELETE')===false,'query_projection');
    $isCustomer=strpos(
        $body['sql'],'PST_PSCustomer_Synthetic__dll'
    )!==false;
    $id=$isCustomer?99111:99222;
    $expected=$ns.':1:'.($isCustomer?'customer':'order').':'.$id;
    sdAssert(strpos($body['sql'],"'".$expected."'")!==false,
        'cross_tenant_key');
    if ($remote==='http_error')return ['status'=>503,'body'=>'private'];
    $rows=$remote==='present'
        ?[['noncanonical_api_column_label'=>$expected]]:[];
    return ['status'=>200,'body'=>json_encode([
        'data'=>$rows,'rowCount'=>count($rows),'done'=>true
    ])];
};
$read=function($desc,$shop,$namespace,$type,$id)
    use($origin,$http){
    return Reader::inspect(
        $desc,$origin,'token_synthetic_not_private',
        $shop,$namespace,$type,$id,$http
    );
};
sdAssert($read($descriptor,1,$ns,'PSCustomer',99111)==='present',
    'customer_exact_match');
sdAssert($read($descriptor,1,$ns,'PSOrder',99222)==='present',
    'order_exact_match');
$remote='absent';
sdAssert($read($descriptor,1,$ns,'PSOrder',99222)==='absent_confirmed',
    'complete_empty_only');
$remote='http_error';
sdAssert($read($descriptor,1,$ns,'PSCustomer',99111)==='unknown',
    'error_misclassified_as_absent');
$remote='present';
$previous=$requests;
$bad=$descriptor;$bad['shop']=2;
sdAssert($read($bad,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'cross_shop_sent');
$bad=$descriptor;$bad['merchant']='DOABH';
sdAssert($read($bad,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'cross_merchant_sent');
$bad=$descriptor;$bad['source']='DO_Custom_Source';
sdAssert($read($bad,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'cross_source_sent');
$bad=$descriptor;$bad['origin']='https://other.c360a.salesforce.com';
sdAssert($read($bad,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'cross_tenant_host_sent');
$bad=$descriptor;$bad['namespace']='pssfmc_stage_other';
sdAssert($read($bad,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'cross_namespace_sent');
$bad=$descriptor;$bad['resolved']['PSCustomer']['key']='bad;DROP';
sdAssert($read($bad,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'sql_identifier_injection_sent');
sdAssert($read($descriptor,2,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'caller_shop_drift_sent');
sdAssert($read($descriptor,1,$ns,'PSCustomer',0)==='unknown'
    && $previous===$requests,'missing_customer_sent');
Configuration::$data['PS_SFMC_MODE']='production';
sdAssert($read($descriptor,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'production_mode_sent');
Configuration::$data['PS_SFMC_MODE']='test';
Configuration::$data['PS_GROWTH_LOCAL_CAPTURE']='staging_armed';
sdAssert($read($descriptor,1,$ns,'PSCustomer',99111)==='unknown'
    && $previous===$requests,'capture_armed_sent');
Configuration::$data['PS_GROWTH_LOCAL_CAPTURE']='off';
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8ScopedDloRead.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
sdAssert(strpos($module,'GrowthG8ScopedDloRead.php')!==false
    && strpos($module,'GrowthG8NativeStagingServices::run(')!==false
    && strpos($cron,'GrowthG8ScopedDloRead')===false
    && strpos($source,'GrowthG8DloPresenceReader::inspect(')!==false,
    'stage_dlo_wiring_missing');
foreach (['->claim(', '->execute(', 'Mail::send(', '->post(',
    'GrowthDataCloudUpsertTransport'] as $forbidden) {
    sdAssert(strpos($source,$forbidden)===false,'unexpected_sender');
}

/* Integrated rehearsal using the actual read-only scoped DLO adapter,
 * native-lease interface, and G7 positive-but-HOLD CSC review contract. */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8StagingSafetyReview.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingSafetyReview as Gate;

$lease=new class {
    public function inspect($shop,$type,$id,$sequence) {
        sdAssert($shop===1 && $sequence>=10,'integration_lease_scope');
        return [
            'status'=>'staging_local_lease_only','state'=>'blocked',
            'attempts'=>0,'last_http'=>null,'ledger_current'=>true,
            'lease_active'=>false,'data_cloud_ingestion_authorized'=>false,
            'outbound_worker_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
    }
};
$csc=function($candidate) {
    sdAssert($candidate['shop']===1,'integration_csc_shop');
    return [
        'status'=>'hold_for_review',
        'reason'=>'csc_candidate_requires_sendtime_fence',
        'csc_source_state'=>'recent_opt_in_observed_not_certified',
        'source_and_consent_rechecked'=>true,
        'send_time_consent_certified'=>false,
        'final_dispatch_fence_certified'=>false,
        'data_cloud_ingestion_authorized'=>false,
        'marketing_sends_authorized'=>false
    ];
};
$lookup=function($shop,$namespace,$object,$id)
    use($descriptor,$read) {
    return $read($descriptor,$shop,$namespace,$object,$id);
};
$selected=[
    ['shop'=>1,'type'=>'PSCustomer','id'=>99111,'sequence'=>10],
    ['shop'=>1,'type'=>'PSOrder','id'=>99222,'sequence'=>11],
];
$reviewScope=[
    'shop'=>1,'merchant'=>'BCABH','namespace'=>$ns,
    'mode'=>'test','capture'=>'off'
];
$remote='present';
$combined=Gate::inspect(
    $reviewScope,$selected,$lease,$csc,$lookup,
    function($candidate) {return 99111;}
);
sdAssert($combined['status']==='staging_review_only_no_sends'
    && $combined['counts']['remote_already_present']===2
    && $combined['counts']['remote_absent_review_only']===0
    && $combined['data_cloud_ingestion_authorized']===false,
    'composed_present_dlo_sends_blocked');
$remote='absent';
$combined=Gate::inspect(
    $reviewScope,$selected,$lease,$csc,$lookup,
    function($candidate) {return 99111;}
);
sdAssert($combined['counts']['remote_absent_review_only']===1
    && $combined['counts']['parent_not_verified']===1
    && $combined['marketing_sends_authorized']===false,
    'composed_missing_parent_dlo_sends_blocked');

echo "G8 scoped DLO PASS: exact tenant/shop/source, tri-state, synthetic no egress.\n";
