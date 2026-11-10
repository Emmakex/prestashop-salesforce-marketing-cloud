<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud;

use RuntimeException;

/**
 * Pure, opt-in v0.3 staging orchestration for multiple installations/shops.
 * NEVER called by the current PrestaShop worker. No transport or credentials.
 * A stable installation namespace MUST be provided by the caller explicitly.
 */
final class StagedInstallationPipeline
{
    private $mapper;
    private $builder;
    private $timezoneReader;

    public function __construct($installationNamespace, $timezoneReader)
    {
        if (!is_callable($timezoneReader)) {
            throw new RuntimeException('data_cloud_site_timezone_reader_required');
        }
        // The existing mapper validates namespaces. The database name, site
        // domain and table prefix must never become an implicit namespace.
        $this->mapper = new StagedUtcRecordMapper(
            new IngestionRecordMapper($installationNamespace)
        );
        $this->builder = new StagedUtcPayloadBuilder(new IngestionPayloadBuilder());
        $this->timezoneReader = $timezoneReader;
    }

    public static function forPrestaShop($installationNamespace)
    {
        return new self($installationNamespace, function ($shopId) {
            return ShopTimezoneResolver::readFromPrestaShop($shopId);
        });
    }

    public function customer(array $source)
    {
        return $this->mapper->customer($source, $this->timezone($source));
    }

    public function order(array $source)
    {
        return $this->mapper->order($source, $this->timezone($source));
    }

    public function encode($objectName, array $records)
    {
        return $this->builder->encode($objectName, $records);
    }

    private function timezone(array $source)
    {
        if (!isset($source['shop_id'])) {
            throw new RuntimeException('data_cloud_site_shop_id_missing');
        }
        $id = $source['shop_id'];
        if (is_int($id)) {
            $shopId = $id;
        } elseif (is_string($id) && ctype_digit($id)
            && (string) (int) $id === $id) {
            $shopId = (int) $id;
        } else {
            throw new RuntimeException('data_cloud_site_invalid_shop_id');
        }
        return ShopTimezoneResolver::resolve($shopId, $this->timezoneReader);
    }
}
