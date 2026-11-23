<?php
/**
 * G8 coordinator + SAME G3 CAS receipt protocol, synthetic PHP 7.1+.
 * No database or HTTP client, no real identities, no second queue.
 */
class Configuration {
    public static $values=[
        'PS_GROWTH_MERCHANT'=>'BCABH',
        'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_ci_20261123',
        'PS_SFMC_MODE'=>'test',
        'PS_GROWTH_LOCAL_CAPTURE'=>'off'
    ];
    public static function get($key,$lang=null,$group=null,$shop=null) {
        return isset(self::$values[$key])?self::$values[$key]:false;
    }
}
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthShopSourceBinding.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthAutomaticOneShotCoordinator.php';
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8DurableReceiptBridge.php';
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthAutomaticOneShotCoordinator as Coordinator;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8DurableReceiptBridge as Bridge;

function receiptAssert($ok,$why) {
    if (!$ok)throw new RuntimeException('g8_durable_receipt_'.$why);
}
class G8SyntheticNativeLease {
    public $rows=[];
    public $claims=0;
    public $writes=0;
    public $tokens=[];
    public $failPersist=false;
    public function add($type,$id,$sequence,$state='ready') {
        $this->rows[$this->key($type,$id,$sequence)]=[
            'state'=>$state,'attempts'=>0,
            'http'=>null,'current'=>true,'active'=>false
        ];
    }
    private function key($type,$id,$sequence) {
        return $type.':'.$id.':'.$sequence;
    }
    private function row($shop,$type,$id,$sequence) {
        receiptAssert($shop===1 && in_array($type,['customer','order'],true)
            && is_int($sequence) && $sequence>0,
            'lease_non_staging_scope');
        return $this->key($type,$id,$sequence);
    }
    public function inspect($shop,$type,$id,$sequence) {
        $key=$this->row($shop,$type,$id,$sequence);
        $r=isset($this->rows[$key])?$this->rows[$key]:null;
        if ($r===null) return ['status'=>'not_found','state'=>'not_found',
            'attempts'=>0,'ledger_current'=>false,'lease_active'=>false,
            'last_http'=>null];
        return [
            'status'=>'staging_local_lease_only','state'=>$r['state'],
            'attempts'=>$r['attempts'],'ledger_current'=>$r['current'],
            'lease_active'=>$r['active'],'last_http'=>$r['http']
        ];
    }
    public function claim($shop,$type,$id,$sequence,$token,$ttl) {
        $key=$this->row($shop,$type,$id,$sequence);
        receiptAssert(preg_match('/^[a-f0-9]{64}$/D',$token)===1
            && $ttl>=30 && $ttl<=900,'weak_claim_token');
        if (!isset($this->rows[$key])
            || $this->rows[$key]['state']!=='ready'
            || $this->rows[$key]['attempts']!==0
            || !$this->rows[$key]['current'])return false;
        $this->rows[$key]['state']='claimed';
        $this->rows[$key]['attempts']=1;
        $this->rows[$key]['active']=true;
        $this->tokens[$key]=$token;
        ++$this->claims;
        return true;
    }
    private function close($shop,$type,$id,$sequence,$token,$state,$http) {
        $key=$this->row($shop,$type,$id,$sequence);
        if ($this->failPersist
            || !isset($this->rows[$key],$this->tokens[$key])
            || $this->rows[$key]['state']!=='claimed'
            || !hash_equals($this->tokens[$key],$token))return false;
        $this->rows[$key]['state']=$state;
        $this->rows[$key]['active']=false;
        $this->rows[$key]['http']=$http;
        ++$this->writes;
        return true;
    }
    public function markAcceptedUnconfirmed($shop,$type,$id,$seq,$token) {
        return $this->close($shop,$type,$id,$seq,$token,'reconcile',202);
    }
    public function markUnknownUnconfirmed($shop,$type,$id,$seq,$token) {
        return $this->close($shop,$type,$id,$seq,$token,'reconcile',null);
    }
    public function holdClaim($shop,$type,$id,$seq,$token,$http) {
        receiptAssert(is_int($http) && $http!==202,
            'invalid_held_http');
        return $this->close($shop,$type,$id,$seq,$token,'held',$http);
    }
    public function cancel($shop,$type,$id,$seq) {
        $key=$this->row($shop,$type,$id,$seq);
        if (!isset($this->rows[$key]) || $this->failPersist
            || $this->rows[$key]['state']!=='claimed')return false;
        $this->rows[$key]['state']='cancelled';
        $this->rows[$key]['active']=false;
        ++$this->writes;
        return true;
    }
}
$ns='pssfmc_stage_ci_20261123';
$scope=[
    'shop'=>1,'merchant'=>'BCABH','namespace'=>$ns,
    'mode'=>'test','capture'=>'off',
    'owner_gate'=>Coordinator::OWNER_GATE
];
$lease=new G8SyntheticNativeLease();
$bridge=new Bridge($lease,$scope);
$remote='absent_confirmed';
$sourceChange=false;$loadCount=0;$eligible=true;
$load=function($c)use($ns,&$sourceChange,&$loadCount) {
    ++$loadCount;
    $customer=$c['type']==='PSCustomer';
    $payload=[
        'source_key'=>$ns.':1:'.($customer?'customer':'order')
            .':'.$c['id'],
        'source_namespace'=>$ns,
        'shop_id'=>1
    ];
    if ($customer)$payload['customer_id']=$c['id'];
    else $payload['customer_source_key']=$ns.':1:customer:44';
    return [
        'shop'=>1,'type'=>$c['type'],'id'=>$c['id'],
        'sequence'=>$c['sequence'],
        'fingerprint'=>hash('sha256',$c['type'].$c['id'].
            ($sourceChange && ($loadCount%2===0)?'drift':'stable')),
        'customer_id'=>44,'payload_private'=>$payload
    ];
};
$local=function($fresh)use(&$eligible){return $eligible;};
$dlo=function($c,$fresh)use(&$remote){return $remote;};
$parent=function($shop,$namespace,$id)use($ns) {
    return $shop===1 && $namespace===$ns && $id===44;
};
$transportStatus=202;$posts=0;
$post=function($source,$type,$payload,$candidate)
    use($bridge,&$posts,&$transportStatus,$load) {
    receiptAssert($source==='ABH_PrestaShop_Data_Cloud','source_changed');
    // The synthetic post callback demonstrates the local lease fence.
    // It NEVER opens a network connection and is NEVER module-wired.
    $fingerprint=hash('sha256',$candidate['type'].$candidate['id'].'stable');
    receiptAssert($bridge->claimedForPost($candidate,$fingerprint),
        'missing_final_claim_fence');
    ++$posts;
    if ($transportStatus==='timeout') {
        throw new RuntimeException('synthetic_timeout_secret_data');
    }
    if ($transportStatus==='malformed')return ['status'=>'unverified'];
    return ['http_status'=>$transportStatus,
        'outcome'=>$transportStatus===202
            ?'accepted_asynchronously_unconfirmed':'rejected'];
};
$reserve=function($c,$fp)use($bridge) {
    return $bridge->reserveOnce($c,$fp);
};
$persist=function($c,$state,$http,$started)use($bridge) {
    return $bridge->persistOutcome($c,$state,$http,$started);
};
$run=function($c)use($scope,$load,$local,$dlo,$parent,$reserve,$post,$persist) {
    return Coordinator::tick(
        $scope,[$c],$load,$local,$dlo,$parent,
        $reserve,$post,$persist
    );
};
$c=['shop'=>1,'type'=>'PSCustomer','id'=>44,'sequence'=>11];
$lease->add('customer',44,11);
$first=$run($c);
receiptAssert($first['reserved']===1
    && $first['accepted_202_unconfirmed']===1
    && $first['pending_reconciliation']===1
    && $lease->rows['customer:44:11']['state']==='reconcile'
    && $lease->rows['customer:44:11']['http']===202
    && $lease->rows['customer:44:11']['attempts']===1,
    '202_not_durable');
