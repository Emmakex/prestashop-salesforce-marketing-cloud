<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * 0.9.3: generic merchant code and Data Cloud source binding.
 * No implicit source for DO, no schema/SQL migration, no identity rewrite,
 * no customer/order POST, no changes to BC's encrypted credentials.
 */
function upgrade_module_0_9_3($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
