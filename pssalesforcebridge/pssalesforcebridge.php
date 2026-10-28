<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Compatibility/Platform.php';
require_once __DIR__ . '/src/Salesforce/AuthClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\Platform;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\AuthClient;

class PsSalesforceBridge extends Module
{
    const CONFIG_AUTH_BASE_URI = 'PS_SFMC_AUTH_BASE_URI';
    const CONFIG_REST_BASE_URI = 'PS_SFMC_REST_BASE_URI';
    const CONFIG_CLIENT_ID = 'PS_SFMC_CLIENT_ID';
    const CONFIG_CLIENT_SECRET = 'PS_SFMC_CLIENT_SECRET';
    const CONFIG_ACCOUNT_ID = 'PS_SFMC_ACCOUNT_ID';
    const CONFIG_MODE = 'PS_SFMC_MODE';
    const CONFIG_CART_ABANDON_MINUTES = 'PS_SFMC_CART_ABANDON_MINUTES';

    public function __construct()
    {
        $this->name = 'pssalesforcebridge';
        $this->tab = 'advertising_marketing';
        $this->version = '0.1.1';
        $this->author = 'Emmakex';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => Platform::MIN_PRESTASHOP,
            'max' => Platform::MAX_PRESTASHOP,
        ];

        parent::__construct();

