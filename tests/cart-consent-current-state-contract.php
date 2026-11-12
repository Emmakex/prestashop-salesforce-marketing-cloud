<?php

require_once dirname(__DIR__) . '/pssalesforcebridge/src/Cart/CartEligibilityPolicy.php';
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Cart/CartContactStateReader.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartEligibilityPolicy;
use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartContactStateReader;

function consentAssert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

final class Customer
{
    public $id;
    public $id_shop;
    public $email;
    public $newsletter;
    public $optin;
    public $active;
    public $deleted;

    public static $records = [];

    public function __construct($id)
    {
        if (!isset(self::$records[$id])) {
            return;
        }
        foreach (self::$records[$id] as $key => $value) {
            $this->$key = $value;
        }
    }
}

$policy = new CartEligibilityPolicy();
$base = [
    'product_count' => 2, 'customer_id' => 10,
    'contact_key' => 'ps_1_10', 'email' => 'example@example.invalid',
    'customer_active' => true, 'customer_deleted' => false,
    'newsletter' => false, 'optin' => true,
];
$partnerOnly = $policy->evaluate($base, CartEligibilityPolicy::MODE_MARKETING_OPTIN);
consentAssert($partnerOnly['eligible'] === false && $partnerOnly['reason'] === 'newsletter_required',
    'Partner opt-in alone must not authorize first-party recovery.');
$base['newsletter'] = true;
$news = $policy->evaluate($base, CartEligibilityPolicy::MODE_MARKETING_OPTIN);
consentAssert($news['eligible'] === true, 'Current first-party newsletter flag should pass technical opt-in.');
$base['customer_deleted'] = true;
consentAssert($policy->evaluate($base, CartEligibilityPolicy::MODE_MARKETING_OPTIN)['eligible'] === false,
    'Deleted customer must fail closed.');
$base['customer_deleted'] = false;
$base['customer_active'] = false;
consentAssert($policy->evaluate($base, CartEligibilityPolicy::MODE_MARKETING_OPTIN)['eligible'] === false,
    'Inactive customer must fail closed.');
$base['customer_active'] = true;
consentAssert($policy->evaluate($base, 'typo')['eligible'] === false,
    'Unknown consent mode must fail closed.');

Customer::$records = [
    10 => ['id' => 10, 'id_shop' => 1, 'email' => 'example@example.invalid',
        'newsletter' => 0, 'optin' => 1, 'active' => 1, 'deleted' => 0],
    11 => ['id' => 11, 'id_shop' => 2, 'email' => 'other@example.invalid',
        'newsletter' => 1, 'optin' => 0, 'active' => 1, 'deleted' => 0],
    12 => ['id' => 12, 'id_shop' => 1, 'email' => 'deleted@example.invalid',
        'newsletter' => 1, 'optin' => 0, 'active' => 1, 'deleted' => 1],
];
$live = CartContactStateReader::read(1, 10);
consentAssert(is_array($live) && $live['newsletter'] === false && $live['optin'] === true,
    'Live subscription must override stale cart data.');
$base['newsletter'] = $live['newsletter'];
$base['optin'] = $live['optin'];
$base['customer_active'] = $live['customer_active'];
$base['customer_deleted'] = $live['customer_deleted'];
consentAssert($policy->evaluate($base, CartEligibilityPolicy::MODE_MARKETING_OPTIN)['eligible'] === false,
    'Unsubscribed customer must be suppressed even with stale cart snapshot.');
consentAssert(CartContactStateReader::read(1, 11) === null,
    'Cross-shop customer lookup must fail closed.');
consentAssert(CartContactStateReader::read(1, 99) === null,
    'Missing customer must fail closed.');
consentAssert(CartContactStateReader::read(1, 0) === null,
    'Invalid customer ID must fail closed.');
$deleted = CartContactStateReader::read(1, 12);
consentAssert(is_array($deleted) && $deleted['customer_deleted'] === true,
    'Live deletion state must propagate to policy.');

$processor = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/Queue/AbandonedCartJourneyProcessor.php');
$readerPos = strpos($processor, 'CartContactStateReader::read(');
$sendPos = strpos($processor, '$this->journey->fire(');
$linkPos = strpos($processor, '$this->recoveryLinks->issueUrl(');
consentAssert($readerPos !== false && $sendPos !== false && $readerPos < $sendPos
    && $linkPos !== false && $readerPos < $linkPos,
    'Journey must re-read live source state before issuing recovery URL or sending.');
consentAssert(strpos($processor, 'customer_email_changed') !== false,
    'Journey must suppress stale email addresses.');
consentAssert(strpos($processor, 'current_customer_unavailable') !== false,
    'Journey must suppress unavailable customers.');
$normalizer = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/Cart/CartNormalizer.php');
consentAssert(strpos($normalizer, "'customer_deleted'") !== false,
    'Captured customer state must include the deletion flag.');

echo "Cart current consent safety contract passed (PHP 7.1 compatible).\n";
