<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_1($module)
{
    $prefix = _DB_PREFIX_;
    $columns = Db::getInstance()->executeS("SHOW COLUMNS FROM `{$prefix}sfmc_queue` LIKE 'lock_token'");

    if (empty($columns)) {
        $sql = "ALTER TABLE `{$prefix}sfmc_queue`
            ADD `lock_token` VARCHAR(64) NULL AFTER `last_error`,
            ADD `locked_at` DATETIME NULL AFTER `lock_token`,
            ADD KEY `idx_sfmc_queue_lock` (`lock_token`)";

        if (!Db::getInstance()->execute($sql)) {
            return false;
        }
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_CRON_TOKEN)) {
        Configuration::updateValue(PsSalesforceBridge::CONFIG_CRON_TOKEN, bin2hex(random_bytes(24)));
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_CUSTOMER_DE_KEY)) {
        Configuration::updateValue(PsSalesforceBridge::CONFIG_CUSTOMER_DE_KEY, 'PS_CUSTOMERS');
    }

    return true;
}
