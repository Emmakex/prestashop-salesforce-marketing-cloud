<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 0.7.1 -> 0.8.0 to a Growth-only module.
 * NEVER drop or read historical customer/cart/queue/Journey tables.
 * NEVER delete credentials, tokens or old settings without a separately
 * approved merchant backup and data-erasure decision.
 *
 * The only mutation is the Test safety gate. The new runtime contains
 * no legacy send/ingest paths and no new SQL schema.
 */
function upgrade_module_0_8_0($module)
{
    return Configuration::updateValue('PS_SFMC_MODE', 'test');
}
