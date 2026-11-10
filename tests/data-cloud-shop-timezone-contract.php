<?php
/** Mock PrestaShop config for offline deterministic contract. */
class Configuration
{
    public static $calls = [];
    public static function get($key, $idLang = null, $idGroup = null, $shopId = null)
    {
        self::$calls[] = [$key, $idLang, $idGroup, $shopId];
        if ($key !== 'PS_TIMEZONE') { return false; }
        return $shopId === 2 ? 'Europe/Madrid' : ($shopId === 3 ? 'America/Lima' : false);
    }
}
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcRecordMapper.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\ShopTimezoneResolver;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedUtcRecordMapper;

function tz_check($ok, $description) {
    if (!$ok) { fwrite(STDERR, $description . "\n"); exit(1); }
}
function tz_reject($fn, $code) {
    try { $fn(); tz_check(false, 'Unsafe timezone accepted.'); }
    catch (RuntimeException $e) {
        tz_check($e->getMessage() === $code, 'Incorrect non-sensitive timezone diagnostic.');
    }
}
tz_check(ShopTimezoneResolver::readFromPrestaShop(2) === 'Europe/Madrid', 'Shop 2 timezone not read.');
tz_check(ShopTimezoneResolver::readFromPrestaShop(3) === 'America/Lima', 'Shop 3 timezone not read.');
tz_check(Configuration::$calls === [
    ['PS_TIMEZONE', null, null, 2],
    ['PS_TIMEZONE', null, null, 3],
], 'Timezone must be retrieved for exact shop ID, not server/php context.');
tz_reject(function () { ShopTimezoneResolver::readFromPrestaShop(4); },
    'data_cloud_shop_timezone_not_configured');
tz_reject(function () { ShopTimezoneResolver::resolve(0, function () { return 'UTC'; }); },
    'data_cloud_shop_timezone_invalid_shop');
tz_reject(function () { ShopTimezoneResolver::resolve(2, null); },
    'data_cloud_shop_timezone_missing_reader');
tz_reject(function () { ShopTimezoneResolver::resolve(2, function () { return 'CET'; }); },
    'data_cloud_shop_timezone_not_configured');
$base = new IngestionRecordMapper('abh_pst');
$utc = new StagedUtcRecordMapper($base);
$src = [
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'synthetic@example.invalid', 'firstname' => 'A',
    'lastname' => 'B', 'date_upd' => '2026-07-09 12:00:00',
];
$record = $utc->customer($src, ShopTimezoneResolver::readFromPrestaShop(2));
tz_check($record['source_updated_utc'] === '2026-07-09T10:00:00.000Z',
    'Staged customer UTC mismatch');
$src['date_upd'] = '2026-10-25 02:30:00';
tz_reject(function () use ($utc, $src) { $utc->customer($src, 'Europe/Madrid'); },
    'data_cloud_timestamp_ambiguous_local_time');
$src['date_upd'] = '';
tz_reject(function () use ($utc, $src) { $utc->customer($src, 'Europe/Madrid'); },
    'data_cloud_timestamp_source_missing');
$source = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php');
tz_check(strpos($source, 'curl_') === false && strpos($source, 'file_get_contents(') === false,
    'Shop timezone preflight must not perform network/IO.');
fwrite(STDOUT, "PST shop timezone and staged UTC contract passed: no customer data or writes.\n");
