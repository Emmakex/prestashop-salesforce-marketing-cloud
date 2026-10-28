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
    public static $instance;
    public $executed = [];
    private $affected = 0;

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
        $this->affected = 1;
        return true;
    }

    public function Affected_Rows()
    {
        return $this->affected;
    }
}

require_once __DIR__ . '/../pssalesforcebridge/src/Operations/RetentionRepository.php';
require_once __DIR__ . '/../pssalesforcebridge/src/Operations/RetentionService.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Operations\RetentionRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Operations\RetentionService;

function retentionAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$db = Db::getInstance();
$service = new RetentionService(new RetentionRepository());
$result = $service->run([
    'queue_days' => 30,
    'log_days' => 30,
    'journey_days' => 90,
    'cart_days' => 30,
    'recovery_days' => 30,
], 1793188800);

retentionAssert(!empty($result['ok']), 'Retention run should complete with a successful repository.');
retentionAssert(isset($result['deleted']['processed_queue']), 'Retention result should report processed queue cleanup.');

$sql = implode("\n---\n", $db->executed);

retentionAssert(
    strpos($sql, "DELETE FROM `ps_sfmc_queue` WHERE `status` = 'processed'") !== false,
    'Queue retention must delete only processed rows.'
);
retentionAssert(
    strpos($sql, "DELETE FROM `ps_sfmc_queue` WHERE `status` = 'pending'") === false
    && strpos($sql, "DELETE FROM `ps_sfmc_queue` WHERE `status` = 'retry'") === false
    && strpos($sql, "DELETE FROM `ps_sfmc_queue` WHERE `status` = 'processing'") === false
    && strpos($sql, "DELETE FROM `ps_sfmc_queue` WHERE `status` = 'failed'") === false,
    'Outstanding and failed queue states must never be auto-purged.'
);
retentionAssert(
    strpos($sql, "`status` IN ('sent', 'suppressed')") !== false,
    'Journey retention must be restricted to sent and suppressed terminal audit states.'
);
retentionAssert(
    strpos($sql, "`status` IN ('failed'") === false,
    'Failed Journey audit must not be auto-purged.'
);
retentionAssert(
    strpos($sql, "('empty', 'ineligible', 'converted', 'recovered')") !== false,
    'Cart retention must target only explicit terminal snapshot states.'
);
retentionAssert(
    strpos($sql, "('active'") === false && strpos($sql, "('abandoned'") === false,
    'Active and abandoned cart snapshots must not be auto-purged.'
);
retentionAssert(
    strpos($sql, "`recovered_cart_id` > 0") !== false
    && strpos($sql, "`id_customer` = 0") !== false
    && strpos($sql, "`contact_key` = ''") !== false,
    'Recovered-token retention must anonymize identity while preserving the recovery relation.'
);
retentionAssert(
    strpos($sql, "`used_at` IS NOT NULL") !== false
    && strpos($sql, "`recovered_cart_id` = 0") !== false,
    'Consumed tokens without a recovered cart should be deletable after retention.'
);
retentionAssert(
    strpos($sql, "`used_at` IS NULL AND `expires_at` <") !== false,
    'Expired unused recovery tokens should be deletable after retention.'
);

fwrite(STDOUT, "retention contract: ok\n");
