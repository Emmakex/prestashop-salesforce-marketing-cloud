<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.1 Test-only local capture capability.
 *
 * Upgrade NEVER installs G3 tables or activates local capture. A separate
 * owner-entered BO action is needed on a staging shop with an enforcing
 * MySQL8/MariaDB10.11 SQL engine. No external Salesforce requests.
 * Preserve all 0.7.1 records and 0.8.0 merchant namespace settings.
 */
function upgrade_module_0_8_1($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE', 'off');
}
