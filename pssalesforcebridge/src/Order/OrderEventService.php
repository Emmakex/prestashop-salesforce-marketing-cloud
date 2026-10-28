<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Order;

use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Recovery\RecoveryTokenRepository;

final class OrderEventService
{
    private $queue;
    private $ledger;
    private $recovery;

    public function __construct(QueueRepository $queue, OrderSyncLedger $ledger, $recovery = null)
    {
        $this->queue = $queue;
        $this->ledger = $ledger;
        $this->recovery = $recovery instanceof RecoveryTokenRepository
            ? $recovery
            : new RecoveryTokenRepository();
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

        // Recovery attribution is intentionally best-effort and local. It must never
        // prevent the authoritative order event from entering the outbound queue.
        $this->recovery->attributeOrder(
            isset($payload['shop_id']) ? (int) $payload['shop_id'] : 0,
            isset($payload['cart_id']) ? (int) $payload['cart_id'] : 0,
            $orderId,
            isset($payload['total_paid']) ? (float) $payload['total_paid'] : 0.0,
            isset($payload['currency']) ? (string) $payload['currency'] : '',
            isset($payload['state_id']) ? (int) $payload['state_id'] : 0,
            !empty($payload['paid']),
            !empty($payload['valid'])
        );

        return $this->queue->enqueue(
            $eventType,
            'order',
            (string) $orderId,
            $dedupeKey,
            $payload
        );
    }
}
