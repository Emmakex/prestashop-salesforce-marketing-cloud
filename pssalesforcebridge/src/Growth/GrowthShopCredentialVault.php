<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;
use RuntimeException;
use Throwable;

/**
 * Per-shop Core OAuth vault for Marketing Cloud Growth + Data Cloud.
 *
 * One authenticated AES-256-GCM envelope in the existing PrestaShop
 * Configuration table. Key material is derived per shop from _COOKIE_KEY_,
 * which lives in the shop server configuration, NOT in the database.
 * Database copies alone cannot decrypt it. Restoring to a different server
 * requires the original _COOKIE_KEY_. No new table, no plaintext fallbacks.
 *
 * Passwords/tokens NEVER enter Smarty, flash messages, logs or audit rows.
 * No outbound records/marketing/campaigns; the connection test performs
 * only the two OAuth token requests.
 */
final class GrowthShopCredentialVault
{
    const CONFIG_KEY='PS_GROWTH_CORE_OAUTH_V1';
    const DELETE_CONFIRM='ELIMINAR_CONEXION_SALESFORCE';

    /** One transactional configuration value per exact shop, no global fallback. */
    public static function save($shop,$orgUrl,$clientId,$clientSecret)
    {
        self::validateShop($shop);
        if (!is_string($orgUrl) || strlen($orgUrl)>253
            || !is_string($clientId) || $clientId==='' || strlen($clientId)>512
            || !preg_match('/^[A-Za-z0-9._-]+$/D',$clientId)
            || !is_string($clientSecret) || $clientSecret==='' || strlen($clientSecret)>4096
            || preg_match('/[\x00-\x1f\x7f]/',$clientSecret)) {
            throw new RuntimeException('growth_vault_input_invalid');
        }
        // Reuse canonical Salesforce-owned My Domain validation; never
        // accept a URL that could send client_secret to another host.
        new AuthClient($orgUrl,$clientId,$clientSecret);
        $host=parse_url($orgUrl,PHP_URL_HOST);
        if (!is_string($host) || $host==='') {
            throw new RuntimeException('growth_vault_org_invalid');
        }
        $data=json_encode([
            'org_url'=>'https://'.strtolower($host),
            'client_id'=>$clientId,
            'client_secret'=>$clientSecret,
        ],JSON_UNESCAPED_SLASHES);
        if (!is_string($data) || strlen($data)>6144) {
            throw new RuntimeException('growth_vault_encode_invalid');
        }
        $key=self::deriveKey($shop);
        if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) {
            throw new RuntimeException('growth_vault_crypto_unavailable');
        }
        $nonce=random_bytes(12);
        $tag='';
        $payload=openssl_encrypt($data,'aes-256-gcm',$key,OPENSSL_RAW_DATA,
            $nonce,$tag,self::aad($shop),16);
        if (!is_string($payload) || strlen($tag)!==16) {
            throw new RuntimeException('growth_vault_encrypt_failed');
        }
        $envelope='gcm1:'.base64_encode($nonce.$tag.$payload);
        // updateValue writes at the exact PrestaShop shop scope, never global.
        // One value prevents partial field rotation and mixed token pairs.
        if (!\Configuration::updateValue(self::CONFIG_KEY,$envelope,false,null,$shop)) {
            throw new RuntimeException('growth_vault_store_failed');
        }
        unset($data,$key,$nonce,$tag,$payload,$envelope,$clientSecret);
        return true;
    }

    /** Returns only safe BO status, not client ID, org name or credentials. */
    public static function inspect($shop)
    {
        try {
            $data=self::read($shop);
            unset($data);
            return ['status'=>'configured','ready'=>true];
        } catch (Throwable $e) {
            return [
                'status'=>$e->getMessage()==='growth_vault_missing'
                    ?'not_configured':'unavailable',
                'ready'=>false,
            ];
        }
    }

    /** Irreversible single-shop credential revocation; no marketing events. */
    public static function remove($shop,$confirmation)
    {
        self::validateShop($shop);
        if ($confirmation!==self::DELETE_CONFIRM) {
            throw new RuntimeException('growth_vault_delete_confirmation_required');
        }
        // Overwrite just this shop's envelope with an explicit tombstone.
        // deleteByName could erase the credentials of all other shops.
        if (!\Configuration::updateValue(
            self::CONFIG_KEY,'revoked:v1',false,null,$shop
        )) {
            throw new RuntimeException('growth_vault_delete_failed');
        }
        return true;
    }

    /**
     * Only OAuth Core + OAuth Data Cloud, no data ingestion or campaigning.
     * Callables are injected in offline tests; default runs real TLS OAuth.
     */
    public static function testConnection($shop,$coreAuth=null,$cloudExchange=null)
    {
        $token=self::obtainCloudToken($shop,$coreAuth,$cloudExchange);
        unset($token);
        return [
            'status'=>'core_and_data_cloud_oauth_verified',
            'core_oauth'=>true,
            'data_cloud_token_exchange'=>true,
            'records_sent'=>0,
            'marketing_sends_authorized'=>false,
        ];
    }

    /**
     * Private server-side credentials -> short-lived Data Cloud token.
     * Caller MUST NOT log, cache, store or return this token to the browser.
     */
    public static function obtainCloudToken($shop,$coreAuth=null,$cloudExchange=null)
    {
        $data=self::read($shop);
        try {
            if ($coreAuth!==null && !is_callable($coreAuth)) {
                throw new RuntimeException('growth_vault_core_callback_invalid');
            }
            if ($cloudExchange!==null && !is_callable($cloudExchange)) {
                throw new RuntimeException('growth_vault_cloud_callback_invalid');
            }
            try {
                $core=$coreAuth===null
                    ?(new AuthClient($data['org_url'],$data['client_id'],
                        $data['client_secret']))->requestAccessToken()
                    :call_user_func($coreAuth,$data['org_url'],
                        $data['client_id'],$data['client_secret']);
            } catch (Throwable $ignored) {
                throw new RuntimeException('growth_vault_core_auth_failed');
            }
            if (!is_array($core) || !isset($core['instance_url'],$core['access_token'])
                || !is_string($core['instance_url'])
                || !is_string($core['access_token']) || $core['access_token']==='') {
                throw new RuntimeException('growth_vault_core_auth_failed');
            }
            try {
                $cloud=$cloudExchange===null
                    ?(new TokenExchangeClient())->exchange(
                        $core['instance_url'],$core['access_token'])
                    :call_user_func($cloudExchange,$core['instance_url'],
                        $core['access_token']);
            } catch (Throwable $ignored) {
                throw new RuntimeException('growth_vault_cloud_exchange_failed');
            }
            unset($core);
            if (!is_array($cloud)
                || !isset($cloud['instance_url'],$cloud['access_token'])
                || !is_string($cloud['instance_url'])
                || !is_string($cloud['access_token'])
                || $cloud['access_token']==='') {
                throw new RuntimeException('growth_vault_cloud_exchange_failed');
            }
            // Even for an injected transport, restrict the returned
            // Data Cloud host to Salesforce-owned c360a to prevent SSRF.
            $url=$cloud['instance_url'];
            if (!preg_match(
                '~^https://(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com/?$~Di',
                $url
            )) {
                throw new RuntimeException('growth_vault_cloud_exchange_failed');
            }
            return [
                'instance_url'=>rtrim($url,'/'),
                'access_token'=>$cloud['access_token'],
            ];
        } finally {
            unset($data);
        }
    }

    private static function read($shop)
    {
        self::validateShop($shop);
        $encoded=\Configuration::get(self::CONFIG_KEY,null,null,$shop);
        if ($encoded===false || $encoded===null || $encoded==='' || $encoded==='revoked:v1') {
            throw new RuntimeException('growth_vault_missing');
        }
        if (!is_string($encoded) || strlen($encoded)>16384
            || strpos($encoded,'gcm1:')!==0) {
            throw new RuntimeException('growth_vault_format_invalid');
        }
        $wire=base64_decode(substr($encoded,5),true);
        if (!is_string($wire) || strlen($wire)<29) {
            throw new RuntimeException('growth_vault_format_invalid');
        }
        $iv=substr($wire,0,12);
        $tag=substr($wire,12,16);
        $body=substr($wire,28);
        $key=self::deriveKey($shop);
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('growth_vault_crypto_unavailable');
        }
        $raw=openssl_decrypt($body,'aes-256-gcm',$key,OPENSSL_RAW_DATA,
            $iv,$tag,self::aad($shop));
        if (!is_string($raw)) {
            throw new RuntimeException('growth_vault_authentication_failed');
        }
        $decoded=json_decode($raw,true);
        unset($wire,$iv,$tag,$body,$key,$raw);
        if (!is_array($decoded)
            || !isset($decoded['org_url'],$decoded['client_id'],
                $decoded['client_secret'])
            || !is_string($decoded['org_url'])
            || !is_string($decoded['client_id'])
            || !is_string($decoded['client_secret'])
            || $decoded['client_id']==='' || $decoded['client_secret']==='') {
            throw new RuntimeException('growth_vault_payload_invalid');
        }
        // Refuse corrupted or migrated content even if its GCM tag passes.
        new AuthClient($decoded['org_url'],$decoded['client_id'],
            $decoded['client_secret']);
        return $decoded;
    }

    private static function deriveKey($shop)
    {
        if (!defined('_COOKIE_KEY_') || !is_string(_COOKIE_KEY_)
            || strlen(_COOKIE_KEY_)<32) {
            throw new RuntimeException('growth_vault_server_key_unavailable');
        }
        return hash_hmac('sha256',
            'pssalesforcebridge:growth:oauth:v1:shop:'.$shop,
            _COOKIE_KEY_,true);
    }

    private static function aad($shop)
    {
        return 'pssalesforcebridge:growth-only:shop:'.$shop.':v1';
    }

    private static function validateShop($shop)
    {
        if (!is_int($shop) || $shop<1 || $shop>2147483647) {
            throw new RuntimeException('growth_vault_shop_scope_invalid');
        }
    }

    private function __construct() {}
}
