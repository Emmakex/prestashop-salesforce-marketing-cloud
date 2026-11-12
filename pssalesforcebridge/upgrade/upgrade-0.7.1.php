<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.7.1 changes cart-consent evaluation and revalidation logic only.
 * Preserve all merchant settings, existing tables and queue rows.
 */
function upgrade_module_0_7_1($module)
{
    return true;
}
