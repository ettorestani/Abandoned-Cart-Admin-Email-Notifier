<?php
/**
 * Abandoned Cart Admin Email Notifier — suite di collaudo
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *
 * Test deterministici: nessuna mail parte davvero (l'invio è sostituito da uno
 * stub che registra le chiamate) e i dati vengono ripristinati alla fine:
 * cliente, indirizzo, carrelli e ordini di prova vengono creati con insert
 * dirette (niente hook) e poi cancellati; la tabella delle notifiche e le
 * chiavi di configurazione toccate tornano come prima.
 *
 * Uso, dalla ROOT di PrestaShop (in DDEV: ddev exec php modules/abandonedcartadminnotifier/tests/run.php):
 *   php modules/abandonedcartadminnotifier/tests/run.php
 *
 * NB: esclusa dallo zip di installazione (.gitattributes).
 */

if (PHP_SAPI !== 'cli') {
    exit('Eseguire da riga di comando.');
}
// Solo nell'ambiente di collaudo DDEV: la suite scrive nel database.
if (getenv('IS_DDEV_PROJECT') !== 'true') {
    fwrite(STDERR, "Rifiutato: questo script gira solo nel container DDEV di collaudo (IS_DDEV_PROJECT=true).\n");
    exit(2);
}

require_once dirname(__FILE__) . '/../../../config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'abandonedcartadminnotifier/abandonedcartadminnotifier.php';

/**
 * Il modulo con l'invio sostituito: registra le mail e simula i fallimenti
 */
class AcnTestNotifier extends AbandonedCartAdminNotifier
{
    /** @var array [subject, vars, to] */
    public $sent = array();
    /** @var array destinatari per cui l'invio fallisce */
    public $failFor = array();
    /** @var int[] carrelli per cui l'invio lancia un Error */
    public $throwForCarts = array();
    /** @var float secondi di attesa a ogni invio */
    public $delay = 0;

    protected function sendMail($subject, array $templateVars, $toEmail)
    {
        if (in_array((int) $templateVars['{id_cart}'], $this->throwForCarts, true)) {
            throw new TypeError('simulated error');
        }
        if ($this->delay > 0) {
            usleep((int) ($this->delay * 1000000));
        }
        $this->sent[] = array($subject, $templateVars, $toEmail);

        return !in_array($toEmail, $this->failFor, true);
    }

    /**
     * @param int $idCart
     * @return array destinatari a cui è andata la mail di quel carrello
     */
    public function sentForCart($idCart)
    {
        $out = array();
        foreach ($this->sent as $mail) {
            if ((int) $mail[1]['{id_cart}'] === (int) $idCart) {
                $out[] = $mail[2];
            }
        }

        return $out;
    }
}

$GLOBALS['acn_pass'] = 0;
$GLOBALS['acn_fail'] = 0;

/**
 * @param string $label
 * @param bool $condition
 * @param string $detail mostrato solo in caso di fallimento
 */
function acnCheck($label, $condition, $detail = '')
{
    if ($condition) {
        $GLOBALS['acn_pass']++;
        echo "  OK   $label\n";
    } else {
        $GLOBALS['acn_fail']++;
        echo "  FAIL $label" . ($detail !== '' ? " -> $detail" : '') . "\n";
    }
}

/**
 * Insert diretta che riempie da sola le colonne NOT NULL senza default
 *
 * @param string $table senza prefisso
 * @param array $values
 * @return int id inserito
 */
function acnInsert($table, array $values)
{
    $db = Db::getInstance();
    foreach ($db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '`') as $col) {
        if (array_key_exists($col['Field'], $values) || $col['Null'] !== 'NO' || $col['Default'] !== null
            || strpos($col['Extra'], 'auto_increment') !== false
        ) {
            continue;
        }
        $type = strtolower($col['Type']);
        if (preg_match('/int|decimal|float|double/', $type)) {
            $values[$col['Field']] = 0;
        } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
            $values[$col['Field']] = date('Y-m-d H:i:s');
        } elseif (strpos($type, 'date') === 0) {
            $values[$col['Field']] = date('Y-m-d');
        } elseif (strpos($type, 'enum') === 0 && preg_match("/enum\\('([^']*)'/", $type, $m)) {
            $values[$col['Field']] = $m[1];
        } else {
            $values[$col['Field']] = '';
        }
    }
    $cols = array();
    $vals = array();
    foreach ($values as $k => $v) {
        $cols[] = '`' . bqSQL($k) . '`';
        $vals[] = $v === null ? 'NULL' : '\'' . pSQL((string) $v) . '\'';
    }
    if (!$db->execute('INSERT INTO `' . _DB_PREFIX_ . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')')) {
        throw new RuntimeException('Insert in ' . $table . ' fallita: ' . $db->getMsgError());
    }

    return (int) $db->Insert_ID();
}

