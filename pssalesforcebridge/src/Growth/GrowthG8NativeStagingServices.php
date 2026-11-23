<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8CloudMetadataClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthNativeCustomerOrderDraft;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthDataCloudUpsertTransport;
use Throwable;

/**
 * Staging-only real PrestaShop + Salesforce callbacks for the G8 worker.
 *
 * Test/capture OFF, approved exact shop, current G3 ledger, per-shop OAuth,
 * tenant DLO metadata, local exclusions and direct ingestion transport.
 *
 * Marketing Cloud Growth campaigns are NOT sent from PrestaShop.
 * The operator's approval authorizes the Data Cloud staging UAT only;
 * it is not marketing-consent approval nor production go-live.
 */
final class GrowthG8NativeStagingServices
{
    public static function run($db,$version,$prefix,$shop)
    {
        if (!is_int($shop) || $shop<1
            || !class_exists('Configuration')
            || !class_exists('Customer')
            || !class_exists('Order')
            || !class_exists('Currency')
            || !is_object($db)
            || (string)\Configuration::get(
                'PS_GROWTH_G8_STAGING_APPROVAL'
            )!==GrowthG8StagingWorker::APPROVAL
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || (string)\Configuration::get('PS_SFMC_CART_CONSENT_MODE')
                !=='marketing_optin') {
            return self::report('staging_dispatch_not_approved');
        }
        $merchant=(string)\Configuration::get('PS_GROWTH_MERCHANT');
        $namespace=(string)\Configuration::get('PS_GROWTH_NAMESPACE');
        if (!GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1) {
            return self::report('staging_dispatch_not_approved');
        }
        $scope=[
            'shop'=>$shop,'merchant'=>$merchant,
            'namespace'=>$namespace,'mode'=>'test','capture'=>'off',
            'owner_gate'=>GrowthAutomaticOneShotCoordinator::OWNER_GATE
        ];

        // No OAuth or metadata requests before a private, exact UAT
        // customer/order selection has been bound by the BO administrator.
        if (GrowthG8UatExactTarget::inspect(
            $shop,$merchant,$namespace
        )===null) {
            return self::report('staging_target_not_bound');
        }
        try {
            $credential=GrowthShopCredentialVault::inspect($shop);
            if (!is_array($credential) || !isset($credential['ready'])
                || $credential['ready']!==true) {
                return self::report('staging_credentials_unavailable');
            }
            $binding=GrowthShopSourceBinding::sourceForSend(
                $shop,$merchant,$namespace
            );
            $cloud=GrowthShopCredentialVault::obtainCloudToken($shop);
            if (!is_array($cloud)
                || !isset($cloud['instance_url'],$cloud['access_token'])
                || !is_string($cloud['instance_url'])
                || !is_string($cloud['access_token'])) {
                return self::report('staging_token_unavailable');
            }
            $origin=$cloud['instance_url'];
            $token=$cloud['access_token'];
            $resolved=GrowthG8CloudMetadataClient::resolve($origin,$token);
            $descriptor=[
                'shop'=>$shop,'merchant'=>$merchant,
                'namespace'=>$namespace,'source'=>$binding,
                'origin'=>$origin,'resolved'=>$resolved
            ];
            $load=function($candidate)use($namespace,$shop) {
                return self::nativeSnapshot($candidate,$namespace,$shop);
            };
            $local=function($fresh)use($shop) {
                return self::localAllowed($shop,$fresh['customer_id']);
            };
            $contact=function($candidate,$customerId,$phase)use($shop) {
                return isset($candidate['shop'])
                    && $candidate['shop']===$shop
                    && in_array($phase,[
                        'before_claim','after_claim','before_transport'
                    ],true)
                    && self::localAllowed($shop,$customerId);
            };
            $dlo=function($candidate,$fresh)use(
                $descriptor,$origin,$token,$namespace,$shop
            ) {
                return GrowthG8ScopedDloRead::inspect(
                    $descriptor,$origin,$token,$shop,$namespace,
                    $candidate['type'],$candidate['id']
                );
            };
            $parent=function($candidateShop,$candidateNs,$customerId)use(
                $descriptor,$origin,$token,$shop,$namespace
            ) {
                return $candidateShop===$shop
                    && $candidateNs===$namespace
                    && GrowthG8ScopedDloRead::inspect(
                        $descriptor,$origin,$token,$shop,$namespace,
                        'PSCustomer',$customerId
                    )==='present';
            };
            $post=function($source,$object,$payload,$candidate,$finalFence)
                use($origin,$token,$namespace,$shop,$merchant,$binding) {
                if (!is_callable($finalFence)
                    || $source!==$binding
                    || $candidate['shop']!==$shop
                    || !in_array($object,['PSCustomer','PSOrder'],true)
                    || !is_array($payload)) {
                    throw new \RuntimeException('g8_staging_post_gate_denied');
                }
                $sent=GrowthDataCloudUpsertTransport::sendIfAuthorized(
                    $origin,$source,$object,[$payload],
                    $namespace,$shop,$token,
                    function($checkNs,$checkShop,$checkObject)use(
                        $finalFence,$namespace,$shop,$object,
                        $merchant,$binding
                    ) {
                        return $checkNs===$namespace
                            && $checkShop===$shop
                            && $checkObject===$object
                            && (string)\Configuration::get(
                                'PS_GROWTH_G8_STAGING_APPROVAL'
                            )===GrowthG8StagingWorker::APPROVAL
                            && (string)\Configuration::get(
                                'PS_SFMC_MODE'
                            )==='test'
                            && (string)\Configuration::get(
                                'PS_GROWTH_LOCAL_CAPTURE'
                            )==='off'
                            && GrowthShopSourceBinding::sourceForSend(
                                $shop,$merchant,$namespace
                            )===$binding
                            && call_user_func($finalFence)===true;
                    }
                );
                return [
                    'http_status'=>isset($sent['http_status'])
                        ?$sent['http_status']:null,
                    'outcome'=>isset($sent['outcome_state'])
                        ?$sent['outcome_state']:'unknown_after_transport'
                ];
            };
            $result=GrowthG8StagingWorker::run(
                $db,$version,$scope,$prefix,[
                    'loadFresh'=>$load,
                    'locallyEligible'=>$local,
                    'contactEligibility'=>$contact,
                    'recordDloStatus'=>$dlo,
                    'parentDloVerified'=>$parent,
                    'postOnce'=>$post
                ]
            );
            unset($cloud,$token,$origin,$descriptor,$resolved);
            return $result;
        } catch (Throwable $ignored) {
            return self::report('staging_dispatch_failed_closed');
        }
    }

