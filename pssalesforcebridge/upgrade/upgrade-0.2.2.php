<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_2($module)
{
    $engine = _MYSQL_ENGINE_;
    $prefix = _DB_PREFIX_;

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_customer_backfill` (
        `id_sfmc_customer_backfill` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_shop` INT UNSIGNED NOT NULL,
        `cursor_customer_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
        `scanned_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `queued_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `started_at` DATETIME NULL,
        `updated_at` DATETIME NOT NULL,
        `completed_at` DATETIME NULL,
        PRIMARY KEY (`id_sfmc_customer_backfill`),
        UNIQUE KEY `uniq_sfmc_customer_backfill_shop` (`id_shop`),
        KEY `idx_sfmc_customer_backfill_status` (`status`)
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_CUSTOMER_BACKFILL_BATCH)) {
        Configuration::updateValue(PsSalesforceBridge::CONFIG_CUSTOMER_BACKFILL_BATCH, 50);
    }

    return true;
}
