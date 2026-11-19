<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.9.1: OAuth credential storage and BO validation are opt-in only.
 * Never backfill secrets, call Salesforce or activate sends during upgrade.
 */
function upgrade_module_0_9_1($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
