<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\Provider\ExplicitProviderRouter;
use RuntimeException;

/**
 * Native PrestaShop customer/order hook -> original G3 Growth outbox.
 *
 * Deliberately local-only: no contact payload stored, no Salesforce token,
 * no requests, no marketing messages or retry scheduler. Requires a separate
 * staging database installation with a Test-only owner-armed gate.
 */
final class GrowthStagingHookCapture
{
    private static $sourceFields = [
        'actionCustomerAccountAdd'=>[
            'email','firstname','lastname','newsletter','optin','active',
            'deleted','is_guest','date_upd'
        ],
        'actionCustomerAccountUpdate'=>[
            'email','firstname','lastname','newsletter','optin','active',
            'deleted','is_guest','date_upd'
        ],
        'actionValidateOrder'=>[
            'id_customer','id_cart','id_currency','total_paid',
            'current_state','date_upd'
        ]
    ];

    public static function capture(
        $hook, $params, $version, $merchant, $namespace, $secret, $insertOrLoad
    ) {
        if (!is_string($hook) || !isset(self::$sourceFields[$hook])
            || !is_array($params)
            || !is_string($secret)
            || !preg_match('/^[a-f0-9]{64}$/D',$secret)
            || !is_callable($insertOrLoad)) {
            throw new RuntimeException('g7_staging_hook_not_eligible');
        }
        $keys=$hook==='actionValidateOrder' ? ['order']
            : ['newCustomer','customer','object'];
        $entity=null;
        foreach($keys as $key) {
            if (isset($params[$key]) && is_object($params[$key])) {
                $entity=$params[$key];
                break;
            }
        }
        if (!is_object($entity)) {
            throw new RuntimeException('g7_staging_hook_object_missing');
        }
        $data=get_object_vars($entity);
        $entityId=self::id(isset($data['id'])?$data['id']:null);
        $shopId=self::id(isset($data['id_shop'])?$data['id_shop']:null);
        if ($entityId===null || $shopId===null) {
            throw new RuntimeException('g7_staging_hook_explicit_scope_required');
        }
        $snapshot=[];
        foreach(self::$sourceFields[$hook] as $field) {
            if (!array_key_exists($field,$data)
                || is_object($data[$field]) || is_array($data[$field])
                || $data[$field]===null) {
                throw new RuntimeException('g7_staging_hook_source_incomplete');
            }
            $snapshot[$field]=$data[$field];
        }
        // A fixed per-install secret keeps hashes of names/emails/order
        // amounts from becoming unsalted reversible fingerprint fixtures.
        $canonical=json_encode([
            $hook,$entityId,$shopId,$snapshot
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($canonical) || strlen($canonical)>32768) {
            throw new RuntimeException('g7_staging_hook_fingerprint_invalid');
        }
        $type=$hook==='actionValidateOrder'?'order':'customer';
        $dedupe=$type.':'.$shopId.':'.$entityId.':'
            .hash_hmac('sha1',$canonical,hex2bin($secret));
        unset($snapshot,$canonical,$data);
        $router=new ExplicitProviderRouter(
            $version,$merchant,ExplicitProviderRouter::GROWTH,$namespace,'test'
        );
        return GrowthBlockedHookAdapter::capture(
            $router,$hook,$params,$dedupe,$insertOrLoad
        );
    }

    /**
     * A real module hook calls this only after explicit BO arming.
     * Fail closed on any missing table, unknown SQL engine, missing identity
     * or malformed source, without breaking storefront order completion.
     */
    public static function captureFromShop($hook,$params)
    {
        if (!class_exists('Configuration')
            || (string) \Configuration::get('PS_SFMC_MODE')!=='test'
            || (string) \Configuration::get('PS_GROWTH_LOCAL_CAPTURE')
                !=='staging_armed'
            || !defined('_PS_VERSION_') || !defined('_DB_PREFIX_')
            || !class_exists('Db')) {
            return ['status'=>'local_capture_disabled'];
        }
        try {
            $version=(string)_PS_VERSION_;
            $merchant=(string)\Configuration::get('PS_GROWTH_MERCHANT');
            $namespace=(string)\Configuration::get('PS_GROWTH_NAMESPACE');
            $secret=(string)\Configuration::get('PS_GROWTH_CAPTURE_HMAC_KEY');
            $db=\Db::getInstance();
            $store=new GrowthStagingOutboxStore(
                $db,$version,$merchant,'marketing_cloud_growth',
                $namespace,'test',_DB_PREFIX_
            );
            $result=self::capture(
                $hook,$params,$version,$merchant,$namespace,
                $secret,[$store,'insertOrLoad']
            );
            return [
                'status'=>'blocked_local_only',
                'inserted'=>$result['inserted'],
                'data_cloud_ingestion_authorized'=>false,
                'marketing_sends_authorized'=>false,
            ];
        } catch (\Throwable $e) {
            // Never report email, customer/order ID, SQL, token or raw
            // exception text to logs, BO, HTTP or order confirmation.
            return [
                'status'=>'local_capture_not_recorded',
                'data_cloud_ingestion_authorized'=>false,
                'marketing_sends_authorized'=>false,
            ];
        }
    }

    private static function id($v)
    {
        if(is_int($v) && $v>0)return $v;
        if(is_string($v) && preg_match('/^[1-9][0-9]*$/D',$v)) {
            $i=(int)$v;
            if($i>0 && (string)$i===$v)return $i;
        }
        return null;
    }

    private function __construct(){}
}
