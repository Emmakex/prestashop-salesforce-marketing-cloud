<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.8: exposes OPTIONAL staging G7 DDL controls; the version upgrade
 * itself performs NO DDL. Reset local capture OFF, keep mode Test and
 * preserve namespace, HMAC, outbox, ledger, all historical records.
 */
function upgrade_module_0_8_8($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
