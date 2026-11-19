<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * v0.9.4: safe local-preview diagnostics and separate send-attempt guidance.
 * No DDL, credentials, identity, source binding, customer data, OAuth,
 * capture activation or outbound events are touched on upgrade.
 */
function upgrade_module_0_9_4($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
