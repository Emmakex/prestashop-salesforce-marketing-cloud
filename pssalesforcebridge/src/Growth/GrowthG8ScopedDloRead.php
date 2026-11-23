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
                return 'unknown';
            }
            $binding=GrowthShopSourceBinding::inspect(
                $shop,$descriptor['merchant'],$namespace
            );
            if (!isset($binding['ready'],$binding['source'])
                || $binding['ready']!==true
                || $binding['source']!==$descriptor['source']) {
                return 'unknown';
            }
            $entry=$descriptor['resolved'][$object];
            if (!is_array($entry) || count($entry)
                !==($object==='PSOrder'?3:2)
                || !isset($entry['dlo'],$entry['key'])
                || ($object==='PSOrder'
                    && (!isset($entry['customer_key'])
                        || $entry['customer_key']===$entry['key']))) {
                return 'unknown';
            }
            // All SQL identifier and domain checks remain enforced by
            // GrowthG8DloPresenceReader. No raw rows escape this adapter.
            return GrowthG8DloPresenceReader::inspect(
                $origin,$accessToken,$namespace,$shop,$object,$id,
                $entry['dlo'],$entry['key'],$http
            );
        } catch (Throwable $ignored) {
            return 'unknown';
        }
    }
    private function __construct(){}
}
