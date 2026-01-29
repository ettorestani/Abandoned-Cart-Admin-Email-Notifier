<?php
/**
 * Cron Controller for Abandoned Cart Admin Notifier
 *
 * This controller handles the cron job execution for scanning and notifying
 * about abandoned carts. It must be called with a valid secure token.
 *
 * @author    Ettore Stani
 * @copyright 2024 Ettore Stani
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AbandonedCartAdminNotifierCronModuleFrontController extends ModuleFrontController
{
    /**
     * Maximum execution time in seconds
     */
    const MAX_EXECUTION_TIME = 300;

    /**
     * @var bool Disable SSL requirement for cron
     */
    public $ssl = false;

    /**
     * @var bool No authentication required
     */
    public $auth = false;

    /**
     * @var bool Disable guest mode check
     */
    public $guestAllowed = true;

    /**
     * Initialize controller
     *
     * @return void
     */
    public function init()
    {
        // Set maximum execution time
        set_time_limit(self::MAX_EXECUTION_TIME);

        parent::init();
    }

    /**
     * Main controller action
     *
     * @return void
     */
    public function initContent()
    {
        // Disable rendering
        $this->ajax = true;

        // Verify secure token
        if (!$this->validateSecureToken()) {
            $this->respondJson(array(
                'success' => false,
                'error' => 'Invalid or missing secure token',
            ), 403);
            return;
        }

        // Execute cron job
        $startTime = microtime(true);

        try {
            $stats = $this->module->processCronJob();
            $executionTime = round(microtime(true) - $startTime, 2);

            $this->respondJson(array(
                'success' => true,
                'execution_time' => $executionTime . 's',
                'statistics' => array(
                    'carts_processed' => $stats['processed'],
                    'emails_sent' => $stats['sent'],
                    'errors' => $stats['errors'],
                ),
                'error_messages' => $stats['error_messages'],
            ));
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[AbandonedCartAdminNotifier] Cron exception: ' . $e->getMessage(),
                3,
                null,
                null,
                null,
                true
            );

            $this->respondJson(array(
                'success' => false,
                'error' => 'Cron execution failed: ' . $e->getMessage(),
            ), 500);
        }
    }

    /**
     * Validate the secure token from request
     *
     * @return bool
     */
    private function validateSecureToken()
    {
        $requestToken = Tools::getValue('secure_token');
        $storedToken = Configuration::get('ACN_SECURE_TOKEN');

        if (empty($requestToken) || empty($storedToken)) {
            return false;
        }

        return hash_equals($storedToken, $requestToken);
    }

    /**
     * Send JSON response
     *
     * @param array $data Response data
     * @param int $httpCode HTTP status code
     * @return void
     */
    private function respondJson($data, $httpCode = 200)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
