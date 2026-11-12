<?php
/**
 * Upgrade to 1.2.0: new notification log columns (attempts)
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param AbandonedCartAdminNotifier $module
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    // Existing rows get attempts = 1 from the column default
    return $module->ensureSchema();
}
