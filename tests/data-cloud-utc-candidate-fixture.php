<?php
/** All values are synthetic; this fixture never reads actual shop/customer data. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/ShopTimezoneResolver.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/SourceTimestampNormalizer.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/StagedUtcRecordMapper.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\StagedUtcRecordMapper;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\ShopTimezoneResolver;

$zone = ShopTimezoneResolver::resolve(2, function ($shopId) {
    return $shopId === 2 ? 'Europe/Madrid' : null;
});
$mapper = new StagedUtcRecordMapper(new IngestionRecordMapper('abh_pst'));
$customer = $mapper->customer([
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'synthetic@example.invalid',
    'firstname' => 'Synthetic', 'lastname' => 'Customer',
    'newsletter' => false, 'optin' => false, 'active' => true,
    'date_upd' => '2026-11-09 12:00:00',
], $zone);
$order = $mapper->order([
    'shop_id' => 2, 'order_id' => 43, 'order_key' => 'ps_2_43',
    'customer_id' => 17, 'contact_key' => 'ps_2_17', 'cart_id' => 11,
    'currency' => 'EUR', 'total_paid' => 74.2, 'paid' => true,
    'state_id' => 2, 'date_upd' => '2026-11-09 12:00:00',
], $zone);
echo json_encode(['PSCustomer' => $customer, 'PSOrder' => $order], JSON_UNESCAPED_SLASHES);
