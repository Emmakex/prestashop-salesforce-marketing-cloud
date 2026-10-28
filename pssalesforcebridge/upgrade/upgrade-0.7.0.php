<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_7_0($module)
{
    $defaults = [
        'PS_SFMC_RETENTION_QUEUE_DAYS' => 30,
        'PS_SFMC_RETENTION_LOG_DAYS' => 30,
        'PS_SFMC_RETENTION_JOURNEY_DAYS' => 90,
        'PS_SFMC_RETENTION_CART_DAYS' => 30,
        'PS_SFMC_RETENTION_RECOVERY_DAYS' => 30,
        'PS_SFMC_RETENTION_LAST_RUN_AT' => '',
    ];

    foreach ($defaults as $key => $value) {
        if (Configuration::get($key) === false && !Configuration::updateValue($key, $value)) {
            return false;
        }
    }

    return true;
}
