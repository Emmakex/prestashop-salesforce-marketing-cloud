<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0($module)
{
    $prefix = _DB_PREFIX_;
    $engine = _MYSQL_ENGINE_;

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_order_sync` (
        `id_sfmc_order_sync` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_shop` INT UNSIGNED NOT NULL,
        `id_order` INT UNSIGNED NOT NULL,
        `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
        `contact_key` VARCHAR(191) NOT NULL DEFAULT '',
        `payload_hash` CHAR(40) NOT NULL,
        `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_sfmc_order_sync`),
        UNIQUE KEY `uniq_sfmc_order_shop` (`id_shop`, `id_order`),
        KEY `idx_sfmc_order_cart` (`id_shop`, `id_cart`),
        KEY `idx_sfmc_order_contact` (`contact_key`)
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_ORDER_DE_KEY)) {
        Configuration::updateValue(PsSalesforceBridge::CONFIG_ORDER_DE_KEY, 'PS_ORDERS');
    }

    return $module->registerHook('actionValidateOrder')
        && $module->registerHook('actionOrderStatusPostUpdate');
}
