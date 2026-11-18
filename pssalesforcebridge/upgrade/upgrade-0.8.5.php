<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * G7 0.8.5: read-only source shape diagnostic, available only from BO.
 * No DDL, no backfill, no worker, no remote request.
 * Preserve all G3 tables, historical tables, namespace and HMAC key.
 * Stop local Test capture on upgrade until the owner explicitly re-arms it.
 */
function upgrade_module_0_8_5($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
