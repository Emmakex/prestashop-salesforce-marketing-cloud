<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Growth;

use RuntimeException;
use Throwable;

/**
 * G8 safe cron preflight for the EXISTING Growth G3 outbox.
 *
 * This never calls OAuth, sends records, mutates SQL, advances a cursor,
 * promotes READY, claims leases, starts a marketing campaign or logs PII.
 *
 * Staging only, exact merchant + namespace + shop, Test and capture OFF.
 * The production Cron controller checks the 48-character secret first.
 */
final class GrowthG8CronReadOnly
{
    const MAX_BATCH = 10;

    public static function inspect(
        $db,$shop,$version,$merchant,$namespace,$prefix,$limit=10,$scanner=null
    ) {
        if (!is_int($shop) || $shop<1
            || !is_string($version) || $version===''
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || !is_string($prefix)
            || !preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix)
            || !is_int($limit) || $limit<1 || $limit>self::MAX_BATCH
            || !class_exists('Configuration')
            || (string)\Configuration::get('PS_GROWTH_MERCHANT')!==$merchant
            || (string)\Configuration::get('PS_GROWTH_NAMESPACE')!==$namespace
            || (string)\Configuration::get('PS_SFMC_MODE')!=='test'
            || (string)\Configuration::get('PS_GROWTH_LOCAL_CAPTURE')!=='off'
        ) {
            return self::report('blocked_by_configuration',0,false);
        }
        // NEVER query an unsuffixed real namespace in production BC or DO.
        if (!preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace)) {
            return self::report('blocked_non_staging',0,false);
        }
        $source=GrowthShopSourceBinding::inspect($shop,$merchant,$namespace);
        if ($source['ready']!==true) {
            return self::report('blocked_source_binding',0,false);
        }

        try {
            // A test callback can be supplied ONLY by local synthetic tests.
            // The module itself always calls the real G3 keyset scanner,
            // whose constructor verifies the exact staging extended schema.
            $batch=$scanner===null
                ?GrowthG7CurrentEventBatch::scan(
                    $db,$version,$merchant,$namespace,$prefix,$shop,0,$limit
                )
                :call_user_func(
                    $scanner,$db,$version,$merchant,$namespace,
                    $prefix,$shop,0,$limit
                );
            if (!is_array($batch)
                || !isset($batch['status'],$batch['candidate_refs_internal'],
                    $batch['has_more'],$batch['next_cursor'])
                || $batch['status']!=='bounded_review_candidates_only'
                || !is_array($batch['candidate_refs_internal'])
                || !is_bool($batch['has_more'])
                || !is_int($batch['next_cursor'])
                || $batch['next_cursor']<0
                || count($batch['candidate_refs_internal'])>$limit
                || !isset($batch['can_claim'],$batch['http_request_performed'],
                    $batch['data_cloud_ingestion_authorized'],
                    $batch['marketing_sends_authorized'])
                || $batch['can_claim']!==false
                || $batch['http_request_performed']!==false
                || $batch['data_cloud_ingestion_authorized']!==false
                || $batch['marketing_sends_authorized']!==false) {
                throw new RuntimeException('g8_cron_batch_not_verified');
            }
            // Inspect only the allowed count, NEVER expose candidate refs,
            // entity IDs, cursor, namespace, source keys or source PII.
            return self::report(
                'staging_events_review_only',
                count($batch['candidate_refs_internal']),
                $batch['has_more']
            );
        } catch (Throwable $error) {
            // An unprepared G3 schema or SQL error is a normal no-go state.
            // Do not include raw errors or schema/table names in the output.
            return self::report('staging_schema_unavailable',0,false);
        }
    }

    private static function report($status,$count,$more)
    {
        return [
            'ok'=>true,
            'status'=>$status,
            'reviewed_candidates'=>$count,
            'more_candidates_present'=>$more,
            'read_only'=>true,
            'cursor_exposed'=>false,
            'identifiers_exposed'=>false,
            'data_cloud_ingestion_authorized'=>false,
            'marketing_sends_authorized'=>false,
            'automatic_retry_authorized'=>false,
            'http_request_performed'=>false,
            'database_write_performed'=>false,
            'scheduler_activated'=>false
        ];
    }

    private function __construct(){}
}
