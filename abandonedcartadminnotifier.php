<?php
/**
 * Abandoned Cart Admin Email Notifier
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * @version   1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AbandonedCartAdminNotifier extends Module
{
    /**
     * Module configuration prefix
     */
    const CONFIG_PREFIX = 'ACN_';

    /**
     * Configuration keys
     */
    const CONFIG_EMAIL_RECIPIENTS = 'ACN_EMAIL_RECIPIENTS';
    const CONFIG_MODULE_ENABLED = 'ACN_MODULE_ENABLED';
    const CONFIG_SECURE_TOKEN = 'ACN_SECURE_TOKEN';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'abandonedcartadminnotifier';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Ettore Stani';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        );
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Abandoned Cart Admin Email Notifier');
        $this->description = $this->l('Sends email notifications to store administrators when a registered cart is abandoned.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module? All notification data will be lost.');
    }

    /**
     * Module installation
     *
     * @return bool
     */
    public function install()
    {
        // Generate secure token for cron
        $secureToken = bin2hex(random_bytes(32));

        return parent::install()
            && $this->installDatabase()
            && Configuration::updateValue(self::CONFIG_EMAIL_RECIPIENTS, '')
            && Configuration::updateValue(self::CONFIG_MODULE_ENABLED, 1)
            && Configuration::updateValue(self::CONFIG_SECURE_TOKEN, $secureToken);
    }

    /**
     * Module uninstallation
     *
     * @return bool
     */
    public function uninstall()
    {
        return $this->uninstallDatabase()
            && Configuration::deleteByName(self::CONFIG_EMAIL_RECIPIENTS)
            && Configuration::deleteByName(self::CONFIG_MODULE_ENABLED)
            && Configuration::deleteByName(self::CONFIG_SECURE_TOKEN)
            && parent::uninstall();
    }

    /**
     * Install database tables
     *
     * @return bool
     */
    private function installDatabase()
    {
        $sql_file = dirname(__FILE__) . '/sql/install.php';
        if (file_exists($sql_file)) {
            return include($sql_file);
        }
        return false;
    }

    /**
     * Uninstall database tables
     *
     * @return bool
     */
    private function uninstallDatabase()
    {
        $sql_file = dirname(__FILE__) . '/sql/uninstall.php';
        if (file_exists($sql_file)) {
            return include($sql_file);
        }
        return false;
    }

    /**
     * Module configuration page content
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        // Handle form submission
        if (Tools::isSubmit('submitAbandonedCartConfig')) {
            $output .= $this->processConfigurationForm();
        }

        // Handle log clearing
        if (Tools::isSubmit('clearNotificationLog')) {
            $output .= $this->clearNotificationLog();
        }

        // Display configuration form
        $output .= $this->renderConfigurationForm();

        // Display notification log
        $output .= $this->renderNotificationLog();

        // Display cron information
        $output .= $this->renderCronInfo();

        // Display support section
        $output .= $this->renderSupportSection();

        return $output;
    }

    /**
     * Render support section
     *
     * @return string HTML panel
     */
    private function renderSupportSection()
    {
        return $this->display(__FILE__, 'views/templates/admin/support.tpl');
    }

    /**
     * Process configuration form submission
     *
     * @return string HTML message
     */
    private function processConfigurationForm()
    {
        $emailRecipients = Tools::getValue(self::CONFIG_EMAIL_RECIPIENTS);
        $moduleEnabled = (int) Tools::getValue(self::CONFIG_MODULE_ENABLED);

        // Validate email addresses
        if (!empty($emailRecipients)) {
            $emails = array_map('trim', explode(',', $emailRecipients));
            foreach ($emails as $email) {
                if (!empty($email) && !Validate::isEmail($email)) {
                    return $this->displayError($this->l('One or more email addresses are not valid.'));
                }
            }
            // Clean and rejoin emails
            $emailRecipients = implode(',', array_filter($emails));
        }

        // Save configuration
        Configuration::updateValue(self::CONFIG_EMAIL_RECIPIENTS, pSQL($emailRecipients));
        Configuration::updateValue(self::CONFIG_MODULE_ENABLED, $moduleEnabled);

        return $this->displayConfirmation($this->l('Configuration saved successfully.'));
    }

    /**
     * Clear notification log (hides entries but keeps tracking data)
     *
     * @return string HTML message
     */
    private function clearNotificationLog()
    {
        $db = Db::getInstance();
        // Only hide log entries, don't delete - this preserves tracking to avoid duplicate notifications
        $result = $db->execute('UPDATE `' . _DB_PREFIX_ . 'abandoned_cart_notifications` SET `log_visible` = 0');

        if ($result) {
            return $this->displayConfirmation($this->l('Notification log cleared successfully.'));
        }
        return $this->displayError($this->l('Error clearing notification log.'));
    }

    /**
     * Render configuration form
     *
     * @return string HTML form
     */
    private function renderConfigurationForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitAbandonedCartConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Get configuration form structure
     *
     * @return array Form structure
     */
    private function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable Module'),
                        'name' => self::CONFIG_MODULE_ENABLED,
                        'is_bool' => true,
                        'desc' => $this->l('Enable or disable sending notifications.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Email Recipients'),
                        'name' => self::CONFIG_EMAIL_RECIPIENTS,
                        'desc' => $this->l('Enter one or more email addresses separated by comma to receive notifications.'),
                        'cols' => 60,
                        'rows' => 3,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Get configuration form values
     *
     * @return array Form values
     */
    private function getConfigFormValues()
    {
        return array(
            self::CONFIG_EMAIL_RECIPIENTS => Configuration::get(self::CONFIG_EMAIL_RECIPIENTS),
            self::CONFIG_MODULE_ENABLED => Configuration::get(self::CONFIG_MODULE_ENABLED),
        );
    }

    /**
     * Render notification log table
     *
     * @return string HTML table
     */
    private function renderNotificationLog()
    {
        $db = Db::getInstance();
        $notifications = $db->executeS('
            SELECT n.*, c.firstname, c.lastname, c.email
            FROM `' . _DB_PREFIX_ . 'abandoned_cart_notifications` n
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON n.id_customer = c.id_customer
            WHERE n.log_visible = 1
            ORDER BY n.date_sent DESC
            LIMIT 50
        ');

        $this->context->smarty->assign(array(
            'notifications' => $notifications,
            'clear_log_url' => $this->context->link->getAdminLink('AdminModules', true)
                . '&configure=' . $this->name . '&clearNotificationLog=1',
        ));

        return $this->display(__FILE__, 'views/templates/admin/notification_log.tpl');
    }

    /**
     * Render cron job information panel
     *
     * @return string HTML panel
     */
    private function renderCronInfo()
    {
        $secureToken = Configuration::get(self::CONFIG_SECURE_TOKEN);
        $cronUrl = $this->context->link->getModuleLink(
            $this->name,
            'cron',
            array('secure_token' => $secureToken),
            true
        );

        $this->context->smarty->assign(array(
            'cron_url' => $cronUrl,
            'secure_token' => $secureToken,
        ));

        return $this->display(__FILE__, 'views/templates/admin/cron_info.tpl');
    }

    /**
     * Process cron job - main method to scan abandoned carts
     *
     * @return array Statistics array with processed carts, sent emails, errors
     */
    public function processCronJob()
    {
        $stats = array(
            'processed' => 0,
            'sent' => 0,
            'errors' => 0,
            'error_messages' => array(),
        );

        // Clear configuration cache to ensure fresh values
        Configuration::clearConfigurationCacheForTesting();

        // Check if module is enabled (read without cache)
        if (!Configuration::get(self::CONFIG_MODULE_ENABLED, null, null, null, false)) {
            $stats['error_messages'][] = 'Module is disabled';
            return $stats;
        }

        // Check if email recipients are configured (read without cache)
        $recipients = Configuration::get(self::CONFIG_EMAIL_RECIPIENTS, null, null, null, false);
        if (empty($recipients)) {
            $stats['error_messages'][] = 'No email recipients configured';
            return $stats;
        }

        // Get abandoned carts
        $abandonedCarts = $this->getAbandonedCarts();

        foreach ($abandonedCarts as $cart) {
            $stats['processed']++;

            try {
                $result = $this->sendNotificationEmail($cart, $recipients);
                if ($result) {
                    $this->logNotification($cart['id_cart'], $cart['id_customer'], 'success', null);
                    $stats['sent']++;
                } else {
                    $errorMsg = 'Failed to send email for cart ID: ' . $cart['id_cart'];
                    $this->logNotification($cart['id_cart'], $cart['id_customer'], 'failed', $errorMsg);
                    $stats['errors']++;
                    $stats['error_messages'][] = $errorMsg;
                }
            } catch (Exception $e) {
                $errorMsg = 'Exception for cart ID ' . $cart['id_cart'] . ': ' . $e->getMessage();
                $this->logNotification($cart['id_cart'], $cart['id_customer'], 'failed', $errorMsg);
                $stats['errors']++;
                $stats['error_messages'][] = $errorMsg;
                $this->log($errorMsg, 3);
            }
        }

        return $stats;
    }

    /**
     * Get abandoned carts that meet notification criteria
     *
     * @return array Array of abandoned cart data
     */
    public function getAbandonedCarts()
    {
        $db = Db::getInstance();

        // Get carts abandoned at least 24 hours ago
        $maxTime = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $sql = '
            SELECT
                c.id_cart,
                c.id_customer,
                c.date_upd,
                cu.firstname,
                cu.lastname,
                cu.email
            FROM `' . _DB_PREFIX_ . 'cart` c
            INNER JOIN `' . _DB_PREFIX_ . 'customer` cu ON c.id_customer = cu.id_customer
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON c.id_cart = o.id_cart
            LEFT JOIN `' . _DB_PREFIX_ . 'abandoned_cart_notifications` acn ON c.id_cart = acn.id_cart
            WHERE c.id_customer > 0
            AND o.id_order IS NULL
            AND acn.id_notification IS NULL
            AND c.date_upd <= \'' . pSQL($maxTime) . '\'
            ORDER BY c.date_upd DESC
            LIMIT 100
        ';

        $carts = $db->executeS($sql);

        return $carts ? $carts : array();
    }

    /**
     * Get products in a cart
     *
     * @param int $idCart Cart ID
     * @return array Array of products with name and quantity
     */
    private function getCartProducts($idCart)
    {
        $db = Db::getInstance();
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $sql = '
            SELECT
                cp.quantity,
                pl.name
            FROM `' . _DB_PREFIX_ . 'cart_product` cp
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON cp.id_product = pl.id_product
                AND pl.id_lang = ' . (int) $idLang . '
                AND pl.id_shop = cp.id_shop
            WHERE cp.id_cart = ' . (int) $idCart;

        $products = $db->executeS($sql);

        return $products ? $products : array();
    }

    /**
     * Get cart total amount
     *
     * @param int $idCart Cart ID
     * @return float Cart total
     */
    private function getCartTotal($idCart)
    {
        $cart = new Cart((int) $idCart);
        return $cart->getOrderTotal(true, Cart::BOTH);
    }

    /**
     * Send notification email for an abandoned cart
     *
     * @param array $cartData Cart and customer data
     * @param string $recipients Comma-separated email recipients
     * @return bool Success status
     */
    public function sendNotificationEmail($cartData, $recipients)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $shopEmail = Configuration::get('PS_SHOP_EMAIL');
        $shopName = Configuration::get('PS_SHOP_NAME');

        // Get cart products
        $products = $this->getCartProducts((int) $cartData['id_cart']);
        $productList = '';
        foreach ($products as $product) {
            $productList .= '- ' . $product['name'] . ' (Quantity: ' . $product['quantity'] . ")\n";
        }

        // Get cart total
        $cartTotal = $this->getCartTotal((int) $cartData['id_cart']);
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $formattedTotal = Tools::displayPrice($cartTotal, $currency);

        // Prepare email template variables
        $templateVars = array(
            '{id_customer}' => $cartData['id_customer'],
            '{firstname}' => $cartData['firstname'],
            '{lastname}' => $cartData['lastname'],
            '{customer_email}' => $cartData['email'],
            '{id_cart}' => $cartData['id_cart'],
            '{date_upd}' => $cartData['date_upd'],
            '{total_amount}' => $formattedTotal,
            '{product_list}' => $productList,
        );

        // Parse recipients - remove duplicates and empty values
        $emailList = array_map('trim', explode(',', $recipients));
        $emailList = array_filter($emailList);
        $emailList = array_unique($emailList);
        $emailList = array_values($emailList);

        // Send email to each recipient individually
        $allSuccess = true;
        foreach ($emailList as $toEmail) {
            $result = Mail::Send(
                $idLang,
                'abandonedcart',
                $this->l('New abandoned cart'),
                $templateVars,
                $toEmail,
                null,
                $shopEmail,
                $shopName,
                null,
                null,
                dirname(__FILE__) . '/mails/',
                false,
                null
            );

            if (!$result) {
                $this->log('Failed to send email to: ' . $toEmail, 3);
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    /**
     * Log notification to database
     *
     * @param int $idCart Cart ID
     * @param int $idCustomer Customer ID
     * @param string $status Email status (success/failed)
     * @param string|null $errorMessage Error message if failed
     * @return bool
     */
    public function logNotification($idCart, $idCustomer, $status, $errorMessage = null)
    {
        $db = Db::getInstance();

        $data = array(
            'id_cart' => (int) $idCart,
            'id_customer' => (int) $idCustomer,
            'date_sent' => date('Y-m-d H:i:s'),
            'email_status' => pSQL($status),
            'error_message' => $errorMessage ? pSQL($errorMessage) : null,
            'log_visible' => 1,
            'date_add' => date('Y-m-d H:i:s'),
        );

        return $db->insert('abandoned_cart_notifications', $data);
    }

    /**
     * Internal logging method
     *
     * @param string $message Log message
     * @param int $severity Severity level (1=info, 2=warning, 3=error)
     * @return void
     */
    private function log($message, $severity = 1)
    {
        PrestaShopLogger::addLog(
            '[AbandonedCartAdminNotifier] ' . $message,
            $severity,
            null,
            null,
            null,
            true
        );
    }
}
