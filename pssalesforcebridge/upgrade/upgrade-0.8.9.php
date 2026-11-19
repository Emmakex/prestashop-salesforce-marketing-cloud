<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.8.9: controlled manual single-customer / single-order UAT in BO.
 * Upgrade NEVER sends, creates tables, enables capture, edits Growth
 * consent, clears historical data or migrates the original G3 outbox.
 * Test and capture OFF are restored even from an armed 0.8.8 staging.
 */
function upgrade_module_0_8_9($module)
{
    return Configuration::updateValue('PS_SFMC_MODE','test')
        && Configuration::updateValue('PS_GROWTH_LOCAL_CAPTURE','off');
}