    private static function nativeSnapshot($c,$ns,$shop)
    {
        try {
            if (!is_array($c)
                || !isset($c['shop'],$c['type'],$c['id'],$c['sequence'])
                || $c['shop']!==$shop
                || !is_int($c['id']) || $c['id']<1
                || !is_int($c['sequence']) || $c['sequence']<1
                || !in_array($c['type'],['PSCustomer','PSOrder'],true)) {
                return null;
            }
            if ($c['type']==='PSCustomer') {
                $customer=new \Customer($c['id']);
                $draft=GrowthNativeCustomerOrderDraft::buildCustomer(
                    $ns,$shop,$customer
                );
                $json=$draft['customer_json'];
                $customerId=$c['id'];
            } else {
                $order=new \Order($c['id']);
                $customerId=isset($order->id_customer)
                    ?(int)$order->id_customer:0;
                if ($customerId<1)return null;
                $customer=new \Customer($customerId);
                $currency=new \Currency((int)$order->id_currency);
                $draft=GrowthNativeCustomerOrderDraft::buildPair(
                    $ns,$shop,$customer,$order,$currency
                );
                $json=$draft['order_json'];
            }
            $doc=json_decode($json,true);
            if (!is_array($doc) || !isset($doc['data'][0])
                || !is_array($doc['data'])
                || count($doc['data'])!==1
                || !is_array($doc['data'][0]))return null;
            $record=$doc['data'][0];
            $fingerprint=hash(
                'sha256',json_encode([$c['sequence'],$record],
                    JSON_UNESCAPED_SLASHES)
            );
            return [
                'shop'=>$shop,'type'=>$c['type'],'id'=>$c['id'],
                'sequence'=>$c['sequence'],'customer_id'=>$customerId,
                'fingerprint'=>$fingerprint,
                'payload_private'=>$record
            ];
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private static function localAllowed($shop,$customerId)
    {
        if (!is_int($customerId) || $customerId<1)return false;
        try {
            $result=GrowthLocalCustomerHoldReader::inspectPrestaShop(
                $shop,$customerId
            );
            // Local account/optin evidence is a conservative ingestion
            // filter, NOT a certified Salesforce marketing consent.
            return is_array($result)
                && isset($result['status'],$result['local_customer_source_state'])
                && $result['status']==='hold_for_review'
                && $result['local_customer_source_state']
                    ==='no_local_negative_observed';
        } catch (Throwable $ignored) {
            return false;
        }
    }

    private static function report($status)
    {
        return [
            'ok'=>false,'status'=>$status,'counts'=>null,
            'marketing_sends_authorized'=>false,
            'records_delivered_certified'=>false,
            'automatic_retry_authorized'=>false,
            'record_identifiers_exposed'=>false,
            'credentials_exposed'=>false
        ];
    }
    private function __construct(){}
}
