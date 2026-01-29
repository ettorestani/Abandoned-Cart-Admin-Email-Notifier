<?php
/**
 * Database uninstallation script
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'abandoned_cart_notifications`;';

return Db::getInstance()->execute($sql);
