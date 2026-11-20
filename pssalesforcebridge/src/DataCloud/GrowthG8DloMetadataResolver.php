<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;
use Throwable;

/**
 * Resolve the exact SQL API name + source-key field of the two existing PST
 * DLOs from Data Cloud metadata, not from display labels or a guessed suffix.
 *
 * This is a pure, no-network resolver. The caller provides the responses to
 * GET /api/v1/metadataEntities?entityType=DataLakeObject and two scoped GET
 * /api/v1/metadata/?entityType=DataLakeObject&entityName=... requests.
 *
 * Never logs or renders names; the result is for the private read-only
 * GrowthG8DloPresenceReader. No merchant source or consent is inferred here.
 */
final class GrowthG8DloMetadataResolver
{
    public static function resolve($entities,$metadataForEntity)
    {
        if (!is_array($entities)
            || !isset($entities['entities'],$entities['done'])
            || !is_array($entities['entities']) || $entities['done']!==true
            || (isset($entities['nextBatchId']) && $entities['nextBatchId']!=='')
            || count($entities['entities'])>10000
            || !is_callable($metadataForEntity)) {
            throw new RuntimeException('g8_dlo_metadata_catalog_unverified');
        }
        $customer=self::findExact($entities['entities'],'pstpscustomer');
        $order=self::findExact($entities['entities'],'pstpsorder');

        $customerMeta=call_user_func($metadataForEntity,$customer);
        $orderMeta=call_user_func($metadataForEntity,$order);
        $customerKey=self::exactField($customerMeta,$customer,'source_key');
        $orderKey=self::exactField($orderMeta,$order,'source_key');
        $orderParent=self::exactField(
            $orderMeta,$order,'customer_source_key'
        );
        if ($orderKey===$orderParent) {
            throw new RuntimeException('g8_dlo_metadata_keys_not_independent');
        }
        // Internal lookup result ONLY; never send to BO/cron/GitHub artifacts.
        return [
            'PSCustomer'=>[
                'dlo'=>$customer,'key'=>$customerKey
            ],
            'PSOrder'=>[
                'dlo'=>$order,'key'=>$orderKey,
                'customer_key'=>$orderParent
            ],
        ];
    }

    /**
     * Enforce exactly one DLO for each historical PST display label.
     * Data Cloud "entities" supplies its SQL/API name, not the label.
     */
    private static function findExact(array $entities,$normalizedLabel)
    {
        $matches=[];
        foreach ($entities as $item) {
            if (!is_array($item) || !isset($item['displayName'],
                $item['entityName'],$item['entityType'])
                || !is_string($item['displayName'])
                || !is_string($item['entityName'])
                || $item['entityType']!=='DataLakeObject') {
                continue;
            }
            $display=strtolower(preg_replace(
                '/[^a-z0-9]/i','',$item['displayName']
            ));
            if ($display===$normalizedLabel && self::sqlName(
                $item['entityName']
            )) {
                $matches[]=$item['entityName'];
            }
        }
        // Duplicates cannot be silently deduplicated: ambiguity is unsafe.
        if (count($matches)!==1) {
            throw new RuntimeException('g8_dlo_metadata_entity_ambiguous');
        }
        return $matches[0];
    }

    /** Resolve only the exact source key, never email, name, or full row. */
    private static function exactField($meta,$entity,$base)
    {
        if (!is_array($meta) || !isset($meta['metadata'])
            || !is_array($meta['metadata'])
            || count($meta['metadata'])!==1) {
            throw new RuntimeException('g8_dlo_metadata_detail_unverified');
        }
        $entry=$meta['metadata'][0];
        if (!isset($entry['name'],$entry['fields'])
            || $entry['name']!==$entity
            || !is_array($entry['fields'])
            || count($entry['fields'])>500) {
            throw new RuntimeException('g8_dlo_metadata_detail_unverified');
        }
        $matches=[];
        foreach ($entry['fields'] as $field) {
            if (!is_array($field) || !isset($field['name'])
                || !is_string($field['name']))continue;
            if (in_array(strtolower($field['name']),
                [$base,$base.'__c'],true)
                && self::sqlName($field['name'])) {
                $matches[]=$field['name'];
            }
        }
        if (count($matches)!==1) {
            throw new RuntimeException('g8_dlo_metadata_field_ambiguous');
        }
        return $matches[0];
    }

    private static function sqlName($str)
    {
        return is_string($str) && strlen($str)<=96
            && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,95}$/D',$str)===1;
    }

    private function __construct(){}
}
