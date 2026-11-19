<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * 0.9.2 adds the BCABH merchant alias for the existing ABH PST connector.
 * No identity rewrite, DB migration, OAuth change, external POST or
 * automatic data/marketing sends.
 */
function upgrade_module_0_9_2($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
