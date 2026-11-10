<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Convert PrestaShop's unzoned, per-shop local clock into an unambiguous UTC
 * timestamp. Offline-only: no credentials, HTTP, schema changes or data writes.
 */
final class SourceTimestampNormalizer
{
    /**
     * @param string $localTime Exact PrestaShop SQL value yyyy-MM-dd HH:mm:ss
     * @param string $shopTimezone Exact IANA timezone configured for that shop
     * @return string Salesforce UTC Zulu yyyy-MM-ddTHH:mm:ss.SSSZ
     */
    public static function toUtc($localTime, $shopTimezone)
    {
        if (!is_string($localTime) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $localTime)) {
            throw new RuntimeException('data_cloud_timestamp_bad_format');
        }
        if (!is_string($shopTimezone) || !in_array($shopTimezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw new RuntimeException('data_cloud_timestamp_timezone_required');
        }
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $localTime, new DateTimeZone('UTC'));
        if ($naive === false || $naive->format('Y-m-d H:i:s') !== $localTime) {
            throw new RuntimeException('data_cloud_timestamp_bad_calendar_date');
        }
        $zone = new DateTimeZone($shopTimezone);
        $naiveEpoch = $naive->getTimestamp();
        // Derive all offsets across a DST window; reject ambiguous/nonexistent clock times.
        $offsets = [$zone->getOffset($naive)];
        $transitions = $zone->getTransitions($naiveEpoch - 172800, $naiveEpoch + 172800);
        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                if (isset($transition['offset'])) {
                    $offsets[] = (int) $transition['offset'];
                }
            }
        }
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $epoch = $naiveEpoch - $offset;
            $localResult = (new DateTimeImmutable('@' . $epoch))
                ->setTimezone($zone)->format('Y-m-d H:i:s');
            if ($localResult === $localTime) {
                $matches[(string) $epoch] = $epoch;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0
                ? 'data_cloud_timestamp_nonexistent_local_time'
                : 'data_cloud_timestamp_ambiguous_local_time');
        }
        return gmdate('Y-m-d\TH:i:s', reset($matches)) . '.000Z';
    }
}
