<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * Candidate-only source mapper. NOT wired to background jobs or ingestion.
 * Requires shop-specific IANA timezone and rejects absent/ambiguous source time.
 */
final class StagedUtcRecordMapper
{
    private $base;

    public function __construct(IngestionRecordMapper $base)
    {
        $this->base = $base;
    }

    public function customer(array $source, $shopTimezone)
    {
        $record = $this->base->customer($source);
        return $this->extend($record, $shopTimezone);
    }

    public function order(array $source, $shopTimezone)
    {
        $record = $this->base->order($source);
        return $this->extend($record, $shopTimezone);
    }

    private function extend(array $record, $shopTimezone)
    {
        if (!is_string($record['source_updated_local']) || $record['source_updated_local'] === '') {
            throw new RuntimeException('data_cloud_timestamp_source_missing');
        }
        $record['source_updated_utc'] = SourceTimestampNormalizer::toUtc(
            $record['source_updated_local'], $shopTimezone
        );
        return $record;
    }
}
