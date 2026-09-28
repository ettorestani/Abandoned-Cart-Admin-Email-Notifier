<?php
/**
 * Upgrade to 1.1.0: one notification row per cart, so failed notifications can be retried in place,
 * and configurable cart age window (defaults: 24 hours, 7 days)
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
function upgrade_module_1_1_0($module)
{
    $db = Db::getInstance();
    $table = '`' . _DB_PREFIX_ . 'abandoned_cart_notifications`';

    // Keep only the latest row per cart before enforcing uniqueness
    $dedupe = $db->execute('
        DELETE n1 FROM ' . $table . ' n1
        INNER JOIN ' . $table . ' n2
            ON n1.id_cart = n2.id_cart AND n1.id_notification < n2.id_notification
    ');

    $hasOldIndex = (bool) $db->executeS('SHOW INDEX FROM ' . $table . ' WHERE Key_name = \'idx_id_cart\'');

    return $dedupe
        && Configuration::updateValue(
            AbandonedCartAdminNotifier::CONFIG_MIN_CART_AGE_HOURS,
            AbandonedCartAdminNotifier::DEFAULT_MIN_CART_AGE_HOURS
        )
        && Configuration::updateValue(
            AbandonedCartAdminNotifier::CONFIG_MAX_CART_AGE_DAYS,
            AbandonedCartAdminNotifier::DEFAULT_MAX_CART_AGE_DAYS
        )
        && (!$hasOldIndex || $db->execute('ALTER TABLE ' . $table . ' DROP INDEX `idx_id_cart`'))
        && $db->execute('ALTER TABLE ' . $table . ' ADD UNIQUE KEY `uniq_id_cart` (`id_cart`)');
}
