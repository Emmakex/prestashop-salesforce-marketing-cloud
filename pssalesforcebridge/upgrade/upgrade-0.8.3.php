<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.3: fix optional source fields at initial account creation.
 *
 * Preserve the existing Growth G3 outbox/ledger, namespace and per-shop
 * HMAC key. Do not automatically re-enable local capture after upgrade.
 * Do not execute replays or invoke any Salesforce operations.
 */
function upgrade_module_0_8_3($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
