<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Customer;

use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

final class CustomerEventService
{
    private $queue;
    private $ledger;

    public function __construct(QueueRepository $queue, CustomerSyncLedger $ledger)
    {
        $this->queue = $queue;
        $this->ledger = $ledger;
    }

    public function enqueue($eventType, $customer, $shopId)
    {
        $payload = CustomerNormalizer::fromCustomer($customer, $shopId);
        $customerId = (int) $payload['customer_id'];

        if ($customerId <= 0 || $payload['contact_key'] === '') {
            return false;
        }

        $encoded = json_encode($payload);
        if ($encoded === false) {
            return false;
        }

        $payloadHash = sha1($encoded);
        $dedupeKey = 'customer:' . (int) $shopId . ':' . $customerId . ':' . $payloadHash;

        if (!$this->ledger->observe($shopId, $customerId, $payload['contact_key'], $payloadHash)) {
            return false;
        }

        return $this->queue->enqueue(
            $eventType,
            'customer',
            (string) $customerId,
            $dedupeKey,
            $payload
        );
    }
}
