<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;
use Throwable;

/**
 * G8 read-only Salesforce Data Cloud DLO presence check (Query API V2).
 *
 * SQL POST is a read-only SELECT, not an ingestion POST. It is NOT connected
 * to the shop cron, hooks or automatic worker. The exact DLO API name + its
 * source-key field name must be verified from the tenant before wiring.
 *
 * Return ONLY: present | absent_confirmed | unknown.
 * Unknown includes HTTP/JSON/SQL errors, missing metadata, partial/paged
 * responses, conflicting or unexpected data, and transport timeouts.
 * Never reveal returned rows, SQL, key, names, emails, tokens or host.
 */
final class GrowthG8DloPresenceReader
{
    const MAX_RESPONSE_BYTES=65536;

    /**
     * @param callable|null $http function($url,$jsonBody,$accessToken)
     *   Expected: ['status'=>int,'body'=>string].
     */
    public static function inspect(
        $origin,$accessToken,$namespace,$shop,$object,$id,
        $dloApiName,$sourceKeyField,$http=null
    ) {
        try {
            $url=self::queryUrl($origin);
            if (!is_string($accessToken) || $accessToken===''
                || strlen($accessToken)>8192
                || preg_match('/[\x00-\x20\x7f]/',$accessToken)
                || !is_string($namespace)
                || preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$namespace)!==1
                || !is_int($shop) || $shop<1
                || !is_int($id) || $id<1
                || !in_array($object,['PSCustomer','PSOrder'],true)
                || !self::identifier($dloApiName)
                || !self::identifier($sourceKeyField)
                || ($http!==null && !is_callable($http))) {
                return 'unknown';
            }
            $key=$namespace.':'.$shop.':'
                .($object==='PSCustomer'?'customer':'order').':'.$id;
            // Table and field are restricted SQL identifiers. The literal
            // key consists exclusively of validated components.
            $q='SELECT "'.$sourceKeyField.'" FROM "'.$dloApiName
                .'" WHERE "'.$sourceKeyField.'" = '."'".$key."'"
                .' LIMIT 2';
            $json=json_encode(['sql'=>$q],JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || strlen($json)>1024) {
                return 'unknown';
            }
            $response=$http===null
                ?self::execute($url,$json,$accessToken)
                :call_user_func($http,$url,$json,$accessToken);
            if (!is_array($response)
                || !isset($response['status'],$response['body'])
                || $response['status']!==200
                || !is_string($response['body'])
                || strlen($response['body'])>self::MAX_RESPONSE_BYTES) {
                return 'unknown';
            }
            $data=json_decode($response['body'],true);
            if (!is_array($data)
                || !isset($data['done'],$data['rowCount'],$data['data'])
                || $data['done']!==true
                || !is_int($data['rowCount']) || $data['rowCount']<0
                || $data['rowCount']>2 || !is_array($data['data'])
                || count($data['data'])!==$data['rowCount']
                || (isset($data['nextBatchId']) && $data['nextBatchId']!=='')
            ) {
                return 'unknown';
            }
            // Even when the rowCount is zero, absence is accepted only
            // from a completed HTTP 200 Query API V2 response.
            if ($data['rowCount']===0) {
                return 'absent_confirmed';
            }
            foreach ($data['data'] as $row) {
                if (!is_array($row)
                    || !array_key_exists($sourceKeyField,$row)
                    || !is_string($row[$sourceKeyField])
                    || !hash_equals($key,$row[$sourceKeyField])) {
                    return 'unknown';
                }
            }
            return 'present';
        } catch (Throwable $ignored) {
            return 'unknown';
        }
    }

    private static function identifier($name)
    {
        return is_string($name) && strlen($name)<=96
            && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,95}$/D',$name)===1;
    }

    private static function queryUrl($origin)
    {
        if (!is_string($origin) || strlen($origin)>253) {
            throw new RuntimeException('g8_dlo_origin_invalid');
        }
        $parsed=parse_url($origin);
        if (!is_array($parsed) || !isset($parsed['scheme'],$parsed['host'])
            || strtolower($parsed['scheme'])!=='https'
            || isset($parsed['user']) || isset($parsed['pass'])
            || isset($parsed['port']) || isset($parsed['query'])
            || isset($parsed['fragment'])
            || (isset($parsed['path'])
                && !in_array($parsed['path'],['','/'],true))
            || preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$/Di',
                $parsed['host']
            )!==1) {
            throw new RuntimeException('g8_dlo_origin_invalid');
        }
        return 'https://'.strtolower($parsed['host']).'/api/v2/query';
    }

    /** Never log SQL, response body or token; HTTPS/TLS and no redirects. */
    private static function execute($url,$body,$token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g8_dlo_transport_unavailable');
        }
        $ch=curl_init($url);
        if ($ch===false) {
            throw new RuntimeException('g8_dlo_transport_unavailable');
        }
        $answer='';
        $overflow=false;
        try {
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$body,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HTTPHEADER=>[
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$token
                ],
                CURLOPT_CONNECTTIMEOUT=>8,
                CURLOPT_TIMEOUT=>15,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>function($handle,$chunk)
                    use (&$answer,&$overflow) {
                    $len=strlen($chunk);
                    if (strlen($answer)+$len>self::MAX_RESPONSE_BYTES) {
                        $overflow=true;
                        return 0;
                    }
                    $answer.=$chunk;
                    return $len;
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
            }
            $ok=curl_exec($ch);
            $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            return [
                'status'=>$ok!==false && !$overflow?$code:0,
                'body'=>$overflow?'':$answer
            ];
        } finally {
            curl_close($ch);
        }
    }

    private function __construct(){}
}
