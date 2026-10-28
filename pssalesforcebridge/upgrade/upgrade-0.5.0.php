<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_5_0($module)
{
    $engine = _MYSQL_ENGINE_;
    $prefix = _DB_PREFIX_;

    $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_journey_event` (
        `id_sfmc_journey_event` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_sfmc_queue` BIGINT UNSIGNED NOT NULL,
        `id_shop` INT UNSIGNED NOT NULL,
        `id_cart` INT UNSIGNED NOT NULL,
        `contact_key` VARCHAR(191) NOT NULL,
        `event_definition_key` VARCHAR(191) NOT NULL,
        `snapshot_hash` CHAR(40) NOT NULL,
        `bridge_event_key` VARCHAR(191) NOT NULL,
        `status` VARCHAR(16) NOT NULL DEFAULT 'processing',
        `event_instance_id` VARCHAR(191) NULL,
        `suppression_reason` VARCHAR(191) NULL,
        `last_error` TEXT NULL,
        `sent_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_sfmc_journey_event`),
        UNIQUE KEY `uniq_sfmc_journey_queue` (`id_sfmc_queue`),
        KEY `idx_sfmc_journey_cart` (`id_shop`, `id_cart`),
        KEY `idx_sfmc_journey_status` (`status`),
        KEY `idx_sfmc_journey_contact` (`contact_key`)
    ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    if (!Configuration::get(PsSalesforceBridge::CONFIG_JOURNEY_EVENT_DEFINITION_KEY)) {
        Configuration::updateValue(
            PsSalesforceBridge::CONFIG_JOURNEY_EVENT_DEFINITION_KEY,
            'PRESTASHOP_ABANDONED_CART'
        );
    }

    return true;
}