/**
 * @param int $hoursAgo
 * @return string
 */
function acnAgo($hoursAgo)
{
    return date('Y-m-d H:i:s', time() - (int) round($hoursAgo * 3600));
}

/**
 * @param string $s
 * @return string spazi speciali (nbsp, narrow nbsp) resi come spazi normali
 */
function acnSpaces($s)
{
    return str_replace(array("\xC2\xA0", "\xE2\x80\xAF"), ' ', $s);
}

$db = Db::getInstance();
$P = _DB_PREFIX_;
$table = $P . 'abandoned_cart_notifications';
$idShop = (int) Context::getContext()->shop->id;
$idShopGroup = (int) Context::getContext()->shop->id_shop_group;
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');

// ---- Backup -------------------------------------------------------------------------------------
$configKeys = array(
    'ACN_EMAIL_RECIPIENTS', 'ACN_MODULE_ENABLED', 'ACN_MIN_CART_AGE_HOURS', 'ACN_MAX_CART_AGE_DAYS',
    'ACN_CRON_ALERT_HOURS', 'ACN_LAST_RUN', 'ACN_LAST_RUN_RESULT', 'PS_MAIL_METHOD',
);
$configBackup = $db->executeS('SELECT * FROM `' . $P . 'configuration` WHERE name IN (\'' . implode('\',\'', $configKeys) . '\')');
$notificationsBackup = $db->executeS('SELECT * FROM `' . $table . '`');
if (!is_array($configBackup) || !is_array($notificationsBackup)) {
    fwrite(STDERR, "Backup non riuscito: interrotto senza toccare nulla.\n");
    exit(2);
}
$created = array('customer' => array(), 'address' => array(), 'cart' => array(), 'orders' => array());

