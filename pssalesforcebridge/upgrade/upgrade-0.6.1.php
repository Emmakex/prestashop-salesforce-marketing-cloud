<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_6_1($module)
{
    $prefix = _DB_PREFIX_;
    $db = Db::getInstance();
    $table = "{$prefix}sfmc_recovery_token";

    $columns = [
        'recovered_order_id' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `recovered_cart_id`',
        'recovered_order_total' => 'DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER `recovered_order_id`',
        'recovered_currency' => "VARCHAR(8) NOT NULL DEFAULT '' AFTER `recovered_order_total`",
        'recovered_order_state_id' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `recovered_currency`',
        'recovered_order_paid' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `recovered_order_state_id`',
        'recovered_order_valid' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `recovered_order_paid`',
        'converted_at' => 'DATETIME NULL AFTER `recovered_order_valid`',
    ];

    foreach ($columns as $name => $definition) {
        $column = $db->getRow("SHOW COLUMNS FROM `{$table}` LIKE '" . pSQL($name) . "'");
        if (!$column && !$db->execute("ALTER TABLE `{$table}` ADD `{$name}` {$definition}")) {
            return false;
        }
    }

    return true;
}