$run($c);
receiptAssert($posts===1 && $lease->claims===1,
    '202_second_post_claimed');
$transportStatus=503;
$bad=['shop'=>1,'type'=>'PSCustomer','id'=>45,'sequence'=>12];
$lease->add('customer',45,12);
$run($bad);
receiptAssert($lease->rows['customer:45:12']['state']==='held'
    && $lease->rows['customer:45:12']['http']===503,
    '503_not_durable_held');
$transportStatus='timeout';
$timeout=['shop'=>1,'type'=>'PSCustomer','id'=>46,'sequence'=>13];
$lease->add('customer',46,13);
$run($timeout);
receiptAssert($lease->rows['customer:46:13']['state']==='reconcile'
    && $lease->rows['customer:46:13']['http']===null
    && $lease->rows['customer:46:13']['attempts']===1,
    'timeout_did_not_quarantine');
$transportStatus='malformed';
$malformed=['shop'=>1,'type'=>'PSCustomer','id'=>47,'sequence'=>14];
$lease->add('customer',47,14);
$run($malformed);
receiptAssert($lease->rows['customer:47:14']['state']==='reconcile'
    && $lease->rows['customer:47:14']['http']===null,
    'malformed_response_must_be_unknown');
$transportStatus=202;
$beforePosts=$posts;
$drift=['shop'=>1,'type'=>'PSCustomer','id'=>48,'sequence'=>15];
$lease->add('customer',48,15);
$sourceChange=true;$loadCount=0;
$driftReport=$run($drift);
$sourceChange=false;
receiptAssert($driftReport['held']===1
    && $lease->rows['customer:48:15']['state']==='cancelled'
    && $posts===$beforePosts,'local_drift_not_cancelled');
