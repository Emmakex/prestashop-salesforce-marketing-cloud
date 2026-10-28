<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Order;

use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

final class OrderEventService
{
    private $queue;
    private $ledger;

    public function __construct(QueueRepository $queue, OrderSyncLedger $ledger)
    {
        $this->queue = $queue;
        $this->ledger = $ledger;
    }

    public function enqueue($eventType, $order, $customer, $cart, $currencyIso, $shopId, $stateId = null, $paid = null)
    {
        $payload = OrderNormalizer::fromOrder($order, $customer, $cart, $currencyIso, $shopId, $stateId, $paid);
        $payload['event_type'] = (string) $eventType;
        $orderId = (int) $payload['order_id'];

        if ($orderId <= 0 || $payload['order_key'] === '') {
            return false;
        }

        $encoded = json_encode($payload);
        if ($encoded === false) {
            return false;
        }

        $payloadHash = sha1($encoded);
        $dedupeKey = 'order:' . (int) $shopId . ':' . $orderId . ':' . $payloadHash;

        if (!$this->ledger->observe(
            $shopId,
            $orderId,
            isset($payload['cart_id']) ? (int) $payload['cart_id'] : 0,
            isset($payload['contact_key']) ? (string) $payload['contact_key'] : '',
            $payloadHash
        )) {
            return false;
        }

        return $this->queue->enqueue(
            $eventType,
            'order',
            (string) $orderId,
            $dedupeKey,
            $payload
        );
    }
}
