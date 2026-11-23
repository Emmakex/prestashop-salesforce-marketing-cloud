<?php
/**
 * G8 pre-dispatch diagnosis is completely local, readonly, no API sends.
 * Synthetic dependency injections validate safe enum phases.
 */
namespace {
    class Configuration {
        public static $values=[
            'PS_GROWTH_MERCHANT'=>'ABH',
            'PS_GROWTH_NAMESPACE'=>'pssfmc_stage_20261123',
            'PS_SFMC_MODE'=>'test',
            'PS_GROWTH_LOCAL_CAPTURE'=>'off',
        ];
        public static function get($key) {
            return isset(self::$values[$key])?self::$values[$key]:false;
        }
    }
    class Customer {
        public $id;
        public function __construct($id) {$this->id=$id;}
    }
    function localG8Assert($ok,$why) {
        if (!$ok)throw new \RuntimeException('local_g8_'.$why);
    }
}
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth {
    final class GrowthShopSourceBinding {
        public static function isMerchant($x){return $x==='ABH';}
        public static function isNamespace($x) {
            return is_string($x)
                && preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$x)===1;
        }
        public static function inspect($shop,$merchant,$ns) {
            return ['ready'=>$shop===1 && $merchant==='ABH'
                && $ns==='pssfmc_stage_20261123'];
        }
    }
    final class GrowthG8UatPairScope {
        public static $candidateBad=false;
        public static function inspect($shop,$merchant,$ns) {
            return $shop===1 && $merchant==='ABH'
                && $ns==='pssfmc_stage_20261123'
                ?['customer_id'=>8,'order_id'=>11]:null;
        }
        public static function candidates($db,$ver,$prefix,$scope,$state) {
            if (self::$candidateBad)return [];
            return [
                ['shop'=>1,'type'=>'PSCustomer','id'=>8,'sequence'=>71],
                ['shop'=>1,'type'=>'PSOrder','id'=>11,'sequence'=>72]
            ];
        }
    }
    final class GrowthG8UatReceiptReview {
        public static $customer='blocked_no_attempt';
        public static $order='blocked_no_attempt';
        public static function inspect($db,$v,$merchant,$ns,$prefix,$shop) {
            return ['status'=>'g3_review_complete',
                'customer'=>['state'=>self::$customer],
                'order'=>['state'=>self::$order]
            ];
        }
    }
    final class GrowthLocalCustomerHoldReader {
        public static $source='no_local_negative_observed';
        public static $throw=false;
        public static function inspectPrestaShop($shop,$customer) {
            if (self::$throw)throw new \RuntimeException('private exception');
            return ['status'=>'hold_for_review',
                'local_customer_source_state'=>self::$source];
        }
    }
    final class GrowthPrestaShopDeliveryLease {
        public static $throw=false;
        public static $valid=true;
        public function __construct($db,$ver,$merchant,$ns,$prefix) {
            if (self::$throw)throw new \RuntimeException('private schema');
        }
        public function inspect($shop,$type,$id,$sequence) {
            return ['status'=>'staging_local_lease_only',
                'state'=>self::$valid?'blocked':'ready','attempts'=>0,
                'ledger_current'=>true,'lease_active'=>false,
                'last_http'=>null];
        }
    }
}
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud {
    final class GrowthNativeCustomerOrderDraft {
        public static $valid=true;
        public static function buildCustomer($ns,$shop,$customer) {
            if (!self::$valid)throw new \RuntimeException('private customer email');
            return ['customer_json'=>json_encode(['data'=>[[
                'source_key'=>$ns.':'.$shop.':customer:'.$customer->id
            ]]])];
        }
    }
}
namespace {
    require_once dirname(__DIR__)
        .'/pssalesforcebridge/src/Growth/GrowthG8LocalPreDispatchReview.php';

