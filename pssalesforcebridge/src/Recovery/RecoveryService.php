<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Recovery;

use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;
use RuntimeException;

final class RecoveryService
{
    private $tokens;
    private $restorer;
    private $carts;
    private $orders;
    private $queue;

    public function __construct(
        RecoveryTokenRepository $tokens,
        CartSessionRestorer $restorer,
        CartRepository $carts,
        OrderSyncLedger $orders,
        QueueRepository $queue
    ) {
        $this->tokens = $tokens;
        $this->restorer = $restorer;
        $this->carts = $carts;
        $this->orders = $orders;
        $this->queue = $queue;
    }

    public function recover($rawToken, $context)
    {
        $token = $this->tokens->claim($rawToken);
        if (!is_array($token)) {
            throw new RuntimeException('Recovery link is invalid, expired or already used.');
        }

        $tokenId = isset($token['id_sfmc_recovery_token']) ? (int) $token['id_sfmc_recovery_token'] : 0;
        $shopId = isset($token['id_shop']) ? (int) $token['id_shop'] : 0;
        $sourceCartId = isset($token['id_cart']) ? (int) $token['id_cart'] : 0;
        $customerId = isset($token['id_customer']) ? (int) $token['id_customer'] : 0;
        $snapshotHash = isset($token['snapshot_hash']) ? (string) $token['snapshot_hash'] : '';
        $contactKey = isset($token['contact_key']) ? (string) $token['contact_key'] : '';

        if ($tokenId <= 0 || $shopId <= 0 || $sourceCartId <= 0 || $snapshotHash === '') {
            throw new RuntimeException('Recovery token context is incomplete.');
        }

        if ($this->orders->hasOrderForCart($shopId, $sourceCartId)) {
            $this->carts->markConverted($shopId, $sourceCartId);
            throw new RuntimeException('This cart has already been converted into an order.');
        }

        $current = $this->carts->find($shopId, $sourceCartId);
        if (!is_array($current)) {
            throw new RuntimeException('The abandoned cart snapshot is no longer available.');
        }

        if (!isset($current['snapshot_hash']) || !hash_equals((string) $current['snapshot_hash'], $snapshotHash)) {
            throw new RuntimeException('The cart changed after this recovery link was created.');
        }

        if (isset($current['status']) && (string) $current['status'] === 'converted') {
            throw new RuntimeException('This cart has already been converted into an order.');
        }

        $recoveredCartId = $this->restorer->restore($sourceCartId, $shopId, $customerId, $context);

        if (!$this->tokens->attachRecoveredCart($tokenId, $recoveredCartId)) {
            throw new RuntimeException('Unable to persist the recovered cart relation.');
        }

        $this->carts->markRecovered($shopId, $sourceCartId);

        $payload = [
            'event_type' => 'CART_RECOVERED',
            'shop_id' => $shopId,
            'source_cart_id' => $sourceCartId,
            'recovered_cart_id' => $recoveredCartId,
            'customer_id' => $customerId,
            'contact_key' => $contactKey,
            'snapshot_hash' => $snapshotHash,
            'recovered_at' => date('Y-m-d H:i:s'),
        ];

        $this->queue->enqueue(
            'CART_RECOVERED',
            'cart',
            $shopId . ':' . $sourceCartId,
            'cart-recovered:' . $shopId . ':' . $sourceCartId . ':' . $recoveredCartId,
            $payload
        );

        return [
            'source_cart_id' => $sourceCartId,
            'recovered_cart_id' => $recoveredCartId,
            'shop_id' => $shopId,
        ];
    }
}
