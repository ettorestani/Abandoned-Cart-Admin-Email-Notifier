<?php
/**
 * Run the abandoned cart scan from the command line, as an alternative to the cron URL
 *
 * Usage, from any directory:
 *   php /path/to/shop/modules/abandonedcartadminnotifier/cli/cron.php [--dry-run]
 *
 * Exit code: 0 on success, 1 when the run reported errors, 2 when it could not start.
 * No token is needed: only someone with shell access to the server can run it.
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

// Never runs through the web server, before PrestaShop is even loaded
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$options = getopt('', array('dry-run', 'help'));
if (isset($options['help'])) {
    echo "Usage: php " . basename(__FILE__) . " [--dry-run]\n"
        . "  --dry-run  list the carts that would be notified, without sending or logging anything\n";
    exit(0);
}
$dryRun = isset($options['dry-run']);

require_once dirname(__FILE__) . '/../../../config/config.inc.php';

/** @var AbandonedCartAdminNotifier|false $module */
$module = Module::getInstanceByName('abandonedcartadminnotifier');
if (!$module || !Module::isInstalled('abandonedcartadminnotifier')) {
    fwrite(STDERR, "The abandonedcartadminnotifier module is not installed.\n");
    exit(2);
}

$startTime = microtime(true);
try {
    $module->initCliContext();
    // No time budget: the CLI has no max_execution_time
    $stats = $module->processCronJob($dryRun, 0, 'cli');
} catch (Throwable $e) {
    fwrite(STDERR, 'Cron execution failed: ' . $e->getMessage() . "\n");
    exit(2);
}

$response = array(
    'success' => true,
    'dry_run' => $dryRun,
    'execution_time' => round(microtime(true) - $startTime, 2) . 's',
    'statistics' => array(
        'carts_processed' => $stats['processed'],
        'emails_sent' => $stats['sent'],
        'errors' => $stats['errors'],
    ),
    'error_messages' => $stats['error_messages'],
);
if ($dryRun) {
    $response['carts_to_notify'] = $stats['carts'];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($stats['errors'] > 0 ? 1 : 0);
