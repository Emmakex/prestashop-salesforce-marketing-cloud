<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use Throwable;

/**
 * Classify a caught failure by an exact, source-controlled code.
 *
 * The Back Office NEVER gets raw exception text, SQL, an email, a source key,
 * an HTTP response, tokens or customer/order properties. In particular
 * unknown failures never inherit a private exception string.
 *
 * Local preview has no network path and must NOT be described as a previous
 * Salesforce send. The send path is distinct and remains fail-closed.
 */
final class GrowthOneRecordSafeDiagnosis
{
    public static function classify($failure,$action)
    {
        $preview=$action==='preview';
        if (!$failure instanceof Throwable) {
            return $preview?'local_unknown':'send_unknown';
        }
        $message=$failure->getMessage();
        if (!is_string($message)) {
            return $preview?'local_unknown':'send_unknown';
        }
        $local=[
            'g7_one_record_invalid_shop_context'=>'shop_context',
            'g7_one_record_invalid_scope'=>'invalid_selection',
            'g7_one_record_customer_not_in_shop'=>'customer_shop',
            'g7_one_record_order_not_in_shop'=>'order_shop',
            'g7_one_record_customer_order_mismatch'=>'customer_order_relation',
            'g7_one_record_currency_unverified'=>'currency',
            'g7_native_draft_cross_scope_or_orphan'=>'customer_order_relation',
            'g7_native_draft_objects_required'=>'local_source',
            'g7_native_draft_customer_fields_missing'=>'customer_fields',
            'g7_native_draft_order_fields_missing'=>'order_fields',
            'g7_native_draft_currency_fields_missing'=>'currency',
            'g7_native_draft_customer_flag_invalid'=>'customer_fields',
            'g7_native_draft_customer_excluded'=>'customer_excluded',
            'g7_native_draft_source_field_invalid'=>'local_source',
            'g7_native_draft_total_unverified'=>'order_total',
            'g4_pst_invalid_email'=>'email',
            'g4_pst_invalid_local_date'=>'local_date',
            'g4_pst_invalid_order_values'=>'order_fields',
            'g4_pst_cross_scope_fk'=>'customer_order_relation',
            'g4_pst_invalid_typed_pk'=>'local_source',
            'g4_pst_invalid_value_type'=>'local_source',
            'g4_pst_invalid_scope'=>'local_source',
            'g4_pst_field_inventory_invalid'=>'local_source',
            'g4_pst_payload_encoding_or_size'=>'local_source',
            'g7_one_record_pst_unverified'=>'local_source',
            'g7_one_record_preview_invalid'=>'local_source',
        ];
        if ($preview) {
            return isset($local[$message])?$local[$message]:'local_unknown';
        }
        $sending=[
            'g7_one_send_scope_or_approval_denied'=>'send_approval',
            'g7_one_send_test_mode_gate_denied'=>'send_gate',
            'g7_one_send_already_attempted'=>'send_attempt_exists',
            'g7_one_send_customer_dlo_review_required'=>'send_dlo_required',
            'g7_one_send_wrong_customer_for_order'=>'send_order_customer',
            'g7_one_send_lock_unavailable'=>'send_locked',
            'g7_one_send_oauth_unavailable'=>'send_oauth',
            'growth_vault_core_auth_failed'=>'send_oauth',
            'growth_vault_cloud_exchange_failed'=>'send_oauth',
            'growth_source_unverified_for_send'=>'send_source',
            'growth_source_existing_invalid'=>'send_source',
            'g7_one_send_source_changed'=>'send_source',
            'g7_one_send_reservation_failed'=>'send_unknown',
            'g7_one_send_result_persist_unverified'=>'send_unknown',
            'g7_one_send_native_source_unverified'=>'send_local',
            'g7_one_send_record_scope_unverified'=>'send_local',
            'g7_one_send_customer_binding_unverified'=>'send_local',
        ];
        return isset($sending[$message])?$sending[$message]
            :(isset($local[$message])?'send_local':'send_unknown');
    }

    private function __construct(){}
}
