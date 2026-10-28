<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_0($module)
{
    $engine = _MYSQL_ENGINE_;
    $prefix = _DB_PREFIX_;

    $queries = [
        "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_cart` (
            `id_sfmc_cart` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
            `contact_key` VARCHAR(191) NOT NULL DEFAULT '',
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `currency` VARCHAR(8) NOT NULL DEFAULT '',
            `total` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `product_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `consent_mode` VARCHAR(32) NOT NULL DEFAULT 'marketing_optin',
            `eligible` TINYINT(1) NOT NULL DEFAULT 0,
            `eligibility_reason` VARCHAR(64) NOT NULL DEFAULT '',
            `status` VARCHAR(16) NOT NULL DEFAULT 'active',
            `last_activity` DATETIME NOT NULL,
            `abandon_after` DATETIME NOT NULL,
            `abandoned_at` DATETIME NULL,
            `snapshot_hash` CHAR(40) NOT NULL,
            `payload` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_sfmc_cart`),
            UNIQUE KEY `uniq_sfmc_cart_shop` (`id_shop`, `id_cart`),
            KEY `idx_sfmc_cart_candidate` (`status`, `eligible`, `abandon_after`),
            KEY `idx_sfmc_cart_contact` (`contact_key`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_cart_item` (
            `id_sfmc_cart_item` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `line_key` VARCHAR(160) NOT NULL,
            `id_product` INT UNSIGNED NOT NULL DEFAULT 0,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `id_customization` INT UNSIGNED NOT NULL DEFAULT 0,
            `sku` VARCHAR(128) NOT NULL DEFAULT '',
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `quantity` INT NOT NULL DEFAULT 0,
            `unit_price` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `line_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `payload_hash` CHAR(40) NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_sfmc_cart_item`),
            UNIQUE KEY `uniq_sfmc_cart_item` (`id_shop`, `id_cart`, `line_key`),
            KEY `idx_sfmc_cart_item_cart` (`id_shop`, `id_cart`)
        ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        if (!Db::getInstance()->execute($query)) {
            return false;
        }
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_CART_CONSENT_MODE)) {
        Configuration::updateValue(PsSalesforceBridge::CONFIG_CART_CONSENT_MODE, 'marketing_optin');
    }

    return $module->registerHook('actionCartSave');
}
