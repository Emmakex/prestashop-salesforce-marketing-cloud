<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Queue;

use Db;
use DbQuery;

class QueueRepository
{
    public function enqueue($eventType, $entityType, $entityId, $dedupeKey, array $payload, $availableAt = null)
    {
        $availableAt = $availableAt ?: date('Y-m-d H:i:s');

        return Db::getInstance()->insert('sfmc_queue', [
            'event_type' => pSQL((string) $eventType),
            'entity_type' => pSQL((string) $entityType),
            'entity_id' => pSQL((string) $entityId),
            'dedupe_key' => pSQL((string) $dedupeKey),
            'payload' => pSQL(json_encode($payload), true),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => pSQL($availableAt),
            'created_at' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
    }

    public function getReady($limit = 50)
    {
        $query = new DbQuery();
        $query->select('*')
            ->from('sfmc_queue')
            ->where("status IN ('pending', 'retry')")
            ->where("available_at <= '" . pSQL(date('Y-m-d H:i:s')) . "'")
            ->orderBy('id_sfmc_queue ASC')
            ->limit(max(1, (int) $limit));

        return Db::getInstance()->executeS($query);
    }
}
