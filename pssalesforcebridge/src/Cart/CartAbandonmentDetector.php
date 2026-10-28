<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

final class CartAbandonmentDetector
{
    private $repository;
    private $orders;
    private $queue;

    public function __construct(CartRepository $repository, OrderSyncLedger $orders, QueueRepository $queue)
    {
        $this->repository = $repository;
        $this->orders = $orders;
        $this->queue = $queue;
    }

    public function process($limit = 100)
    {
        $rows = $this->repository->candidates($limit);
        $result = [
            'scanned' => count($rows),
            'abandoned' => 0,
            'converted' => 0,
            'invalid' => 0,
        ];

        foreach ($rows as $row) {
            $shopId = isset($row['id_shop']) ? (int) $row['id_shop'] : 0;
            $cartId = isset($row['id_cart']) ? (int) $row['id_cart'] : 0;

            if ($shopId <= 0 || $cartId <= 0) {
                ++$result['invalid'];
                continue;
            }

            if ($this->orders->hasOrderForCart($shopId, $cartId)) {
                $this->repository->markConverted($shopId, $cartId);
                ++$result['converted'];
                continue;
            }

            $payload = json_decode(isset($row['payload']) ? (string) $row['payload'] : '', true);
            if (!is_array($payload)) {
                ++$result['invalid'];
                continue;
            }

            $snapshotHash = isset($row['snapshot_hash']) ? (string) $row['snapshot_hash'] : sha1(json_encode($payload));
            $payload['event_type'] = 'CART_ABANDONED';
            $payload['abandoned_at'] = date('Y-m-d H:i:s');
            $payload['snapshot_hash'] = $snapshotHash;

            $dedupeKey = 'cart-abandoned:' . $shopId . ':' . $cartId . ':' . $snapshotHash;
            $entityId = $shopId . ':' . $cartId;

            if (!$this->queue->enqueue('CART_ABANDONED', 'cart', $entityId, $dedupeKey, $payload)) {
                ++$result['invalid'];
                continue;
            }

            if ($this->repository->markAbandoned($shopId, $cartId)) {
                ++$result['abandoned'];
            }
        }

        return $result;
    }
}
