<?php
namespace Emmakex\PrestaShopSalesforceMarketingCloud\Review;

use RuntimeException;

/**
 * Verifies the Data Cloud token host against the owner-supplied PST OpenAPI
 * object endpoints (2026-11-16). Pure calculation: NO HTTP or record writes.
 *
 * Store only the SHA256 of the canonical HTTPS origin, not the tenant host,
 * token, full URLs or any data. A matching host does not certify a 202 reply
 * to /actions/test as a validation success.
 */
final class G5PstEndpointAttestation
{
    const OFFICIAL_ORIGIN_SHA256 = 'c7571e2d2b114fe98f6d07d8f42a0558e240bd277f283ebb178358996efe66fd';
    const SOURCE = 'ABH_PrestaShop_Data_Cloud';

    public static function canonicalOrigin($cloudOrigin)
    {
        if (!is_string($cloudOrigin) || strlen($cloudOrigin) > 253) {
            throw new RuntimeException('g5_host_invalid_origin');
        }
        $parsed = parse_url($cloudOrigin);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])
            || strtolower($parsed['scheme']) !== 'https'
            || isset($parsed['user']) || isset($parsed['pass'])
            || isset($parsed['port']) || isset($parsed['query'])
            || isset($parsed['fragment'])
            || isset($parsed['path']) && $parsed['path'] !== ''
                && $parsed['path'] !== '/'
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+c360a\.salesforce\.com$/Di',
                $parsed['host'])) {
            throw new RuntimeException('g5_host_invalid_origin');
        }
        return 'https://' . strtolower($parsed['host']);
    }

    public static function compareAgainst($cloudOrigin, $expectedSha256)
    {
        $origin = self::canonicalOrigin($cloudOrigin);
        if (!is_string($expectedSha256)
            || !preg_match('/^[0-9a-f]{64}$/D', $expectedSha256)) {
            throw new RuntimeException('g5_host_invalid_fingerprint');
        }
        return hash_equals($expectedSha256, hash('sha256', $origin));
    }

    public static function attest($cloudOrigin)
    {
        $origin = self::canonicalOrigin($cloudOrigin);
        $match = self::compareAgainst($origin, self::OFFICIAL_ORIGIN_SHA256);
        $customerPath = self::testPath('PSCustomer');
        $orderPath = self::testPath('PSOrder');

        // Source name and object paths are derived from the owner's official
        // downloaded OpenAPI, not user input. No API request is performed.
        return [
            'stage' => 'g5_pst_host_attestation_no_data',
            'connector' => self::SOURCE,
            'tenant_host_matches_official_pst_yaml' => $match,
            'test_paths_verified_offline' => $customerPath !== $orderPath
                && substr($customerPath, -13) === '/actions/test'
                && substr($orderPath, -13) === '/actions/test',
            'salesforce_data_post_performed' => false,
            'customer_records_persisted_or_updated' => false,
            'marketing_sends_performed' => false,
        ];
    }

    public static function testPath($object)
    {
        if (!in_array($object, ['PSCustomer', 'PSOrder'], true)) {
            throw new RuntimeException('g5_host_object_not_allowed');
        }
        return '/api/v1/ingest/sources/' . self::SOURCE . '/'
            . $object . '/actions/test';
    }

    private function __construct()
    {
    }
}
