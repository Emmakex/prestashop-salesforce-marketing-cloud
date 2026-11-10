<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Offline-only schema v0.3 prototype. No HTTP, credentials or production worker.
 * Composes the certified v0.2 builder instead of loosening its field allowlist.
 */
final class StagedUtcPayloadBuilder
{
    private $baseline;

    public function __construct(IngestionPayloadBuilder $baseline)
    {
        $this->baseline = $baseline;
    }

    public static function fields()
    {
        $objects = IngestionPayloadBuilder::fields();
        foreach ($objects as $name => $properties) {
            $objects[$name]['source_updated_utc'] = 'string';
        }
        return $objects;
    }

    public function encode($objectName, array $records)
    {
        $objects = self::fields();
        if (!is_string($objectName) || !isset($objects[$objectName])) {
            throw new RuntimeException('data_cloud_utc_payload_object_not_allowed');
        }
        if (count($records) < 1 || count($records) > IngestionPayloadBuilder::MAX_RECORDS) {
            throw new RuntimeException('data_cloud_utc_payload_invalid_count');
        }

        $expected = array_keys($objects[$objectName]);
        $base = [];
        foreach ($records as $record) {
            if (!is_array($record) || array_keys($record) !== $expected) {
                throw new RuntimeException('data_cloud_utc_payload_schema_drift');
            }
            $timestamp = $record['source_updated_utc'];
            if (!is_string($timestamp) || !preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$/D',
                $timestamp
            )) {
                throw new RuntimeException('data_cloud_utc_payload_invalid_timestamp');
            }
            // PHP 7.1 (PrestaShop 1.7) cannot reliably parse the millisecond
            // "v" date format specifier. Regex already verifies exactly three
            // fractional digits, so validate calendar and UTC clock separately.
            $clock = substr($timestamp, 0, 19);
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s', $clock, new DateTimeZone('UTC')
            );
            if (!$date || $date->format('Y-m-d\TH:i:s') !== $clock) {
                throw new RuntimeException('data_cloud_utc_payload_invalid_timestamp');
            }
            $copy = $record;
            unset($copy['source_updated_utc']);
            $base[] = $copy;
        }

        // Reuse source-key, namespace, relationship and data-type checks.
        $this->baseline->encode($objectName, $base);

        $json = json_encode(
            ['data' => array_values($records)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            throw new RuntimeException('data_cloud_utc_payload_json_error');
        }
        if (strlen($json) > IngestionPayloadBuilder::MAX_BYTES) {
            throw new RuntimeException('data_cloud_utc_payload_size_exceeded');
        }
        return $json;
    }
}
