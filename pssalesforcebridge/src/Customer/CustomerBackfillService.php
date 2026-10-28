<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Customer;

use Db;

final class CustomerBackfillService
{
    private $repository;
    private $events;

    public function __construct(CustomerBackfillRepository $repository, CustomerEventService $events)
    {
        $this->repository = $repository;
        $this->events = $events;
    }

    public function start(array $shopIds)
    {
        return $this->repository->startOrResume($shopIds);
    }

    public function process($batchSize)
    {
        $batchSize = max(10, min(500, (int) $batchSize));
        $jobs = $this->repository->runningJobs();
        $result = [
            'jobs' => count($jobs),
            'scanned' => 0,
            'queued' => 0,
            'completed' => 0,
        ];

        foreach ($jobs as $job) {
            $shopId = isset($job['id_shop']) ? (int) $job['id_shop'] : 0;
            $cursor = isset($job['cursor_customer_id']) ? (int) $job['cursor_customer_id'] : 0;

            if ($shopId <= 0) {
                continue;
            }

            $ids = $this->loadCustomerIds($shopId, $cursor, $batchSize);
            if (empty($ids)) {
                $this->repository->markCompleted($shopId);
                ++$result['completed'];
                continue;
            }

            $scanned = 0;
            $queued = 0;
            $lastId = $cursor;

            foreach ($ids as $customerId) {
                $customerId = (int) $customerId;
                $lastId = max($lastId, $customerId);
                ++$scanned;

                $customer = new \Customer($customerId);
                if (!isset($customer->id) || (int) $customer->id <= 0) {
                    continue;
                }

                if ($this->events->enqueue('CUSTOMER_BACKFILL', $customer, $shopId)) {
                    ++$queued;
                }
            }

            $this->repository->advance($shopId, $lastId, $scanned, $queued);
            $result['scanned'] += $scanned;
            $result['queued'] += $queued;

            if (count($ids) < $batchSize) {
                $this->repository->markCompleted($shopId);
                ++$result['completed'];
            }
        }

        return $result;
    }

    private function loadCustomerIds($shopId, $cursor, $limit)
    {
        $customerTable = _DB_PREFIX_ . 'customer';
        $customerShopTable = _DB_PREFIX_ . 'customer_shop';
        $shopId = (int) $shopId;
        $cursor = (int) $cursor;
        $limit = (int) $limit;

        if ($this->tableExists($customerShopTable)) {
            $sql = "SELECT DISTINCT c.`id_customer`
                FROM `{$customerTable}` c
                INNER JOIN `{$customerShopTable}` cs
                    ON cs.`id_customer` = c.`id_customer`
                    AND cs.`id_shop` = {$shopId}
                WHERE c.`id_customer` > {$cursor}
                  AND c.`deleted` = 0
                ORDER BY c.`id_customer` ASC
                LIMIT {$limit}";
        } else {
            $sql = "SELECT c.`id_customer`
                FROM `{$customerTable}` c
                WHERE c.`id_customer` > {$cursor}
                  AND c.`id_shop` = {$shopId}
                  AND c.`deleted` = 0
                ORDER BY c.`id_customer` ASC
                LIMIT {$limit}";
        }

        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['id_customer'])) {
                $ids[] = (int) $row['id_customer'];
            }
        }

        return $ids;
    }

    private function tableExists($table)
    {
        $escaped = pSQL($table);
        return (bool) Db::getInstance()->getValue("SHOW TABLES LIKE '{$escaped}'");
    }
}