$restore = function () use ($db, $P, $table, $configKeys, $configBackup, $notificationsBackup, &$created) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach ($created['orders'] as $id) {
        $db->execute('DELETE FROM `' . $P . 'orders` WHERE id_order = ' . (int) $id);
    }
    foreach ($created['cart'] as $id) {
        $db->execute('DELETE FROM `' . $P . 'cart_product` WHERE id_cart = ' . (int) $id);
        $db->execute('DELETE FROM `' . $P . 'cart` WHERE id_cart = ' . (int) $id);
    }
    foreach ($created['address'] as $id) {
        $db->execute('DELETE FROM `' . $P . 'address` WHERE id_address = ' . (int) $id);
    }
    foreach ($created['customer'] as $id) {
        $db->execute('DELETE FROM `' . $P . 'customer_group` WHERE id_customer = ' . (int) $id);
        $db->execute('DELETE FROM `' . $P . 'customer` WHERE id_customer = ' . (int) $id);
    }

    $db->execute('DELETE FROM `' . $table . '`');
    foreach ($notificationsBackup as $row) {
        $cols = array();
        $vals = array();
        foreach ($row as $k => $v) {
            $cols[] = '`' . bqSQL($k) . '`';
            $vals[] = $v === null ? 'NULL' : '\'' . pSQL($v) . '\'';
        }
        $db->execute('INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
    }

    $db->execute('DELETE FROM `' . $P . 'configuration` WHERE name IN (\'' . implode('\',\'', $configKeys) . '\')');
    foreach ($configBackup as $row) {
        $cols = array();
        $vals = array();
        foreach ($row as $k => $v) {
            $cols[] = '`' . bqSQL($k) . '`';
            $vals[] = $v === null ? 'NULL' : '\'' . pSQL($v, true) . '\'';
        }
        $db->execute('INSERT INTO `' . $P . 'configuration` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
    }
    acnReloadConfiguration();

    $left = (int) $db->getValue('SELECT COUNT(*) FROM `' . $table . '`', false);
    echo "\n== Ripristino ==\n  notifiche: " . count($notificationsBackup) . " righe prima, $left dopo\n";
};
register_shutdown_function(function () use ($restore) {
    $restore();
});

/**
 * Configuration::clearConfigurationCacheForTesting() non esiste su tutte le versioni
 */
function acnReloadConfiguration()
{
    if (method_exists('Configuration', 'clearConfigurationCacheForTesting')) {
        Configuration::clearConfigurationCacheForTesting();
    }
    Configuration::loadConfiguration();
}

echo "PrestaShop " . _PS_VERSION_ . " / PHP " . PHP_VERSION . "\n";

try {
    $module = new AcnTestNotifier();
    $module->initCliContext();
    acnCheck('modulo installato in versione 1.2.0', Module::isInstalled('abandonedcartadminnotifier') && $module->version === '1.2.0');

    $columns = array();
    foreach ($db->executeS('SHOW COLUMNS FROM `' . $table . '`') as $col) {
        $columns[] = $col['Field'];
    }
    acnCheck('schema: colonne attempts, cart_total, id_currency', !array_diff(array('attempts', 'cart_total', 'id_currency'), $columns), implode(',', $columns));

    // ---- Fixture --------------------------------------------------------------------------------
    $idProduct = (int) $db->getValue('
        SELECT p.id_product FROM `' . $P . 'product` p
        INNER JOIN `' . $P . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . '
        INNER JOIN `' . $P . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_shop = ' . $idShop . ' AND pl.id_lang = ' . $idLang . '
        WHERE ps.active = 1 AND ps.price > 0 AND p.cache_is_pack = 0
        ORDER BY p.id_product', false);
    if (!$idProduct) {
        throw new RuntimeException('Nessun prodotto attivo con prezzo nel negozio');
    }

    $mkCustomer = function ($deleted = 0) use (&$created, $idShop, $idShopGroup, $idLang) {
        $id = acnInsert('customer', array(
            'id_shop_group' => $idShopGroup, 'id_shop' => $idShop, 'id_lang' => $idLang,
            'id_default_group' => (int) Configuration::get('PS_CUSTOMER_GROUP'),
            'firstname' => 'Test<b>', 'lastname' => 'Acn{shop_name}',
            'email' => 'acn-test-' . uniqid() . '@example.invalid', 'passwd' => md5(uniqid()),
            'secure_key' => md5(uniqid()), 'active' => 1, 'is_guest' => 0, 'deleted' => (int) $deleted,
            'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s'),
        ));
        $created['customer'][] = $id;

        return $id;
    };
    $mkCart = function ($idCustomer, $hoursAgo, $withProduct = true, $shop = null, $product = null) use (&$created, $db, $P, $idShop, $idShopGroup, $idLang, $idCurrency, $idProduct) {
        $id = acnInsert('cart', array(
            'id_shop_group' => $idShopGroup, 'id_shop' => $shop === null ? $idShop : $shop, 'id_lang' => $idLang,
            'id_currency' => $idCurrency, 'id_customer' => $idCustomer, 'id_guest' => 0,
            'id_address_delivery' => 0, 'id_address_invoice' => 0, 'id_carrier' => 0,
            'secure_key' => md5(uniqid()), 'date_add' => acnAgo($hoursAgo), 'date_upd' => acnAgo($hoursAgo),
        ));
        $created['cart'][] = $id;
        if ($withProduct) {
            acnInsert('cart_product', array(
                'id_cart' => $id, 'id_product' => $product === null ? $idProduct : $product,
                'id_address_delivery' => 0, 'id_shop' => $shop === null ? $idShop : $shop,
                'id_product_attribute' => 0, 'quantity' => 2, 'date_add' => acnAgo($hoursAgo),
            ));
        }
        // acnInsert non conosce date_upd del carrello se la tabella ha ON UPDATE: lo fisso a mano
        $db->execute('UPDATE `' . $P . 'cart` SET date_upd = \'' . pSQL(acnAgo($hoursAgo)) . '\' WHERE id_cart = ' . (int) $id);

        return $id;
    };
    $mkOrder = function ($idCustomer, $idCart, $hoursAgo) use (&$created, $idShop, $idShopGroup, $idLang, $idCurrency) {
        $id = acnInsert('orders', array(
            'reference' => 'ACNTEST', 'id_shop_group' => $idShopGroup, 'id_shop' => $idShop, 'id_lang' => $idLang,
            'id_customer' => $idCustomer, 'id_cart' => $idCart, 'id_currency' => $idCurrency,
            'secure_key' => md5(uniqid()), 'payment' => 'test', 'module' => 'test', 'conversion_rate' => 1,
            'date_add' => acnAgo($hoursAgo), 'date_upd' => acnAgo($hoursAgo),
        ));
        $created['orders'][] = $id;

        return $id;
    };

    $customer = $mkCustomer();
    $idAddress = acnInsert('address', array(
        'id_country' => (int) Configuration::get('PS_COUNTRY_DEFAULT'), 'id_customer' => $customer,
        'alias' => 'test', 'lastname' => 'Acn', 'firstname' => 'Test', 'address1' => 'Via Prova 1',
        'city' => 'Bari', 'postcode' => '70100', 'phone' => '080 1234567', 'phone_mobile' => '333 1234567',
        'deleted' => 0, 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s'),
    ));
    $created['address'][] = $idAddress;

    Configuration::updateValue('ACN_MIN_CART_AGE_HOURS', 12);
    Configuration::updateValue('ACN_MAX_CART_AGE_DAYS', 7);
    Configuration::updateValue('ACN_MODULE_ENABLED', 1);
    Configuration::updateValue('ACN_EMAIL_RECIPIENTS', 'uno@example.invalid,due@example.invalid');
    if ((int) Configuration::get('PS_MAIL_METHOD') === 3) {
        Configuration::updateValue('PS_MAIL_METHOD', 1);
    }

    $eligible = $mkCart($customer, 20);
    $tooRecent = $mkCart($customer, 2);
    $tooOld = $mkCart($customer, 24 * 10);
    $empty = $mkCart($customer, 20, false);
    // Cliente a parte: il suo ordine escluderebbe anche gli altri carrelli dello stesso cliente
    $orderedCustomer = $mkCustomer();
    $ordered = $mkCart($orderedCustomer, 21);
    $mkOrder($orderedCustomer, $ordered, 20.5);
    $deletedProduct = $mkCart($customer, 20, true, null, 99999999);
    $otherShop = $mkCart($customer, 20, true, 9999);
    $deletedCustomerCart = $mkCart($mkCustomer(1), 20);
    // Cliente che ha ordinato (con un altro carrello) dopo l'ultima modifica di questo
    $buyer = $mkCustomer();
    $boughtLater = $mkCart($buyer, 30);
    $mkOrder($buyer, $mkCart($buyer, 25, false), 10);
    // Bordi della finestra: 11 ore no, 13 ore sì; 6 giorni sì, 8 giorni no
    $edge11h = $mkCart($customer, 11);
    $edge13h = $mkCart($customer, 13);
    $edge6d = $mkCart($customer, 24 * 6);
    $edge8d = $mkCart($customer, 24 * 8);

    // ---- 1. Selezione dei carrelli --------------------------------------------------------------
    echo "\n== 1. Selezione dei carrelli ==\n";
    $selected = array();
    foreach ($module->getAbandonedCarts() as $row) {
        $selected[] = (int) $row['id_cart'];
    }
    $expect = array(
        'idoneo (20 ore)' => array($eligible, true),
        'troppo recente (2 ore)' => array($tooRecent, false),
        'troppo vecchio (10 giorni)' => array($tooOld, false),
        'vuoto' => array($empty, false),
        'già ordinato' => array($ordered, false),
        'solo prodotti eliminati' => array($deletedProduct, false),
        'di un altro negozio' => array($otherShop, false),
        'cliente cancellato' => array($deletedCustomerCart, false),
        'cliente che ha ordinato dopo' => array($boughtLater, false),
        'bordo 11 ore' => array($edge11h, false),
        'bordo 13 ore' => array($edge13h, true),
        'bordo 6 giorni' => array($edge6d, true),
        'bordo 8 giorni' => array($edge8d, false),
    );
    foreach ($expect as $label => $case) {
        $info = $db->getRow('SELECT c.id_cart, c.date_upd, c.id_shop, c.id_customer, (SELECT COUNT(*) FROM `' . $P . 'cart_product` cp WHERE cp.id_cart = c.id_cart) products FROM `' . $P . 'cart` c WHERE c.id_cart = ' . (int) $case[0], false);
        acnCheck(($case[1] ? 'selezionato: ' : 'escluso: ') . $label, in_array($case[0], $selected, true) === $case[1], json_encode($info) . ' selezionati: ' . count($selected));
    }

    // ---- 2. Formattazione -----------------------------------------------------------------------
    echo "\n== 2. Formattazione ==\n";
    $currency = new Currency($idCurrency);
    $price = $module->formatPrice(23.33, $currency);
    echo '  info prezzo 23.33 ' . $currency->iso_code . ' -> "' . $price . '" (hex ' . bin2hex($price) . ")\n";
    if ($currency->iso_code === 'EUR' && Language::getIsoById($idLang) === 'it') {
        acnCheck('prezzo come nel negozio: "23,33 €"', acnSpaces($price) === '23,33 €', $price);
        acnCheck('migliaia: "1.234,50 €"', acnSpaces($module->formatPrice(1234.5, $currency)) === '1.234,50 €', $module->formatPrice(1234.5, $currency));
    } else {
        echo "  SKIP valuta/lingua non EUR/it\n";
    }
    if (method_exists('Tools', 'displayPrice') && version_compare(_PS_VERSION_, '1.7.6.0', '<')) {
        acnCheck('su 1.7.3 identico a Tools::displayPrice', $price === call_user_func(array('Tools', 'displayPrice'), 23.33, $currency));
    }

    $lang = new Language($idLang);
    $expectedDate = date(str_replace(':s', '', $lang->date_format_full), strtotime('2026-11-12 14:30:00'));
    acnCheck('data nel formato della lingua, senza secondi', $module->formatDateTime('2026-11-12 14:30:59', $idLang) === $expectedDate, $module->formatDateTime('2026-11-12 14:30:59', $idLang));
    $now = strtotime('2026-11-12 12:00:00');
    acnCheck('fermo da 1 ora', $module->formatIdleTime('2026-11-12 11:00:00', $now) === sprintf($module->l('%d hour'), 1));
    acnCheck('fermo da 36 ore', $module->formatIdleTime('2026-11-11 00:00:00', $now) === sprintf($module->l('%d hours'), 36));
    acnCheck('fermo da 3 giorni', $module->formatIdleTime('2026-11-09 11:00:00', $now) === sprintf($module->l('%d days'), 3));
    acnCheck('telefono: cellulare / fisso', $module->getCustomerPhone($customer, 0) === '333 1234567 / 080 1234567', $module->getCustomerPhone($customer, 0));
    acnCheck('telefono assente: stringa vuota', $module->getCustomerPhone($buyer, 0) === '');

    // ---- 3. Sanificazione -----------------------------------------------------------------------
    echo "\n== 3. Sanificazione ==\n";
    acnCheck('tag e segnaposto rimossi', $module->sanitizeMailValue('<script>x</script>{shop_name}') === 'scriptx/scriptshop_name');
    acnCheck('entità decodificate e poi ripulite', $module->sanitizeMailValue('&amp;lt;b&amp;gt;') === 'b');
    acnCheck('valori normali intatti', $module->sanitizeMailValue('Rossi & Figli "srl"') === 'Rossi & Figli "srl"');

    $data = $module->getNotificationData(array(
        'id_cart' => $eligible, 'id_customer' => $customer, 'firstname' => 'Test<b>', 'lastname' => 'Acn{shop_name}',
        'email' => 'x@example.invalid', 'date_upd' => acnAgo(20),
    ));
    $data['products'][] = array(
        'name' => '<img src=x onerror=alert(1)>{firstname}', 'reference' => '<i>R</i>', 'quantity' => 1,
        'unit_price' => '1,00 €', 'total' => '1,00 €', 'url' => 'javascript:alert(1)',
    );
    $data['products'][] = array(
        'name' => 'Link', 'reference' => '', 'quantity' => 1, 'unit_price' => '1,00 €', 'total' => '1,00 €',
        'url' => 'https://example.com/a"onmouseover="x',
    );
    $vars = $module->buildTemplateVars($data);
    $all = implode("\n", $vars);
    acnCheck('nessun tag iniettato nelle variabili', strpos($all, '<img') === false && strpos($all, '<i>') === false && strpos($all, '<b>') === false);
    acnCheck('nessun segnaposto iniettato', strpos($vars['{firstname}'] . $vars['{lastname}'] . $vars['{product_rows_html}'], '{') === false);
    acnCheck('link javascript: scartato', strpos($vars['{product_rows_html}'], 'javascript:') === false);
    acnCheck('virgolette nel link codificate', strpos($vars['{product_rows_html}'], 'a%22onmouseover=%22x') !== false && strpos($vars['{product_rows_html}'], '"onmouseover') === false);
    acnCheck('link al prodotto vero presente', (bool) preg_match('#<a href="https?://#', $vars['{product_rows_html}']));
    acnCheck('quantità del carrello (2)', strpos($vars['{product_list_txt}'], ': 2 x ') !== false, $vars['{product_list_txt}']);
    acnCheck('telefono nelle variabili', $vars['{customer_phone}'] === '333 1234567 / 080 1234567');
    $cart = new Cart($eligible);
    acnCheck('totale = Cart::getOrderTotal formattato', $vars['{total_amount}'] === $module->formatPrice($cart->getOrderTotal(true, Cart::BOTH), $currency));

    // ---- 4. Invio, doppioni, retry --------------------------------------------------------------
    echo "\n== 4. Invio, doppioni e retry ==\n";
    $db->execute('DELETE FROM `' . $table . '` WHERE id_cart IN (' . implode(',', array_map('intval', $created['cart'])) . ')');

    $dry = $module->processCronJob(true, 0, 'cli');
    $dryIds = array_map(function ($c) {
        return (int) $c['id_cart'];
    }, $dry['carts']);
    acnCheck('dry run: elenca il carrello idoneo', in_array($eligible, $dryIds, true));
    acnCheck('dry run: nessuna mail', count($module->sent) === 0);
    acnCheck('dry run: nessuna riga nel log', !$db->getValue('SELECT 1 FROM `' . $table . '` WHERE id_cart = ' . (int) $eligible, false));

    $run1 = $module->processCronJob(false, 0, 'cli');
    acnCheck('invio: entrambi i destinatari', $module->sentForCart($eligible) === array('uno@example.invalid', 'due@example.invalid'), implode(',', $module->sentForCart($eligible)));
    $row = $db->getRow('SELECT * FROM `' . $table . '` WHERE id_cart = ' . (int) $eligible, false);
    acnCheck('log: success, 1 tentativo', $row && $row['email_status'] === 'success' && (int) $row['attempts'] === 1);
    acnCheck('log: totale e valuta salvati', $row && abs((float) $row['cart_total'] - (float) $cart->getOrderTotal(true, Cart::BOTH)) < 0.01 && (int) $row['id_currency'] === $idCurrency);
    acnCheck('ultima esecuzione registrata', strtotime(Configuration::get('ACN_LAST_RUN')) >= time() - 60);
    $result = json_decode(Configuration::get('ACN_LAST_RUN_RESULT'), true);
    acnCheck('esito registrato (cli, conteggi)', $result['source'] === 'cli' && (int) $result['sent'] === (int) $run1['sent']);

    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    acnCheck('secondo giro: nessun doppione', $module->sentForCart($eligible) === array());

    // Fallimento totale: ritentato fino a MAX_SEND_ATTEMPTS
    $failing = $mkCart($customer, 15);
    $module->failFor = array('uno@example.invalid', 'due@example.invalid');
    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    $row = $db->getRow('SELECT * FROM `' . $table . '` WHERE id_cart = ' . (int) $failing, false);
    acnCheck('fallimento totale: failed, 1 tentativo', $row && $row['email_status'] === 'failed' && (int) $row['attempts'] === 1);
    $module->processCronJob(false, 0, 'cli');
    $row = $db->getRow('SELECT * FROM `' . $table . '` WHERE id_cart = ' . (int) $failing, false);
    acnCheck('retry al giro dopo: 2 tentativi', (int) $row['attempts'] === 2);
    $db->execute('UPDATE `' . $table . '` SET attempts = ' . (int) AbandonedCartAdminNotifier::MAX_SEND_ATTEMPTS . ' WHERE id_cart = ' . (int) $failing);
    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    acnCheck('dopo ' . AbandonedCartAdminNotifier::MAX_SEND_ATTEMPTS . ' tentativi non si ritenta più', $module->sentForCart($failing) === array());

    // Fallimento parziale: registrato come inviato, niente retry (gli altri riceverebbero doppioni)
    $partial = $mkCart($customer, 16);
    $module->failFor = array('due@example.invalid');
    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    $row = $db->getRow('SELECT * FROM `' . $table . '` WHERE id_cart = ' . (int) $partial, false);
    acnCheck('fallimento parziale: success con nota', $row && $row['email_status'] === 'success' && strpos($row['error_message'], 'due@example.invalid') !== false);
    $result = json_decode(Configuration::get('ACN_LAST_RUN_RESULT'), true);
    acnCheck('gli indirizzi non finiscono nella configurazione', strpos((string) $result['message'], '@') === false, (string) $result['message']);
    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    acnCheck('fallimento parziale: non ritentato', $module->sentForCart($partial) === array());
    $module->failFor = array();

    // Un Error PHP su un carrello non blocca gli altri
    $broken = $mkCart($customer, 17);
    $afterBroken = $mkCart($customer, 18);
    $module->throwForCarts = array($broken);
    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    $row = $db->getRow('SELECT * FROM `' . $table . '` WHERE id_cart = ' . (int) $broken, false);
    acnCheck('Error PHP: carrello registrato come failed', $row && $row['email_status'] === 'failed');
    acnCheck('Error PHP: i carrelli successivi partono lo stesso', count($module->sentForCart($afterBroken)) === 2);
    $module->throwForCarts = array();

    // Limite di tempo: dopo il primo carrello lento non ne parte un altro
    $slowA = $mkCart($customer, 19);
    $slowB = $mkCart($customer, 19.5);
    $module->delay = 0.6;
    $module->sent = array();
    $stats = $module->processCronJob(false, 1, 'cli');
    $module->delay = 0;
    $notified = count($module->sentForCart($slowA)) + count($module->sentForCart($slowB));
    acnCheck('limite di tempo: un solo carrello, il resto al giro dopo', $stats['processed'] === 1 && $notified <= 2, "inviate $notified, processati {$stats['processed']}");
    acnCheck('limite di tempo: messaggio', (bool) preg_grep('/Time budget/', $stats['error_messages']));

    // Lock: con un altro processo in corso non parte nulla
    $other = Db::getClass();
    $other = new $other(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, true);
    $lockName = substr(_DB_NAME_ . '_' . _DB_PREFIX_ . 'acn_cron', 0, 64);
    $other->getValue('SELECT GET_LOCK(\'' . pSQL($lockName) . '\', 0)', false);
    $module->sent = array();
    $stats = $module->processCronJob(false, 0, 'cli');
    acnCheck('lock: secondo giro concorrente rifiutato', count($module->sent) === 0 && (bool) preg_grep('/already in progress/', $stats['error_messages']));
    $other->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lockName) . '\')', false);
    unset($other); // il distruttore chiude la connessione

    // Posta disattivata nel negozio: niente falsi "inviato"
    $mailMethod = Configuration::get('PS_MAIL_METHOD');
    Configuration::updateValue('PS_MAIL_METHOD', 3);
    $disabledCart = $mkCart($customer, 14);
    $module->sent = array();
    $stats = $module->processCronJob(false, 0, 'cli');
    Configuration::updateValue('PS_MAIL_METHOD', $mailMethod);
    acnCheck('posta disattivata: nessun invio e nessun log', count($module->sent) === 0
        && !$db->getValue('SELECT 1 FROM `' . $table . '` WHERE id_cart = ' . (int) $disabledCart, false)
        && (bool) preg_grep('/disabled/', $stats['error_messages']));

    // Modulo disattivato
    Configuration::updateValue('ACN_MODULE_ENABLED', 0);
    $module->sent = array();
    $module->processCronJob(false, 0, 'cli');
    acnCheck('modulo disattivato: nessun invio', count($module->sent) === 0);
    Configuration::updateValue('ACN_MODULE_ENABLED', 1);

    // Mail di prova: dati di esempio completi
    $sample = $module->buildTemplateVars($module->getSampleNotificationData());
    acnCheck('mail di prova: due prodotti e totale', substr_count($sample['{product_rows_html}'], '<tr>') === 2 && $sample['{total_amount}'] !== '');
} catch (Throwable $e) {
    acnCheck('nessuna eccezione', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$restore();

echo "\n" . $GLOBALS['acn_pass'] . ' OK, ' . $GLOBALS['acn_fail'] . " FAIL\n";
exit($GLOBALS['acn_fail'] > 0 ? 1 : 0);
