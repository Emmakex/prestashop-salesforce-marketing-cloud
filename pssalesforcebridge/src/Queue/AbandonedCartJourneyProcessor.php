<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Queue;

use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartEligibilityPolicy;
use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Journey\JourneyEventAuditRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\JourneyClient;
use Exception;
use RuntimeException;

final class AbandonedCartJourneyProcessor
{
    const MAX_ATTEMPTS = 5;

    private $queue;
    private $carts;
    private $orders;
    private $eligibility;
    private $journey;
    private $audit;
    private $consentMode;
    private $eventDefinitionKey;

    public function __construct(
        QueueRepository $queue,
        CartRepository $carts,
        OrderSyncLedger $orders,
        CartEligibilityPolicy $eligibility,
        JourneyClient $journey,
        JourneyEventAuditRepository $audit,
        $consentMode,
        $eventDefinitionKey
    ) {
        $this->queue = $queue;
        $this->carts = $carts;
        $this->orders = $orders;
        $this->eligibility = $eligibility;
        $this->journey = $journey;
        $this->audit = $audit;
        $this->consentMode = (string) $consentMode;
        $this->eventDefinitionKey = (string) $eventDefinitionKey;
    }

    public function process($limit = 20)
    {
        $lockToken = bin2hex(random_bytes(16));
        $rows = $this->queue->claimAbandonedCartBatch($limit, $lockToken);
        $result = [
            'claimed' => count($rows),
            'processed' => 0,
            'suppressed' => 0,
            'retry' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $queueId = isset($row['id_sfmc_queue']) ? (int) $row['id_sfmc_queue'] : 0;
            $attempts = isset($row['attempts']) ? (int) $row['attempts'] : 0;

            try {
                $payload = json_decode(isset($row['payload']) ? (string) $row['payload'] : '', true);
                if (!is_array($payload)) {
                    throw new RuntimeException('Abandoned cart queue payload is invalid JSON.');
                }

                $shopId = isset($payload['shop_id']) ? (int) $payload['shop_id'] : 0;
                $cartId = isset($payload['cart_id']) ? (int) $payload['cart_id'] : 0;
                $snapshotHash = isset($payload['snapshot_hash']) ? (string) $payload['snapshot_hash'] : '';
                $contactKey = isset($payload['contact_key']) ? trim((string) $payload['contact_key']) : '';
                $bridgeEventKey = isset($row['dedupe_key']) ? (string) $row['dedupe_key'] : ('cart-abandoned:' . $shopId . ':' . $cartId . ':' . $snapshotHash);

                if ($queueId <= 0 || $shopId <= 0 || $cartId <= 0 || $snapshotHash === '' || $contactKey === '') {
                    throw new RuntimeException('Abandoned cart queue payload is incomplete.');
                }

                $existingAudit = $this->audit->findByQueueId($queueId);
                if (is_array($existingAudit) && isset($existingAudit['status']) && (string) $existingAudit['status'] === 'sent') {
                    if (!$this->queue->markProcessed($queueId)) {
                        throw new RuntimeException('Unable to close an already-sent Journey queue event.');
                    }
                    ++$result['processed'];
                    continue;
                }

                if (!$this->audit->observe(
                    $queueId,
                    $shopId,
                    $cartId,
                    $contactKey,
                    $this->eventDefinitionKey,
                    $snapshotHash,
                    $bridgeEventKey
                )) {
                    throw new RuntimeException('Unable to initialize Journey Builder audit record.');
                }

                $current = $this->carts->find($shopId, $cartId);
                if (!is_array($current)) {
                    $this->suppress($queueId, 'cart_snapshot_missing', $result);
                    continue;
                }

                if ($this->orders->hasOrderForCart($shopId, $cartId)) {
                    $this->carts->markConverted($shopId, $cartId);
                    $this->suppress($queueId, 'converted_to_order', $result);
                    continue;
                }

                if (isset($current['status']) && (string) $current['status'] === 'converted') {
                    $this->suppress($queueId, 'converted_to_order', $result);
                    continue;
                }

                if (!isset($current['snapshot_hash']) || !hash_equals((string) $current['snapshot_hash'], $snapshotHash)) {
                    $this->suppress($queueId, 'stale_snapshot', $result);
                    continue;
                }

                if (!isset($current['status']) || (string) $current['status'] !== 'abandoned') {
                    $this->suppress($queueId, 'cart_not_abandoned', $result);
                    continue;
                }

                if (empty($current['product_count']) || empty($current['eligible'])) {
                    $this->suppress($queueId, 'cart_not_eligible', $result);
                    continue;
                }

                if (!empty($current['abandon_after']) && strtotime((string) $current['abandon_after']) > time()) {
                    $this->suppress($queueId, 'abandonment_window_changed', $result);
                    continue;
                }

                $currentPayload = json_decode(isset($current['payload']) ? (string) $current['payload'] : '', true);
                if (!is_array($currentPayload)) {
                    throw new RuntimeException('Current cart snapshot payload is invalid JSON.');
                }

                $eligibility = $this->eligibility->evaluate($currentPayload, $this->consentMode);
                if (empty($eligibility['eligible'])) {
                    $reason = isset($eligibility['reason']) ? (string) $eligibility['reason'] : 'ineligible';
                    $this->suppress($queueId, 'eligibility:' . $reason, $result);
                    continue;
                }

                $event = $this->journey->fire($contactKey, $this->eventData($currentPayload, $payload, $bridgeEventKey, $snapshotHash));
                $eventInstanceId = isset($event['event_instance_id']) ? (string) $event['event_instance_id'] : '';

                if (!$this->audit->markSent($queueId, $eventInstanceId)) {
                    throw new RuntimeException('Unable to mark Journey Builder event as sent.');
                }

                if (!$this->queue->markProcessed($queueId)) {
                    throw new RuntimeException('Unable to mark abandoned cart queue event as processed.');
                }

                ++$result['processed'];
            } catch (Exception $e) {
                $delay = min(3600, 60 * (int) pow(2, min($attempts, 6)));
                $terminal = ($attempts + 1) >= self::MAX_ATTEMPTS;
                $this->audit->markFailure($queueId, $e->getMessage(), $terminal);
                $this->queue->markFailure($queueId, $attempts, $e->getMessage(), $delay, self::MAX_ATTEMPTS);
                ++$result[$terminal ? 'failed' : 'retry'];
            }
        }

        return $result;
    }

