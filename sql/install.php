<?php
/**
 * Database installation script
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'abandoned_cart_notifications` (
    `id_notification` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_cart` INT(11) UNSIGNED NOT NULL,
    `id_customer` INT(11) UNSIGNED NOT NULL,
    `date_sent` DATETIME NOT NULL,
    `email_status` ENUM("success", "failed") NOT NULL DEFAULT "success",
    `error_message` TEXT NULL,
    `log_visible` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_notification`),
    INDEX `idx_id_cart` (`id_cart`),
    INDEX `idx_id_customer` (`id_customer`),
    INDEX `idx_date_sent` (`date_sent`),
    INDEX `idx_log_visible` (`log_visible`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

return Db::getInstance()->execute($sql);
