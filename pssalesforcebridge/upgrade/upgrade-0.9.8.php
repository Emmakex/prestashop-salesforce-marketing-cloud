<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * v0.9.8: Fix protected staging cron token display in PrestaShop 9.
 * No migration/rotation/credential creation on upgrade. Missing cron
 * token may be initialized only by explicit, gated BO POST.
 */
function upgrade_module_0_9_8($module)
{
    return true;
}
