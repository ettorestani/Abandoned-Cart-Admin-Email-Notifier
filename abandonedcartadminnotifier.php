<?php
/**
 * Abandoned Cart Admin Email Notifier
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * @version   1.2.0
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
    const CONFIG_CRON_ALERT_HOURS = 'ACN_CRON_ALERT_HOURS';
    const CONFIG_LAST_RUN = 'ACN_LAST_RUN';
    const CONFIG_LAST_RUN_RESULT = 'ACN_LAST_RUN_RESULT';

    /**
     * Cart selection window defaults: carts idle for at least MIN hours and at most MAX days
     */
    const DEFAULT_MIN_CART_AGE_HOURS = 24;
    const DEFAULT_MAX_CART_AGE_DAYS = 7;
    const MAX_ALLOWED_CART_AGE_DAYS = 90;
    const MAX_CARTS_PER_RUN = 100;

    /**
     * The configuration page warns when the cron has not run for this many hours
     */
    const DEFAULT_CRON_ALERT_HOURS = 48;
    const MAX_CRON_ALERT_HOURS = 720;

    /**
     * Notification log rows per page on the configuration page
     */
    const LOG_PAGE_SIZE = 20;

    /**
     * Seconds after which a web run stops starting new carts, safely below the cron controller's
     * 300 s time limit: a run killed mid-cart would resend that cart's emails on the next run
     */
    const CRON_TIME_BUDGET = 240;

    /**
     * A notification that failed for every recipient is retried on later runs, at most this many times in all
     */
    const MAX_SEND_ATTEMPTS = 5;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'abandonedcartadminnotifier';
        $this->tab = 'administration';
        $this->version = '1.2.0';
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
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module? The settings will be deleted; the notification history is kept, so already notified carts are not notified again after a reinstall.');
    }

    /**
     * Module installation
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->installDatabase()
            && $this->ensureSchema()
            && Configuration::updateValue(self::CONFIG_EMAIL_RECIPIENTS, '')
            && Configuration::updateValue(self::CONFIG_MODULE_ENABLED, 1)
            && Configuration::updateValue(self::CONFIG_SECURE_TOKEN, $this->generateSecureToken())
            && Configuration::updateValue(self::CONFIG_MIN_CART_AGE_HOURS, self::DEFAULT_MIN_CART_AGE_HOURS)
            && Configuration::updateValue(self::CONFIG_MAX_CART_AGE_DAYS, self::DEFAULT_MAX_CART_AGE_DAYS)
            && Configuration::updateValue(self::CONFIG_CRON_ALERT_HOURS, self::DEFAULT_CRON_ALERT_HOURS);
    }

    /**
     * Module uninstallation
     *
     * The notification table is kept on purpose: it records which carts were already notified,
     * so a reinstall does not notify them again. To remove it, drop the table by hand.
     *
     * @return bool
     */
    public function uninstall()
    {
        return Configuration::deleteByName(self::CONFIG_EMAIL_RECIPIENTS)
            && Configuration::deleteByName(self::CONFIG_MODULE_ENABLED)
            && Configuration::deleteByName(self::CONFIG_SECURE_TOKEN)
            && Configuration::deleteByName(self::CONFIG_MIN_CART_AGE_HOURS)
            && Configuration::deleteByName(self::CONFIG_MAX_CART_AGE_DAYS)
            && Configuration::deleteByName(self::CONFIG_CRON_ALERT_HOURS)
            && Configuration::deleteByName(self::CONFIG_LAST_RUN)
            && Configuration::deleteByName(self::CONFIG_LAST_RUN_RESULT)
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
     * Add the columns introduced after 1.0.0 when they are missing
     *
     * Used by the upgrade scripts and by install(), since the table can be left over from an earlier install.
     *
     * @return bool
     */
    public function ensureSchema()
    {
        $db = Db::getInstance();
        $table = '`' . _DB_PREFIX_ . 'abandoned_cart_notifications`';
        $columns = array(
            'attempts' => 'INT(10) UNSIGNED NOT NULL DEFAULT 1 AFTER `error_message`',
            'cart_total' => 'DECIMAL(20,6) NULL DEFAULT NULL AFTER `attempts`',
            'id_currency' => 'INT(10) UNSIGNED NULL DEFAULT NULL AFTER `cart_total`',
        );

        $existing = array();
        $rows = $db->executeS('SHOW COLUMNS FROM ' . $table);
        foreach (is_array($rows) ? $rows : array() as $row) {
            $existing[] = $row['Field'];
        }

        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)
                && !$db->execute('ALTER TABLE ' . $table . ' ADD COLUMN `' . $name . '` ' . $definition)
            ) {
                return false;
            }
        }

        return true;
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

        // Handle log clearing: only on POST, a link must not change data
        if ($this->isPostRequest() && Tools::isSubmit('clearNotificationLog')) {
            $output .= $this->clearNotificationLog();
        }

        if ($this->isPostRequest() && Tools::isSubmit('regenerateCronToken')) {
            Configuration::updateValue(self::CONFIG_SECURE_TOKEN, $this->generateSecureToken());
            $output .= $this->displayConfirmation(
                $this->l('A new secure token has been generated: update the URL in the server cron job, the old one no longer works.')
            );
        }

        if ($this->isPostRequest() && Tools::isSubmit('sendTestEmail')) {
            $output .= $this->sendTestEmail();
        }

        // Cron health first: a cron that stopped running must be noticed
        $output .= $this->renderCronStatus();

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
     * Whether the current request is a POST (state-changing actions are never triggered by a GET link)
     *
     * @return bool
     */
    private function isPostRequest()
    {
        return isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST';
    }

    /**
     * @return string 64 hex characters
     */
    private function generateSecureToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * URL of this configuration page, with the admin token
     *
     * @return string
     */
    private function getConfigPageUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
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
        $alertHours = trim((string) Tools::getValue(self::CONFIG_CRON_ALERT_HOURS));

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
        if (!ctype_digit($alertHours) || (int) $alertHours < 1 || (int) $alertHours > self::MAX_CRON_ALERT_HOURS) {
            return $this->displayError(sprintf(
                $this->l('The cron alert delay must be a whole number of hours between 1 and %d.'),
                self::MAX_CRON_ALERT_HOURS
            ));
        }

        // Configuration::updateValue() escapes the value itself
        Configuration::updateValue(self::CONFIG_EMAIL_RECIPIENTS, implode(',', $emails));
        Configuration::updateValue(self::CONFIG_MODULE_ENABLED, $moduleEnabled);
        Configuration::updateValue(self::CONFIG_MIN_CART_AGE_HOURS, (int) $minHours);
        Configuration::updateValue(self::CONFIG_MAX_CART_AGE_DAYS, (int) $maxDays);
        Configuration::updateValue(self::CONFIG_CRON_ALERT_HOURS, (int) $alertHours);

        return $this->displayConfirmation($this->l('Configuration saved successfully.'));
    }

    /**
     * Send the notification template with a sample cart to the saved recipients
     *
     * Nothing is logged: the sample cart does not exist.
     *
     * @return string HTML message
     */
    private function sendTestEmail()
    {
        $recipients = $this->parseEmailList(Configuration::get(self::CONFIG_EMAIL_RECIPIENTS));
        if (empty($recipients)) {
            return $this->displayError($this->l('Save at least one email recipient before sending a test email.'));
        }
        if ($this->isMailDisabled()) {
            return $this->displayError($this->l('Email sending is disabled in Advanced Parameters > E-mail: no email can be sent.'));
        }

        try {
            $failed = $this->sendToRecipients(
                $this->l('Test email') . ': ' . $this->l('New abandoned cart'),
                $this->buildTemplateVars($this->getSampleNotificationData()),
                $recipients
            );
        } catch (Throwable $e) {
            $this->log('Test email failed: ' . $e->getMessage(), 3);

            return $this->displayError($this->l('The test email could not be sent:') . ' ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        if (count($failed) === count($recipients)) {
            return $this->displayError($this->l('The test email could not be sent. Check the email settings and the PrestaShop logs.'));
        }
        if ($failed) {
            return $this->displayWarning(
                $this->l('The test email could not be sent to:') . ' ' . htmlspecialchars(implode(', ', $failed), ENT_QUOTES, 'UTF-8')
            );
        }

        return $this->displayConfirmation(sprintf(
            $this->l('Test email sent to %d recipient(s). Check that it arrived, including the spam folder.'),
            count($recipients)
        ));
    }

    /**
     * Notification data for a made-up cart, used by the test email
     *
     * @return array Same shape as getNotificationData()
     */
    public function getSampleNotificationData()
    {
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $shopUrl = $this->context->link->getPageLink('index', true);
        $dateUpd = date('Y-m-d H:i:s', time() - 26 * 3600);

        return array(
            'id_customer' => 0,
            'firstname' => 'Mario',
            'lastname' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'phone' => '+39 000 0000000',
            'id_cart' => 0,
            'date_upd' => $this->formatDateTime($dateUpd, (int) Configuration::get('PS_LANG_DEFAULT')),
            'idle_for' => $this->formatIdleTime($dateUpd),
            'total' => $this->formatPrice(350, $currency),
            'products' => array(
                array(
                    'name' => $this->l('Sample product') . ' 1',
                    'reference' => 'SAMPLE-1',
                    'quantity' => 2,
                    'unit_price' => $this->formatPrice(100, $currency),
                    'total' => $this->formatPrice(200, $currency),
                    'url' => $shopUrl,
                ),
                array(
                    'name' => $this->l('Sample product') . ' 2',
                    'reference' => '',
                    'quantity' => 1,
                    'unit_price' => $this->formatPrice(150, $currency),
                    'total' => $this->formatPrice(150, $currency),
                    'url' => $shopUrl,
                ),
            ),
        );
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
                        'desc' => sprintf(
                            $this->l('Carts idle for longer than this are never notified. Failed notifications are retried on the next runs, up to %d attempts, as long as the cart is within this age.'),
                            self::MAX_SEND_ATTEMPTS
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Cron alert'),
                        'name' => self::CONFIG_CRON_ALERT_HOURS,
                        'suffix' => $this->l('hours'),
                        'class' => 'fixed-width-sm',
                        'required' => true,
                        'desc' => $this->l('This page shows a warning when the cron job has not run for this many hours.'),
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
            self::CONFIG_CRON_ALERT_HOURS => $this->getCronAlertHours(),
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
     * @return int
     */
    private function getCronAlertHours()
    {
        $hours = (int) Configuration::get(self::CONFIG_CRON_ALERT_HOURS);

        return $hours > 0 ? $hours : self::DEFAULT_CRON_ALERT_HOURS;
    }

    /**
     * Render the last cron run, with a warning when it is older than the alert delay
     *
     * @return string HTML panel
     */
    private function renderCronStatus()
    {
        $lastRun = (string) Configuration::get(self::CONFIG_LAST_RUN);
        $timestamp = $lastRun !== '' ? strtotime($lastRun) : false;
        $result = json_decode((string) Configuration::get(self::CONFIG_LAST_RUN_RESULT), true);
        $result = is_array($result) ? $result : array();
        $alertHours = $this->getCronAlertHours();

        $this->context->smarty->assign(array(
            'cron_has_run' => (bool) $timestamp,
            'cron_is_stale' => !$timestamp || time() - $timestamp > $alertHours * 3600,
            'cron_alert_hours' => $alertHours,
            'cron_last_run' => $timestamp ? $this->formatDateTime($lastRun, (int) $this->context->language->id) : '',
            'cron_idle_for' => $timestamp ? $this->formatIdleTime($lastRun) : '',
            'cron_source' => isset($result['source']) && $result['source'] === 'cli' ? 'CLI' : 'URL',
            'cron_processed' => isset($result['processed']) ? (int) $result['processed'] : 0,
            'cron_sent' => isset($result['sent']) ? (int) $result['sent'] : 0,
            'cron_errors' => isset($result['errors']) ? (int) $result['errors'] : 0,
            'cron_message' => isset($result['message']) ? (string) $result['message'] : '',
            'form_action' => $this->getConfigPageUrl(),
        ));

        return $this->display(__FILE__, 'views/templates/admin/cron_status.tpl');
    }

    /**
     * Render notification log table, newest first, LOG_PAGE_SIZE rows per page
     *
     * @return string HTML table
     */
    private function renderNotificationLog()
    {
        $db = Db::getInstance();
        $table = '`' . _DB_PREFIX_ . 'abandoned_cart_notifications`';

        $total = (int) $db->getValue('SELECT COUNT(*) FROM ' . $table . ' WHERE log_visible = 1', false);
        $pages = max(1, (int) ceil($total / self::LOG_PAGE_SIZE));
        $page = min($pages, max(1, (int) Tools::getValue('acn_log_page', 1)));

        $notifications = $db->executeS('
            SELECT n.*, c.firstname, c.lastname, c.email
            FROM ' . $table . ' n
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON n.id_customer = c.id_customer
            WHERE n.log_visible = 1
            ORDER BY n.date_sent DESC, n.id_notification DESC
            LIMIT ' . (int) (($page - 1) * self::LOG_PAGE_SIZE) . ', ' . (int) self::LOG_PAGE_SIZE
        );
        $notifications = is_array($notifications) ? $notifications : array();

        $idLang = (int) $this->context->language->id;
        $currencies = array();
        foreach ($notifications as &$notification) {
            $notification['date_sent_display'] = $this->formatDateTime($notification['date_sent'], $idLang);
            $notification['total_display'] = '';
            if ($notification['cart_total'] !== null && (int) $notification['id_currency'] > 0) {
                $idCurrency = (int) $notification['id_currency'];
                if (!isset($currencies[$idCurrency])) {
                    $currencies[$idCurrency] = new Currency($idCurrency);
                }
                if (Validate::isLoadedObject($currencies[$idCurrency])) {
                    $notification['total_display'] = $this->formatPrice($notification['cart_total'], $currencies[$idCurrency]);
                }
            }
            $notification['cart_url'] = $this->context->link->getAdminLink(
                'AdminCarts',
                true,
                array(),
                array('id_cart' => (int) $notification['id_cart'], 'viewcart' => 1)
            );
        }
        unset($notification);

        $this->context->smarty->assign(array(
            'notifications' => $notifications,
            'form_action' => $this->getConfigPageUrl(),
            'log_total' => $total,
            'log_page' => $page,
            'log_pages' => $pages,
            'log_page_url' => $this->getConfigPageUrl() . '&acn_log_page=',
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
            'form_action' => $this->getConfigPageUrl(),
            // The cron must run at least as often as the threshold, or carts are notified late
            'cron_schedule' => $this->getMinCartAgeHours() < 24 ? '0 * * * *' : '0 2 * * *',
            'cli_command' => 'php ' . _PS_MODULE_DIR_ . $this->name . '/cli/cron.php',
        ));

        return $this->display(__FILE__, 'views/templates/admin/cron_info.tpl');
    }

    /**
     * Complete the context that config/config.inc.php leaves partial on the command line
     *
     * Shop, language and link are already set there; cart prices and the mail also need
     * a currency, an empty customer and cart (as on a front request) and the CLDR locale.
     *
     * @return void
     */
    public function initCliContext()
    {
        $context = Context::getContext();
        if (!Validate::isLoadedObject($context->language)) {
            $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        }
        if (!Validate::isLoadedObject($context->currency)) {
            $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        }
        if (!$context->customer) {
            $context->customer = new Customer();
        }
        if (!$context->cart) {
            // Like a front request: Product::getPriceStatic() needs a cart object in the context
            $context->cart = new Cart();
        }
        if (!$context->link) {
            $context->link = new Link();
        }

        // 1.7.6+: prices are formatted by the CLDR locale, which needs the front container
        // that a front controller would build (not available on the command line otherwise)
        $builder = 'PrestaShop\\PrestaShop\\Adapter\\ContainerBuilder';
        if (property_exists($context, 'currentLocale') && method_exists('Tools', 'getContextLocale')) {
            try {
                if (!isset($context->container) && class_exists($builder) && method_exists($builder, 'getContainer')) {
                    $context->container = call_user_func(array($builder, 'getContainer'), 'front', _PS_MODE_DEV_);
                }
                if (!$context->currentLocale) {
                    $context->currentLocale = Tools::getContextLocale($context);
                }
            } catch (Throwable $e) {
                $this->log('CLI locale setup failed, prices use the fallback format: ' . $e->getMessage(), 2);
            }
        }
    }

    /**
     * Process cron job - main method to scan abandoned carts
     *
     * Every real run (not a dry run) is recorded, even when it stops early, so the configuration
     * page can show when the cron last ran.
     *
     * @param bool $dryRun List the carts that would be notified without sending or logging anything
     * @param int $timeBudget Seconds after which no new cart is started (0 = no limit)
     * @param string $source 'web' or 'cli'
     * @return array Statistics array with processed carts, sent emails, errors
     */
    public function processCronJob($dryRun = false, $timeBudget = self::CRON_TIME_BUDGET, $source = 'web')
    {
        $stats = $this->runCronJob($dryRun, $timeBudget);
        if (!$dryRun) {
            $this->recordCronRun($stats, $source);
        }

        return $stats;
    }

    /**
     * @param array $stats
     * @param string $source
     * @return void
     */
    private function recordCronRun(array $stats, $source)
    {
        Configuration::updateValue(self::CONFIG_LAST_RUN, date('Y-m-d H:i:s'));
        Configuration::updateValue(self::CONFIG_LAST_RUN_RESULT, json_encode(array(
            'source' => $source === 'cli' ? 'cli' : 'web',
            'processed' => (int) $stats['processed'],
            'sent' => (int) $stats['sent'],
            'errors' => (int) $stats['errors'],
            // Configuration strips tags; keep it short, recipient addresses stay out of it
            'message' => Tools::substr(
                implode(' | ', preg_replace('/Failed recipients: .*/', 'Failed recipients', $stats['error_messages'])),
                0,
                250
            ),
        )));
    }

    /**
     * @param bool $dryRun
     * @param int $timeBudget
     * @return array
     */
    private function runCronJob($dryRun, $timeBudget)
    {
        $startTime = microtime(true);
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

        if ($this->isMailDisabled()) {
            // Mail::Send() returns true without sending: carts would be marked as notified for nothing
            $stats['error_messages'][] = 'Email sending is disabled in the shop settings (Advanced Parameters > E-mail)';
            return $stats;
        }

        // Prevent concurrent runs from sending the same notification twice
        if (!$this->acquireLock()) {
            $stats['error_messages'][] = 'Another cron run is already in progress';
            return $stats;
        }

        try {
            $carts = $this->getAbandonedCarts();
            foreach ($carts as $index => $cart) {
                if ($timeBudget > 0 && microtime(true) - $startTime >= $timeBudget) {
                    $stats['error_messages'][] = sprintf(
                        'Time budget of %d s reached: %d carts left for the next run',
                        $timeBudget,
                        count($carts) - $index
                    );
                    break;
                }
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
                } catch (Throwable $e) {
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
     * Failed notifications are retried on each run until the cart leaves the age window,
     * at most MAX_SEND_ATTEMPTS times in all.
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
            WHERE c.id_shop = ' . (int) Context::getContext()->shop->id . '
            AND c.id_customer > 0
            AND cu.deleted = 0
            AND o.id_order IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM `' . _DB_PREFIX_ . 'abandoned_cart_notifications` acn
                WHERE acn.id_cart = c.id_cart
                AND (acn.email_status = \'success\' OR acn.attempts >= ' . (int) self::MAX_SEND_ATTEMPTS . ')
            )
            AND c.date_upd BETWEEN \'' . pSQL($minDate) . '\' AND \'' . pSQL($maxDate) . '\'
            AND EXISTS (
                SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp
                INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = cp.id_product
                WHERE cp.id_cart = c.id_cart
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
     * Format an amount in the given currency, the same way the shop does
     *
     * PrestaShop 1.7.6+ formats through the CLDR locale; Tools displayPrice is deprecated
     * in 8.x and removed in 9.x, so it is only the fallback for 1.7.0-1.7.5.
     *
     * @param float $amount
     * @param Currency $currency
     * @return string
     */
    public function formatPrice($amount, Currency $currency)
    {
        $context = Context::getContext();

        if (method_exists($context, 'getCurrentLocale')) {
            try {
                $locale = $context->getCurrentLocale();
                if (!$locale && method_exists('Tools', 'getContextLocale')) {
                    // No locale in the context (e.g. CLI): build it from the context language
                    $locale = Tools::getContextLocale($context);
                }
                if ($locale) {
                    return $locale->formatPrice($amount, $currency->iso_code);
                }
            } catch (Throwable $e) {
                $this->log('Price formatting through the locale failed: ' . $e->getMessage(), 2);
            }
        }

        if (method_exists('Tools', 'displayPrice')) {
            try {
                return call_user_func(array('Tools', 'displayPrice'), $amount, $currency);
            } catch (Throwable $e) {
                // On 1.7.6+ displayPrice needs the locale too
                $this->log('Price formatting failed: ' . $e->getMessage(), 2);
            }
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . $currency->sign;
    }

    /**
     * Collect the raw data shown in the notification for a cart
     *
     * @param array $cartData Row from getAbandonedCarts()
     * @return array
     */
    public function getNotificationData(array $cartData)
    {
        $cart = new Cart((int) $cartData['id_cart']);
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        }

        // Same prices as the cart page: tax included, the customer's group and specific prices
        $products = array();
        foreach ($cart->getProducts() as $product) {
            $products[] = array(
                'name' => $product['name'],
                'reference' => isset($product['reference']) ? $product['reference'] : '',
                'quantity' => (int) $product['cart_quantity'],
                'unit_price' => $this->formatPrice($product['price_wt'], $currency),
                'total' => $this->formatPrice($product['total_wt'], $currency),
                'url' => $this->getProductUrl($product, (int) $cart->id_lang, (int) $cart->id_shop),
            );
        }

        return array(
            'id_customer' => (int) $cartData['id_customer'],
            'firstname' => $cartData['firstname'],
            'lastname' => $cartData['lastname'],
            'email' => $cartData['email'],
            'phone' => $this->getCustomerPhone((int) $cartData['id_customer'], (int) $cart->id_address_delivery),
            'id_cart' => (int) $cartData['id_cart'],
            'date_upd' => $this->formatDateTime($cartData['date_upd'], (int) Configuration::get('PS_LANG_DEFAULT')),
            'idle_for' => $this->formatIdleTime($cartData['date_upd']),
            'total' => $this->formatPrice($cart->getOrderTotal(true, Cart::BOTH), $currency),
            'products' => $products,
        );
    }

    /**
     * Turn notification data into Mail::Send() template vars, neutralizing every customer-controlled value
     *
     * @param array $data From getNotificationData()
     * @return array
     */
    public function buildTemplateVars(array $data)
    {
        $rowsHtml = '';
        $listTxt = '';
        $cell = 'padding: 8px; border-bottom: 1px solid #eeeeee; vertical-align: top;';
        foreach ($data['products'] as $product) {
            $name = $this->sanitizeMailValue($product['name']);
            $reference = $this->sanitizeMailValue($product['reference']);
            $quantity = (int) $product['quantity'];
            $unitPrice = $this->sanitizeMailValue($product['unit_price']);
            $total = $this->sanitizeMailValue($product['total']);
            // Only http(s) links from Link::getProductLink(); quotes are encoded so the href cannot be closed
            $url = preg_match('#^https?://#i', $product['url'])
                ? str_replace(array('"', '<', '>', '{', '}', ' '), array('%22', '%3C', '%3E', '%7B', '%7D', '%20'), $product['url'])
                : '';

            $rowsHtml .= '<tr>'
                . '<td style="' . $cell . '">'
                . ($url !== '' ? '<a href="' . $url . '" style="color: #25b9d7; text-decoration: none;">' . $name . '</a>' : $name)
                . ($reference !== '' ? '<br><span style="font-size: 12px; color: #777777;">' . $this->l('Ref.') . ' ' . $reference . '</span>' : '')
                . '</td>'
                . '<td style="' . $cell . ' text-align: center;">' . $quantity . '</td>'
                . '<td style="' . $cell . ' text-align: right; white-space: nowrap;">' . $unitPrice . '</td>'
                . '<td style="' . $cell . ' text-align: right; white-space: nowrap;">' . $total . '</td>'
                . '</tr>';
            $listTxt .= '- ' . $name . ($reference !== '' ? ' (' . $this->l('Ref.') . ' ' . $reference . ')' : '') . "\n"
                . '  ' . $this->l('Quantity') . ': ' . $quantity . ' x ' . $unitPrice . ' = ' . $total . "\n"
                . ($url !== '' ? '  ' . $url . "\n" : '');
        }

        return array(
            '{id_customer}' => (int) $data['id_customer'],
            '{firstname}' => $this->sanitizeMailValue($data['firstname']),
            '{lastname}' => $this->sanitizeMailValue($data['lastname']),
            '{customer_email}' => $this->sanitizeMailValue($data['email']),
            '{customer_phone}' => $data['phone'] !== '' ? $this->sanitizeMailValue($data['phone']) : $this->l('not available'),
            '{id_cart}' => (int) $data['id_cart'],
            '{date_upd}' => $this->sanitizeMailValue($data['date_upd']),
            '{idle_for}' => $this->sanitizeMailValue($data['idle_for']),
            '{total_amount}' => $this->sanitizeMailValue($data['total']),
            '{product_rows_html}' => $rowsHtml,
            '{product_list_txt}' => $listTxt,
        );
    }

    /**
     * Customer phone numbers, from the cart's delivery address or else the latest address
     *
     * @param int $idCustomer
     * @param int $idAddressDelivery
     * @return string Mobile and landline, separated by " / ", or an empty string
     */
    public function getCustomerPhone($idCustomer, $idAddressDelivery)
    {
        $row = Db::getInstance()->getRow('
            SELECT a.phone, a.phone_mobile
            FROM `' . _DB_PREFIX_ . 'address` a
            WHERE a.id_customer = ' . (int) $idCustomer . '
            AND a.deleted = 0
            AND (a.phone <> \'\' OR a.phone_mobile <> \'\')
            ORDER BY (a.id_address = ' . (int) $idAddressDelivery . ') DESC, a.date_upd DESC
        ', false);
        if (!$row) {
            return '';
        }

        $phones = array();
        foreach (array($row['phone_mobile'], $row['phone']) as $phone) {
            $phone = trim((string) $phone);
            if ($phone !== '' && !in_array($phone, $phones, true)) {
                $phones[] = $phone;
            }
        }

        return implode(' / ', $phones);
    }

    /**
     * Date and time in the language's format, without seconds (e.g. 12/11/2026 14:30)
     *
     * @param string $date Y-m-d H:i:s
     * @param int $idLang
     * @return string
     */
    public function formatDateTime($date, $idLang)
    {
        $timestamp = strtotime((string) $date);
        if (!$timestamp) {
            return (string) $date;
        }

        $language = new Language((int) $idLang);
        $format = Validate::isLoadedObject($language) && $language->date_format_full
            ? $language->date_format_full
            : 'Y-m-d H:i:s';

        return date(str_replace(':s', '', $format), $timestamp);
    }

    /**
     * How long a cart has been idle: hours below two days, then days
     *
     * @param string $date Y-m-d H:i:s
     * @param int|null $now Timestamp, defaults to the current time
     * @return string
     */
    public function formatIdleTime($date, $now = null)
    {
        $seconds = ($now === null ? time() : (int) $now) - (int) strtotime((string) $date);
        $hours = max(0, (int) floor($seconds / 3600));
        if ($hours < 48) {
            return sprintf($hours === 1 ? $this->l('%d hour') : $this->l('%d hours'), $hours);
        }

        return sprintf($this->l('%d days'), (int) floor($hours / 24));
    }

    /**
     * Public URL of a cart product, or an empty string if it cannot be built
     *
     * @param array $product Row from Cart::getProducts()
     * @param int $idLang
     * @param int $idShop
     * @return string
     */
    private function getProductUrl(array $product, $idLang, $idShop)
    {
        try {
            $link = $this->context->link ? $this->context->link : new Link();

            return (string) $link->getProductLink(
                (int) $product['id_product'],
                isset($product['link_rewrite']) ? $product['link_rewrite'] : null,
                isset($product['category']) ? $product['category'] : null,
                null,
                $idLang > 0 ? $idLang : null,
                $idShop > 0 ? $idShop : null,
                (int) $product['id_product_attribute']
            );
        } catch (Throwable $e) {
            return '';
        }
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
        return $this->sendToRecipients(
            $this->l('New abandoned cart'),
            $this->buildTemplateVars($this->getNotificationData($cartData)),
            $recipients
        );
    }

    /**
     * Send the notification template to each recipient individually
     *
     * @param string $subject
     * @param array $templateVars
     * @param array $recipients
     * @return array Recipients the email could not be sent to
     */
    private function sendToRecipients($subject, array $templateVars, array $recipients)
    {
        $failedRecipients = array();
        foreach ($recipients as $toEmail) {
            if (!$this->sendMail($subject, $templateVars, $toEmail)) {
                $this->log('Failed to send email to: ' . $toEmail, 3);
                $failedRecipients[] = $toEmail;
            }
        }

        return $failedRecipients;
    }

    /**
     * Send one email through PrestaShop (overridden by the test suite)
     *
     * @param string $subject
     * @param array $templateVars
     * @param string $toEmail
     * @return bool
     */
    protected function sendMail($subject, array $templateVars, $toEmail)
    {
        return (bool) Mail::Send(
            (int) Configuration::get('PS_LANG_DEFAULT'),
            'abandonedcart',
            $subject,
            $templateVars,
            $toEmail,
            null,
            Configuration::get('PS_SHOP_EMAIL'),
            Configuration::get('PS_SHOP_NAME'),
            null,
            null,
            dirname(__FILE__) . '/mails/',
            false,
            null
        );
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
    public function sanitizeMailValue($value)
    {
        $value = (string) $value;
        do {
            $previous = $value;
            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        } while ($value !== $previous);

        return str_replace(array('<', '>', '{', '}'), '', $value);
    }

    /**
     * Whether the shop is set to never send emails (PS_MAIL_METHOD 3 on every supported version)
     *
     * @return bool
     */
    public function isMailDisabled()
    {
        return (int) Configuration::get('PS_MAIL_METHOD') === 3;
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

        // Cart total at notification time, for the log
        $total = 'NULL';
        $idCurrency = 'NULL';
        try {
            $cart = new Cart((int) $idCart);
            if (Validate::isLoadedObject($cart)) {
                $total = number_format((float) $cart->getOrderTotal(true, Cart::BOTH), 6, '.', '');
                $idCurrency = (string) (int) $cart->id_currency;
            }
        } catch (Throwable $e) {
            $this->log('Cart total for the log failed, cart ID ' . (int) $idCart . ': ' . $e->getMessage(), 2);
        }

        return Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'abandoned_cart_notifications`
                (`id_cart`, `id_customer`, `date_sent`, `email_status`, `error_message`, `cart_total`, `id_currency`, `log_visible`, `date_add`)
            VALUES (' . (int) $idCart . ', ' . (int) $idCustomer . ', \'' . pSQL($now) . '\', \'' . $status . '\', ' . $error . ', ' . $total . ', ' . $idCurrency . ', 1, \'' . pSQL($now) . '\')
            ON DUPLICATE KEY UPDATE
                `date_sent` = VALUES(`date_sent`),
                `email_status` = VALUES(`email_status`),
                `error_message` = VALUES(`error_message`),
                `cart_total` = VALUES(`cart_total`),
                `id_currency` = VALUES(`id_currency`),
                `attempts` = `attempts` + 1,
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
