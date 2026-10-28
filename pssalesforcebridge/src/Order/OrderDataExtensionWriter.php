<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Order;

use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\RestClient;
use RuntimeException;

final class OrderDataExtensionWriter
{
    private $client;
    private $externalKey;

    public function __construct(RestClient $client, $externalKey)
    {
        $this->client = $client;
        $this->externalKey = trim((string) $externalKey);
    }

    public function upsert(array $order)
    {
        if ($this->externalKey === '') {
            throw new RuntimeException('Order Data Extension external key is not configured.');
        }

        if (empty($order['order_key'])) {
            throw new RuntimeException('Order payload does not contain order_key.');
        }

        $row = [
            'keys' => [
                'OrderKey' => (string) $order['order_key'],
            ],
            'values' => [
                'OrderId' => isset($order['order_id']) ? (int) $order['order_id'] : 0,
                'ShopId' => isset($order['shop_id']) ? (int) $order['shop_id'] : 0,
                'CartId' => isset($order['cart_id']) ? (int) $order['cart_id'] : 0,
                'CustomerId' => isset($order['customer_id']) ? (int) $order['customer_id'] : 0,
                'ContactKey' => isset($order['contact_key']) ? (string) $order['contact_key'] : '',
                'Reference' => isset($order['reference']) ? (string) $order['reference'] : '',
                'CurrencyId' => isset($order['currency_id']) ? (int) $order['currency_id'] : 0,
                'Currency' => isset($order['currency']) ? (string) $order['currency'] : '',
                'TotalPaid' => isset($order['total_paid']) ? (float) $order['total_paid'] : 0,
                'TotalProducts' => isset($order['total_products']) ? (float) $order['total_products'] : 0,
                'TotalShipping' => isset($order['total_shipping']) ? (float) $order['total_shipping'] : 0,
                'TotalDiscounts' => isset($order['total_discounts']) ? (float) $order['total_discounts'] : 0,
                'Payment' => isset($order['payment']) ? (string) $order['payment'] : '',
                'Module' => isset($order['module']) ? (string) $order['module'] : '',
                'StateId' => isset($order['state_id']) ? (int) $order['state_id'] : 0,
                'Paid' => !empty($order['paid']),
                'Valid' => !empty($order['valid']),
                'EventType' => isset($order['event_type']) ? (string) $order['event_type'] : '',
                'DateAdd' => isset($order['date_add']) && $order['date_add'] !== '' ? (string) $order['date_add'] : null,
                'DateUpd' => isset($order['date_upd']) && $order['date_upd'] !== '' ? (string) $order['date_upd'] : null,
            ],
        ];

        return $this->client->postJson(
            '/hub/v1/dataevents/key:' . rawurlencode($this->externalKey) . '/rowset',
            [$row]
        );
    }
}