        $this->displayName = $this->l('Salesforce Marketing Cloud for PrestaShop');
        $this->description = $this->l('Connects PrestaShop commerce events to Salesforce Marketing Cloud Engagement.');
        $this->confirmUninstall = $this->l('Uninstall the connector? Local queue and logs will be removed.');
    }

    public function install()
    {
        if (!Platform::isSupported()) {
            return false;
        }

        return parent::install()
            && $this->installConfiguration()
            && $this->installDatabase();
    }

    public function uninstall()
    {
        return $this->uninstallDatabase()
            && $this->uninstallConfiguration()
            && parent::uninstall();
    }

    private function installConfiguration()
    {
        return Configuration::updateValue(self::CONFIG_MODE, 'test')
            && Configuration::updateValue(self::CONFIG_CART_ABANDON_MINUTES, 60);
    }

    private function uninstallConfiguration()
    {
        $keys = [
            self::CONFIG_AUTH_BASE_URI,
            self::CONFIG_REST_BASE_URI,
            self::CONFIG_CLIENT_ID,
            self::CONFIG_CLIENT_SECRET,
            self::CONFIG_ACCOUNT_ID,
            self::CONFIG_MODE,
            self::CONFIG_CART_ABANDON_MINUTES,
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    private function installDatabase()
    {
        $engine = _MYSQL_ENGINE_;
        $prefix = _DB_PREFIX_;

        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_queue` (
                `id_sfmc_queue` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_type` VARCHAR(64) NOT NULL,
                `entity_type` VARCHAR(32) NOT NULL,
                `entity_id` VARCHAR(64) NOT NULL,
                `dedupe_key` VARCHAR(191) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `available_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `processed_at` DATETIME NULL,
                `last_error` TEXT NULL,
                PRIMARY KEY (`id_sfmc_queue`),
                UNIQUE KEY `uniq_sfmc_dedupe` (`dedupe_key`),
                KEY `idx_sfmc_queue_ready` (`status`, `available_at`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_log` (
                `id_sfmc_log` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `level` VARCHAR(16) NOT NULL,
                `event` VARCHAR(64) NOT NULL,
                `context` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_sfmc_log`),
                KEY `idx_sfmc_log_created` (`created_at`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallDatabase()
    {
        $prefix = _DB_PREFIX_;

        return Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}sfmc_queue`")
            && Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}sfmc_log`");
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPsSalesforceBridge')) {
            $output .= $this->saveConfiguration();
        }

        if (Tools::isSubmit('testPsSalesforceBridgeConnection')) {
            $output .= $this->testConnection();
        }

        return $output . $this->renderForm();
    }

    private function saveConfiguration()
    {
        $authBaseUri = rtrim(trim((string) Tools::getValue(self::CONFIG_AUTH_BASE_URI)), '/');
        $restBaseUri = rtrim(trim((string) Tools::getValue(self::CONFIG_REST_BASE_URI)), '/');
        $clientId = trim((string) Tools::getValue(self::CONFIG_CLIENT_ID));
        $clientSecret = (string) Tools::getValue(self::CONFIG_CLIENT_SECRET);
        $accountId = trim((string) Tools::getValue(self::CONFIG_ACCOUNT_ID));
        $mode = Tools::getValue(self::CONFIG_MODE) === 'production' ? 'production' : 'test';
        $abandonMinutes = max(15, (int) Tools::getValue(self::CONFIG_CART_ABANDON_MINUTES));

        if (!$this->isHttpsUri($authBaseUri) || !$this->isHttpsUri($restBaseUri)) {
            return $this->displayError($this->l('Authentication and REST Base URIs must be valid HTTPS URLs.'));
        }

        if ($clientId === '') {
            return $this->displayError($this->l('Client ID is required.'));
        }

        Configuration::updateValue(self::CONFIG_AUTH_BASE_URI, $authBaseUri);
        Configuration::updateValue(self::CONFIG_REST_BASE_URI, $restBaseUri);
        Configuration::updateValue(self::CONFIG_CLIENT_ID, $clientId);
        Configuration::updateValue(self::CONFIG_ACCOUNT_ID, $accountId);
        Configuration::updateValue(self::CONFIG_MODE, $mode);
        Configuration::updateValue(self::CONFIG_CART_ABANDON_MINUTES, $abandonMinutes);

        // Empty secret means "keep the existing value" so masked forms never erase it accidentally.
        if ($clientSecret !== '') {
            Configuration::updateValue(self::CONFIG_CLIENT_SECRET, $clientSecret);
        }

        return $this->displayConfirmation($this->l('Configuration saved.'));
    }

    private function testConnection()
    {
        try {
            $client = new AuthClient(
                (string) Configuration::get(self::CONFIG_AUTH_BASE_URI),
                (string) Configuration::get(self::CONFIG_CLIENT_ID),
                (string) Configuration::get(self::CONFIG_CLIENT_SECRET),
                (string) Configuration::get(self::CONFIG_ACCOUNT_ID)
            );

            $token = $client->requestAccessToken();

            if (empty($token['access_token'])) {
                throw new RuntimeException('Salesforce did not return an access token.');
            }

            return $this->displayConfirmation($this->l('Connection successful. Salesforce returned a valid access token.'));
        } catch (Exception $e) {
            return $this->displayError($this->l('Connection failed: ') . $e->getMessage());
        }
    }

    private function renderForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitPsSalesforceBridge';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');

        $helper->fields_value = [
            self::CONFIG_AUTH_BASE_URI => (string) Configuration::get(self::CONFIG_AUTH_BASE_URI),
            self::CONFIG_REST_BASE_URI => (string) Configuration::get(self::CONFIG_REST_BASE_URI),
            self::CONFIG_CLIENT_ID => (string) Configuration::get(self::CONFIG_CLIENT_ID),
            self::CONFIG_CLIENT_SECRET => '',
            self::CONFIG_ACCOUNT_ID => (string) Configuration::get(self::CONFIG_ACCOUNT_ID),
            self::CONFIG_MODE => (string) Configuration::get(self::CONFIG_MODE),
            self::CONFIG_CART_ABANDON_MINUTES => (int) Configuration::get(self::CONFIG_CART_ABANDON_MINUTES),
        ];

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Salesforce Marketing Cloud connection'),
                    'icon' => 'icon-cloud',
                ],
                'input' => [
                    ['type' => 'text', 'label' => $this->l('Authentication Base URI'), 'name' => self::CONFIG_AUTH_BASE_URI, 'required' => true],
                    ['type' => 'text', 'label' => $this->l('REST Base URI'), 'name' => self::CONFIG_REST_BASE_URI, 'required' => true],
                    ['type' => 'text', 'label' => $this->l('Client ID'), 'name' => self::CONFIG_CLIENT_ID, 'required' => true],
                    ['type' => 'password', 'label' => $this->l('Client Secret'), 'name' => self::CONFIG_CLIENT_SECRET, 'desc' => $this->l('Leave blank to keep the existing secret.')],
                    ['type' => 'text', 'label' => $this->l('Business Unit MID / Account ID'), 'name' => self::CONFIG_ACCOUNT_ID],
                    [
                        'type' => 'select',
                        'label' => $this->l('Mode'),
                        'name' => self::CONFIG_MODE,
                        'options' => [
                            'query' => [
                                ['id' => 'test', 'name' => $this->l('Test')],
                                ['id' => 'production', 'name' => $this->l('Production')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    ['type' => 'text', 'label' => $this->l('Abandoned cart threshold (minutes)'), 'name' => self::CONFIG_CART_ABANDON_MINUTES, 'suffix' => $this->l('minutes')],
                ],
                'submit' => ['title' => $this->l('Save')],
                'buttons' => [
                    [
                        'title' => $this->l('Test connection'),
                        'name' => 'testPsSalesforceBridgeConnection',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-refresh',
                    ],
                ],
            ],
        ];

        return $helper->generateForm([$form]);
    }

    private function isHttpsUri($uri)
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
            return false;
        }

        return strtolower((string) parse_url($uri, PHP_URL_SCHEME)) === 'https';
    }
}
