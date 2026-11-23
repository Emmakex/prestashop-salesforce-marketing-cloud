<?php
/** Read-only post-attempt G8 customer/order G3 receipt review. Synthetic. */
class Configuration {
    public static $data=[
        'PS_GROWTH_MERCHANT'=>'ABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_20261123',
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off'
    ];
    public static function get($k,$lang=null,$group=null,$shop=null) {
        return isset(self::$data[$k])?self::$data[$k]:false;
    }
    public static function updateValue(
        $k,$v,$html=false,$group=null,$shop=null
    ) {
        self::$data[$k]=$v;return true;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8UatPairScope.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8UatReceiptReview.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatPairScope as Pair;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatReceiptReview as Review;

function receiptReviewAssert($yes,$why) {
    if (!$yes)throw new RuntimeException('g8_receipt_review_'.$why);
}
$ns='pssfmc_stage_20261123';
receiptReviewAssert(Pair::bind(1,'ABH',$ns,8,11),'target_not_bound');
$db=new class {
    public $calls=0;
    public $customerState='reconcile';
    public $customerAttempts='1';
    public $customerHttp='202';
    public $orderState='blocked';
    public $orderAttempts='0';
    public function executeS($sql,$array=true,$cache=false) {
        $this->calls++;
        receiptReviewAssert(
            $array===true && $cache===false
            && strpos($sql,"l.source_namespace='pssfmc_stage_20261123'")!==false
            && strpos($sql,'l.shop_id=1')!==false
            && strpos($sql,'o.accepted_sequence=l.latest_accepted_sequence')!==false
            && strpos($sql,'INNER JOIN')!==false
            && strpos($sql,'LIMIT 2')!==false
            && strpos($sql,'UPDATE ')===false
            && strpos($sql,'DELETE ')===false,
            'sql_scope_or_write'
        );
        $customer=strpos($sql,"l.entity_type='customer'")!==false;
        receiptReviewAssert(
            ($customer && strpos($sql,'l.entity_id=8')!==false)
            || (!$customer && strpos($sql,"l.entity_type='order'")!==false
                && strpos($sql,'l.entity_id=11')!==false),
            'cross_record_query'
        );
        $state=$customer?$this->customerState:$this->orderState;
        $attempts=$customer?$this->customerAttempts:$this->orderAttempts;
        return [[
            'accepted_sequence'=>$customer?'40':'41',
            'delivery_state'=>$state,
            'delivery_attempts'=>$attempts,
            'delivery_last_http'=>$customer?$this->customerHttp:null,
            'lease_until_epoch'=>null,
            'event_status'=>'blocked',
            'ledger_status'=>'blocked',
            'latest_accepted_sequence'=>$customer?'40':'41'
        ]];
    }
};
$run=function($nsValue='pssfmc_stage_20261123')use($db) {
    return Review::inspect($db,'9.1.5','ABH',$nsValue,'ci_',1);
};
$r=$run();
receiptReviewAssert($r['status']==='g3_review_complete'
    && $r['customer']['state']==='accepted_202_unconfirmed'
    && $r['customer']['attempts']===1
    && $r['customer']['http_status']===202
    && $r['order']['state']==='blocked_no_attempt'
    && $r['order']['attempts']===0
    && $r['read_only']===true
    && $r['dispatch_authorized']===false
    && $r['retry_authorized']===false
    && $db->calls===2,'safe_receipt_not_reported');
$json=json_encode($r);
receiptReviewAssert(strpos($json,$ns)===false
    && strpos($json,'QEUOXGXAF')===false
    && strpos($json,'@')===false,'privacy');
$db->customerState='claimed';$db->customerHttp=null;
$r=$run();
receiptReviewAssert($r['customer']['state']==='claimed_unconfirmed',
    'claimed_mistaken_as_unsent');
$db->customerState='reconcile';$db->customerHttp=null;
$r=$run();
receiptReviewAssert($r['customer']['state']==='reconciliation_required'
    && $r['retry_authorized']===false,
    'unknown_transport_retried');
$db->customerState='ready';$db->customerAttempts='0';
$r=$run();
receiptReviewAssert($r['customer']['state']==='ready_no_attempt',
    'unattempted_ready_unverified');
$before=$db->calls;
receiptReviewAssert($run('pssfmc_prod')['status']==='g3_review_unavailable'
    && $db->calls===$before,'production_namespace_reached_db');
Configuration::$data['PS_GROWTH_LOCAL_CAPTURE']='staging_armed';
receiptReviewAssert($run()['status']==='g3_review_unavailable'
    && $db->calls===$before,'armed_capture_reached_db');
Configuration::$data['PS_GROWTH_LOCAL_CAPTURE']='off';

$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$tpl=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/views/templates/admin/console.tpl');
$start=strpos($module,'private function handleG8UatReadOnly(');
$end=strpos($module,'private function handleG8UatOperator(');
receiptReviewAssert($start!==false && $end>$start,
    'admin_reader_absent');
$handler=substr($module,$start,$end-$start);
foreach ([
    'isCredentialAdmin($shop)',
    'hash_equals($this->credentialCsrf($shop),$csrf)',
    'GrowthG8UatPairScope::inspect(',
    'GrowthG8UatReceiptReview::inspect(',
    'GrowthG8CloudMetadataClient::resolve(',
    'GrowthG8ScopedDloRead::inspectWithEvidence(',
    "PSCustomer",
    "Data Cloud",
    "No reintentes"
] as $must) {
    receiptReviewAssert(strpos($handler,$must)!==false,'bo_guard_missing');
}
foreach ([
    'submitPsGrowthG8ReadG3','submitPsGrowthG8ReadDlo'
] as $name) {
    receiptReviewAssert(
        strpos($module,"Tools::isSubmit('$name')")!==false
        && strpos($tpl,'name="'.$name.'"')!==false,
        'read_only_button_missing'
    );
}
receiptReviewAssert(strpos($handler,'GrowthDataCloudUpsertTransport')===false
    && strpos($handler,'GrowthG8NativeStagingServices::run(')===false
    && strpos($handler,'GrowthG8StagingReadyGate::promote(')===false
    && strpos($handler,'GrowthG8DurableReceiptBridge(')===false
    && strpos($handler,'INSERT INTO')===false
    && strpos($handler,'UPDATE ')===false,
    'reconciliation_sender_detected');
echo "G8 post-attempt reconciliation PASS: exact receipt and DLO read-only, no resend.\n";