    $review='Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8LocalPreDispatchReview';
    $receipt='Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatReceiptReview';
    $local='Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthLocalCustomerHoldReader';
    $pair='Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatPairScope';
    $lease='Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthPrestaShopDeliveryLease';
    $draft='Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft';
    $db=new class {
        public function executeS($sql,$array=true,$cache=false) {
            throw new \RuntimeException('read must be via permitted reader');
        }
    };
    $run=function($ns='pssfmc_stage_20261123')use($review,$db) {
        return $review::inspect($db,'9.1.5','ABH',$ns,'ci_',1);
    };
    $report=$run();
    localG8Assert(
        $report['status']==='local_pre_dispatch_validated_no_send'
        && !$report['dispatch_authorized']
        && !$report['http_request_performed']
        && !$report['database_write_performed']
        && !$report['retry_authorized'],'nominal_preflight');
    $json=json_encode($report);
    localG8Assert(strpos($json,'@')===false
        && strpos($json,'pssfmc_stage')===false
        && strpos($json,'private')===false,'no_sensitive_output');
    localG8Assert($run('pssfmc_prod')['status']==='stage_scope_invalid',
        'production_preflight_blocked');
    $local::$source='known_negative';
    localG8Assert($run()['status']==='local_customer_excluded',
        'optout_not_held');
    $local::$source='unknown';
    localG8Assert($run()['status']==='local_customer_read_unverified',
        'unknown_local_read_accepted');
    $local::$source='no_local_negative_observed';
    $local::$throw=true;
    localG8Assert($run()['status']==='local_customer_reader_exception',
        'reader_exception_exposed');
    $local::$throw=false;
    $pair::$candidateBad=true;
    localG8Assert($run()['status']==='g3_candidate_unverified',
        'candidate_bad_not_blocked');
    $pair::$candidateBad=false;
    $lease::$throw=true;
    localG8Assert($run()['status']==='g7_schema_or_candidate_unverified',
        'lease_exception_exposed');
    $lease::$throw=false;$lease::$valid=false;
    localG8Assert($run()['status']==='g7_lease_unverified',
        'lease_wrong_state_not_blocked');
    $lease::$valid=true;$draft::$valid=false;
    localG8Assert($run()['status']==='native_customer_payload_unverified',
        'invalid_native_payload_allowed');
    $draft::$valid=true;$receipt::$customer='reconcile';
    localG8Assert($run()['status']==='g3_not_pristine_no_retry',
        'previous_attempt_not_blocked');
    $receipt::$customer='blocked_no_attempt';
    Configuration::$values['PS_GROWTH_LOCAL_CAPTURE']='staging_armed';
    localG8Assert($run()['status']==='stage_scope_invalid',
        'capture_armed_not_blocked');
    Configuration::$values['PS_GROWTH_LOCAL_CAPTURE']='off';

    $module=file_get_contents(dirname(__DIR__)
        .'/pssalesforcebridge/pssalesforcebridge.php');
    $view=file_get_contents(dirname(__DIR__)
        .'/pssalesforcebridge/views/templates/admin/console.tpl');
    $source=file_get_contents(dirname(__DIR__)
        .'/pssalesforcebridge/src/Growth/GrowthG8LocalPreDispatchReview.php');
    $start=strpos($module,'private function handleG8UatReadOnly($operation)');
    $end=strpos($module,'private function handleG8UatOperator($operation)');
    localG8Assert($start!==false && $end>$start,'bo_reader_not_found');
    $handler=substr($module,$start,$end-$start);
    localG8Assert(
        strpos($module,"Tools::isSubmit('submitPsGrowthG8DiagnoseLocal')")!==false
        && strpos($view,'name="submitPsGrowthG8DiagnoseLocal"')!==false
        && strpos($handler,"if (\$operation==='local')")!==false
        && strpos($handler,'GrowthG8LocalPreDispatchReview::inspect(')!==false
        && strpos($handler,'isCredentialAdmin($shop)')!==false
        && strpos($handler,'hash_equals($this->credentialCsrf($shop),$csrf)')!==false,
        'bo_review_not_protected');
    foreach ([
        'curl_','GrowthDataCloudUpsertTransport','Mail::send(',
        'INSERT INTO','UPDATE ','DELETE FROM','markAcceptedUnconfirmed(',
        'reserveOnce(','claim(','sendIfAuthorized('
    ] as $forbidden) {
        localG8Assert(strpos($source,$forbidden)===false,
            'source_not_readonly');
    }
    echo "G8 local pre-dispatch diagnosis PASS: stage, local, G7, native checks; no egress.\n";
}
