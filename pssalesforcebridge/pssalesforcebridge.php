<?php

/**
 * PrestaShop -> Salesforce Marketing Cloud Growth + Data Cloud.
 *
 * v0.8.6: per-entity safe diagnostics for native PST readback; no egress.
 * v0.8.5: staging-only read-only native PSCustomer/PSOrder shape check.
 * v0.8.4: capture initial ORDER_CREATED before PrestaShop applies order state.
 * v0.8.3: ALFABH publisher and MariaDB 11.8 database check.
 * v0.8.1: staged local-only Growth event capture, explicitly armed in BO.
 * No Salesforce delivery, campaign, auto migration or production activation.
 * Existing 0.7.1 legacy tables, settings and customer data are preserved but
 * NEVER read, sent or deleted by this new runtime.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Compatibility/Platform.php';
require_once __DIR__ . '/src/Compatibility/ShopResolver.php';
require_once __DIR__ . '/src/DataCloud/ShopTimezoneResolver.php';
require_once __DIR__ . '/src/DataCloud/InstallationReadinessInspector.php';
require_once __DIR__ . '/src/DataCloud/PrestaShopReadinessProbe.php';
require_once __DIR__ . '/src/DataCloud/InstallationReadinessDashboard.php';
require_once __DIR__ . '/src/Growth/GrowthOnlyHookPreflight.php';
require_once __DIR__ . '/src/Provider/ExplicitProviderRouter.php';
require_once __DIR__ . '/src/DataCloud/LiveEventUtcObservation.php';
require_once __DIR__ . '/src/DataCloud/StagedAtomicUtcAcceptance.php';
require_once __DIR__ . '/src/Growth/BlockedOutboxCoordinator.php';
require_once __DIR__ . '/src/Compatibility/CustomerHookAdapter.php';
require_once __DIR__ . '/src/Compatibility/OrderHookAdapter.php';
require_once __DIR__ . '/src/Growth/GrowthBlockedHookAdapter.php';
require_once __DIR__ . '/src/Growth/GrowthOutboxSchemaPlan.php';
require_once __DIR__ . '/src/Growth/GrowthSqlEngineGate.php';
require_once __DIR__ . '/src/Growth/GrowthStagingSchemaBootstrap.php';
require_once __DIR__ . '/src/Growth/GrowthStagingOutboxStore.php';
require_once __DIR__ . '/src/Growth/GrowthStagingHookCapture.php';
require_once __DIR__ . '/src/DataCloud/IngestionPayloadBuilder.php';
require_once __DIR__ . '/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once __DIR__ . '/src/DataCloud/GrowthNativeCustomerOrderDraft.php';
require_once __DIR__ . '/src/Growth/GrowthStagingNativeReadback.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\Platform;
use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\ShopResolver;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\PrestaShopReadinessProbe;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\InstallationReadinessDashboard;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOnlyHookPreflight;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingSchemaBootstrap;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingNativeReadback;

class PsSalesforceBridge extends Module
{
    const CONFIG_MODE = 'PS_SFMC_MODE';
    const CONFIG_CRON_TOKEN = 'PS_SFMC_CRON_TOKEN';
    const CONFIG_MERCHANT = 'PS_GROWTH_MERCHANT';
    const CONFIG_NAMESPACE = 'PS_GROWTH_NAMESPACE';
    const CONFIG_LOCAL_CAPTURE = 'PS_GROWTH_LOCAL_CAPTURE';
    const CONFIG_CAPTURE_HMAC = 'PS_GROWTH_CAPTURE_HMAC_KEY';

    const PROVIDER = 'marketing_cloud_growth';
    const DATA_PLATFORM = 'data_cloud';
    const SCHEMA_SOURCE = 'ABH_PrestaShop_Data_Cloud';

    public function __construct()
    {
        $this->name = 'pssalesforcebridge';
        $this->tab = 'advertising_marketing';
        $this->version = '0.8.6';
        $this->author = 'ALFABH';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => Platform::MIN_PRESTASHOP,
            'max' => Platform::MAX_PRESTASHOP,
        ];

        parent::__construct();
        $this->displayName = $this->l('Salesforce Marketing Cloud Growth + Data Cloud');
        $this->description = $this->l('Growth-only PrestaShop bridge. Data ingestion and campaign sends remain blocked pending approval.');
        $this->confirmUninstall = $this->l('Uninstall the module? Existing historical tables and data will remain untouched.');
    }

    public function install()
    {
        if (!Platform::isSupported() || !parent::install()) {
            return false;
        }
        // No additional tables, no credential provisioning and no external
        // requests. Reuse the existing token only if already present.
        if (Configuration::get(self::CONFIG_CRON_TOKEN) === false
            && !Configuration::updateValue(
                self::CONFIG_CRON_TOKEN, bin2hex(random_bytes(24))
            )) {
            return false;
        }
        if (!Configuration::updateValue(self::CONFIG_MODE, 'test')
            || !Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE, 'off')) {
            return false;
        }

        return $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionCustomerAccountUpdate')
            && $this->registerHook('actionValidateOrder');
    }

    public function uninstall()
    {
        // Do not delete 0.7.1 historical queue, cart, recovery, ledger,
        // identity or consent evidence. A separate owner-approved cleanup
        // migration is required; an uninstall is NOT data-erasure consent.
        if (!parent::uninstall()) {
            return false;
        }
        Configuration::deleteByName(self::CONFIG_MERCHANT);
        Configuration::deleteByName(self::CONFIG_NAMESPACE);
        return true;
    }

    /**
     * Recognize new hook shapes without persisting, queueing, or exporting
     * customer data. A true return only means the hook was safely handled;
     * it NEVER means a customer was exported or a campaign was triggered.
     */
    public function hookActionCustomerAccountAdd($params)
    {
        GrowthOnlyHookPreflight::inspect('CUSTOMER_CREATED', $params);
        GrowthStagingHookCapture::captureFromShop('actionCustomerAccountAdd', $params);
        return true;
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        GrowthOnlyHookPreflight::inspect('CUSTOMER_UPDATED', $params);
        GrowthStagingHookCapture::captureFromShop('actionCustomerAccountUpdate', $params);
        return true;
    }

    public function hookActionValidateOrder($params)
    {
        GrowthOnlyHookPreflight::inspect('ORDER_CREATED', $params);
        GrowthStagingHookCapture::captureFromShop('actionValidateOrder', $params);
        return true;
    }

    /**
     * These two hooks can remain registered on upgraded 0.7.1 shops.
     * Never call any retired cart or campaign writers or infer ORDER_PAID/ORDER_CANCELLED
     * from an unreviewed status code; Growth processing is blocked.
     */
    public function hookActionCartSave($params)
    {
        return true;
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        return true;
    }

    public function getContent()
    {
        $message = '';
        if (Tools::isSubmit('submitPsGrowthReadback')) {
            $message = $this->verifyStagingNativeReadback();
        } elseif (Tools::isSubmit('submitPsGrowthBridge')) {
            $message = $this->saveGrowthSettings();
        } elseif (Tools::isSubmit('submitPsGrowthStagingCaptureStop')) {
            $message = $this->disableStagingCapture();
        } elseif (Tools::isSubmit('submitPsGrowthStagingCapture')) {
            $message = $this->armStagingCapture();
        }

        $html = $message;
        $html .= $this->displayInformation(
            $this->l('Provider: Marketing Cloud Growth + Data Cloud only. Mode: Test. The existing Salesforce Core OAuth and Data Cloud token exchange have already been certified; this panel does not repeat them.')
        );
        $html .= $this->displayInformation(
            $this->l('No Data Cloud ingestion, schema upload, Data Streams, identity matching, historic backfill, campaign sends or cart recovery is enabled.')
        );
        try {
            $shops = ShopResolver::activeShopIds(isset($this->context) ? $this->context : null);
            $review = PrestaShopReadinessProbe::inspect($shops);
            $html .= (new InstallationReadinessDashboard())->render($review);
        } catch (\Throwable $e) {
            $html .= $this->displayError(
                $this->l('The read-only Data Cloud installation checks are unavailable; exports remain blocked.')
            );
        }
        return $html . $this->renderGrowthForm() . $this->renderCaptureForm()
            . $this->renderReadbackForm();
    }

    /**
     * Explicit Back Office action. Read only; never reveal an ID, email, JSON,
     * amount, endpoint, OAuth token, SQL statement or raw exception message.
     * This cannot certify consent, source-write provenance or remote delivery.
     */
    private function verifyStagingNativeReadback()
    {
        if ((string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)
                !=='staging_armed') {
            return $this->displayError(
                $this->l('Local staging capture must be armed in Test. No data read or sent.')
            );
        }
        try {
            $db=Db::getInstance();
            $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
            $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
            $loadCustomer=function($id) { return new \Customer($id); };
            $loadOrder=function($id) { return new \Order($id); };
            $loadCurrency=function($id) { return new \Currency($id); };
            $messages=[];
            $blocked=false;
            // Inspect each entity independently: a nullable Customer source
            // field must not hide that the Order readback is valid (or vice
            // versa). Never reveal raw SQL, exception text or source data.
            foreach (['customer','order'] as $type) {
                try {
                    $report=GrowthStagingNativeReadback::inspectLatest(
                        $db,(string)_PS_VERSION_,$merchant,$namespace,_DB_PREFIX_,
                        $type,$loadCustomer,$loadOrder,$loadCurrency
                    );
                    $status=(string)$report['status'];
                    if (!in_array($status,[
                        'native_pst_shape_verified_blocked','no_blocked_event'
                    ],true)) {
                        $status='unknown_status';
                        $blocked=true;
                    }
                    $messages[]=$type . ': ' . $status;
                } catch (\Throwable $e) {
                    $blocked=true;
                    $messages[]=$type . ': blocked ('
                        . self::readbackSafeFailureCode($e) . ')';
                }
            }
            $message=$this->l('G7 local native PST check (no sends): ')
                . $this->escape(implode(' | ',$messages))
                . '. ' . $this->l('No records sent. Source freshness, consent and all exports remain unapproved.');
            return $blocked ? $this->displayError($message)
                : $this->displayConfirmation($message);
        } catch (\Throwable $e) {
            return $this->displayError(
                $this->l('G7 local PST check blocked (setup_unavailable). No records sent.')
            );
        }
    }

    /**
     * Whitelist only STATIC source-condition identifiers in the BO. Never
     * echo a raw Throwable message, SQL, arbitrary class or trace. Unknown
     * errors become a single opaque marker, not false success.
     */
    private static function readbackSafeFailureCode(\Throwable $e)
    {
        $known=[
            'g7_readback_not_authorized'=>'local_test_not_armed',
            'g7_readback_sql_engine_unverified'=>'db_check_constraints_unverified',
            'g7_readback_binding_unverified'=>'g3_binding_unverified',
            'g7_readback_event_lookup_unverified'=>'outbox_query_unverified',
            'g7_readback_event_invalid'=>'outbox_event_invalid',
            'g7_readback_ledger_unverified'=>'ledger_query_unverified',
            'g7_readback_ledger_outdated'=>'ledger_event_not_latest',
            'g7_readback_ledger_changed'=>'ledger_changed_during_check',
            'g7_readback_order_scope_invalid'=>'native_order_scope_invalid',
            'g7_readback_order_relationship_invalid'=>'native_order_relation_missing',
            'g7_native_draft_objects_required'=>'native_object_unavailable',
            'g7_native_draft_customer_fields_missing'=>'native_customer_field_missing',
            'g7_native_draft_order_fields_missing'=>'native_order_field_missing',
            'g7_native_draft_currency_fields_missing'=>'native_currency_field_missing',
            'g7_native_draft_customer_flag_invalid'=>'native_customer_flag_unverified',
            'g7_native_draft_customer_excluded'=>'native_customer_excluded',
            'g7_native_draft_source_field_invalid'=>'native_source_field_type_invalid',
            'g7_native_draft_cross_scope_or_orphan'=>'native_source_scope_or_relation_invalid',
            'g7_native_draft_total_unverified'=>'native_order_total_invalid',
            'g4_pst_invalid_local_date'=>'native_source_date_invalid',
            'g4_pst_invalid_email'=>'native_customer_email_invalid',
            'g4_pst_field_inventory_invalid'=>'pst_fields_invalid',
            'g4_pst_invalid_value_type'=>'pst_value_type_invalid',
            'g4_pst_invalid_scope'=>'pst_scope_invalid',
            'g4_pst_invalid_typed_pk'=>'pst_identity_invalid',
            'g4_pst_cross_scope_fk'=>'pst_relation_invalid',
            'g4_pst_invalid_order_values'=>'pst_order_values_invalid'
        ];
        $raw=$e->getMessage();
        return isset($known[$raw]) ? $known[$raw] : 'unclassified_blocked';
    }

    private function renderReadbackForm()
    {
        $helper=new HelperForm();
        $helper->module=$this;
        $helper->name_controller=$this->name;
        $helper->token=Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex=AdminController::$currentIndex.'&configure='.$this->name;
        $helper->submit_action='submitPsGrowthReadback';
        $helper->default_form_language=(int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang=(int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        return $helper->generateForm([['form'=>[
            'legend'=>[
                'title'=>$this->l('G7 - Read-only native PST source check'),
                'icon'=>'icon-search'
            ],
            'description'=>$this->l('Explicitly check the latest blocked customer and order on staging. Results contain only generic statuses. No SQL changes, emails or Salesforce requests.'),
            'input'=>[],
            'submit'=>['title'=>$this->l('Check native customer and order (no sends)')]
        ]]]);
    }

    private function saveGrowthSettings()
    {
        $merchant = Tools::getValue(self::CONFIG_MERCHANT);
        $namespace = Tools::getValue(self::CONFIG_NAMESPACE);
        if (!is_string($merchant) ||
            !preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $merchant) ||
            !is_string($namespace) ||
            !preg_match('/^[a-z][a-z0-9_]{5,31}$/D', $namespace)) {
            return $this->displayError(
                $this->l('Merchant and installation namespace are required. Use uppercase merchant and lowercase namespace identifiers.')
            );
        }
        // These are CANDIDATES. Saving them does not certify global uniqueness
        // nor authorize shipping records to the connected PST source.
        $oldMerchant = Configuration::get(self::CONFIG_MERCHANT);
        $oldNamespace = Configuration::get(self::CONFIG_NAMESPACE);
        if ((is_string($oldMerchant) && $oldMerchant !== '' && $oldMerchant !== $merchant)
            || (is_string($oldNamespace) && $oldNamespace !== '' && $oldNamespace !== $namespace)) {
            return $this->displayError(
                $this->l('Installation identity cannot be changed in place. Review namespace ownership before migration.')
            );
        }
        if (!Configuration::updateValue(self::CONFIG_MODE, 'test')
            || !Configuration::updateValue(self::CONFIG_MERCHANT, $merchant)
            || !Configuration::updateValue(self::CONFIG_NAMESPACE, $namespace)) {
            return $this->displayError($this->l('Unable to save Growth configuration.'));
        }
        return $this->displayConfirmation(
            $this->l('Growth identity candidate saved in Test. No external requests were made.')
        );
    }

    /**
     * Explicit opt-in for a local-only Test journal. This is an actual
     * database change; never called during install/upgrade or cron.
     */
    private function armStagingCapture()
    {
        if ((string)Configuration::get(self::CONFIG_LOCAL_CAPTURE) === 'staging_armed') {
            return $this->displayInformation(
                $this->l('Staging local capture already enabled; no change made.')
            );
        }
        $confirmation = Tools::getValue('PS_GROWTH_STAGING_CONFIRM');
        if ($confirmation !== GrowthStagingSchemaBootstrap::CONFIRM) {
            return $this->displayError(
                $this->l('Exact staging-only confirmation required. No change made.')
            );
        }
        try {
            $merchant = Configuration::get(self::CONFIG_MERCHANT);
            $namespace = Configuration::get(self::CONFIG_NAMESPACE);
            $database = Db::getInstance();
            GrowthStagingSchemaBootstrap::install(
                $database, (string)_PS_VERSION_, $merchant, $namespace,
                _DB_PREFIX_, $confirmation
            );
            $key = Configuration::get(self::CONFIG_CAPTURE_HMAC);
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
                $key = bin2hex(random_bytes(32));
            }
            if (!Configuration::updateValue(self::CONFIG_CAPTURE_HMAC, $key)
                || !Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE, 'staging_armed')) {
                Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE, 'off');
                return $this->displayError(
                    $this->l('Could not arm local Test capture. No export enabled.')
                );
            }
        } catch (\Throwable $e) {
            Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE, 'off');
            return $this->displayError(
                $this->l('Local staging schema preflight failed. No export enabled. Check SQL engine/namespace.')
            );
        }
        return $this->displayConfirmation(
            $this->l('Local Test capture enabled. Only blocked customer/order identifiers are journaled. No Salesforce requests.')
        );
    }

    private function disableStagingCapture()
    {
        if (!Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE, 'off')) {
            return $this->displayError($this->l('Local capture could not be disabled.'));
        }
        return $this->displayConfirmation(
            $this->l('Local capture stopped. Existing G3 evidence and historical data are preserved.')
        );
    }

    private function renderCaptureForm()
    {
        $enabled = (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE) === 'staging_armed';
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = $enabled
            ? 'submitPsGrowthStagingCaptureStop'
            : 'submitPsGrowthStagingCapture';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->fields_value = ['PS_GROWTH_STAGING_CONFIRM' => ''];
        $input = $enabled ? [] : [[
            'type' => 'text',
            'name' => 'PS_GROWTH_STAGING_CONFIRM',
            'label' => $this->l('Staging-only approval phrase'),
            'required' => true,
            'desc' => $this->l('Only on an authorized staging shop, after privacy approval and backup. Enter ENABLE_STAGING_LOCAL_CAPTURE_ONLY. Creates three local Growth tables, no outbound sends.')
        ]];
        return $helper->generateForm([['form' => [
            'legend' => [
                'title' => $this->l('G7 - Local Test capture (no Salesforce sends)'),
                'icon' => 'icon-lock'
            ],
            'input' => $input,
            'submit' => ['title' => $enabled
                ? $this->l('Stop local capture')
                : $this->l('Prepare & enable local Test capture')],
        ]]]);
    }

    private function renderGrowthForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitPsGrowthBridge';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->fields_value = [
            self::CONFIG_MERCHANT => (string) Configuration::get(self::CONFIG_MERCHANT),
            self::CONFIG_NAMESPACE => (string) Configuration::get(self::CONFIG_NAMESPACE),
        ];

        return $helper->generateForm([['form' => [
            'legend' => [
                'title' => $this->l('Marketing Cloud Growth + Data Cloud (Test)'),
                'icon' => 'icon-cloud',
            ],
            'input' => [
                ['type' => 'text', 'label' => $this->l('Merchant code'),
                    'name' => self::CONFIG_MERCHANT, 'required' => true],
                ['type' => 'text', 'label' => $this->l('Installation namespace candidate'),
                    'name' => self::CONFIG_NAMESPACE, 'required' => true],
            ],
            'submit' => ['title' => $this->l('Save Test identity')],
        ]]]);
    }

    public function healthReport()
    {
        // A healthy local module is not a Data Cloud export authorization.
        return [
            'ok' => true,
            'status' => 'blocked_test',
            'mode' => 'test',
            'module_version' => $this->version,
            'prestashop_version' => defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown',
            'provider' => self::PROVIDER,
            'data_platform' => self::DATA_PLATFORM,
            'source' => self::SCHEMA_SOURCE,
            'identity_candidate_present' =>
                (string) Configuration::get(self::CONFIG_MERCHANT) !== ''
                && (string) Configuration::get(self::CONFIG_NAMESPACE) !== '',
            'schema_upload_allowed' => false,
            'data_streams_allowed' => false,
            'record_ingestion_allowed' => false,
            'local_capture_enabled' => (string) Configuration::get(self::CONFIG_LOCAL_CAPTURE) === 'staging_armed',
            'local_capture_outbound_allowed' => false,
            'historical_backfill_allowed' => false,
            'marketing_sends_allowed' => false,
            'recovery_allowed' => false,
        ];
    }

    public function cronReport()
    {
        // Pure status endpoint. No legacy queue, retry, backfill, scheduler,
        // retention or retired campaign processor is called.
        return [
            'ok' => true,
            'status' => 'blocked_test',
            'provider' => self::PROVIDER,
            'growth_delivery' => ['skipped' => 'approval_required'],
            'data_cloud_ingestion' => ['skipped' => 'schema_required'],
            'marketing_sends' => ['skipped' => 'approval_required'],
        ];
    }
}
