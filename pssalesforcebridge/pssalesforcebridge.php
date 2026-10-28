<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Compatibility/Platform.php';
require_once __DIR__ . '/src/Compatibility/CustomerHookAdapter.php';
require_once __DIR__ . '/src/Compatibility/ShopResolver.php';
require_once __DIR__ . '/src/Salesforce/AuthClient.php';
require_once __DIR__ . '/src/Salesforce/RestClient.php';
require_once __DIR__ . '/src/Queue/QueueRepository.php';
require_once __DIR__ . '/src/Queue/CustomerQueueProcessor.php';
require_once __DIR__ . '/src/Customer/CustomerNormalizer.php';
require_once __DIR__ . '/src/Customer/CustomerSyncLedger.php';
require_once __DIR__ . '/src/Customer/CustomerEventService.php';
require_once __DIR__ . '/src/Customer/CustomerDataExtensionWriter.php';
require_once __DIR__ . '/src/Customer/CustomerBackfillRepository.php';
require_once __DIR__ . '/src/Customer/CustomerBackfillService.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\CustomerHookAdapter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\Platform;
use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\ShopResolver;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerBackfillRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerBackfillService;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerDataExtensionWriter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerEventService;
use Emmakex\PrestaShopSalesforceMarketingCloud\Customer\CustomerSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\CustomerQueueProcessor;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\Salesforce\RestClient;

class PsSalesforceBridge extends Module
{
    const CONFIG_AUTH_BASE_URI = 'PS_SFMC_AUTH_BASE_URI';
    const CONFIG_REST_BASE_URI = 'PS_SFMC_REST_BASE_URI';
    const CONFIG_CLIENT_ID = 'PS_SFMC_CLIENT_ID';
    const CONFIG_CLIENT_SECRET = 'PS_SFMC_CLIENT_SECRET';
    const CONFIG_ACCOUNT_ID = 'PS_SFMC_ACCOUNT_ID';
    const CONFIG_MODE = 'PS_SFMC_MODE';
    const CONFIG_CART_ABANDON_MINUTES = 'PS_SFMC_CART_ABANDON_MINUTES';
    const CONFIG_CUSTOMER_DE_KEY = 'PS_SFMC_CUSTOMER_DE_KEY';
    const CONFIG_CRON_TOKEN = 'PS_SFMC_CRON_TOKEN';
    const CONFIG_CUSTOMER_BACKFILL_BATCH = 'PS_SFMC_CUSTOMER_BACKFILL_BATCH';

    public function __construct()
    {
        $this->name = 'pssalesforcebridge';
        $this->tab = 'advertising_marketing';
        $this->version = '0.2.2';
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
            && $this->installDatabase()
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionCustomerAccountUpdate');
    }

    public function uninstall()
    {
        return $this->uninstallDatabase()
            && $this->uninstallConfiguration()
            && parent::uninstall();
    }

    public function hookActionCustomerAccountAdd($params)
    {
        return $this->captureCustomerEvent('CUSTOMER_CREATED', is_array($params) ? $params : []);
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        return $this->captureCustomerEvent('CUSTOMER_UPDATED', is_array($params) ? $params : []);
    }

    public function processCustomerBackfill($batchSize = null)
    {
        if ($batchSize === null) {
            $batchSize = (int) Configuration::get(self::CONFIG_CUSTOMER_BACKFILL_BATCH);
        }

        return $this->customerBackfillService()->process($batchSize > 0 ? $batchSize : 50);
    }

    public function processCustomerQueue($limit = 50)
    {
        if ((string) Configuration::get(self::CONFIG_MODE) !== 'production') {
            return ['skipped' => 'test_mode', 'claimed' => 0, 'processed' => 0, 'retry' => 0, 'failed' => 0];
        }

        $externalKey = trim((string) Configuration::get(self::CONFIG_CUSTOMER_DE_KEY));
        if ($externalKey === '') {
            throw new RuntimeException('Customer Data Extension external key is not configured.');
        }

        $auth = new AuthClient(
            (string) Configuration::get(self::CONFIG_AUTH_BASE_URI),
            (string) Configuration::get(self::CONFIG_CLIENT_ID),
            (string) Configuration::get(self::CONFIG_CLIENT_SECRET),
            (string) Configuration::get(self::CONFIG_ACCOUNT_ID)
        );
        $token = $auth->requestAccessToken();

        $rest = new RestClient(
            (string) Configuration::get(self::CONFIG_REST_BASE_URI),
            isset($token['access_token']) ? (string) $token['access_token'] : ''
        );

        $processor = new CustomerQueueProcessor(
            new QueueRepository(),
            new CustomerDataExtensionWriter($rest, $externalKey),
            new CustomerSyncLedger()
        );

        return $processor->process($limit);
    }

