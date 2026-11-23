<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * v0.9.7 beta: protected staging-only BO cron token handoff.
 * Does not rotate tokens, modify config, G3/G7, sender, permissions or
 * campaigns. No automatic cron activation or external requests on upgrade.
 */
function upgrade_module_0_9_7($module)
{
    return true;
}
