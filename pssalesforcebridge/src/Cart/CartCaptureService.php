<?php

namespace Emmakex\PrestaShopSalesforceMarketingCloud\Cart;

use Emmakex\PrestaShopSalesforceMarketingCloud\Compatibility\CartHookAdapter;
use Emmakex\PrestaShopSalesforceMarketingCloud\Order\OrderSyncLedger;

final class CartCaptureService
{
    private $repository;
    private $orders;
    private $eligibility;

    public function __construct(CartRepository $repository, OrderSyncLedger $orders, CartEligibilityPolicy $eligibility)
    {
        $this->repository = $repository;
        $this->orders = $orders;
        $this->eligibility = $eligibility;
    }

    public function capture($cart, $context, $abandonMinutes, $consentMode)
    {
        $shopId = CartHookAdapter::shopId($cart, $context);
        $customer = CartHookAdapter::customer($cart);
        $payload = CartNormalizer::fromCart(
            $cart,
            $customer,
            CartHookAdapter::products($cart),
            CartHookAdapter::currencyIso($cart, $context),
            $shopId,
            CartHookAdapter::total($cart)
        );

        if ((int) $payload['cart_id'] <= 0 || (int) $shopId <= 0) {
            return false;
        }

        $evaluation = $this->eligibility->evaluate($payload, $consentMode);
        $eligible = !empty($evaluation['eligible']);
        $reason = isset($evaluation['reason']) ? (string) $evaluation['reason'] : 'unknown';
        $status = 'active';

        if ((int) $payload['product_count'] <= 0) {
            $status = 'empty';
            $eligible = false;
            $reason = 'empty_cart';
        } elseif ($this->orders->hasOrderForCart($shopId, $payload['cart_id'])) {
            $status = 'converted';
            $eligible = false;
            $reason = 'converted_to_order';
        } elseif (!$eligible) {
            $status = 'ineligible';
        }

        $activityTs = strtotime((string) $payload['last_activity']);
        if ($activityTs === false) {
            $activityTs = time();
            $payload['last_activity'] = date('Y-m-d H:i:s', $activityTs);
        }

        $abandonMinutes = max(15, (int) $abandonMinutes);
        $abandonAfter = date('Y-m-d H:i:s', $activityTs + ($abandonMinutes * 60));
        $payload['eligible'] = $eligible;
        $payload['eligibility_reason'] = $reason;
        $payload['consent_mode'] = (string) $consentMode;
        $payload['status'] = $status;
        $payload['abandon_after'] = $abandonAfter;

        $encoded = json_encode($payload);
        if ($encoded === false) {
            return false;
        }

        return $this->repository->observe(
            $payload,
            $status,
            $eligible,
            $reason,
            $consentMode,
            $abandonAfter,
            sha1($encoded)
        );
    }
}
