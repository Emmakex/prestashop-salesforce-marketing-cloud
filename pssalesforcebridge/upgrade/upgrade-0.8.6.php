<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.6: Safe per-entity diagnostic only, never a Salesforce sender.
 * This is a non-destructive upgrade preserving G3 outbox, ledger,
 * installation namespace, HMAC key and historical 0.7.1 data.
 * Revoke local Test capture until the owner explicitly re-arms it.
 */
function upgrade_module_0_8_6($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
