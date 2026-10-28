<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_6_1($module)
{
    $engine = _MYSQL_ENGINE_;
    $prefix = _DB_PREFIX_;

    $query = "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_recovery_order` (
        `id_sfmc_recovery_order` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_sfmc_recovery_token` BIGINT UNSIGNED NOT NULL,
        `id_shop` INT UNSIGNED NOT NULL,
        `source_cart_id` INT UNSIGNED NOT NULL,
        `recovered_cart_id` INT UNSIGNED NOT NULL,
        `id_order` INT UNSIGNED NOT NULL,
        `order_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `currency` VARCHAR(8) NOT NULL DEFAULT '',
        `state_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `paid` TINYINT(1) NOT NULL DEFAULT 0,
        `valid` TINYINT(1) NOT NULL DEFAULT 0,
        `converted_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_sfmc_recovery_order`),
        UNIQUE KEY `uniq_sfmc_recovery_order` (`id_shop`, `id_order`),
        KEY `idx_sfmc_recovery_order_token` (`id_sfmc_recovery_token`),
        KEY `idx_sfmc_recovery_order_cart` (`id_shop`, `recovered_cart_id`),
        KEY `idx_sfmc_recovery_order_paid` (`paid`, `currency`)
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return (bool) Db::getInstance()->execute($query);
}