    private function suppress($queueId, $reason, array &$result)
    {
        if (!$this->audit->markSuppressed($queueId, $reason)) {
            throw new RuntimeException('Unable to persist Journey suppression reason.');
        }

        if (!$this->queue->markProcessed($queueId)) {
            throw new RuntimeException('Unable to close suppressed Journey queue event.');
        }

        ++$result['suppressed'];
    }

    private function eventData(array $current, array $queued, $bridgeEventKey, $snapshotHash)
    {
        return [
            'BridgeEventKey' => (string) $bridgeEventKey,
            'CartKey' => isset($current['cart_key']) ? (string) $current['cart_key'] : '',
            'CartId' => isset($current['cart_id']) ? (int) $current['cart_id'] : 0,
            'ShopId' => isset($current['shop_id']) ? (int) $current['shop_id'] : 0,
            'CustomerId' => isset($current['customer_id']) ? (int) $current['customer_id'] : 0,
            'ContactKey' => isset($current['contact_key']) ? (string) $current['contact_key'] : '',
            'Email' => isset($current['email']) ? (string) $current['email'] : '',
            'Firstname' => isset($current['firstname']) ? (string) $current['firstname'] : '',
            'Lastname' => isset($current['lastname']) ? (string) $current['lastname'] : '',
            'Currency' => isset($current['currency']) ? (string) $current['currency'] : '',
            'Total' => isset($current['total']) ? (float) $current['total'] : 0.0,
            'ProductCount' => isset($current['product_count']) ? (int) $current['product_count'] : 0,
            'ItemCount' => isset($current['item_count']) ? (int) $current['item_count'] : 0,
            'LastActivity' => isset($current['last_activity']) ? (string) $current['last_activity'] : '',
            'AbandonedAt' => isset($queued['abandoned_at']) ? (string) $queued['abandoned_at'] : '',
            'SnapshotHash' => (string) $snapshotHash,
            'RecoveryUrl' => '',
        ];
    }
}
