<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;

/**
 * Identity-scoped Data Cloud ingestion source for ONE PrestaShop shop.
 * No write to Salesforce: the owner must certify the existing PST v0.4
 * PSCustomer/PSOrder source before enabling a one-record POST.
 *
 * BC's historical source remains unchanged for ABH/BCABH. Other merchants
 * (e.g. DOABH) cannot borrow that source or use it by default.
 */
final class GrowthShopSourceBinding
{
    const CONFIG_KEY='PS_GROWTH_SOURCE_V1';
    const BC_SOURCE='ABH_PrestaShop_Data_Cloud';
    const ATTEST='CONFIRMO_ORIGEN_Y_ESQUEMA_DATA_CLOUD';

    public static function isMerchant($merchant)
    {
        return is_string($merchant)
            && preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D',$merchant)===1;
    }

    public static function isNamespace($namespace)
    {
        return is_string($namespace)
            && preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$namespace)===1;
    }

    public static function isSource($source)
    {
        return is_string($source)
            && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,95}$/D',$source)===1;
    }

    private static function isBc($merchant)
    {
        return $merchant==='ABH' || $merchant==='BCABH';
    }

    /** Returns safe public configuration status, never customer data. */
    public static function inspect($shop,$merchant,$namespace)
    {
        if (!is_int($shop) || $shop<1
            || !self::isMerchant($merchant) || !self::isNamespace($namespace)) {
            return ['ready'=>false,'source'=>'','status'=>'identity_missing'];
        }
        $stored=\Configuration::get(self::CONFIG_KEY,null,null,$shop);
        if ($stored===false || $stored===null || $stored==='') {
            if (self::isBc($merchant)) {
                return ['ready'=>true,'source'=>self::BC_SOURCE,
                    'status'=>'bc_legacy_source'];
            }
            return ['ready'=>false,'source'=>'','status'=>'source_missing'];
        }
        if (!is_string($stored) || strlen($stored)>512) {
            return ['ready'=>false,'source'=>'','status'=>'source_invalid'];
        }
        $row=json_decode($stored,true);
        if (!is_array($row) || !isset(
            $row['merchant'],$row['namespace'],$row['source'],$row['attested']
        ) || $row['merchant']!==$merchant
            || $row['namespace']!==$namespace
            || $row['attested']!==true
            || !self::isSource($row['source'])
            || (self::isBc($merchant) && $row['source']!==self::BC_SOURCE)
            || (!self::isBc($merchant) && $row['source']===self::BC_SOURCE)) {
            return ['ready'=>false,'source'=>'','status'=>'source_invalid'];
        }
        return ['ready'=>true,'source'=>$row['source'],'status'=>'source_declared'];
    }

    public static function bind($shop,$merchant,$namespace,$source,$confirmation)
    {
        if (!is_int($shop) || $shop<1
            || !self::isMerchant($merchant) || !self::isNamespace($namespace)
            || !self::isSource($source)
            || $confirmation!==self::ATTEST) {
            throw new RuntimeException('growth_source_binding_invalid');
        }
        if ((self::isBc($merchant) && $source!==self::BC_SOURCE)
            || (!self::isBc($merchant) && $source===self::BC_SOURCE)) {
            throw new RuntimeException('growth_source_cross_merchant');
        }
        $existing=self::inspect($shop,$merchant,$namespace);
        if ($existing['ready']) {
            // Idempotence is safe but source migration is NOT.
            if ($existing['source']!==$source) {
                throw new RuntimeException('growth_source_immutable');
            }
            return true;
        }
        if ($existing['status']==='source_invalid') {
            throw new RuntimeException('growth_source_existing_invalid');
        }
        // A declared source is a HUMAN attestation, not live Data Cloud
        // schema verification. The next POST still requires explicit consent.
        $serialized=json_encode([
            'merchant'=>$merchant,'namespace'=>$namespace,
            'source'=>$source,'attested'=>true
        ],JSON_UNESCAPED_SLASHES);
        if (!is_string($serialized)
            || !\Configuration::updateValue(
                self::CONFIG_KEY,$serialized,false,null,$shop
            )) {
            throw new RuntimeException('growth_source_store_failed');
        }
        return true;
    }

    public static function sourceForSend($shop,$merchant,$namespace)
    {
        $state=self::inspect($shop,$merchant,$namespace);
        if (!$state['ready']) {
            throw new RuntimeException('growth_source_unverified_for_send');
        }
        return $state['source'];
    }

    private function __construct(){}
}
