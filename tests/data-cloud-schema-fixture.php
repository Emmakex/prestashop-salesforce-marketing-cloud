<?php
/** Synthetic fixtures derived from the real production-normalized mapper. No tenant data. */
require_once dirname(__DIR__) . '/pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\IngestionRecordMapper;

$mapper = new IngestionRecordMapper('abh_pst');
$customer = $mapper->customer([
    'shop_id' => 2, 'customer_id' => 17, 'contact_key' => 'ps_2_17',
    'email' => 'sample@example.invalid', 'firstname' => 'Example',
    'lastname' => 'Person', 'newsletter' => false, 'optin' => false,
    'active' => true, 'date_upd' => '2026-11-09 12:00:00',
]);
$order = $mapper->order([
    'shop_id' => 2, 'order_id' => 43, 'order_key' => 'ps_2_43',
    'customer_id' => 17, 'contact_key' => 'ps_2_17', 'cart_id' => 11,
    'currency' => 'EUR', 'total_paid' => 74.2, 'paid' => true,
    'state_id' => 2, 'date_upd' => '2026-11-09 12:00:00',
]);
echo json_encode(['PSCustomer' => $customer, 'PSOrder' => $order], JSON_UNESCAPED_SLASHES);
