<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Review;

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthStreamingIngestionPlan;
use RuntimeException;

/**
 * One fixed, non-marketable synthetic record in ABH's already deployed
 * PSCustomer/PSOrder schema. Separate from the previous /actions/test key.
 *
 * This class is outside the installable PrestaShop module and has no hooks,
 * cron, queue, access to PrestaShop DB, or campaign capability.
 *
 * The default is only a local plan. The actual POST can be invoked ONLY
 * from a protected manual main workflow with an exact confirmation. It is
 * a REAL asynchronous upsert into the tenant, not an isolated mock tenant.
 * A 202 is never evidence that the DLO/DMO record exists.
 */
final class G5ControlledSyntheticUpsert
{
    const SOURCE = 'ABH_PrestaShop_Data_Cloud';
    const NAMESPACE_VALUE = 'growth_g5_canary_20261117';
    const SHOP = 2;
    const ID = 987654321;
    const APPROVAL = 'I_APPROVE_SYNTHETIC_UPSERT';
    const CUSTOMER_MODE = 'customer_upsert_once';
    const ORDER_MODE = 'order_upsert_after_customer_verified';

    public static function prepare($cloudOrigin, $object)
    {
        if (!in_array($object, ['PSCustomer', 'PSOrder'], true)) {
            throw new RuntimeException('g5_canary_object_forbidden');
        }
        $scope = self::NAMESPACE_VALUE . ':' . self::SHOP . ':';
        $customerKey = $scope . 'customer:' . self::ID;
        if ($object === 'PSCustomer') {
            $row = [
                'source_key' => $customerKey,
                'source_namespace' => self::NAMESPACE_VALUE,
                'shop_id' => self::SHOP,
                'customer_id' => self::ID,
                'email' => 'g5-canary-20261117@example.invalid',
                'first_name' => 'SYNTHETIC',
                'last_name' => 'NO_SEND_CANARY',
                'newsletter_flag' => false,
                'partner_optin_flag' => false,
                'active_flag' => false,
                'source_updated_local' => '2026-11-17 09:00:00',
            ];
        } else {
            $row = [
                'source_key' => $scope . 'order:' . self::ID,
                'source_namespace' => self::NAMESPACE_VALUE,
                'shop_id' => self::SHOP,
                'order_id' => self::ID,
                'customer_source_key' => $customerKey,
                'cart_source_key' => '',
                'currency' => 'EUR',
                'total_paid' => 0.0,
                'paid_flag' => false,
                'state_id' => 0,
                'source_updated_local' => '2026-11-17 09:00:00',
            ];
        }

        $plan = GrowthStreamingIngestionPlan::prepare(
            $cloudOrigin, self::SOURCE, $object, [$row],
            self::NAMESPACE_VALUE, self::SHOP, 'marketing_cloud_growth', 'test'
        );
        if ($plan['status'] !== 'blocked_transport_plan_only'
            || $plan['count'] !== 1
            || $plan['dispatch_authorized'] !== false
            || $plan['marketing_sends_authorized'] !== false
            || substr($plan['url'], -13) === '/actions/test') {
            throw new RuntimeException('g5_canary_plan_not_strict');
        }
        return $plan;
    }

    /**
     * Tests can inject a one-call transport; a real run uses postOnce.
     * Returns sanitized counters and state only; no response body or PII.
     */
    public static function execute($cloudOrigin, $token, $object, $transport = null)
    {
        self::assertManualAuthorization($object);
        if (!is_string($token) || strlen($token) < 12
            || strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('g5_canary_token_invalid');
        }
        $plan = self::prepare($cloudOrigin, $object);
        if ($transport !== null && !is_callable($transport)) {
            throw new RuntimeException('g5_canary_transport_invalid');
        }
        $reply = $transport === null
            ? self::postOnce($plan['url'], $plan['body'], $token)
            : call_user_func($transport, $plan['url'], $plan['body'], $token);
        if (!is_array($reply) || !isset($reply['status'], $reply['received'])
            || !is_int($reply['status']) || !is_bool($reply['received'])) {
            throw new RuntimeException('g5_canary_transport_response_invalid');
        }
        $classification = GrowthStreamingIngestionPlan::classify(
            $reply['status'], $reply['received']
        );
        return [
            'object' => $object,
            'record_count' => 1,
            'upsert_attempted' => true,
            'http_status' => $reply['received'] ? $reply['status'] : null,
            'outcome_state' => $classification['outcome_state'],
            'accepted_asynchronously_unconfirmed' =>
                $classification['request_accepted_asynchronously'],
            'dlo_record_verified' => false,
            'dmo_mapping_verified' => false,
            'needs_manual_data_explorer_check' => true,
            'automatic_retry_authorized' => false,
            'marketing_sends_authorized' => false,
        ];
    }

    private static function assertManualAuthorization($object)
    {
        if (PHP_SAPI !== 'cli'
            || getenv('GITHUB_REPOSITORY')
                !== 'Emmakex/prestashop-salesforce-marketing-cloud'
            || getenv('GITHUB_REF') !== 'refs/heads/main'
            || getenv('GITHUB_EVENT_NAME') !== 'workflow_dispatch'
            || getenv('G5_CANARY_APPROVAL') !== self::APPROVAL) {
            throw new RuntimeException('g5_canary_manual_approval_required');
        }
        $mode = getenv('G5_CANARY_MODE');
        if ($object === 'PSCustomer' && $mode !== self::CUSTOMER_MODE
            || $object === 'PSOrder' && ($mode !== self::ORDER_MODE
                || getenv('G5_CANARY_CUSTOMER_CONFIRMED')
                    !== 'CUSTOMER_DLO_VERIFIED')) {
            throw new RuntimeException('g5_canary_sequence_not_approved');
        }
    }

    private static function postOnce($url, $json, $token)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('g5_canary_curl_unavailable');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('g5_canary_transport_failed_before_call');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $received = is_string($body);
        unset($body);
        return ['status' => $status, 'received' => $received];
    }

    private function __construct() {}
}
