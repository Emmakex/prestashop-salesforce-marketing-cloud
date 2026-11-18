<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.4: accept the pre-status Order object at actionValidateOrder time.
 *
 * This upgrade performs NO DDL, replay, backfill, remote request or sender.
 * Explicit Test mode with local capture OFF is mandatory after updating.
 * Historical 0.7.1 tables, Growth G3 outbox and HMAC key are preserved.
 */
function upgrade_module_0_8_4($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
