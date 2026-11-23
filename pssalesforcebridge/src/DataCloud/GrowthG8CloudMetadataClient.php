<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;
use Throwable;

/**
 * G8 production-capable READ-ONLY tenant metadata resolver.
 * Reuses the exact endpoints and pure resolver certified by G8 CI.
 * No fallback names, redirects, cookies, logs, PII or credentials persisted.
 */
final class GrowthG8CloudMetadataClient
{
    const MAX_BYTES=2097152;

    public static function resolve($origin,$token,$fetch=null)
    {
        if (!self::validOrigin($origin) || !is_string($token)
            || $token==='' || strlen($token)>8192
            || preg_match('/[\x00-\x20\x7f]/',$token)
            || ($fetch!==null && !is_callable($fetch))) {
            throw new RuntimeException('g8_metadata_client_scope_invalid');
        }
        try {
            $call=$fetch===null
                ?function($path)use($origin,$token) {
                    return self::get($origin,$token,$path);
                }
                :$fetch;
            $catalog=call_user_func(
                $call,'/api/v1/metadataEntities?entityType=DataLakeObject'
            );
            return GrowthG8DloMetadataResolver::resolve(
                $catalog,
                function($name)use($call) {
                    if (!is_string($name)
                        || preg_match(
                            '/^[A-Za-z][A-Za-z0-9_]{2,95}$/D',$name
                        )!==1) {
                        throw new RuntimeException('g8_metadata_name_invalid');
                    }
                    return call_user_func(
                        $call,'/api/v1/metadata?entityType=DataLakeObject'
                            .'&entityName='.$name
                    );
                }
            );
        } catch (Throwable $ignored) {
            throw new RuntimeException('g8_metadata_unverified');
        }
    }

    private static function get($origin,$token,$path)
    {
        if ($path!=='/api/v1/metadataEntities?entityType=DataLakeObject'
            && preg_match(
                '~^/api/v1/metadata\?entityType=DataLakeObject&entityName=[A-Za-z][A-Za-z0-9_]{2,95}$~D',
                $path
            )!==1) {
            throw new RuntimeException('g8_metadata_path_denied');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g8_metadata_transport_unavailable');
        }
        $ch=curl_init($origin.$path);
        if ($ch===false) {
            throw new RuntimeException('g8_metadata_transport_unavailable');
        }
        $body='';
        try {
            $options=[
                CURLOPT_HTTPGET=>true,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HTTPHEADER=>[
                    'Authorization: Bearer '.$token,
                    'Accept: application/json'
                ],
                CURLOPT_CONNECTTIMEOUT=>8,
                CURLOPT_TIMEOUT=>25,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>function($handle,$chunk)
                    use(&$body) {
                    $len=strlen($chunk);
                    if (strlen($body)+$len>self::MAX_BYTES)return 0;
                    $body.=$chunk;
                    return $len;
                }
            ];
            if (defined('CURLOPT_PROTOCOLS')
                && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch,$options);
            $ok=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            if ($ok===false || $status!==200) {
                throw new RuntimeException('g8_metadata_http_unverified');
            }
            $result=json_decode($body,true);
            if (!is_array($result)) {
                throw new RuntimeException('g8_metadata_json_unverified');
            }
            return $result;
        } finally {
            curl_close($ch);
        }
    }

    private static function validOrigin($origin)
    {
        return is_string($origin)
            && strlen($origin)<=253
            && preg_match(
                '~^https://(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$~Di',
                $origin
            )===1;
    }

    private function __construct(){}
}
