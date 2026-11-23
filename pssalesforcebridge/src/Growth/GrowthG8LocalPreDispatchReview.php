<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft;
use Throwable;

/**
 * G8 UAT diagnosis before G3 CAS. No CRM calls, POST, DDL, ledger changes,
 * dispatch, hidden retry, payload logging or new state.
 *
 * State names are fixed, PII-free; private exception messages are discarded.
 * Runs even after staging dispatch approval is revoked.
 */
final class GrowthG8LocalPreDispatchReview
{
    public static function inspect($db,$version,$merchant,$namespace,$prefix,$shop)
    {
        if (!is_object($db) || !is_callable([$db,'executeS'])
            || !is_string($version) || $version===''
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1
            || !is_int($shop) || $shop<1
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace) {
            return self::report('stage_scope_invalid');
        }
        $pair=GrowthG8UatPairScope::inspect($shop,$merchant,$namespace);
        if (!is_array($pair))return self::report('uat_pair_not_bound');
        $source=GrowthShopSourceBinding::inspect($shop,$merchant,$namespace);
        if (!isset($source['ready']) || $source['ready']!==true) {
            return self::report('source_binding_unverified');
        }
        try {
            $receipt=GrowthG8UatReceiptReview::inspect(
                $db,$version,$merchant,$namespace,$prefix,$shop
            );
            if ($receipt['status']!=='g3_review_complete') {
                return self::report('g3_receipt_unverified');
            }
            if ($receipt['customer']['state']!=='blocked_no_attempt'
                || $receipt['order']['state']!=='blocked_no_attempt') {
                // A previous claim or result never authorizes a replay.
                return self::report('g3_not_pristine_no_retry');
            }
        } catch (Throwable $ignored) {
            return self::report('g3_receipt_unverified');
        }
        try {
            $local=GrowthLocalCustomerHoldReader::inspectPrestaShop(
                $shop,$pair['customer_id']
            );
            if (!is_array($local)
                || !isset($local['status'],$local['local_customer_source_state'])
                || $local['status']!=='hold_for_review') {
                return self::report('local_customer_read_unverified');
            }
            if ($local['local_customer_source_state']==='known_negative') {
                // Return only allowlisted source-state codes already computed
                // by the local reader. Never expose customer fields or raw data.
                $allowed=[
                    'local_customer_deleted',
                    'local_customer_inactive',
                    'local_customer_guest',
                    'local_newsletter_negative',
                    'local_optin_negative'
                ];
                if (!isset($local['reasons']) || !is_array($local['reasons'])
                    || count($local['reasons'])<1
                    || count($local['reasons'])>count($allowed)) {
                    return self::report('local_customer_read_unverified');
                }
                $reasons=[];
                foreach ($local['reasons'] as $reason) {
                    if (!is_string($reason)
                        || !in_array($reason,$allowed,true)
                        || in_array($reason,$reasons,true)) {
                        return self::report('local_customer_read_unverified');
                    }
                    $reasons[]=$reason;
                }
                return self::report('local_customer_excluded',$reasons);
            }
            if ($local['local_customer_source_state']
                !=='no_local_negative_observed') {
                return self::report('local_customer_read_unverified');
            }
        } catch (Throwable $ignored) {
            return self::report('local_customer_reader_exception');
        }
        try {
            $candidates=GrowthG8UatPairScope::candidates(
                $db,$version,$prefix,[
                    'shop'=>$shop,'merchant'=>$merchant,
                    'namespace'=>$namespace
                ],'blocked'
            );
            if (!is_array($candidates) || count($candidates)!==2
                || $candidates[0]['type']!=='PSCustomer'
                || $candidates[0]['id']!==$pair['customer_id']
                || $candidates[1]['type']!=='PSOrder'
                || $candidates[1]['id']!==$pair['order_id']) {
                return self::report('g3_candidate_unverified');
            }
            $lease=new GrowthPrestaShopDeliveryLease(
                $db,$version,$merchant,$namespace,$prefix
            );
            $read=$lease->inspect(
                $shop,'customer',
                $pair['customer_id'],$candidates[0]['sequence']
            );
            if (!is_array($read) || !isset(
                    $read['status'],$read['state'],$read['attempts'],
                    $read['ledger_current'],$read['lease_active']
                ) || $read['status']!=='staging_local_lease_only'
                || $read['state']!=='blocked' || $read['attempts']!==0
                || $read['ledger_current']!==true
                || $read['lease_active']!==false
                || !array_key_exists('last_http',$read)
                || $read['last_http']!==null) {
                return self::report('g7_lease_unverified');
            }
        } catch (Throwable $ignored) {
            return self::report('g7_schema_or_candidate_unverified');
        }
        try {
            if (!class_exists('Customer')) {
                return self::report('prestashop_customer_unavailable');
            }
            $customer=new \Customer($pair['customer_id']);
            $draft=GrowthNativeCustomerOrderDraft::buildCustomer(
                $namespace,$shop,$customer
            );
            if (!is_array($draft) || !isset($draft['customer_json'])
                || !is_string($draft['customer_json'])) {
                return self::report('native_customer_payload_unverified');
            }
            $payload=json_decode($draft['customer_json'],true);
            if (!is_array($payload) || !isset($payload['data'])
                || !is_array($payload['data'])
                || count($payload['data'])!==1
                || !is_array($payload['data'][0])
                || !isset($payload['data'][0]['source_key'])
                || $payload['data'][0]['source_key']
                    !==$namespace.':'.$shop.':customer:'.$pair['customer_id']) {
                return self::report('native_customer_payload_unverified');
            }
            unset($customer,$draft,$payload);
            // Local success is not DLO confirmation or authorization to
            // resend. The original unknown attempt remains quarantined.
            return self::report('local_pre_dispatch_validated_no_send');
        } catch (Throwable $ignored) {
            return self::report('native_customer_payload_unverified');
        }
    }
    private static function report($status,array $reasons=[])
    {
        return [
            'status'=>$status,'local_exclusion_reasons'=>$reasons,
            'read_only'=>true,
            'customer_data_exposed'=>false,
            'http_request_performed'=>false,
            'database_write_performed'=>false,
            'dispatch_authorized'=>false,
            'retry_authorized'=>false,
            'marketing_sends_authorized'=>false
        ];
    }
    private function __construct(){}
}
