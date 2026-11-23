<?php
/**
 * OAuth/BO CSRF regression: emulate a PrestaShop 9 form whose AdminModules
 * controller token differs between rendering and POST. Pure synthetic.
 * The independent form HMAC must remain constant, per-shop and per-employee.
 */
require_once dirname(__DIR__)
    .'/pssalesforcebridge/src/Growth/GrowthStableBoCsrf.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Growth\GrowthStableBoCsrf as Csrf;

function stableCsrfAssert($ok,$case) {
    if (!$ok)throw new RuntimeException('growth_stable_csrf_'.$case);
}
$secret=str_repeat('K',48);
$shop=3;$employee=9;
$getAdminToken='admin-modules-get-token';
$postAdminToken='admin-modules-post-token';
stableCsrfAssert($getAdminToken!==$postAdminToken,'fixture_stale_route');

$csrfGet=Csrf::issue($secret,$shop,$employee);
$csrfPost=Csrf::issue($secret,$shop,$employee);
stableCsrfAssert(strlen($csrfGet)===64
    && $csrfGet===$csrfPost
    && Csrf::verify($csrfGet,$secret,$shop,$employee),
    'fresh_form_always_failed_after_route_change');

stableCsrfAssert(!Csrf::verify($csrfGet,$secret,4,$employee),
    'cross_shop_token_accepted');
stableCsrfAssert(!Csrf::verify($csrfGet,$secret,$shop,10),
    'cross_employee_token_accepted');
stableCsrfAssert(!Csrf::verify($csrfGet,str_repeat('Z',48),$shop,$employee),
    'cross_server_token_accepted');
stableCsrfAssert(!Csrf::verify('',$secret,$shop,$employee),
    'missing_token_accepted');
stableCsrfAssert(!Csrf::verify(
    strtoupper($csrfGet),$secret,$shop,$employee
), 'malformed_token_accepted');
stableCsrfAssert(!Csrf::verify(str_repeat('a',64),$secret,$shop,$employee),
    'forged_token_accepted');
stableCsrfAssert(Csrf::issue('',3,9)===''
    && Csrf::issue(str_repeat('x',12),3,9)===''
    && Csrf::issue($secret,0,9)===''
    && !Csrf::verify('',$secret,3,9),
    'weak_server_secret_fallback');
$module=file_get_contents(
    dirname(__DIR__).'/pssalesforcebridge/pssalesforcebridge.php'
);
$methodStart=strpos($module,'private function credentialCsrf($shop)');
$methodEnd=strpos($module,'private function handleSalesforceCredentials(');
stableCsrfAssert($methodStart!==false && $methodEnd>$methodStart,
    'module_csrf_method_missing');
$method=substr($module,$methodStart,$methodEnd-$methodStart);
stableCsrfAssert(strpos($method,'GrowthStableBoCsrf::issue(')!==false
    && strpos($method,'_COOKIE_KEY_')!==false
    && strpos($method,'isCredentialAdmin($shop)')!==false
    && strpos($method,'Tools::getAdminTokenLite')===false,
    'module_csrf_still_uses_volatile_admin_token');
stableCsrfAssert(strpos($module,
    "getAdminTokenLite('AdminModules')")!==false
    && strpos($module,
        "PS_GROWTH_OAUTH_CSRF")!==false,
    'controller_token_or_form_security_removed');
stableCsrfAssert(substr_count($module,
    "|| !hash_equals(\$this->credentialCsrf(\$shop),\$csrf)")===3
    && substr_count($module,
        "\$this->credentialCsrf(\$shop)===''")===3,
    'form_missing_or_empty_csrf_not_blocked');
echo "Growth BO CSRF PASS: stable across route POST, server-keyed, shop+employee, no fail-open.\n";
