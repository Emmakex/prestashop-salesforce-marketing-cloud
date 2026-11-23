<?php
/**
 * G8 UAT must NEVER drain unrelated G3 events. Synthetic source/read tests.
 * A BO administrator binds one customer + one order in the current shop.
 */
class Configuration {
    public static $data=[
        'PS_GROWTH_MERCHANT'=>'ABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_20261123'
    ];
    public static function get($key,$lang=null,$group=null,$shop=null) {
        return array_key_exists($key,self::$data)?self::$data[$key]:false;
    }
    public static function updateValue(
        $key,$value,$html=false,$group=null,$shop=null
    ) {
        self::$data[$key]=$value;
        return true;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Provider/ExplicitProviderRouter.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8UatExactTarget.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatExactTarget as Target;

function targetAssert($ok,$code) {
    if (!$ok)throw new RuntimeException('g8_uat_target_'.$code);
}
$ns='pssfmc_stage_20261123';
targetAssert(Target::inspect(1,'ABH',$ns)===null,'default_not_off');
targetAssert(Target::bind(1,'ABH',$ns,8,11),'binding_failed');
targetAssert(Target::bind(1,'ABH',$ns,8,11),'idempotence_failed');
targetAssert(!Target::bind(1,'ABH',$ns,9,12),
    'allowed_silent_switch');
targetAssert(Target::inspect(2,'ABH',$ns)===null,
    'other_shop_cross_read');
targetAssert(Target::inspect(1,'ABH','pssfmc_prod_20261123')===null,
    'other_namespace_cross_read');
targetAssert(!Target::bind(1,'ABH','pssfmc_prod_20261123',8,11),
    'production_target_accepted');
$read=Target::inspect(1,'ABH',$ns);
targetAssert($read['customer_id']===8 && $read['order_id']===11,
    'identity_mismatch');

$db=new class {
    public $calls=[];
    public $customerState='blocked';
    public $orderState='blocked';
    public $customerAttempts=0;
    public function executeS($sql,$array=true,$cache=false) {
        $this->calls[]=$sql;
        targetAssert(
            $array===true && $cache===false
            && strpos($sql,"l.source_namespace='pssfmc_stage_20261123'")!==false
            && strpos($sql,"l.shop_id=1")!==false
            && strpos($sql,"l.status='blocked'")!==false
            && strpos($sql,"o.status='blocked'")!==false
            && strpos($sql,"l.latest_accepted_sequence=o.accepted_sequence")!==false
            && strpos($sql," LIMIT 2")!==false
            && strpos($sql,"UPDATE")===false
            && strpos($sql,"DELETE")===false,
            'unsafe_sql_read'
        );
        $customer=strpos($sql,"l.entity_type='customer'")!==false;
        $order=strpos($sql,"l.entity_type='order'")!==false;
        targetAssert(($customer && strpos($sql,"l.entity_id=8")!==false)
            || ($order && strpos($sql,"l.entity_id=11")!==false),
            'unrelated_event_access');
        return [[
            'accepted_sequence'=>$customer?'37':'38',
            'delivery_state'=>$customer
                ?$this->customerState:$this->orderState,
            'delivery_attempts'=>$customer
                ?(string)$this->customerAttempts:'0',
            'lease_token_hash'=>null,'lease_until_epoch'=>null,
            'delivery_last_http'=>null
        ]];
    }
};
$scope=['shop'=>1,'merchant'=>'ABH','namespace'=>$ns];
$blocked=Target::candidates($db,'9.1.5','ci_',$scope,'blocked');
targetAssert(count($db->calls)===2 && $blocked===[
    ['shop'=>1,'type'=>'PSCustomer','id'=>8,'sequence'=>37],
    ['shop'=>1,'type'=>'PSOrder','id'=>11,'sequence'=>38]
], 'exact_ordered_pair_mismatch');
$db->customerState='reconcile';
$blockedAfter=Target::candidates(
    $db,'9.1.5','ci_',$scope,'blocked'
);
targetAssert($blockedAfter===[
    ['shop'=>1,'type'=>'PSOrder','id'=>11,'sequence'=>38]
], 'customer_reconcile_replayed');
$db->orderState='ready';
$ready=Target::candidates($db,'9.1.5','ci_',$scope,'ready');
targetAssert($ready===[
    ['shop'=>1,'type'=>'PSOrder','id'=>11,'sequence'=>38]
], 'ready_scan_leaked_other_events');
$db->customerState='ready';$db->customerAttempts=1;
$noReplay=Target::candidates($db,'9.1.5','ci_',$scope,'ready');
targetAssert(count($noReplay)===1
    && $noReplay[0]['type']==='PSOrder',
    'previous_customer_attempt_replayed');
targetAssert(Target::clear(1,'ABH',$ns),'revoke_failed');
targetAssert(Target::inspect(1,'ABH',$ns)===null,
    'revoked_target_still_usable');
$blocked=false;
try {
    Target::candidates($db,'9.1.5','ci_',$scope,'blocked');
} catch (RuntimeException $ignored) {$blocked=true;}
targetAssert($blocked,'missing_target_allowed');
echo "G8 UAT exact target PASS: no unrelated G3 events, no replay, no production.\n";
