<?php

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    function pSQL($value, $htmlOK = false)
    {
        return str_replace("'", "\\'", (string) $value);
    }
}

final class Db
{
    const INSERT_IGNORE = 1;

    public static $instance;
    public $executed = [];
    public $updates = [];

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function execute($sql)
    {
        $this->executed[] = (string) $sql;
        return true;
    }

    public function executeS($sql)
    {
        $this->executed[] = (string) $sql;
        return [];
    }

    public function update($table, array $data, $where)
    {
        $this->updates[] = [
            'table' => (string) $table,
            'data' => $data,
            'where' => (string) $where,
        ];

        return true;
    }
}

require_once __DIR__ . '/../pssalesforcebridge/src/Queue/QueueRepository.php';
require_once __DIR__ . '/../pssalesforcebridge/src/Customer/CustomerSyncLedger.php';
require_once __DIR__ . '/../pssalesforcebridge/src/Order/OrderSyncLedger.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;

function contractAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$db = Db::getInstance();
$queue = new QueueRepository();
$queue->claimCustomerBatch(50, 'contract-lock');

$claimSql = implode("\n", $db->executed);
contractAssert(
    strpos($claimSql, "`entity_type` = 'customer'") !== false,
    'Customer queue claims must select by entity_type=customer so CUSTOMER_BACKFILL is deliverable.'
);
contractAssert(
    strpos($claimSql, "CUSTOMER_CREATED") === false && strpos($claimSql, "CUSTOMER_UPDATED") === false,
    'Customer queue claims must not depend on an enumerated live-event allowlist.'
);

$db->updates = [];
$customerLedger = new CustomerSyncLedger();
contractAssert($customerLedger->markSynced(1, 22, 'customer-hash'), 'Customer markSynced should report DB execution success.');
$customerUpdate = end($db->updates);
contractAssert(isset($customerUpdate['where']) && strpos($customerUpdate['where'], "`payload_hash` = 'customer-hash'") !== false, 'Customer completion must be conditional on the expected payload hash.');
contractAssert(!array_key_exists('payload_hash', $customerUpdate['data']), 'Customer completion must never overwrite a newer payload hash.');

$db->updates = [];
$orderLedger = new OrderSyncLedger();
contractAssert($orderLedger->markSynced(1, 33, 'order-hash'), 'Order markSynced should report DB execution success.');
$orderUpdate = end($db->updates);
contractAssert(isset($orderUpdate['where']) && strpos($orderUpdate['where'], "`payload_hash` = 'order-hash'") !== false, 'Order completion must be conditional on the expected payload hash.');
contractAssert(!array_key_exists('payload_hash', $orderUpdate['data']), 'Order completion must never overwrite a newer payload hash.');

fwrite(STDOUT, "sync correctness contract: ok\n");
