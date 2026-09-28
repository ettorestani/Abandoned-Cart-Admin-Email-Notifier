<?php
/**
 * Abandoned Cart Admin Email Notifier
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * @version   1.1.0
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
    const CONFIG_MIN_CART_AGE_HOURS = 'ACN_MIN_CART_AGE_HOURS';
    const CONFIG_MAX_CART_AGE_DAYS = 'ACN_MAX_CART_AGE_DAYS';

    /**
     * Cart selection window defaults: carts idle for at least MIN hours and at most MAX days
     */
    const DEFAULT_MIN_CART_AGE_HOURS = 24;
    const DEFAULT_MAX_CART_AGE_DAYS = 7;
    const MAX_ALLOWED_CART_AGE_DAYS = 90;
    const MAX_CARTS_PER_RUN = 100;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'abandonedcartadminnotifier';
        $this->tab = 'administration';
        $this->version = '1.1.0';
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
            && Configuration::updateValue(self::CONFIG_SECURE_TOKEN, $secureToken)
            && Configuration::updateValue(self::CONFIG_MIN_CART_AGE_HOURS, self::DEFAULT_MIN_CART_AGE_HOURS)
            && Configuration::updateValue(self::CONFIG_MAX_CART_AGE_DAYS, self::DEFAULT_MAX_CART_AGE_DAYS);
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
            && Configuration::deleteByName(self::CONFIG_MIN_CART_AGE_HOURS)
            && Configuration::deleteByName(self::CONFIG_MAX_CART_AGE_DAYS)
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
        $emails = $this->parseEmailList(Tools::getValue(self::CONFIG_EMAIL_RECIPIENTS));
        $moduleEnabled = (int) (bool) Tools::getValue(self::CONFIG_MODULE_ENABLED);
        $minHours = trim((string) Tools::getValue(self::CONFIG_MIN_CART_AGE_HOURS));
        $maxDays = trim((string) Tools::getValue(self::CONFIG_MAX_CART_AGE_DAYS));

        foreach ($emails as $email) {
            if (!Validate::isEmail($email)) {
                return $this->displayError($this->l('One or more email addresses are not valid.'));
            }
        }

        if (!ctype_digit($minHours) || (int) $minHours < 1) {
            return $this->displayError($this->l('The inactivity threshold must be a whole number of hours, at least 1.'));
        }
        if (!ctype_digit($maxDays) || (int) $maxDays < 1 || (int) $maxDays > self::MAX_ALLOWED_CART_AGE_DAYS) {
            return $this->displayError(sprintf(
                $this->l('The maximum cart age must be a whole number of days between 1 and %d.'),
                self::MAX_ALLOWED_CART_AGE_DAYS
            ));
        }
        if ((int) $maxDays * 24 <= (int) $minHours) {
            return $this->displayError($this->l('The maximum cart age must be longer than the inactivity threshold.'));
        }

        // Configuration::updateValue() escapes the value itself
        Configuration::updateValue(self::CONFIG_EMAIL_RECIPIENTS, implode(',', $emails));
        Configuration::updateValue(self::CONFIG_MODULE_ENABLED, $moduleEnabled);
        Configuration::updateValue(self::CONFIG_MIN_CART_AGE_HOURS, (int) $minHours);
        Configuration::updateValue(self::CONFIG_MAX_CART_AGE_DAYS, (int) $maxDays);

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
                    array(
                        'type' => 'text',
                        'label' => $this->l('Inactivity threshold'),
                        'name' => self::CONFIG_MIN_CART_AGE_HOURS,
                        'suffix' => $this->l('hours'),
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('A cart is considered abandoned when it has not been updated for this many hours. Below 24 hours, run the cron job every hour.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Maximum cart age'),
                        'name' => self::CONFIG_MAX_CART_AGE_DAYS,
                        'suffix' => $this->l('days'),
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('Carts idle for longer than this are never notified. Failed notifications are retried until the cart exceeds this age.'),
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
            self::CONFIG_MIN_CART_AGE_HOURS => $this->getMinCartAgeHours(),
            self::CONFIG_MAX_CART_AGE_DAYS => $this->getMaxCartAgeDays(),
        );
    }

    /**
     * @return int
     */
    private function getMinCartAgeHours()
    {
        $hours = (int) Configuration::get(self::CONFIG_MIN_CART_AGE_HOURS);

        return $hours > 0 ? $hours : self::DEFAULT_MIN_CART_AGE_HOURS;
    }

    /**
     * @return int
     */
    private function getMaxCartAgeDays()
    {
        $days = (int) Configuration::get(self::CONFIG_MAX_CART_AGE_DAYS);

        return $days > 0 ? $days : self::DEFAULT_MAX_CART_AGE_DAYS;
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
            // The cron must run at least as often as the threshold, or carts are notified late
            'cron_schedule' => $this->getMinCartAgeHours() < 24 ? '0 * * * *' : '0 2 * * *',
        ));

        return $this->display(__FILE__, 'views/templates/admin/cron_info.tpl');
    }

    /**
     * Process cron job - main method to scan abandoned carts
     *
     * @param bool $dryRun List the carts that would be notified without sending or logging anything
     * @return array Statistics array with processed carts, sent emails, errors
     */
    public function processCronJob($dryRun = false)
    {
        $stats = array(
            'processed' => 0,
            'sent' => 0,
            'errors' => 0,
            'error_messages' => array(),
        );

        if ($dryRun) {
            // Works even with the module disabled, so the selection can be checked before enabling it
            $stats['carts'] = array();
            foreach ($this->getAbandonedCarts() as $cart) {
                $stats['processed']++;
                $stats['carts'][] = array(
                    'id_cart' => (int) $cart['id_cart'],
                    'id_customer' => (int) $cart['id_customer'],
                    'date_upd' => $cart['date_upd'],
                );
            }

            return $stats;
        }

        if (!Configuration::get(self::CONFIG_MODULE_ENABLED)) {
            $stats['error_messages'][] = 'Module is disabled';
            return $stats;
        }

        $recipients = $this->parseEmailList(Configuration::get(self::CONFIG_EMAIL_RECIPIENTS));
        if (empty($recipients)) {
            $stats['error_messages'][] = 'No email recipients configured';
            return $stats;
        }

        // Prevent concurrent runs from sending the same notification twice
        if (!$this->acquireLock()) {
            $stats['error_messages'][] = 'Another cron run is already in progress';
            return $stats;
        }

        try {
            foreach ($this->getAbandonedCarts() as $cart) {
                $stats['processed']++;

                try {
                    $failedRecipients = $this->sendNotificationEmail($cart, $recipients);
                    if (count($failedRecipients) < count($recipients)) {
                        // At least one recipient got it: don't retry, or the others would get duplicates
                        $errorMsg = $failedRecipients ? 'Failed recipients: ' . implode(', ', $failedRecipients) : null;
                        $this->logNotification($cart['id_cart'], $cart['id_customer'], 'success', $errorMsg);
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
        } finally {
            $this->releaseLock();
        }

        return $stats;
    }

    /**
     * Get abandoned carts that meet notification criteria
     *
     * Failed notifications are retried on each run until the cart leaves the age window.
     *
     * @return array Array of abandoned cart data
     */
    public function getAbandonedCarts()
    {
        $db = Db::getInstance();

        $maxDate = date('Y-m-d H:i:s', strtotime('-' . $this->getMinCartAgeHours() . ' hours'));
        $minDate = date('Y-m-d H:i:s', strtotime('-' . $this->getMaxCartAgeDays() . ' days'));

        $sql = '
            SELECT
                c.id_cart,
                c.id_customer,
                c.id_currency,
                c.date_upd,
                cu.firstname,
                cu.lastname,
                cu.email
            FROM `' . _DB_PREFIX_ . 'cart` c
            INNER JOIN `' . _DB_PREFIX_ . 'customer` cu ON c.id_customer = cu.id_customer
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON c.id_cart = o.id_cart
            WHERE c.id_customer > 0
            AND cu.deleted = 0
            AND o.id_order IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM `' . _DB_PREFIX_ . 'abandoned_cart_notifications` acn
                WHERE acn.id_cart = c.id_cart AND acn.email_status = \'success\'
            )
            AND c.date_upd BETWEEN \'' . pSQL($minDate) . '\' AND \'' . pSQL($maxDate) . '\'
            AND EXISTS (
                SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp WHERE cp.id_cart = c.id_cart
            )
            AND NOT EXISTS (
                SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o2
                WHERE o2.id_customer = c.id_customer AND o2.date_add >= c.date_upd
            )
            ORDER BY c.date_upd DESC
            LIMIT ' . (int) self::MAX_CARTS_PER_RUN;

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
     * Get cart total amount formatted in the cart's own currency
     *
     * @param int $idCart Cart ID
     * @return string Formatted cart total
     */
    private function getFormattedCartTotal($idCart)
    {
        $cart = new Cart((int) $idCart);
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        }

        return Tools::displayPrice($cart->getOrderTotal(true, Cart::BOTH), $currency);
    }

    /**
     * Send notification email for an abandoned cart
     *
     * @param array $cartData Cart and customer data
     * @param array $recipients Recipient email addresses
     * @return array Recipients the email could not be sent to
     */
    public function sendNotificationEmail($cartData, array $recipients)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $shopEmail = Configuration::get('PS_SHOP_EMAIL');
        $shopName = Configuration::get('PS_SHOP_NAME');

        $productList = '';
        foreach ($this->getCartProducts((int) $cartData['id_cart']) as $product) {
            $productList .= '- ' . $this->sanitizeMailValue($product['name'])
                . ' (' . $this->l('Quantity') . ': ' . (int) $product['quantity'] . ")\n";
        }

        $templateVars = array(
            '{id_customer}' => (int) $cartData['id_customer'],
            '{firstname}' => $this->sanitizeMailValue($cartData['firstname']),
            '{lastname}' => $this->sanitizeMailValue($cartData['lastname']),
            '{customer_email}' => $this->sanitizeMailValue($cartData['email']),
            '{id_cart}' => (int) $cartData['id_cart'],
            '{date_upd}' => $this->sanitizeMailValue($cartData['date_upd']),
            '{total_amount}' => $this->getFormattedCartTotal((int) $cartData['id_cart']),
            '{product_list}' => $productList,
        );

        // Send email to each recipient individually
        $failedRecipients = array();
        foreach ($recipients as $toEmail) {
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
                $failedRecipients[] = $toEmail;
            }
        }

        return $failedRecipients;
    }

    /**
     * Neutralize customer-controlled values before they are injected into the email templates
     *
     * Mail::Send() decodes HTML entities in template vars, so escaping would be undone:
     * decode fully here and drop markup and placeholder delimiters instead.
     *
     * @param string $value
     * @return string
     */
    private function sanitizeMailValue($value)
    {
        $value = (string) $value;
        do {
            $previous = $value;
            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        } while ($value !== $previous);

        return str_replace(array('<', '>', '{', '}'), '', $value);
    }

    /**
     * Split a recipient string (comma, semicolon or newline separated) into unique addresses
     *
     * @param string $value
     * @return array
     */
    private function parseEmailList($value)
    {
        $emails = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($emails));
    }

    /**
     * Acquire a MySQL named lock so only one cron run processes carts at a time
     *
     * @return bool
     */
    private function acquireLock()
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT GET_LOCK(\'' . pSQL($this->getLockName()) . '\', 0)',
            false
        );
    }

    /**
     * @return void
     */
    private function releaseLock()
    {
        Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($this->getLockName()) . '\')', false);
    }

    /**
     * @return string
     */
    private function getLockName()
    {
        // Named locks are server-wide: scope to this database
        return substr(_DB_NAME_ . '_' . _DB_PREFIX_ . 'acn_cron', 0, 64);
    }

    /**
     * Log notification to database (one row per cart, updated on retry)
     *
     * @param int $idCart Cart ID
     * @param int $idCustomer Customer ID
     * @param string $status Email status (success/failed)
     * @param string|null $errorMessage Error message if failed
     * @return bool
     */
    public function logNotification($idCart, $idCustomer, $status, $errorMessage = null)
    {
        $now = date('Y-m-d H:i:s');
        $status = $status === 'success' ? 'success' : 'failed';
        $error = $errorMessage === null ? 'NULL' : '\'' . pSQL($errorMessage) . '\'';

        return Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'abandoned_cart_notifications`
                (`id_cart`, `id_customer`, `date_sent`, `email_status`, `error_message`, `log_visible`, `date_add`)
            VALUES (' . (int) $idCart . ', ' . (int) $idCustomer . ', \'' . pSQL($now) . '\', \'' . $status . '\', ' . $error . ', 1, \'' . pSQL($now) . '\')
            ON DUPLICATE KEY UPDATE
                `date_sent` = VALUES(`date_sent`),
                `email_status` = VALUES(`email_status`),
                `error_message` = VALUES(`error_message`),
                `log_visible` = 1
        ');
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
