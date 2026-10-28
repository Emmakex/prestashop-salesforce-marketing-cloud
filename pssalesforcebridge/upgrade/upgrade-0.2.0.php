<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_0($module)
{
    $engine = _MYSQL_ENGINE_;
    $prefix = _DB_PREFIX_;

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_customer_sync` (
        `id_sfmc_customer_sync` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_shop` INT UNSIGNED NOT NULL,
        `id_customer` INT UNSIGNED NOT NULL,
        `contact_key` VARCHAR(191) NOT NULL,
        `payload_hash` CHAR(40) NOT NULL,
        `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_sfmc_customer_sync`),
        UNIQUE KEY `uniq_sfmc_customer_shop` (`id_shop`, `id_customer`),
        UNIQUE KEY `uniq_sfmc_customer_contact` (`contact_key`)
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    return $module->registerHook('actionCustomerAccountAdd')
        && $module->registerHook('actionCustomerAccountUpdate');
}