$blocked=['shop'=>1,'type'=>'PSCustomer','id'=>49,'sequence'=>16];
$lease->add('customer',49,16,'blocked');
$blockedReport=$run($blocked);
receiptAssert($blockedReport['reserved']===0
    && $posts===$beforePosts,'blocked_g3_claimed');
$uncertain=['shop'=>1,'type'=>'PSCustomer','id'=>50,'sequence'=>17];
$lease->add('customer',50,17);
$lease->failPersist=true;
$reported=$run($uncertain);
receiptAssert($reported['status']==='reconciliation_required'
    && $lease->rows['customer:50:17']['state']==='claimed'
    && $lease->rows['customer:50:17']['attempts']===1,
    'failed_receipt_retried_or_silent');
$lease->failPersist=false;
$claims=$lease->claims;
$run($uncertain);
receiptAssert($lease->claims===$claims
    && $lease->rows['customer:50:17']['state']==='claimed',
    'failed_receipt_double_claim');
$invalid=$scope;$invalid['mode']='production';
$denied=false;
try {new Bridge($lease,$invalid);}
catch (RuntimeException $error) {
    $denied=$error->getMessage()==='g8_durable_bridge_scope_denied';
}
receiptAssert($denied,'production_scope_accepted');
$source=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthG8DurableReceiptBridge.php');
$module=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/pssalesforcebridge.php');
$cron=file_get_contents(dirname(__DIR__)
    .'/pssalesforcebridge/controllers/front/cron.php');
receiptAssert(strpos($module,'GrowthG8DurableReceiptBridge')===false
    && strpos($cron,'GrowthG8DurableReceiptBridge')===false,
    'premature_live_wiring');
foreach (['curl_','Mail::send(', '->post(',
    "delivery_state='ready'",'GrowthDataCloudUpsertTransport'] as $bad) {
    receiptAssert(strpos($source,$bad)===false,'unexpected_egress');
}
foreach (['private@example.test',$ns,'source_key','ABH_PrestaShop'] as $secret) {
    receiptAssert(strpos(json_encode($first),$secret)===false,
        'private_receipt_exposed');
}
echo "G8 durable receipt bridge PASS: one CAS, 202/reconcile, 503/held, timeout/unknown, local cancellation, no replay.\n";
