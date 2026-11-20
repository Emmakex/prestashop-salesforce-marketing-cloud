<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * v0.9.5 -- staged G8 cron preflight; no sender, SQL migration or scheduler.
 * Keep credentials, identities and previous manual one-shot receipts intact.
 */
function upgrade_module_0_9_5($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
