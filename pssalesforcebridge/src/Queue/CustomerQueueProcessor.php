<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Queue;

use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerDataExtensionWriter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerSyncLedger;
use Exception;
use RuntimeException;

final class CustomerQueueProcessor
{
    const MAX_ATTEMPTS = 5;

    private $queue;
    private $writer;
    private $ledger;

    public function __construct(QueueRepository $queue, CustomerDataExtensionWriter $writer, CustomerSyncLedger $ledger)
    {
        $this->queue = $queue;
        $this->writer = $writer;
        $this->ledger = $ledger;
    }

    public function process($limit = 20)
    {
        $lockToken = bin2hex(random_bytes(16));
        $rows = $this->queue->claimCustomerBatch($limit, $lockToken);
        $result = ['claimed' => count($rows), 'processed' => 0, 'retry' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $id = isset($row['id_sfmc_queue']) ? (int) $row['id_sfmc_queue'] : 0;
            $attempts = isset($row['attempts']) ? (int) $row['attempts'] : 0;

            try {
                $payload = json_decode(isset($row['payload']) ? $row['payload'] : '', true);
                if (!is_array($payload)) {
                    throw new RuntimeException('Queue payload is invalid JSON.');
                }

                $this->writer->upsert($payload);
                $payloadHash = sha1(json_encode($payload));

                if (!$this->ledger->markSynced(
                    isset($payload['shop_id']) ? (int) $payload['shop_id'] : 0,
                    isset($payload['customer_id']) ? (int) $payload['customer_id'] : 0,
                    $payloadHash
                )) {
                    throw new RuntimeException('Unable to update the local customer synchronization ledger.');
                }

                if (!$this->queue->markProcessed($id)) {
                    throw new RuntimeException('Unable to mark the queue event as processed.');
                }

                ++$result['processed'];
            } catch (Exception $e) {
                $delay = min(3600, 60 * (int) pow(2, min($attempts, 6)));
                $willFail = ($attempts + 1) >= self::MAX_ATTEMPTS;
                $this->queue->markFailure($id, $attempts, $e->getMessage(), $delay, self::MAX_ATTEMPTS);
                ++$result[$willFail ? 'failed' : 'retry'];
            }
        }

        return $result;
    }
}
