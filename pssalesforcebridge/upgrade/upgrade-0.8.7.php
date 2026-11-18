<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.7: read-only Back Office G7 HTML escaping regression fix.
 * Preserve original G3 binding/outbox/ledger and HMAC as-is.
 * No schema mutation, backfill, Salesforce call, retry, consent or send.
 * Revoke staging capture until owner explicitly arms it after upgrade.
 */
function upgrade_module_0_8_7($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