    private function captureCustomerEvent($eventType, array $params)
    {
        $customer = CustomerHookAdapter::customerFromParams($params);
        if (!$customer) {
            return false;
        }

        $shopId = CustomerHookAdapter::shopId($customer, isset($this->context) ? $this->context : null);
        $service = new CustomerEventService(new QueueRepository(), new CustomerSyncLedger());

        return $service->enqueue($eventType, $customer, $shopId);
    }

    private function customerBackfillService()
    {
        return new CustomerBackfillService(
            new CustomerBackfillRepository(),
            new CustomerEventService(new QueueRepository(), new CustomerSyncLedger())
        );
    }

    private function installConfiguration()
    {
        return Configuration::updateValue(self::CONFIG_MODE, 'test')
            && Configuration::updateValue(self::CONFIG_CART_ABANDON_MINUTES, 60)
            && Configuration::updateValue(self::CONFIG_CUSTOMER_DE_KEY, 'PS_CUSTOMERS')
            && Configuration::updateValue(self::CONFIG_CUSTOMER_BACKFILL_BATCH, 50)
            && Configuration::updateValue(self::CONFIG_CRON_TOKEN, bin2hex(random_bytes(24)));
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
            self::CONFIG_CUSTOMER_DE_KEY,
            self::CONFIG_CRON_TOKEN,
            self::CONFIG_CUSTOMER_BACKFILL_BATCH,
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
                `lock_token` VARCHAR(64) NULL,
                `locked_at` DATETIME NULL,
                PRIMARY KEY (`id_sfmc_queue`),
                UNIQUE KEY `uniq_sfmc_dedupe` (`dedupe_key`),
                KEY `idx_sfmc_queue_ready` (`status`, `available_at`),
                KEY `idx_sfmc_queue_lock` (`lock_token`)
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
            "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_customer_sync` (
                `id_sfmc_customer_sync` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `contact_key` VARCHAR(191) NOT NULL,
                `payload_hash` CHAR(40) NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_sfmc_customer_sync`),
                UNIQUE KEY `uniq_sfmc_customer_shop` (`id_shop`, `id_customer`),
                UNIQUE KEY `uniq_sfmc_customer_contact` (`contact_key`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `{$prefix}sfmc_customer_backfill` (
                `id_sfmc_customer_backfill` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `cursor_customer_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `scanned_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `queued_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `started_at` DATETIME NULL,
                `updated_at` DATETIME NOT NULL,
                `completed_at` DATETIME NULL,
                PRIMARY KEY (`id_sfmc_customer_backfill`),
                UNIQUE KEY `uniq_sfmc_customer_backfill_shop` (`id_shop`),
                KEY `idx_sfmc_customer_backfill_status` (`status`)
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

        return Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}sfmc_customer_backfill`")
            && Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}sfmc_customer_sync`")
            && Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}sfmc_queue`")
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

        if (Tools::isSubmit('startPsSalesforceBridgeCustomerBackfill')) {
            $output .= $this->startCustomerBackfill();
        }

        return $output . $this->renderBackfillStatus() . $this->renderForm();
    }

    private function startCustomerBackfill()
    {
        $shopIds = ShopResolver::activeShopIds(isset($this->context) ? $this->context : null);
        if (empty($shopIds)) {
            return $this->displayError($this->l('No active shops were found for customer backfill.'));
        }

        if (!$this->customerBackfillService()->start($shopIds)) {
            return $this->displayError($this->l('Unable to start or resume customer backfill.'));
        }

        return $this->displayConfirmation($this->l('Customer backfill started or resumed. The cron will process it incrementally.'));
    }

    private function renderBackfillStatus()
    {
        $rows = (new CustomerBackfillRepository())->summary();
        if (empty($rows)) {
            return $this->displayInformation($this->l('Customer backfill has not been started.'));
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = sprintf(
                'Shop %d: %s, scanned %d, queued %d, cursor %d',
                isset($row['id_shop']) ? (int) $row['id_shop'] : 0,
                isset($row['status']) ? (string) $row['status'] : 'unknown',
                isset($row['scanned_count']) ? (int) $row['scanned_count'] : 0,
                isset($row['queued_count']) ? (int) $row['queued_count'] : 0,
                isset($row['cursor_customer_id']) ? (int) $row['cursor_customer_id'] : 0
            );
        }

        return $this->displayInformation($this->l('Customer backfill status: ') . implode(' | ', $parts));
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
        $customerDeKey = trim((string) Tools::getValue(self::CONFIG_CUSTOMER_DE_KEY));
        $backfillBatch = max(10, min(500, (int) Tools::getValue(self::CONFIG_CUSTOMER_BACKFILL_BATCH)));

        if (!$this->isHttpsUri($authBaseUri) || !$this->isHttpsUri($restBaseUri)) {
            return $this->displayError($this->l('Authentication and REST Base URIs must be valid HTTPS URLs.'));
        }

        if ($clientId === '') {
            return $this->displayError($this->l('Client ID is required.'));
        }

        if ($customerDeKey === '') {
            return $this->displayError($this->l('Customer Data Extension External Key is required.'));
        }

        Configuration::updateValue(self::CONFIG_AUTH_BASE_URI, $authBaseUri);
        Configuration::updateValue(self::CONFIG_REST_BASE_URI, $restBaseUri);
        Configuration::updateValue(self::CONFIG_CLIENT_ID, $clientId);
        Configuration::updateValue(self::CONFIG_ACCOUNT_ID, $accountId);
        Configuration::updateValue(self::CONFIG_MODE, $mode);
        Configuration::updateValue(self::CONFIG_CART_ABANDON_MINUTES, $abandonMinutes);
        Configuration::updateValue(self::CONFIG_CUSTOMER_DE_KEY, $customerDeKey);
        Configuration::updateValue(self::CONFIG_CUSTOMER_BACKFILL_BATCH, $backfillBatch);

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

        $cronToken = (string) Configuration::get(self::CONFIG_CRON_TOKEN);
        $cronUrl = '';
        if (isset($this->context->link)) {
            $cronUrl = $this->context->link->getModuleLink($this->name, 'cron', ['token' => $cronToken], true);
        }

        $helper->fields_value = [
            self::CONFIG_AUTH_BASE_URI => (string) Configuration::get(self::CONFIG_AUTH_BASE_URI),
            self::CONFIG_REST_BASE_URI => (string) Configuration::get(self::CONFIG_REST_BASE_URI),
            self::CONFIG_CLIENT_ID => (string) Configuration::get(self::CONFIG_CLIENT_ID),
            self::CONFIG_CLIENT_SECRET => '',
            self::CONFIG_ACCOUNT_ID => (string) Configuration::get(self::CONFIG_ACCOUNT_ID),
            self::CONFIG_MODE => (string) Configuration::get(self::CONFIG_MODE),
            self::CONFIG_CART_ABANDON_MINUTES => (int) Configuration::get(self::CONFIG_CART_ABANDON_MINUTES),
            self::CONFIG_CUSTOMER_DE_KEY => (string) Configuration::get(self::CONFIG_CUSTOMER_DE_KEY),
            self::CONFIG_CRON_TOKEN => $cronToken,
            self::CONFIG_CUSTOMER_BACKFILL_BATCH => (int) Configuration::get(self::CONFIG_CUSTOMER_BACKFILL_BATCH),
            'PS_SFMC_CRON_URL' => $cronUrl,
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
                    ['type' => 'text', 'label' => $this->l('Customer Data Extension External Key'), 'name' => self::CONFIG_CUSTOMER_DE_KEY, 'required' => true],
                    [
                        'type' => 'select',
                        'label' => $this->l('Mode'),
                        'name' => self::CONFIG_MODE,
                        'options' => [
                            'query' => [
                                ['id' => 'test', 'name' => $this->l('Test (no outbound customer writes)')],
                                ['id' => 'production', 'name' => $this->l('Production')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    ['type' => 'text', 'label' => $this->l('Customer backfill batch size'), 'name' => self::CONFIG_CUSTOMER_BACKFILL_BATCH, 'desc' => $this->l('Customers scanned per active shop and cron run. Allowed range: 10–500.')],
                    ['type' => 'text', 'label' => $this->l('Cron URL'), 'name' => 'PS_SFMC_CRON_URL', 'readonly' => true, 'desc' => $this->l('Call this protected URL from the server cron every 5 minutes.')],
                    ['type' => 'text', 'label' => $this->l('Cron token'), 'name' => self::CONFIG_CRON_TOKEN, 'readonly' => true],
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
                    [
                        'title' => $this->l('Start / resume customer backfill'),
                        'name' => 'startPsSalesforceBridgeCustomerBackfill',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-database',
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
