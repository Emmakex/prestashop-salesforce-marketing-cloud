<?php

/**
 * PrestaShop -> Salesforce Marketing Cloud Growth + Data Cloud.
 *
 * v0.9.6 beta: protected staging pair cron, customer before order (OFF by default).
 * v0.9.5: staging G8 controlled UAT dispatcher (OFF until owner approval).
 * Existing public version remains frozen until end-to-end certification.
 * v0.9.4: safe, distinct local preview vs real send diagnostics; no PII.
 * v0.9.3: merchant-independent source binding for each shop (DOABH etc.).
 * v0.9.2: secure per-shop Salesforce credentials and OAuth-only BO test.
 * v0.9.0: simplified accessible Back Office pre-beta with one guided smoke.
 * v0.8.9: one-customer/one-order manual Test smoke, individual guarded
 * Data Cloud upsert only on explicit BO authorization. No auto sends.
 * v0.8.8: owner-gated additive staging G7 lease columns (no outbound).
 * v0.8.7: fix missing BO escape helper to reveal safe G7 diagnoses.
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
require_once __DIR__ . '/src/Growth/GrowthG7DeliverySchemaPlan.php';
require_once __DIR__ . '/src/Growth/GrowthG7StagingDeliveryMigration.php';
require_once __DIR__ . '/src/Growth/GrowthSqlEngineGate.php';
require_once __DIR__ . '/src/Growth/GrowthStagingSchemaBootstrap.php';
require_once __DIR__ . '/src/Growth/GrowthStagingOutboxStore.php';
require_once __DIR__ . '/src/Growth/GrowthStagingHookCapture.php';
require_once __DIR__ . '/src/DataCloud/IngestionPayloadBuilder.php';
require_once __DIR__ . '/src/DataCloud/GrowthTypedPstPayloadBuilder.php';
require_once __DIR__ . '/src/DataCloud/GrowthNativeCustomerOrderDraft.php';
require_once __DIR__ . '/src/Growth/GrowthStagingNativeReadback.php';
require_once __DIR__ . '/src/Growth/GrowthOneRecordSelection.php';
require_once __DIR__ . '/src/Growth/GrowthOneRecordSafeDiagnosis.php';
require_once __DIR__ . '/src/Growth/GrowthOneRecordManualSender.php';
require_once __DIR__ . '/src/Growth/GrowthShopCredentialVault.php';
require_once __DIR__ . '/src/Growth/GrowthShopSourceBinding.php';
require_once __DIR__ . '/src/Growth/GrowthPrestaShopDeliveryLease.php';
require_once __DIR__ . '/src/Growth/GrowthG7CurrentEventBatch.php';
require_once __DIR__ . '/src/Growth/GrowthG8StagingCandidateAdapter.php';
require_once __DIR__ . '/src/Growth/GrowthStableBoCsrf.php';
require_once __DIR__ . '/src/Growth/GrowthG8ExactCaptureInspector.php';
require_once __DIR__ . '/src/Growth/GrowthG8UatPairScope.php';
require_once __DIR__ . '/src/Growth/GrowthG8UatReceiptReview.php';
require_once __DIR__ . '/src/Growth/GrowthG8LocalPreDispatchReview.php';
require_once __DIR__ . '/src/Growth/GrowthG8CronReadOnly.php';
require_once __DIR__ . '/src/DataCloud/GrowthG8DloMetadataResolver.php';
require_once __DIR__ . '/src/DataCloud/GrowthG8DloPresenceReader.php';
require_once __DIR__ . '/src/DataCloud/GrowthG8CloudMetadataClient.php';
require_once __DIR__ . '/src/Growth/GrowthG8ScopedDloRead.php';
require_once __DIR__ . '/src/Growth/GrowthLocalCustomerHoldReader.php';
require_once __DIR__ . '/src/Growth/GrowthAutomaticOneShotCoordinator.php';
require_once __DIR__ . '/src/Growth/GrowthG8DurableReceiptBridge.php';
require_once __DIR__ . '/src/Growth/GrowthG8StagingReadyGate.php';
require_once __DIR__ . '/src/Growth/GrowthG8StagingWorker.php';
require_once __DIR__ . '/src/Growth/GrowthG8NativeStagingServices.php';
require_once __DIR__ . '/src/DataCloud/GrowthStreamingIngestionPlan.php';
require_once __DIR__ . '/src/DataCloud/GrowthDataCloudUpsertTransport.php';
require_once __DIR__ . '/src/Growth/AuthClient.php';
require_once __DIR__ . '/src/DataCloud/TokenExchangeClient.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\Platform;
use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\ShopResolver;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\PrestaShopReadinessProbe;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\InstallationReadinessDashboard;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOnlyHookPreflight;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingHookCapture;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingSchemaBootstrap;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStagingNativeReadback;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG7StagingDeliveryMigration;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOneRecordSelection;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOneRecordSafeDiagnosis;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthOneRecordManualSender;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthShopCredentialVault;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthShopSourceBinding;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStableBoCsrf;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8ExactCaptureInspector;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatPairScope;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8UatReceiptReview;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8LocalPreDispatchReview;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8CronReadOnly;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8StagingWorker;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8NativeStagingServices;
use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\AuthClient;
use Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\TokenExchangeClient;

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
        $this->version = '0.9.7';
        $this->author = 'ALFABH';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => Platform::MIN_PRESTASHOP,
            'max' => Platform::MAX_PRESTASHOP,
        ];

        parent::__construct();
        $this->displayName = $this->l('Salesforce Marketing Cloud Growth + Data Cloud');
        $this->description = $this->l('PrestaShop connector for Salesforce Growth + Data Cloud. Controlled one-record testing; automatic synchronization and campaigns remain disabled.');
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
        if (Tools::isSubmit('submitPsGrowthOauthSave')) {
            $message = $this->handleSalesforceCredentials('save');
        } elseif (Tools::isSubmit('submitPsGrowthOauthTest')) {
            $message = $this->handleSalesforceCredentials('test');
        } elseif (Tools::isSubmit('submitPsGrowthOauthRemove')) {
            $message = $this->handleSalesforceCredentials('remove');
        } elseif (Tools::isSubmit('submitPsGrowthG8CronTokenReveal')) {
            $message=$this->handleG8CronTokenReveal();
        } elseif (Tools::isSubmit('submitPsGrowthG8TargetBind')) {
            $message=$this->handleG8UatTarget('bind');
        } elseif (Tools::isSubmit('submitPsGrowthG8TargetRevoke')) {
            $message=$this->handleG8UatTarget('revoke');
        } elseif (Tools::isSubmit('submitPsGrowthG8ReadG3')) {
            $message=$this->handleG8UatReadOnly('g3');
        } elseif (Tools::isSubmit('submitPsGrowthG8DiagnoseLocal')) {
            $message=$this->handleG8UatReadOnly('local');
        } elseif (Tools::isSubmit('submitPsGrowthG8ReadDlo')) {
            $message=$this->handleG8UatReadOnly('dlo');
        } elseif (Tools::isSubmit('submitPsGrowthG8Preflight')) {
            $message=$this->handleG8UatOperator('preflight');
        } elseif (Tools::isSubmit('submitPsGrowthG8RunCustomer')) {
            $message=$this->handleG8UatOperator('customer');
        } elseif (Tools::isSubmit('submitPsGrowthG8RunOrder')) {
            $message=$this->handleG8UatOperator('order');
        } elseif (Tools::isSubmit('submitPsGrowthG8CaptureInspect')) {
            $message = $this->handleG8ExactCaptureInspect();
        } elseif (Tools::isSubmit('submitPsGrowthOnePreview')) {
            $message = $this->runManualOneRecord('preview');
        } elseif (Tools::isSubmit('submitPsGrowthOneCustomerSend')) {
            $message = $this->runManualOneRecord('customer');
        } elseif (Tools::isSubmit('submitPsGrowthOneOrderSend')) {
            $message = $this->runManualOneRecord('order');
        } elseif (Tools::isSubmit('submitPsGrowthBridge')) {
            $message = $this->saveGrowthSettings();
        } elseif (Tools::isSubmit('submitPsGrowthG8CapturePrepare')) {
            $message=$this->handleG8StageCapturePrepare();
        } elseif (Tools::isSubmit('submitPsGrowthG8UatApprove')) {
            $message=$this->handleG8StagingUat('approve');
        } elseif (Tools::isSubmit('submitPsGrowthG8UatRevoke')) {
            $message=$this->handleG8StagingUat('revoke');
        } elseif (Tools::isSubmit('submitPsGrowthStagingCaptureStop')) {
            // Keep only the safe OFF switch for an already armed old staging shop.
            $message = $this->disableStagingCapture();
        }
        // Previous G7 development buttons are no longer callable from BO:
        // no schema DDL, capture arming or stale readback UI in the pre-beta.
        return $message . $this->renderClientConsole();
    }

    /**
     * Compact client-facing PrestaShop Back Office. Smarty receives only
     * sanitized state and two numeric form values; never credentials, payload,
     * email, source keys or HTTP responses.
     */
    private function renderClientConsole()
    {
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        $mode=(string)Configuration::get(self::CONFIG_MODE);
        $capture=(string)Configuration::get(self::CONFIG_LOCAL_CAPTURE);
        $shop=$this->credentialContextShopId();
        $identity=GrowthShopSourceBinding::isMerchant($merchant)
            && GrowthShopSourceBinding::isNamespace($namespace)
            && $shop>0;
        $source=GrowthShopSourceBinding::inspect($shop,$merchant,$namespace);
        // On a new independent installation, propose a random namespace.
        // Do not overwrite one that has already been saved.
        $suggestedNamespace=$namespace!==''?$namespace
            :'pssfmc_'.bin2hex(random_bytes(8));
        $credentialStatus=GrowthShopCredentialVault::inspect($shop);
        $credentials=$credentialStatus['ready'];
        $canManage=$this->isCredentialAdmin($shop);
        $g8Target=GrowthG8UatPairScope::inspect(
            $shop,$merchant,$namespace
        );
        $customerInput=Tools::getValue('PS_GROWTH_ONE_CUSTOMER_ID');
        $orderInput=Tools::getValue('PS_GROWTH_ONE_ORDER_ID');
        // Render only normalized numeric IDs from the current request, not
        // arbitrary POST strings or source customer/order properties.
        $customerInput=is_scalar($customerInput)
            && preg_match('/^[1-9][0-9]{0,14}$/D',(string)$customerInput)
            ?(string)$customerInput:'';
        $orderInput=is_scalar($orderInput)
            && preg_match('/^[1-9][0-9]{0,14}$/D',(string)$orderInput)
            ?(string)$orderInput:'';
        $action=AdminController::$currentIndex.'&configure='.$this->name
            .'&token='.Tools::getAdminTokenLite('AdminModules');
        $this->context->smarty->assign([
            'sfmc_action'=>$action,
            'sfmc_version'=>$this->version,
            'sfmc_merchant'=>$merchant,
            'sfmc_namespace'=>$suggestedNamespace,
            'sfmc_identity_ready'=>$identity,
            'sfmc_source_ready'=>$source['ready'],
            'sfmc_source_name'=>$source['source'],
            'sfmc_source_confirmation'=>GrowthShopSourceBinding::ATTEST,
            'sfmc_credentials_ready'=>$credentials,
            'sfmc_credentials_locked'=>$credentialStatus['status']==='unavailable',
            'sfmc_credentials_admin_allowed'=>$canManage,
            'sfmc_credentials_csrf'=>$canManage
                ?$this->credentialCsrf($shop):'',
            'sfmc_capture_armed'=>$capture==='staging_armed',
            'sfmc_g8_is_stage'=>$shop>0
                && preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )===1,
            'sfmc_g8_target_bound'=>$g8Target!==null,
            'sfmc_g8_target_can_bind'=>$canManage
                && $g8Target===null && $mode==='test'
                && $capture==='off'
                && preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )===1,
            'sfmc_g8_capture_prepare_available'=>$canManage
                && $identity && $source['ready'] && $credentials
                && $mode==='test' && $capture==='off'
                && preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )===1,
            'sfmc_g8_capture_prepare_phrase'=>GrowthStagingSchemaBootstrap::CONFIRM,
            'sfmc_g8_backup_phrase'=>'I_HAVE_A_VERIFIED_STAGING_BACKUP',
            'sfmc_g8_staging_available'=>$canManage
                && $g8Target!==null
                && $identity && $source['ready'] && $credentials
                && $mode==='test' && $capture==='off'
                && preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )===1
                && (string)Configuration::get(
                    'PS_SFMC_CART_CONSENT_MODE'
                )==='marketing_optin',
            'sfmc_g8_staging_approved'=>(string)Configuration::get(
                'PS_GROWTH_G8_STAGING_APPROVAL'
            )===GrowthG8StagingWorker::APPROVAL,
            'sfmc_g8_staging_confirmation'=>GrowthG8StagingWorker::APPROVAL,
            'sfmc_can_send'=>$canManage && $identity && $source['ready']
                && $credentials && $mode==='test'
                && $capture==='off',
            'sfmc_customer_input'=>$customerInput,
            'sfmc_order_input'=>$orderInput,
            'sfmc_customer_status'=>GrowthOneRecordManualSender::statusForShop(
                $namespace,$shop,'PSCustomer'),
            'sfmc_order_status'=>GrowthOneRecordManualSender::statusForShop(
                $namespace,$shop,'PSOrder')
        ]);
        return $this->display(__FILE__,'views/templates/admin/console.tpl');
    }

    /**
     * Read-only G3 receipt and tenant DLO checks for an uncertain G8 attempt.
     * No staging approval is needed to reconcile after a safety revocation.
     * Does NOT claim, promote, cancel, retry, resend, or enable marketing.
     */
    private function handleG8UatReadOnly($operation)
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        if (!in_array($operation,['g3','dlo','local'],true)
            || !$this->isCredentialAdmin($shop)
            || !is_string($csrf) || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1) {
            return $this->displayError(
                $this->l('Revisión G8 no autorizada para esta tienda. No se ha enviado nada nuevo.')
            );
        }
        $target=GrowthG8UatPairScope::inspect(
            $shop,$merchant,$namespace
        );
        if (!is_array($target)) {
            return $this->displayError(
                $this->l('Revisión G8 sin cliente/pedido de UAT seleccionado. No se ha enviado nada.')
            );
        }
        if ($operation==='local') {
            try {
                $report=GrowthG8LocalPreDispatchReview::inspect(
                    Db::getInstance(),(string)_PS_VERSION_,
                    $merchant,$namespace,_DB_PREFIX_,$shop
                );
                $status=is_array($report) && isset($report['status'])
                    ?(string)$report['status']:'stage_scope_invalid';
                $safe=[
                    'stage_scope_invalid',
                    'uat_pair_not_bound',
                    'source_binding_unverified',
                    'g3_receipt_unverified',
                    'g3_not_pristine_no_retry',
                    'local_customer_read_unverified',
                    'local_customer_excluded',
                    'local_customer_reader_exception',
                    'g3_candidate_unverified',
                    'g7_lease_unverified',
                    'g7_schema_or_candidate_unverified',
                    'prestashop_customer_unavailable',
                    'native_customer_payload_unverified',
                    'local_pre_dispatch_validated_no_send'
                ];
                if (!in_array($status,$safe,true)) {
                    $status='stage_scope_invalid';
                }
                // Only fixed, PII-free reason codes may appear in the BO.
                $detail='';
                if ($status==='local_customer_excluded') {
                    $allowed=[
                        'local_customer_deleted',
                        'local_customer_inactive',
                        'local_customer_guest',
                        'local_newsletter_negative',
                        'local_optin_negative'
                    ];
                    $reasons=isset($report['local_exclusion_reasons'])
                        ?$report['local_exclusion_reasons']:null;
                    $valid=is_array($reasons) && count($reasons)>0
                        && count($reasons)<=count($allowed);
                    if ($valid) {
                        foreach ($reasons as $reason) {
                            if (!is_string($reason)
                                || !in_array($reason,$allowed,true)) {
                                $valid=false;
                                break;
                            }
                        }
                    }
                    $detail=sprintf(
                        $this->l(' Motivos locales: %s.'),
                        $valid?implode(', ',array_unique($reasons))
                            :'local_customer_reason_unverified'
                    );
                }
                $message=sprintf(
                    $this->l('Diagnóstico local G8 sin enviar: %s.%s Ningún intento nuevo ni modificación G3. Este resultado no autoriza reintentar ni activar campañas.'),
                    $status,$detail
                );
                return $status==='local_customer_excluded'
                    ?$this->displayError($message)
                    :$this->displayConfirmation($message);
            } catch (\Throwable $ignored) {
                return $this->displayError(
                    $this->l('Diagnóstico local G8 inconcluso. No se ha realizado ningún envío ni cambio de G3.')
                );
            }
        }
        if ($operation==='g3') {
            try {
                $report=GrowthG8UatReceiptReview::inspect(
                    Db::getInstance(),(string)_PS_VERSION_,
                    $merchant,$namespace,_DB_PREFIX_,$shop
                );
                if ($report['status']!=='g3_review_complete') {
                    return $this->displayError(
                        $this->l('G3: lectura no concluyente. No reintentes el envío.')
                    );
                }
                // Both labels derive from fixed internal state enums;
                // do not show customer IDs, source keys or SQL.
                return $this->displayConfirmation(
                    sprintf(
                        $this->l('Revisión G3 (solo lectura): cliente = %s; pedido = %s. Ningún dato enviado. No repitas G8 hasta comprobar Data Cloud.'),
                        $report['customer']['state'],
                        $report['order']['state']
                    )
                );
            } catch (\Throwable $ignored) {
                return $this->displayError(
                    $this->l('G3: revisión no concluyente. No reintentes el envío.')
                );
            }
        }
        try {
            // This is a Salesforce READ-ONLY Query V2 SELECT, not ingestion.
            $cloud=GrowthShopCredentialVault::obtainCloudToken($shop);
            if (!is_array($cloud)
                || !isset($cloud['instance_url'],$cloud['access_token'])) {
                return $this->displayError(
                    $this->l('Data Cloud: token no disponible para verificación de lectura. No reintentes el envío.')
                );
            }
            $origin=$cloud['instance_url'];
            $token=$cloud['access_token'];
            $metadata=\Emmakex\PrestaShopSalesforceMarketingCloud\DataCloud\GrowthG8CloudMetadataClient::resolve(
                $origin,$token
            );
            $source=GrowthShopSourceBinding::sourceForSend(
                $shop,$merchant,$namespace
            );
            $descriptor=[
                'shop'=>$shop,'merchant'=>$merchant,
                'namespace'=>$namespace,'source'=>$source,
                'origin'=>$origin,'resolved'=>$metadata
            ];
            $evidence=\Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthG8ScopedDloRead::inspectWithEvidence(
                $descriptor,$origin,$token,$shop,$namespace,
                'PSCustomer',$target['customer_id']
            );
            unset($cloud,$origin,$token,$metadata,$descriptor);
            $presence=is_array($evidence)
                && isset($evidence['result'])?$evidence['result']:'unknown';
            if ($presence==='present') {
                return $this->displayConfirmation(
                    $this->l('Data Cloud (lectura): cliente de UAT presente en el DLO de Salesforce. No se ha enviado nada nuevo. El resultado G3 debe revisarse antes del pedido.')
                );
            }
            if ($presence==='absent_confirmed') {
                return $this->displayError(
                    $this->l('Data Cloud (lectura): cliente todavía no localizado en el DLO. Una respuesta ausente no descarta un envío en tránsito. No reintentes.')
                );
            }
            $reason=is_array($evidence)
                && isset($evidence['reason']) && is_string($evidence['reason'])
                ?$evidence['reason']:'query_evidence_unverified';
            $safe=[
                'scope_unverified','scope_exception',
                'query_evidence_unverified','request_invalid',
                'transport_unverified','response_unverified',
                'json_unverified','batch_unverified',
                'row_not_object','row_projection_unverified',
                'row_value_not_string','row_key_not_exact',
                'transport_exception',
                'http_status_0','http_status_400',
                'http_status_401','http_status_403',
                'http_status_404','http_status_405',
                'http_status_408','http_status_422',
                'http_status_429','http_status_500',
                'http_status_502','http_status_503',
                'http_status_504','http_status_other'
            ];
            if (!in_array($reason,$safe,true)) {
                $reason='query_evidence_unverified';
            }
            return $this->displayError(
                sprintf($this->l('Data Cloud: consulta de presencia inconclusa (%s). No se ha ejecutado ningún POST de ingesta. No reintentes el envío.'),$reason)
            );
        } catch (\Throwable $ignored) {
            return $this->displayError(
                $this->l('Data Cloud: lectura no verificada. No reintentes el envío.')
            );
        }
    }

    /**
     * Operator-controlled exact G8 UAT from Back Office: no cron secret.
     * Preflight is local-only and no-send. Sending is object-by-object,
     * requires deliberate typed confirmation, and uses the SAME worker,
     * CAS, DLO and durable receipts as the protected cron operation.
     *
     * Re-submitting a form can NEVER override the G3 no-replay claim.
     */
    private function handleG8UatOperator($operation)
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        if (!in_array($operation,['preflight','customer','order'],true)
            || !$this->isCredentialAdmin($shop)
            || !is_string($csrf) || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'
            || (string)Configuration::get(
                'PS_GROWTH_G8_STAGING_APPROVAL'
            )!==GrowthG8StagingWorker::APPROVAL
            || (string)Configuration::get(
                'PS_SFMC_CART_CONSENT_MODE'
            )!=='marketing_optin'
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1) {
            return $this->displayError(
                $this->l('UAT G8 bloqueada por configuración, tienda o autorización. No se ha iniciado ningún envío nuevo.')
            );
        }
        $target=GrowthG8UatPairScope::inspect(
            $shop,$merchant,$namespace
        );
        if (!is_array($target)) {
            return $this->displayError(
                $this->l('No hay cliente y pedido fijados para esta UAT G8.')
            );
        }
        // Use the already-certified read-only G8 local diagnostic for the
        // BO preflight too. A separate local reader was a divergent path.
        // This branch returns before the customer/order worker can run.
        if ($operation==='preflight') {
            $preflightPhase='read';
            try {
                $review=GrowthG8LocalPreDispatchReview::inspect(
                    Db::getInstance(),(string)_PS_VERSION_,
                    $merchant,$namespace,_DB_PREFIX_,$shop
                );
                if (!is_array($review)
                    || !isset($review['status'],$review['read_only'],
                        $review['http_request_performed'],
                        $review['database_write_performed'],
                        $review['dispatch_authorized'],
                        $review['retry_authorized'])
                    || $review['status']!=='local_pre_dispatch_validated_no_send'
                    || $review['read_only']!==true
                    || $review['http_request_performed']!==false
                    || $review['database_write_performed']!==false
                    || $review['dispatch_authorized']!==false
                    || $review['retry_authorized']!==false) {
                    return $this->displayError(
                        $this->l('G8: preflight local no validado. Usa Diagnosticar preenvío G8 sin enviar. No se ha realizado POST, ni se autoriza ejecutar cliente o pedido.')
                    );
                }
                $preflightPhase='render';
                return $this->displayConfirmation(
                    $this->l('Preflight local G8 correcto: mismas comprobaciones G3/G7, elegibilidad y borrador que el diagnóstico sin enviar. Ningún POST ni modificación G3. No certifica presencia en Data Cloud ni autoriza campañas.')
                );
            } catch (\Throwable $ignored) {
                if ($preflightPhase==='render') {
                    return $this->displayError(
                        $this->l('G8: error al mostrar la confirmación del preflight local. No se ha ejecutado POST desde esta acción. No ejecutes cliente ni pedido.')
                    );
                }
                return $this->displayError(
                    $this->l('G8: error al leer el diagnóstico local del preflight. No se ha ejecutado POST desde esta acción. Usa Diagnosticar preenvío G8 sin enviar.')
                );
            }
        }

        // Customer execution uses the SAME fresh, no-send, exact-pair G3/G7
        // review as successful preflight. Order retains its own fresh local
        // customer gate because its parent may already have been delivered.
        // Native worker independently rechecks consent, source, lease and
        // DLO immediately before ANY transport. Never rely on a past click.
        $phase='customer_local_review';
        try {
            if ($operation==='customer') {
                $review=GrowthG8LocalPreDispatchReview::inspect(
                    Db::getInstance(),(string)_PS_VERSION_,
                    $merchant,$namespace,_DB_PREFIX_,$shop
                );
                $eligible=is_array($review)
                    && isset($review['status'],$review['read_only'],
                        $review['http_request_performed'],
                        $review['database_write_performed'],
                        $review['dispatch_authorized'],
                        $review['retry_authorized'])
                    && $review['status']==='local_pre_dispatch_validated_no_send'
                    && $review['read_only']===true
                    && $review['http_request_performed']===false
                    && $review['database_write_performed']===false
                    && $review['dispatch_authorized']===false
                    && $review['retry_authorized']===false;
                if (!$eligible) {
                    return $this->displayError(
                        $this->l('G8: preparación de cliente no validada por el diagnóstico local G3/G7. No se ha iniciado POST; conserva los recibos y no repitas un envío incierto.')
                    );
                }
            } else {
                $phase='order_local_reader';
                $local=GrowthLocalCustomerHoldReader::inspectPrestaShop(
                    $shop,$target['customer_id']
                );
                $eligible=is_array($local)
                    && isset($local['status'],
                        $local['local_customer_source_state'])
                    && $local['status']==='hold_for_review'
                    && $local['local_customer_source_state']
                        ==='no_local_negative_observed';
                if (!$eligible) {
                    return $this->displayError(
                        $this->l('G8: elegibilidad local de pedido no superada. No se ha iniciado POST; comprueba el cliente sin cambiar consentimientos.')
                    );
                }
            }
            $phase='confirmation';
            $confirm=Tools::getValue('PS_GROWTH_G8_RUN_CONFIRM');
            $expected=$operation==='customer'
                ?'EXECUTE_G8_CUSTOMER_ONCE':'EXECUTE_G8_ORDER_ONCE';
            if (!is_string($confirm) || $confirm!==$expected) {
                return $this->displayError(
                    $this->l('Confirmación exacta de ejecución G8 incorrecta. No se ha enviado nada.')
                );
            }
            // Do not reuse the one-record manual send path. Ingest only
            // the selected object via the protected native G8 worker.
            // Once this call is entered, a POST might have started. From
            // here onward no exception may be called a pre-send failure.
            $phase='worker_invoked';
            $outcome=GrowthG8NativeStagingServices::run(
                Db::getInstance(),(string)_PS_VERSION_,_DB_PREFIX_,
                $shop,$operation==='customer'?'PSCustomer':'PSOrder'
            );
            $phase='receipt_reporting';
            $count=is_array($outcome) && isset($outcome['counts'])
                && is_array($outcome['counts'])?$outcome['counts']:null;
            if ($count!==null
                && isset($count['accepted_202_unconfirmed'])
                && (int)$count['accepted_202_unconfirmed']===1) {
                return $this->displayConfirmation(
                    $operation==='customer'
                    ?$this->l('G8 cliente: HTTP 202 recibido y registrado como pendiente de conciliación. Comprueba el cliente en Data Cloud antes de autorizar el pedido. No repitas la ejecución.')
                    :$this->l('G8 pedido: HTTP 202 recibido y registrado como pendiente de conciliación. Verifica el pedido en Data Cloud y no repitas la ejecución.')
                );
            }
            if ($count!==null && isset($count['reserved'])
                && (int)$count['reserved']===0
                && isset($count['pending_reconciliation'])
                && (int)$count['pending_reconciliation']===0) {
                return $this->displayError(
                    $this->l('G8 no ha reservado ningún evento nuevo. Revisa elegibilidad, presencia DLO y estado de G3; no repitas dispatch automáticamente.')
                );
            }
            return $this->displayError(
                $this->l('G8: resultado sin confirmación de recepción. Puede existir un intento en G3; no repitas la operación. Comprueba el recibo y Data Cloud.')
            );
        } catch (\Throwable $ignored) {
            if ($phase==='customer_local_review') {
                return $this->displayError(
                    $this->l('G8: excepción en revisión local G3/G7 de cliente, antes del transporte. Sin POST en esta operación; usa Diagnosticar preenvío G8 sin enviar.')
                );
            }
            if ($phase==='order_local_reader') {
                return $this->displayError(
                    $this->l('G8: excepción en lectura local del cliente para pedido, antes del transporte. Sin POST en esta operación; no envíes el pedido.')
                );
            }
            if ($phase==='confirmation') {
                return $this->displayError(
                    $this->l('G8: excepción leyendo o mostrando la confirmación, antes del transporte. Sin POST en esta operación. Mantén G3 intacto.')
                );
            }
            // The worker may have reached the transport even if this UI
            // failed to render. Never infer non-delivery from this error.
            return $this->displayError(
                $this->l('G8: resultado de la ejecución no confirmado. Puede haber existido un POST. No reintentes; revisa recibos G3 y presencia exacta en Data Cloud.')
            );
        }
    }

    /**
     * Bind only the two exact, current, never-attempted UAT events to the
     * staging worker. No Salesforce request and no G3 mutation here.
     */
    private function handleG8UatTarget($operation)
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        if (!in_array($operation,['bind','revoke'],true)
            || !$this->isCredentialAdmin($shop)
            || !is_string($csrf)
            || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1) {
            return $this->displayError(
                $this->l('UAT exacta no autorizada para esta tienda. No se han enviado registros.')
            );
        }
        if ($operation==='revoke') {
            if (!GrowthG8UatPairScope::clear(
                $shop,$merchant,$namespace
            ) || !Configuration::updateValue(
                'PS_GROWTH_G8_STAGING_APPROVAL',
                'off',false,null,$shop
            )) {
                return $this->displayError(
                    $this->l('No se ha podido retirar la selección G8. Comprueba la autorización y desactiva la UAT.')
                );
            }
            return $this->displayConfirmation(
                $this->l('Selección G8 retirada y autorización de staging desactivada. No se han enviado registros.')
            );
        }
        if ((string)Configuration::get(
                'PS_GROWTH_G8_STAGING_APPROVAL'
            )===GrowthG8StagingWorker::APPROVAL) {
            return $this->displayError(
                $this->l('Desactiva G8 antes de cambiar la selección de UAT.')
            );
        }
        $rawCustomer=Tools::getValue('PS_GROWTH_ONE_CUSTOMER_ID');
        $rawOrder=Tools::getValue('PS_GROWTH_ONE_ORDER_ID');
        if (!is_scalar($rawCustomer) || !is_scalar($rawOrder)
            || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$rawCustomer)
            || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$rawOrder)) {
            return $this->displayError(
                $this->l('Introduce los IDs numéricos del cliente y pedido de pruebas.')
            );
        }
        $customerId=(int)$rawCustomer;
        $orderId=(int)$rawOrder;
        try {
            $customer=new Customer($customerId);
            $order=new Order($orderId);
            if ((int)$customer->id!==$customerId
                || (int)$customer->id_shop!==$shop
                || (int)$order->id!==$orderId
                || (int)$order->id_shop!==$shop
                || (int)$order->id_customer!==$customerId) {
                throw new \RuntimeException('g8_target_link_invalid');
            }
            $proof=GrowthG8ExactCaptureInspector::inspect(
                Db::getInstance(),(string)_PS_VERSION_,
                $merchant,$namespace,_DB_PREFIX_,$shop,
                $customerId,$orderId
            );
            if ($proof['status']!=='both_events_captured'
                || !GrowthG8UatPairScope::bind(
                    $shop,$merchant,$namespace,$customerId,$orderId
                )) {
                return $this->displayError(
                    $this->l('No se puede fijar G8: verifica la captura exacta de ambos eventos, sin intentos ni selecciones anteriores. No se ha enviado nada.')
                );
            }
            return $this->displayConfirmation(
                $this->l('UAT G8 vinculada únicamente a este cliente y pedido de pruebas. Ningún evento histórico podrá entrar en dispatch. No se ha enviado nada.')
            );
        } catch (\Throwable $ignored) {
            return $this->displayError(
                $this->l('Selección G8 no verificada. No se ha enviado nada a Salesforce.')
            );
        }
    }

    /**
     * Admin-only exact G3 verification for the selected UAT customer/order.
     * Works without phpMyAdmin or cron secrets. Does not send or promote.
     */
    private function handleG8ExactCaptureInspect()
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        $customerRaw=Tools::getValue('PS_GROWTH_ONE_CUSTOMER_ID');
        $orderRaw=Tools::getValue('PS_GROWTH_ONE_ORDER_ID');
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        if (!$this->isCredentialAdmin($shop)
            || !is_string($csrf) || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)
            || !is_scalar($customerRaw) || !is_scalar($orderRaw)
            || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$customerRaw)
            || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$orderRaw)
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off') {
            return $this->displayError(
                $this->l('Comprobación G3 bloqueada: autorización o tienda de staging no válidas. No se ha enviado nada.')
            );
        }
        $customerId=(int)$customerRaw;
        $orderId=(int)$orderRaw;
        try {
            $order=new Order($orderId);
            $customer=new Customer($customerId);
            if ((int)$order->id_customer!==$customerId
                || (int)$order->id_shop!==$shop
                || (int)$customer->id_shop!==$shop
                || (int)$customer->id!==$customerId
                || (int)$order->id!==$orderId) {
                return $this->displayError(
                    $this->l('El cliente y el pedido no pertenecen a esta tienda o no coinciden. No se ha enviado nada.')
                );
            }
            $result=GrowthG8ExactCaptureInspector::inspect(
                Db::getInstance(),
                (string)_PS_VERSION_,$merchant,$namespace,
                _DB_PREFIX_,$shop,$customerId,$orderId
            );
            if ($result['status']==='both_events_captured') {
                return $this->displayConfirmation(
                    $this->l('Captura G3 confirmada: cliente y pedido presentes como eventos vigentes y bloqueados, sin intentos. No se ha enviado nada a Salesforce.')
                );
            }
            if ($result['status']==='capture_not_confirmed') {
                return $this->displayError(
                    $this->l('Captura G3 todavía no confirmada para ambos registros. No autorices dispatch ni repitas la compra. Revisaremos cuál de los dos eventos falta.')
                );
            }
            return $this->displayError(
                $this->l('No ha sido posible verificar G3. Captura detenida; ningún registro enviado.')
            );
        } catch (\Throwable $ignored) {
            return $this->displayError(
                $this->l('No ha sido posible leer los eventos G3. No se ha enviado nada a Salesforce.')
            );
        }
    }

    /**
     * Prepare the SAME G3/G7 tables and arm local capture for the UAT.
     * Staging only. Explicit verified backup, owner, CSRF, Test, capture OFF,
     * immutable shop/source and exact confirmation. No outbound request.
     * After generating a new synthetic customer/order, operator turns
     * capture OFF and separately authorizes G8 dispatch.
     */
    private function handleG8StageCapturePrepare()
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        $confirm=Tools::getValue('PS_GROWTH_G8_CAPTURE_CONFIRM');
        $backup=Tools::getValue('PS_GROWTH_G8_BACKUP_CONFIRM');
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        if (!$this->isCredentialAdmin($shop)
            || !is_string($csrf)
            || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)
            || $confirm!==GrowthStagingSchemaBootstrap::CONFIRM
            || $backup!=='I_HAVE_A_VERIFIED_STAGING_BACKUP'
            || !GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )!==1
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'
            || GrowthShopSourceBinding::inspect(
                $shop,$merchant,$namespace
            )['ready']!==true
            || (string)Configuration::get('PS_GROWTH_G8_STAGING_APPROVAL')
                ===GrowthG8StagingWorker::APPROVAL) {
            return $this->displayError(
                $this->l('Preparación de staging bloqueada: selecciona la tienda, confirma el respaldo y la autorización exacta, y mantén Test, captura OFF y G8 sin activar.')
            );
        }
        try {
            $db=Db::getInstance();
            GrowthStagingSchemaBootstrap::install(
                $db,(string)_PS_VERSION_,$merchant,$namespace,
                _DB_PREFIX_,GrowthStagingSchemaBootstrap::CONFIRM
            );
            GrowthG7StagingDeliveryMigration::apply(
                $db,(string)_PS_VERSION_,$merchant,$namespace,_DB_PREFIX_,
                GrowthG7StagingDeliveryMigration::APPROVE,true
            );
            $hmac=Configuration::get(self::CONFIG_CAPTURE_HMAC);
            if (!is_string($hmac)
                || preg_match('/^[a-f0-9]{64}$/D',$hmac)!==1) {
                $hmac=bin2hex(random_bytes(32));
            }
            if (!Configuration::updateValue(
                self::CONFIG_CAPTURE_HMAC,$hmac
            ) || !Configuration::updateValue(
                self::CONFIG_LOCAL_CAPTURE,'staging_armed'
            )) {
                Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE,'off');
                throw new \RuntimeException('g8_capture_not_persisted');
            }
            return $this->displayConfirmation(
                $this->l('G3 y G7 verificados. Captura local activada únicamente en staging. Crea un cliente y pedido sintéticos nuevos; después pulsa Detener captura local antes de ejecutar G8. No se ha enviado nada a Salesforce.')
            );
        } catch (\Throwable $ignored) {
            // Partial DDL in MySQL is not automatically reversible. Never
            // hide this possibility or retry a schema mutation by itself.
            Configuration::updateValue(self::CONFIG_LOCAL_CAPTURE,'off');
            return $this->displayError(
                $this->l('Preparación de staging bloqueada. Captura OFF; si hubo un cambio parcial de esquema, revisa la estructura de G3/G7 antes de volver a intentarlo. No se ha enviado nada.')
            );
        }
    }

    /**
     * Explicit opt-in for a SINGLE staging shop, revocable at any time.
     * Never an approval for production ingestion or Growth campaigns.
     */
    private function handleG8StagingUat($operation)
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        if (!in_array($operation,['approve','revoke'],true)
            || !$this->isCredentialAdmin($shop)
            || !is_string($csrf)
            || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)) {
            return $this->displayError(
                $this->l('Autorización de staging no válida. No se ha cambiado nada.')
            );
        }
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
        if ($operation==='approve') {
            if (!GrowthShopSourceBinding::isNamespace($namespace)
                || preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )!==1
                || (string)Configuration::get(self::CONFIG_MODE)!=='test'
                || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'
                || GrowthG8UatPairScope::inspect(
                    $shop,$merchant,$namespace
                )===null
                || (string)Configuration::get(
                    'PS_SFMC_CART_CONSENT_MODE'
                )!=='marketing_optin'
                || GrowthShopSourceBinding::inspect(
                    $shop,$merchant,$namespace
                )['ready']!==true
                || GrowthShopCredentialVault::inspect($shop)['ready']!==true
                || Tools::getValue('PS_GROWTH_G8_CONFIRM')
                    !==GrowthG8StagingWorker::APPROVAL) {
                return $this->displayError(
                    $this->l('La autorización G8 requiere una tienda de staging, modo Test, conexión válida y confirmación exacta. No se han activado envíos.')
                );
            }
        }
        $value=$operation==='approve'
            ?GrowthG8StagingWorker::APPROVAL:'off';
        if (!Configuration::updateValue(
            'PS_GROWTH_G8_STAGING_APPROVAL',
            $value,false,null,$shop
        )) {
            return $this->displayError(
                $this->l('No se ha podido guardar la autorización G8.')
            );
        }
        return $this->displayConfirmation($operation==='approve'
            ?$this->l('UAT G8 de esta tienda de staging autorizada. Solo se ejecuta bajo cron protegido; no se han enviado registros al guardar.')
            :$this->l('Autorización G8 de staging retirada. No se iniciarán nuevos envíos.'));
    }

    /**
     * Only the primary Back Office administrator may change or test OAuth
     * secrets. A module-editor account alone is never sufficient.
     * Multi-shop "All shops" context is not a valid credential owner.
     */
    /**
     * A single-shop install may use CONTEXT_ALL internally. That is safe
     * only when multistore is disabled; true multistore requires an exact
     * selected shop and never accepts all/group context.
     */
    private function credentialContextShopId()
    {
        if (!class_exists('Shop') || !method_exists('Shop','isFeatureActive')) {
            return 0;
        }
        $single=!Shop::isFeatureActive();
        $shop=(int)Shop::getContextShopID();
        if ($shop>0
            && ($single || Shop::getContext()===Shop::CONTEXT_SHOP)) {
            return $shop;
        }
        return $single?(int)Configuration::get('PS_SHOP_DEFAULT'):0;
    }

    private function isCredentialAdmin($shop)
    {
        $employee=isset($this->context,$this->context->employee)
            ?$this->context->employee:null;
        return is_object($employee)
            && isset($employee->id,$employee->id_profile)
            && (int)$employee->id>0
            && (int)$employee->id_profile===1
            && is_int($shop) && $shop>0
            && $shop===$this->credentialContextShopId();
    }

    private function credentialCsrf($shop)
    {
        // The action URL still carries PrestaShop's AdminModules token.
        // The module's additional CSRF must survive a route/token refresh
        // between GET and POST, without making credentials public.
        $employee=isset($this->context,$this->context->employee)
            ?$this->context->employee:null;
        if (!$this->isCredentialAdmin($shop) || !is_object($employee)
            || !defined('_COOKIE_KEY_')) {
            return '';
        }
        return GrowthStableBoCsrf::issue(
            (string)_COOKIE_KEY_,(int)$shop,(int)$employee->id
        );
    }

    /**
     * Explicit one-shop secret management. Tokens, input and exceptions
     * never reach the browser or a logger; only allowlisted labels do.
     */
    private function handleSalesforceCredentials($operation)
    {
        $shop=$this->credentialContextShopId();
        if (!in_array($operation,['save','test','remove'],true)
            || !$this->isCredentialAdmin($shop)) {
            return $this->displayError(
                $this->l('Solo un administrador principal puede gestionar la conexión de una tienda concreta.')
            );
        }
        $csrf=Tools::getValue('PS_GROWTH_OAUTH_CSRF');
        if (!is_string($csrf) || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)) {
            return $this->displayError(
                $this->l('La autorización del formulario ha caducado. Vuelve a abrir la configuración.')
            );
        }
        try {
            if ($operation==='save') {
                $url=Tools::getValue('PS_GROWTH_OAUTH_ORG');
                $id=Tools::getValue('PS_GROWTH_OAUTH_CLIENT_ID');
                $secret=Tools::getValue('PS_GROWTH_OAUTH_CLIENT_SECRET');
                GrowthShopCredentialVault::save($shop,$url,$id,$secret);
                return $this->displayConfirmation(
                    $this->l('Conexión cifrada guardada para esta tienda. Ahora pulsa Probar conexión para verificar OAuth.')
                );
            }
            if ($operation==='remove') {
                GrowthShopCredentialVault::remove(
                    $shop,Tools::getValue('PS_GROWTH_OAUTH_DELETE_CONFIRM')
                );
                return $this->displayConfirmation(
                    $this->l('Conexión revocada únicamente en esta tienda. No se ha enviado ningún registro.')
                );
            }
            GrowthShopCredentialVault::testConnection($shop);
            return $this->displayConfirmation(
                $this->l('Conexión correcta: OAuth Core y token de Data Cloud comprobados. Sin envíos de clientes ni campañas.')
            );
        } catch (\Throwable $e) {
            // Strict, human-actionable allowlist. Never show exception text.
            $code=$e->getMessage();
            $known=[
                'growth_vault_missing'=> $this->l('No hay credenciales guardadas en esta tienda.'),
                'growth_vault_input_invalid'=> $this->l('Revisa los tres campos. El Client ID y el secreto no pueden estar vacíos.'),
                'growth_oauth_invalid_org_url'=> $this->l('La URL debe ser HTTPS y pertenecer al dominio Salesforce My Domain de tu organización.'),
                'growth_vault_server_key_unavailable'=> $this->l('La clave privada del servidor PrestaShop no está disponible. No se ha guardado nada.'),
                'growth_vault_crypto_unavailable'=> $this->l('El servidor necesita OpenSSL para cifrar la conexión.'),
                'growth_vault_core_auth_failed'=> $this->l('Salesforce Core no ha aceptado el inicio de sesión OAuth. Comprueba la Connected App y sus permisos.'),
                'growth_vault_cloud_exchange_failed'=> $this->l('Salesforce Core respondió, pero no se pudo obtener el token de Data Cloud. Revisa permisos de la integración.'),
                'growth_vault_delete_confirmation_required'=> $this->l('Para revocar, escribe exactamente la frase de confirmación indicada.'),
            ];
            return $this->displayError(
                isset($known[$code])?$known[$code]
                    :$this->l('No se pudo completar la operación de conexión. Comprueba el servidor y la configuración; ningún dato se ha enviado.')
            );
        }
    }

    /**
     * Explicit super-admin secret hand-off for staging only. This is the
     * current cron token, not a new token or an authorization to dispatch.
     * POST + existing AdminModules token + stable CSRF; no GET exposure.
     * Never log, serialize to Smarty, add to URL, or send to Salesforce.
     */
    private function handleG8CronTokenReveal()
    {
        $shop=$this->credentialContextShopId();
        $csrf=Tools::getValue('PS_GROWTH_G8_CSRF');
        $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
        if (!$this->isCredentialAdmin($shop)
            || !is_string($csrf) || $csrf===''
            || $this->credentialCsrf($shop)===''
            || !hash_equals($this->credentialCsrf($shop),$csrf)
            || !class_exists('Shop') || Shop::isFeatureActive()
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off'
            || preg_match('/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace)!==1
            || (string)Configuration::get('PS_GROWTH_G8_STAGING_APPROVAL')!=='off') {
            return $this->displayError(
                $this->l('Acceso al token de cron bloqueado. Requiere superadministrador, staging de una sola tienda, modo Test, captura OFF y UAT G8 desactivada.')
            );
        }
        $token=Configuration::get(self::CONFIG_CRON_TOKEN);
        if (!is_string($token)
            || preg_match('/^[a-f0-9]{48}$/D',$token)!==1) {
            return $this->displayError(
                $this->l('El token de cron no esta disponible o tiene formato inesperado. No se ha modificado.')
            );
        }
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
        // Masked/read-only input is rendered ONLY in this privileged POST
        // response, outside Smarty; refreshing the BO no longer shows it.
        $html='<div class="sfmc-cron-secret" role="group">'
            .'<label for="sfmc-cron-secret-value">'
            .$this->l('Token de cron de staging: copia el valor en el secreto G8_STAGING_CRON_TOKEN de GitHub Actions. No lo pegues en el chat.')
            .'</label>'
            .'<input id="sfmc-cron-secret-value" type="password" readonly '
            .'autocomplete="off" spellcheck="false" '
            .'value="'.$this->escape($token).'" '
            .'style="display:block;width:100%;max-width:460px;margin-top:8px" />'
            .'<p>'.$this->l('Selecciona el campo y copia con Ctrl/Cmd+A, Ctrl/Cmd+C. Al recargar desaparece; este acceso no ejecuta ni autoriza ningun envio.').'</p>'
            .'</div>';
        unset($token);
        return $this->displayInformation($html);
    }

    /**
     * Stage-only schema administration. No output includes identifiers,
     * field values, SQL strings or throwable messages. There is NO sender.
     * Upgrades never invoke this action automatically.
     */
    private function reviewG7DeliverySchema($action)
    {
        if (!in_array($action,['inspect','apply','rollback'],true)) {
            return $this->displayError($this->l('G7 schema action unavailable.'));
        }
        try {
            $db=Db::getInstance();
            $args=[
                $db,(string)_PS_VERSION_,
                (string)Configuration::get(self::CONFIG_MERCHANT),
                (string)Configuration::get(self::CONFIG_NAMESPACE),_DB_PREFIX_
            ];
            if ($action==='inspect') {
                $report=call_user_func_array(
                    [GrowthG7StagingDeliveryMigration::class,'inspect'],$args
                );
            } else {
                $isApply=$action==='apply';
                $confirm=Tools::getValue(
                    $isApply?'PS_G7_SCHEMA_CONFIRM':'PS_G7_ROLLBACK_CONFIRM'
                );
                $backup=Tools::getValue(
                    $isApply?'PS_G7_BACKUP_CONFIRM':'PS_G7_ROLLBACK_BACKUP'
                );
                $args[]=$confirm;
                $args[]=$backup==='I_HAVE_A_VERIFIED_STAGING_BACKUP';
                $report=call_user_func_array([
                    GrowthG7StagingDeliveryMigration::class,
                    $isApply?'apply':'rollback'
                ],$args);
            }
            $status=(string)$report['status'];
            if (!in_array($status,[
                'original_g3_verified','extended_g7_verified',
                'already_extended_verified','extended_blocked_no_sends',
                'already_original_verified','rolled_back_no_sends'
            ],true)) {
                return $this->displayError(
                    $this->l('G7 schema inspection returned an unverified status.')
                );
            }
            $message=$this->l('G7 staging outbox: ')
                . $this->escape($status)
                . '. ' . $this->l('Only local blocked records. No Salesforce requests or campaign sends.');
            return $this->displayConfirmation($message);
        } catch (\Throwable $e) {
            // A SQL/DDL exception might contain private identifiers or full
            // SQL. Never show its raw text even with debug mode enabled.
            return $this->displayError(
                $this->l('G7 schema action blocked. Confirm Test, local capture OFF, exact approval, verified external backup and original G3 schema. If an ALTER failed, inspect staging SQL metadata before retrying. No sends.')
            );
        }
    }

    private function renderG7DeliverySchemaForms()
    {
        $result='';
        foreach (['inspect','apply','rollback'] as $type) {
            $helper=new HelperForm();
            $helper->module=$this;
            $helper->name_controller=$this->name;
            $helper->token=Tools::getAdminTokenLite('AdminModules');
            $helper->currentIndex=AdminController::$currentIndex
                .'&configure='.$this->name;
            $helper->submit_action= $type==='inspect'?'submitPsG7SchemaInspect':
                ($type==='apply'?'submitPsG7SchemaPrepare':'submitPsG7SchemaRollback');
            $helper->default_form_language=(int)Configuration::get('PS_LANG_DEFAULT');
            $helper->allow_employee_form_lang=(int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
            $inputs=[];
            if ($type!=='inspect') {
                $name=$type==='apply'?'PS_G7_SCHEMA_CONFIRM':'PS_G7_ROLLBACK_CONFIRM';
                $backup=$type==='apply'?'PS_G7_BACKUP_CONFIRM':'PS_G7_ROLLBACK_BACKUP';
                $inputs=[
                    ['type'=>'text','name'=>$name,
                        'label'=>$this->l('Exact staging-only approval phrase'),
                        'required'=>true,
                        'desc'=>$type==='apply'
                            ?GrowthG7StagingDeliveryMigration::APPROVE
                            :GrowthG7StagingDeliveryMigration::UNDO],
                    ['type'=>'text','name'=>$backup,
                        'label'=>$this->l('External backup verification'),
                        'required'=>true,
                        'desc'=>$this->l('Type I_HAVE_A_VERIFIED_STAGING_BACKUP. This module does NOT create or verify a backup.')]
                ];
                $helper->fields_value=[$name=>'', $backup=>''];
            }
            $label=$type==='inspect'?'Inspect G3/G7 outbox (read only)':
                ($type==='apply'?'Extend G3 outbox on staging (no sends)':
                    'Rollback G7 columns on staging (only if unused)');
            $result.=$helper->generateForm([['form'=>[
                'legend'=>[
                    'title'=>$this->l('G7 - Same outbox delivery schema (Test only)'),
                    'icon'=>'icon-lock'
                ],
                'description'=>$this->l('Stage only. First STOP local capture. No table is added; only five optional columns in the current G3 outbox. All events and sends stay blocked. Neither install nor upgrade changes the SQL schema.'),
                'input'=>$inputs,
                'submit'=>['title'=>$this->l($label)]
            ]]]);
        }
        return $result;
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

    /** Escape the strictly allowlisted G7 status message for Back Office HTML.
     * The previous 0.8.6 call site used an undefined $this->escape() method.
     * That Error was caught by the outer handler as setup_unavailable, hiding
     * both per-entity results even when they had actually been computed.
     */
    private function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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


    /**
     * Owner-operated Test UAT. One customer + its exact order, one explicit
     * Data Cloud upsert per object. NO campaign send, cron or batch.
     * All sources/JSON/credentials remain private to the PHP request.
     */
    private function runManualOneRecord($action)
    {
        if (!in_array($action,['preview','customer','order'],true)
            || (string)Configuration::get(self::CONFIG_MODE)!=='test'
            || (string)Configuration::get(self::CONFIG_LOCAL_CAPTURE)!=='off') {
            return $this->displayError(
                $this->l('La prueba individual requiere modo Test y captura local desactivada. No se ha enviado ningún registro.')
            );
        }
        $rawCustomer=Tools::getValue('PS_GROWTH_ONE_CUSTOMER_ID');
        $rawOrder=Tools::getValue('PS_GROWTH_ONE_ORDER_ID');
        if (!is_scalar($rawCustomer) || !is_scalar($rawOrder)
            || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$rawCustomer)
            || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$rawOrder)) {
            return $this->displayError(
                $this->l('Selecciona un cliente y un pedido válido del mismo cliente. No se ha enviado ningún registro.')
            );
        }
        try {
            // A specific shop context is mandatory; All Shops is not safe.
            $shop=$this->credentialContextShopId();
            if ($shop<1) {
                throw new \RuntimeException('g7_one_record_invalid_shop_context');
            }
            $customerId=(int)$rawCustomer;
            $orderId=(int)$rawOrder;
            $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
            $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
            $prepare=function()use($namespace,$shop,$customerId,$orderId) {
                return GrowthOneRecordSelection::prepareInternal(
                    $namespace,$shop,$customerId,$orderId,
                    function($id){return new \Customer($id);},
                    function($id){return new \Order($id);},
                    function($id){return new \Currency($id);}
                );
            };
            if ($action==='preview') {
                $private=$prepare();
                $report=GrowthOneRecordSelection::summarize($private);
                unset($private);
                return $this->displayConfirmation(
                    $this->l('Cliente y pedido validados. Los datos y su vinculación son correctos para la prueba. No se ha enviado nada a Salesforce.')
                );
            }
            if (!$this->isCredentialAdmin($shop)
                || !GrowthShopCredentialVault::inspect($shop)['ready']) {
                return $this->displayError(
                    $this->l('Configura primero la conexión Salesforce desde un administrador autorizado. No se ha enviado ningún registro.')
                );
            }
            $getToken=function()use($shop) {
                return GrowthShopCredentialVault::obtainCloudToken($shop);
            };
            $report=GrowthOneRecordManualSender::dispatch(
                \Db::getInstance(),$namespace,$merchant,$shop,
                $action==='customer'?'PSCustomer':'PSOrder',
                Tools::getValue('PS_GROWTH_ONE_APPROVAL'),
                Tools::getValue('PS_GROWTH_DLO_CUSTOMER_CONFIRM'),
                $prepare,$getToken
            );
            $status=(string)$report['status'];
            if (!in_array($status,[
                'one_record_accepted_unconfirmed_manual_dlo_check',
                'one_record_unverified_manual_reconciliation_required'
            ],true)) {
                throw new \RuntimeException('g7_one_send_result_unverified');
            }
            return $this->displayInformation(
                $this->l('Resultado del envío individual: ')
                .$this->escape($status)
                .$this->l('. Comprueba el registro en Salesforce Data Cloud antes de continuar. No se realizará otro intento ni se enviarán campañas.')
            );
        } catch (\Throwable $e) {
            // Never expose raw errors or source records. Local preview does
            // not send; actual sends remain one-shot and must be reconciled.
            return $this->displayError(
                $this->safeOneRecordFailureMessage(
                    GrowthOneRecordSafeDiagnosis::classify($e,$action),
                    $action
                )
            );
        }
    }

    /**
     * All strings are static and translatable. No private exception text,
     * email, order details, Data Cloud response or OAuth token leaks to BO.
     */
    private function safeOneRecordFailureMessage($diagnostic,$action)
    {
        if ($action==='preview') {
            switch ($diagnostic) {
                case 'shop_context':
                    return $this->l('Validación local: selecciona una tienda concreta en PrestaShop. No se ha enviado nada.');
                case 'invalid_selection':
                    return $this->l('Validación local: los identificadores o la tienda no son válidos. No se ha enviado nada.');
                case 'customer_shop':
                    return $this->l('Validación local: el cliente no existe en la tienda seleccionada o no pertenece a ella. No se ha enviado nada.');
                case 'order_shop':
                    return $this->l('Validación local: el pedido no existe en la tienda seleccionada o no pertenece a ella. No se ha enviado nada.');
                case 'customer_order_relation':
                    return $this->l('Validación local: el pedido no pertenece al cliente seleccionado, o sus referencias de tienda no coinciden. No se ha enviado nada.');
                case 'currency':
                    return $this->l('Validación local: no se puede comprobar la moneda asociada al pedido. No se ha enviado nada.');
                case 'customer_fields':
                    return $this->l('Validación local: faltan datos obligatorios o indicadores válidos del cliente. No se ha enviado nada.');
                case 'order_fields':
                    return $this->l('Validación local: faltan datos obligatorios o válidos del pedido. No se ha enviado nada.');
                case 'customer_excluded':
                    return $this->l('Validación local: el cliente es invitado o está eliminado; no puede utilizarse en esta prueba. No se ha enviado nada.');
                case 'email':
                    return $this->l('Validación local: el correo asociado al cliente no supera la validación de formato. No se ha enviado nada.');
                case 'local_date':
                    return $this->l('Validación local: alguna fecha de origen del cliente o pedido no tiene un formato válido. No se ha enviado nada.');
                case 'order_total':
                    return $this->l('Validación local: el importe del pedido no se puede interpretar de forma segura. No se ha enviado nada.');
                default:
                    return $this->l('Validación local bloqueada: los datos nativos no cumplen el esquema PSCustomer/PSOrder. No se ha enviado nada a Salesforce. No es un error OAuth.');
            }
        }
        switch ($diagnostic) {
            case 'send_attempt_exists':
                return $this->l('Envío bloqueado: ya existe un intento registrado para este objeto y tienda. Comprueba primero Data Cloud; no lo repitas.');
            case 'send_approval':
                return $this->l('Envío bloqueado: falta la autorización individual exacta. No se ha iniciado esta solicitud.');
            case 'send_gate':
                return $this->l('Envío bloqueado: cambió la identidad, el modo Test o la captura local. Revisa configuración antes de continuar.');
            case 'send_source':
                return $this->l('Envío bloqueado: el origen Data Cloud de esta tienda no está vinculado o ha cambiado. No repitas sin revisarlo.');
            case 'send_dlo_required':
                return $this->l('Pedido bloqueado: confirma primero en Data Cloud la recepción del cliente exacto. No se debe enviar el pedido todavía.');
            case 'send_order_customer':
                return $this->l('Pedido bloqueado: no coincide con el cliente del intento previamente aceptado. No se debe enviar el pedido.');
            case 'send_locked':
                return $this->l('Envío bloqueado: existe otra operación en curso. Revisa el estado de Data Cloud antes de tomar otra acción.');
            case 'send_oauth':
                return $this->l('Envío bloqueado: no se ha podido obtener el token OAuth. Usa Probar conexión y revisa Salesforce.');
            case 'send_local':
                return $this->l('Envío bloqueado: el cliente o pedido no supera la validación local actual. No se debe forzar el envío.');
            default:
                return $this->l('Envío sin resultado verificable. Comprueba la recepción en Salesforce y el estado local antes de cualquier otro intento. No se permiten reintentos automáticos.');
        }
    }

    private function renderManualOneRecordForms()
    {
        $result='';
        foreach (['preview','customer','order'] as $action) {
            $helper=new HelperForm();
            $helper->module=$this;
            $helper->name_controller=$this->name;
            $helper->token=Tools::getAdminTokenLite('AdminModules');
            $helper->currentIndex=AdminController::$currentIndex
                .'&configure='.$this->name;
            $helper->submit_action=$action==='preview'
                ?'submitPsGrowthOnePreview'
                :($action==='customer'
                    ?'submitPsGrowthOneCustomerSend'
                    :'submitPsGrowthOneOrderSend');
            $helper->default_form_language=(int)Configuration::get('PS_LANG_DEFAULT');
            $helper->allow_employee_form_lang=(int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
            $inputs=[
                ['type'=>'text','name'=>'PS_GROWTH_ONE_CUSTOMER_ID',
                    'label'=>$this->l('Customer ID (same shop)'), 'required'=>true],
                ['type'=>'text','name'=>'PS_GROWTH_ONE_ORDER_ID',
                    'label'=>$this->l('Linked order ID (same customer)'), 'required'=>true]
            ];
            if ($action!=='preview') {
                $inputs[]=[
                    'type'=>'text','name'=>'PS_GROWTH_ONE_APPROVAL',
                    'label'=>$this->l('One-record personal data ingestion approval'),
                    'required'=>true,
                    'desc'=>GrowthOneRecordManualSender::APPROVAL
                ];
            }
            if ($action==='order') {
                $inputs[]=[
                    'type'=>'text','name'=>'PS_GROWTH_DLO_CUSTOMER_CONFIRM',
                    'label'=>$this->l('Customer DLO verified in Salesforce'),
                    'required'=>true,
                    'desc'=>GrowthOneRecordManualSender::CUSTOMER_DLO_ATTESTATION
                ];
            }
            $helper->fields_value=[
                'PS_GROWTH_ONE_CUSTOMER_ID'=>'',
                'PS_GROWTH_ONE_ORDER_ID'=>'',
                'PS_GROWTH_ONE_APPROVAL'=>'',
                'PS_GROWTH_DLO_CUSTOMER_CONFIRM'=>''
            ];
            $label=$action==='preview'
                ?$this->l('1. Validate one customer and its order (no sends)')
                :($action==='customer'
                    ?$this->l('2. Send exactly one selected customer to Data Cloud')
                    :$this->l('3. Send exactly one linked order after customer DLO verification'));
            $result.=$helper->generateForm([['form'=>[
                'legend'=>['title'=>$this->l('G7 - Controlled one-record Data Cloud UAT'),
                    'icon'=>'icon-lock'],
                'description'=>$this->l('Pilot Test only. Manual intervention. Each send is an irreversible single attempt, even on timeout; no retry, no customer marketing or batch. Credentials must exist server-side, not in this panel. Check DLO/DMO after every HTTP 202.'),
                'input'=>$inputs,
                'submit'=>['title'=>$label]
            ]]]);
        }
        return $result;
    }

    private function saveGrowthSettings()
    {
        $shop=$this->credentialContextShopId();
        if (!$this->isCredentialAdmin($shop)) {
            return $this->displayError(
                $this->l('Selecciona una tienda concreta y utiliza una cuenta administradora autorizada.')
            );
        }
        $merchant=Tools::getValue(self::CONFIG_MERCHANT);
        $namespace=Tools::getValue(self::CONFIG_NAMESPACE);
        $source=Tools::getValue('PS_GROWTH_SOURCE_API');
        $confirmation=Tools::getValue('PS_GROWTH_SOURCE_ATTEST');
        if (!GrowthShopSourceBinding::isMerchant($merchant)
            || !GrowthShopSourceBinding::isNamespace($namespace)
            || !is_string($source) || !is_string($confirmation)) {
            return $this->displayError(
                $this->l('Código comercial en mayúsculas e identificador de instalación en minúsculas, sin espacios ni guiones.')
            );
        }
        $oldMerchant=Configuration::get(self::CONFIG_MERCHANT);
        $oldNamespace=Configuration::get(self::CONFIG_NAMESPACE);
        if ((is_string($oldMerchant) && $oldMerchant!=='' && $oldMerchant!==$merchant)
            || (is_string($oldNamespace) && $oldNamespace!=='' && $oldNamespace!==$namespace)) {
            return $this->displayError(
                $this->l('La identidad ya está fijada. No se puede cambiar ni reutilizar para otra tienda.')
            );
        }
        $current=GrowthShopSourceBinding::inspect($shop,$merchant,$namespace);
        if ($current['status']==='source_invalid') {
            return $this->displayError(
                $this->l('La vinculación del origen Data Cloud no coincide con esta tienda. No se ha modificado.')
            );
        }
        if ($source!=='' && $current['ready']
            && $source!==$current['source']) {
            return $this->displayError(
                $this->l('El origen de Salesforce ya está vinculado. Para cambiarlo se requiere una migración controlada.')
            );
        }
        if (!$current['ready']
            && (!GrowthShopSourceBinding::isSource($source)
                || $confirmation!==GrowthShopSourceBinding::ATTEST
                || $source===GrowthShopSourceBinding::BC_SOURCE)) {
            return $this->displayError(
                $this->l('Para una tienda nueva, indica su origen Data Cloud existente y confirma el esquema PSCustomer/PSOrder. No se enviarán datos.')
            );
        }
        if (!Configuration::updateValue(self::CONFIG_MODE,'test')
            || !Configuration::updateValue(self::CONFIG_MERCHANT,$merchant)
            || !Configuration::updateValue(self::CONFIG_NAMESPACE,$namespace)) {
            return $this->displayError(
                $this->l('No se pudo guardar la identidad. No se han enviado registros.')
            );
        }
        if (!$current['ready']) {
            try {
                GrowthShopSourceBinding::bind(
                    $shop,$merchant,$namespace,$source,$confirmation
                );
            } catch (\Throwable $ignored) {
                return $this->displayError(
                    $this->l('Identidad guardada, pero el origen Data Cloud sigue pendiente. No está autorizado ningún envío.')
                );
            }
        }
        return $this->displayConfirmation(
            $this->l('Tienda configurada y origen Data Cloud declarado. La comprobación del esquema real y el envío son pasos independientes.')
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
            $this->l('Captura local detenida. Se conservan los datos históricos y las pruebas anteriores.')
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
        // Status must reflect this merchant's binding, not always BC's.
        // No SQL, HTTP or other side effects in health diagnostics.
        $shop=$this->credentialContextShopId();
        $binding=GrowthShopSourceBinding::inspect(
            $shop,
            (string)Configuration::get(self::CONFIG_MERCHANT),
            (string)Configuration::get(self::CONFIG_NAMESPACE)
        );
        return [
            'ok' => true,
            'status' => 'blocked_test',
            'mode' => 'test',
            'module_version' => $this->version,
            'prestashop_version' => defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown',
            'provider' => self::PROVIDER,
            'data_platform' => self::DATA_PLATFORM,
            'source' => $binding['ready'] ? $binding['source'] : '',
            'source_binding_ready' => $binding['ready'],
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

    public function cronReport($operation='status')
    {
        // "status" remains inert, "inspect" is read-only. Existing
        // "dispatch" remains a customer-only staging UAT. "dispatch_pair"
        // requires an explicit protected cron operation and the same
        // owner/target gates, and processes at most the selected customer
        // followed by its order, with strict DLO parent confirmation.
        if ($operation==='inspect') {
            $shop=isset($this->context,$this->context->shop,
                $this->context->shop->id)
                ?(int)$this->context->shop->id:0;
            if (class_exists('Shop') && Shop::isFeatureActive()
                && (Shop::getContext()!==Shop::CONTEXT_SHOP
                    || (int)Shop::getContextShopID()!==$shop)) {
                $shop=0;
            }
            $merchant=(string)Configuration::get(self::CONFIG_MERCHANT);
            $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
            // Never connect to a shop DB to inspect events when this is
            // an ordinary production namespace (BC/DO).
            $staging=$shop>0 && preg_match(
                '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
            )===1;
            $db=$staging ? Db::getInstance():null;
            return GrowthG8CronReadOnly::inspect(
                $db,$shop,defined('_PS_VERSION_')?(string)_PS_VERSION_:'',
                $merchant,$namespace,_DB_PREFIX_
            );
        }
        if ($operation==='dispatch' || $operation==='dispatch_pair') {
            $shop=$this->credentialContextShopId();
            $namespace=(string)Configuration::get(self::CONFIG_NAMESPACE);
            if ($shop<1
                || preg_match(
                    '/(?:^|_)(?:stage|staging)(?:_|$)/D',$namespace
                )!==1
                || (string)Configuration::get('PS_SFMC_MODE')!=='test'
                || (string)Configuration::get('PS_GROWTH_LOCAL_CAPTURE')
                    !=='off'
                || (string)Configuration::get(
                    'PS_GROWTH_G8_STAGING_APPROVAL'
                )!==GrowthG8StagingWorker::APPROVAL) {
                return [
                    'ok'=>false,'status'=>'staging_dispatch_not_approved',
                    'marketing_sends_authorized'=>false,
                    'automatic_retry_authorized'=>false
                ];
            }
            // The operation is deliberately opt-in by name. A configured
            // "dispatch" cron NEVER silently changes to sending orders.
            // Passing null selects both exact UAT rows. The worker itself
            // rechecks PSCustomer DLO before PSOrder, CAS once per event,
            // and quarantines every uncertain HTTP outcome.
            $type=$operation==='dispatch_pair'?null:'PSCustomer';
            return GrowthG8NativeStagingServices::run(
                Db::getInstance(),
                defined('_PS_VERSION_')?(string)_PS_VERSION_:'',
                _DB_PREFIX_,$shop,$type
            );
        }
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
