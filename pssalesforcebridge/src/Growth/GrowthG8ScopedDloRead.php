<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8DloPresenceReader;
use Throwable;

/**
 * Read-only tenant/shop-specific bridge to the existing G8 Query V2 reader.
 *
 * The descriptor MUST be built privately from freshly resolved metadata
 * returned by GrowthG8DloMetadataResolver for this SAME token and origin.
 * Never cache or reuse BC's DLO API names for another merchant. It cannot
 * authorize dispatch, reserve a lease, or change consent.
 */
final class GrowthG8ScopedDloRead
{
    public static function inspect(
        array $descriptor,$origin,$accessToken,
        $shop,$namespace,$object,$id,$http=null
    ) {
        $evidence=self::inspectWithEvidence(
            $descriptor,$origin,$accessToken,
            $shop,$namespace,$object,$id,$http
        );
        return $evidence['result'];
    }

    /**
     * Same strict scoped SELECT, exposing ONLY non-identifying reason enums
     * to the protected Back Office read-only presence diagnostic. Worker
     * callers still receive only the original three-state value above.
     */
    public static function inspectWithEvidence(
        array $descriptor,$origin,$accessToken,
        $shop,$namespace,$object,$id,$http=null
    ) {
        try {
            if (!is_int($shop) || $shop<1
                || !GrowthShopSourceBinding::isNamespace($namespace)
                || preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )!==1
                || !in_array($object,['PSCustomer','PSOrder'],true)
                || !is_int($id) || $id<1
                || !isset(
                    $descriptor['shop'],$descriptor['merchant'],
                    $descriptor['namespace'],$descriptor['source'],
                    $descriptor['origin'],$descriptor['resolved']
                )
                || $descriptor['shop']!==$shop
                || $descriptor['namespace']!==$namespace
                || $descriptor['origin']!==$origin
                || !GrowthShopSourceBinding::isMerchant(
                    $descriptor['merchant']
                )
                || !GrowthShopSourceBinding::isSource(
                    $descriptor['source']
                )
                || !class_exists('Configuration')
                || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
                || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')
                    !=='off'
                || (string)\Configuration::get('PS_GROWTH_MERCHANT')
                    !==$descriptor['merchant']
                || (string)\Configuration::get('PS_GROWTH_NAMESPACE')
                    !==$namespace
                || !is_array($descriptor['resolved'])
                || count($descriptor['resolved'])!==2
                || !isset(
                    $descriptor['resolved']['PSCustomer'],
                    $descriptor['resolved']['PSOrder']
                )
                || ($http!==null && !is_callable($http))) {
                return self::evidence('unknown','scope_unverified');
            }
            $binding=GrowthShopSourceBinding::inspect(
                $shop,$descriptor['merchant'],$namespace
            );
            if (!isset($binding['ready'],$binding['source'])
                || $binding['ready']!==true
                || $binding['source']!==$descriptor['source']) {
                return self::evidence('unknown','scope_unverified');
            }
            $entry=$descriptor['resolved'][$object];
            if (!is_array($entry) || count($entry)
                !==($object==='PSOrder'?3:2)
                || !isset($entry['dlo'],$entry['key'])
                || ($object==='PSOrder'
                    && (!isset($entry['customer_key'])
                        || $entry['customer_key']===$entry['key']))) {
                return self::evidence('unknown','scope_unverified');
            }
            // All SQL identifier and domain checks remain enforced by
            // GrowthG8DloPresenceReader. No raw rows escape this adapter.
            $evidence=GrowthG8DloPresenceReader::inspectWithEvidence(
                $origin,$accessToken,$namespace,$shop,$object,$id,
                $entry['dlo'],$entry['key'],$http
            );
            if (!is_array($evidence)
                || !isset($evidence['result'],$evidence['reason'])
                || !in_array($evidence['result'],
                    ['present','absent_confirmed','unknown'],true)
                || !is_string($evidence['reason'])
                || !self::safeReason($evidence['reason'])) {
                return self::evidence('unknown','query_evidence_unverified');
            }
            return $evidence;
        } catch (Throwable $ignored) {
            return self::evidence('unknown','scope_exception');
        }
    }

    private static function evidence($result,$reason)
    {
        return ['result'=>$result,'reason'=>$reason];
    }

    /** Fixed, source-independent codes only. Never echo server strings. */
    private static function safeReason($reason)
    {
        if (in_array($reason,[
            'request_invalid','transport_unverified',
            'response_unverified','json_unverified',
            'batch_unverified','row_not_object',
            'row_projection_unverified','row_value_not_string',
            'row_key_not_exact','transport_exception',
            'complete_empty','exact_match'
        ],true))return true;
        return in_array($reason,[
            'http_status_0','http_status_400',
            'http_status_401','http_status_403',
            'http_status_404','http_status_405',
            'http_status_408','http_status_422',
            'http_status_429','http_status_500',
            'http_status_502','http_status_503',
            'http_status_504','http_status_other'
        ],true);
    }

    private function __construct(){}
}
