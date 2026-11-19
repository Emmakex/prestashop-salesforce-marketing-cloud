<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthDataCloudUpsertTransport;
use RuntimeException;
use Throwable;

/**
 * One single, explicitly owner-approved REAL Data Cloud ingestion smoke.
 *
 * This is NOT a bulk dispatcher, cart recovery or marketing sender.
 * It consumes one irreversible attempt slot for each object (PSCustomer
 * then PSOrder) on each installation/shop, including on HTTP timeout.
 * No automatic retries, DLO/DMO claims or reset action. No extra DB table.
 *
 * Auth callback fetches an existing Salesforce Core -> Data Cloud token
 * from server-side credentials. These secrets are NEVER form inputs.
 */
final class GrowthOneRecordManualSender
{
    const APPROVAL='I_APPROVE_ONE_REAL_DATA_CLOUD_RECORD';
    const CUSTOMER_DLO_ATTESTATION='CUSTOMER_DLO_CONFIRMED_IN_SALESFORCE';

    public static function dispatch(
        $db,$namespace,$merchant,$shop,$object,$approval,$dloAttestation,
        $prepareRecords,$getToken,$sendHttp=null
    ) {
        if (!is_object($db) || !is_callable([$db,'getValue'])
            || !is_callable($prepareRecords) || !is_callable($getToken)
            || ($sendHttp!==null && !is_callable($sendHttp))
            || !is_string($namespace)
            || !preg_match('/^[a-z][a-z0-9_]{5,31}$/D',$namespace)
            || !is_string($merchant)
            || !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D',$merchant)
            || !is_int($shop) || $shop<1
            || !in_array($object,['PSCustomer','PSOrder'],true)
            || $approval!==self::APPROVAL) {
            throw new RuntimeException('g7_one_send_scope_or_approval_denied');
        }
        self::assertConfigured($merchant,$namespace);
        $key=self::key($namespace,$shop,$object);
        $customerKey=self::key($namespace,$shop,'PSCustomer');
        if ($object==='PSOrder'
            && ($dloAttestation!==self::CUSTOMER_DLO_ATTESTATION
                || strpos((string)\Configuration::get($customerKey),
                    'accepted_unconfirmed_202:')!==0)) {
            throw new RuntimeException('g7_one_send_customer_dlo_review_required');
        }
        if (\Configuration::get($key)!==false
            && (string)\Configuration::get($key)!=='') {
            throw new RuntimeException('g7_one_send_already_attempted');
        }

        // An auth failure before reservation creates NO data-ingest attempt.
        $token=call_user_func($getToken);
        if (!is_array($token)
            || !isset($token['instance_url'],$token['access_token'])
            || !is_string($token['instance_url'])
            || !is_string($token['access_token'])
            || $token['access_token']==='') {
            throw new RuntimeException('g7_one_send_oauth_unavailable');
        }
        // Prevent two BO clicks from sending the same record concurrently.
        $lock=hash('sha256','growth-manual-one:'.$namespace.':'.$shop.':'.$object);
        if ((string)$db->getValue("SELECT GET_LOCK('$lock', 3)",false)!=='1') {
            throw new RuntimeException('g7_one_send_lock_unavailable');
        }
        try {
            self::assertConfigured($merchant,$namespace);
            if (\Configuration::get($key)!==false
                && (string)\Configuration::get($key)!=='') {
                throw new RuntimeException('g7_one_send_already_attempted');
            }
            if ($object==='PSOrder'
                && (strpos((string)\Configuration::get($customerKey),
                        'accepted_unconfirmed_202:')!==0
                    || $dloAttestation!==self::CUSTOMER_DLO_ATTESTATION)) {
                throw new RuntimeException('g7_one_send_customer_dlo_review_required');
            }
            // Fresh native read performed INSIDE the single-record lock.
            $prepared=call_user_func($prepareRecords);
            if (!is_array($prepared)
                || !isset($prepared['customer_record_private'],
                    $prepared['order_record_private'])
                || $prepared['customer_order_relation_verified']!==true) {
                throw new RuntimeException('g7_one_send_native_source_unverified');
            }
            $record=$object==='PSCustomer'
                ?$prepared['customer_record_private']
                :$prepared['order_record_private'];
            if (!is_array($record)
                || $record['source_namespace']!==$namespace
                || $record['shop_id']!==$shop) {
                throw new RuntimeException('g7_one_send_record_scope_unverified');
            }
            // Tie the order to the EXACT customer already accepted: never
            // borrow a different contact's successful 202 confirmation.
            $secret=(string)\Configuration::get('PS_SFMC_CRON_TOKEN');
            $sourceKey=$prepared['customer_record_private']['source_key'];
            if (strlen($secret)<40 || !is_string($sourceKey)
                || !preg_match('/^[a-z0-9_]+:[0-9]+:customer:[0-9]+$/D',$sourceKey)) {
                throw new RuntimeException('g7_one_send_customer_binding_unverified');
            }
            $customerReceipt='accepted_unconfirmed_202:'
                .hash_hmac('sha256',$sourceKey,$secret);
            if ($object==='PSOrder' && !hash_equals($customerReceipt,
                (string)\Configuration::get($customerKey))) {
                throw new RuntimeException('g7_one_send_wrong_customer_for_order');
            }
            // Persist BEFORE any external POST: failures/timeout never
            // cause an automatic replay of a potentially accepted request.
            if (!\Configuration::updateValue($key,'attempt_reserved')) {
                throw new RuntimeException('g7_one_send_reservation_failed');
            }
            $report=GrowthDataCloudUpsertTransport::sendIfAuthorized(
                $token['instance_url'],'ABH_PrestaShop_Data_Cloud',
                $object,[$record],$namespace,$shop,$token['access_token'],
                function($checkNamespace,$checkShop,$checkObject)
                    use($namespace,$shop,$object,$key,$merchant) {
                    self::assertConfigured($merchant,$namespace);
                    return $checkNamespace===$namespace
                        && $checkShop===$shop
                        && $checkObject===$object
                        && (string)\Configuration::get($key)==='attempt_reserved';
                },
                $sendHttp
            );
            $accepted=$report['outcome_state']
                ==='accepted_asynchronously_unconfirmed';
            // Configuration failure leaves attempt_reserved: also fail closed.
            if (!\Configuration::updateValue(
                $key,$accepted
                    ?($object==='PSCustomer'?$customerReceipt:'accepted_unconfirmed_202')
                    :'held_reconcile'
            )) {
                throw new RuntimeException('g7_one_send_result_persist_unverified');
            }
            return [
                'status'=>$accepted
                    ?'one_record_accepted_unconfirmed_manual_dlo_check'
                    :'one_record_unverified_manual_reconciliation_required',
                'outcome_state'=>$report['outcome_state'],
                'record_persisted_in_salesforce_certified'=>false,
                'dlo_dmo_verified'=>false,
                'retry_authorized'=>false,
                'marketing_sends_authorized'=>false,
                'new_queue_created'=>false
            ];
        } catch (Throwable $error) {
            // Leave attempt_reserved untouched after any unknown outcome.
            // Do not return exception messages: network/auth can contain PII.
            if ((string)\Configuration::get($key)==='attempt_reserved') {
                \Configuration::updateValue($key,'held_reconcile');
            }
            throw $error;
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('$lock')",false);
            unset($token);
        }
    }

    private static function assertConfigured($merchant,$namespace)
    {
        if (!class_exists('Configuration')
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace) {
            throw new RuntimeException('g7_one_send_test_mode_gate_denied');
        }
    }

    private static function key($namespace,$shop,$object)
    {
        return 'PS_GR_ONE_'.substr(hash('sha256',
            $namespace.':'.$shop.':'.$object),0,24);
    }

    private function __construct(){}
}
