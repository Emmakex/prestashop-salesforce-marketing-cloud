<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * v0.9.6 beta: add the explicit protected staging dispatch_pair operation.
 *
 * No configuration, schema, credential, consent, queue or ownership change.
 * Never enable a cron scheduler, G8 approval, capture, retry or campaigns
 * during update. Preserve all existing G3/G7 receipts and source bindings.
 */
function upgrade_module_0_9_6($module)
{
    return true;
}
