<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.9.0 pre-beta client UX. No schema change, no automatic delivery,
 * no new credential persistence, no deletion of historical evidence.
 */
function upgrade_module_0_9_0($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
