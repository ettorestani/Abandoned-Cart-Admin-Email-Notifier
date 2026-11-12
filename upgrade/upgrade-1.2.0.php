<?php
/**
 * Upgrade to 1.2.0: new notification log columns (attempts, cart total) and cron alert delay (default 48 hours)
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
    return $module->ensureSchema()
        && Configuration::updateValue(
            AbandonedCartAdminNotifier::CONFIG_CRON_ALERT_HOURS,
            AbandonedCartAdminNotifier::DEFAULT_CRON_ALERT_HOURS
        );
}
