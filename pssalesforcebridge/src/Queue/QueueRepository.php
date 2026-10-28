<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Queue;

use Db;

final class QueueRepository
{
    public function enqueue($eventType, $entityType, $entityId, $dedupeKey, array $payload)
    {
        $now = date('Y-m-d H:i:s');
        $encoded = json_encode($payload);

        if ($encoded === false) {
            return false;
        }

        return (bool) Db::getInstance()->insert(
            'sfmc_queue',
            [
                'event_type' => (string) $eventType,
                'entity_type' => (string) $entityType,
                'entity_id' => (string) $entityId,
                'dedupe_key' => (string) $dedupeKey,
                'payload' => $encoded,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
            ],
            false,
            true,
            Db::INSERT_IGNORE
        );
    }
}
