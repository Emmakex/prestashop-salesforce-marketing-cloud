<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_6_0($module)
{
    $engine = _MYSQL_ENGINE_;
    $prefix = _DB_PREFIX_;
    $db = Db::getInstance();

    $column = $db->getRow("SHOW COLUMNS FROM `{$prefix}sfmc_cart` LIKE 'recovered_at'");
    if (!$column) {
        if (!$db->execute("ALTER TABLE `{$prefix}sfmc_cart` ADD `recovered_at` DATETIME NULL AFTER `abandoned_at`")) {
            return false;
        }
    }

    $query = "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_recovery_token` (
        `id_sfmc_recovery_token` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `token_hash` CHAR(64) NOT NULL,
        `id_shop` INT UNSIGNED NOT NULL,
        `id_cart` INT UNSIGNED NOT NULL,
        `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
        `contact_key` VARCHAR(191) NOT NULL DEFAULT '',
        `snapshot_hash` CHAR(40) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME NULL,
        `recovered_cart_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_sfmc_recovery_token`),
        UNIQUE KEY `uniq_sfmc_recovery_token_hash` (`token_hash`),
        KEY `idx_sfmc_recovery_source` (`id_shop`, `id_cart`),
        KEY `idx_sfmc_recovery_expiry` (`expires_at`, `used_at`),
        KEY `idx_sfmc_recovery_cart` (`recovered_cart_id`)
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$db->execute($query)) {
        return false;
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_RECOVERY_TOKEN_TTL_HOURS)) {
        Configuration::updateValue(PsSalesforceBridge::CONFIG_RECOVERY_TOKEN_TTL_HOURS, 168);
    }

    return true;
}
