<?php

require_once dirname(__DIR__, 2) . '/src/Recovery/RecoveryTokenRepository.php';
require_once dirname(__DIR__, 2) . '/src/Recovery/CartSessionRestorer.php';
require_once dirname(__DIR__, 2) . '/src/Recovery/RecoveryService.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderSyncLedger;
use Emmakex\PrestaShopSalesforceMarketingCloud\Queue\QueueRepository;
use Emmakex\PrestaShopSalesforceMarketingCloud\Recovery\CartSessionRestorer;
use Emmakex\PrestaShopSalesforceMarketingCloud\Recovery\RecoveryService;
use Emmakex\PrestaShopSalesforceMarketingCloud\Recovery\RecoveryTokenRepository;

class PsSalesforceBridgeRecoverModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        try {
            $service = new RecoveryService(
                new RecoveryTokenRepository(),
                new CartSessionRestorer(),
                new CartRepository(),
                new OrderSyncLedger(),
                new QueueRepository()
            );

            $service->recover((string) Tools::getValue('t'), $this->context);

            Tools::redirect($this->context->link->getPageLink('order', true));
        } catch (Exception $e) {
            if (class_exists('PrestaShopLogger')) {
                PrestaShopLogger::addLog(
                    'Salesforce bridge cart recovery rejected: ' . substr($e->getMessage(), 0, 500),
                    2,
                    null,
                    'PsSalesforceBridge'
                );
            }

            http_response_code(410);
            header('Content-Type: text/html; charset=utf-8');

            $home = isset($this->context->link)
                ? $this->context->link->getPageLink('index', true)
                : '/';

            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
                . '<title>Cart recovery</title></head><body>'
                . '<h1>This recovery link can no longer be used.</h1>'
                . '<p>The cart may have changed, been purchased, expired, or the link may already have been used.</p>'
                . '<p><a href="' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '">Return to the shop</a></p>'
                . '</body></html>';
            exit;
        }
    }
}
