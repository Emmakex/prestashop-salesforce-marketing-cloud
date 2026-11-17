<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.2 author metadata and MariaDB 11.8 staging CHECK certification.
 *
 * Fail closed on every upgrade: preserve source settings, tables and HMAC
 * keys, keep outbound services blocked and local capture switched off.
 * Only a separate owner action in BO may arm staging local capture.
 */
function upgrade_module_0_8_2($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
