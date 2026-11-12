<?php
require_once dirname(__DIR__) . '/pssalesforcebridge/src/Cart/CartSegmentClassifier.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Cart\CartSegmentClassifier;

function cartSegAssert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function cartSegReject(array $data, $threshold, $code)
{
    try {
        CartSegmentClassifier::classify($data, $threshold);
        cartSegAssert(false, 'Unsafe cart segment accepted.');
    } catch (RuntimeException $error) {
        cartSegAssert($error->getMessage() === $code, 'Unexpected error code: ' . $error->getMessage());
    }
}
$base = [
    'shop_id' => 1, 'cart_id' => 24, 'product_count' => 2,
    'has_order' => false, 'known_contact' => true, 'is_guest' => false,
    'activity_time_verified' => true, 'age_minutes' => 120,
    'cart_status' => 'ineligible',
    'newsletter' => true, 'optin' => true,
];
$r = CartSegmentClassifier::classify($base);
cartSegAssert($r['segment'] === 'abandoned' && $r['identity'] === 'registered',
    'Registered customer with an old cart must be analytically abandoned even when sending is ineligible.');
foreach (['outreach_authorized', 'marketing_send_authorized', 'data_cloud_ingestion_authorized'] as $flag) {
    cartSegAssert($r[$flag] === false, 'No classifier output may authorize side effects.');
}
$guest = $base;
$guest['is_guest'] = true;
cartSegAssert(CartSegmentClassifier::classify($guest)['identity'] === 'guest', 'Guest segment must be distinct.');
$anon = $guest;
$anon['known_contact'] = false;
cartSegAssert(CartSegmentClassifier::classify($anon)['identity'] === 'anonymous', 'Anonymous carts must remain analytics-only.');
$converted = $base;
$converted['has_order'] = true;
cartSegAssert(CartSegmentClassifier::classify($converted)['segment'] === 'converted', 'Order existence must suppress abandonment.');
$converted['cart_status'] = 'abandoned';
cartSegAssert(CartSegmentClassifier::classify($converted)['segment'] === 'converted', 'Converted carts win over stale state.');
$recovered = $base;
$recovered['cart_status'] = 'recovered';
cartSegAssert(CartSegmentClassifier::classify($recovered)['segment'] === 'recovery_started', 'Recovery link is not necessarily a purchase.');
$empty = $base;
$empty['product_count'] = 0;
cartSegAssert(CartSegmentClassifier::classify($empty)['segment'] === 'empty', 'Empty carts are not abandoned.');
$recent = $base;
$recent['age_minutes'] = 59;
cartSegAssert(CartSegmentClassifier::classify($recent)['segment'] === 'active', 'Do not mark recent carts as abandoned.');
$timeless = $base;
$timeless['activity_time_verified'] = false;
cartSegAssert(CartSegmentClassifier::classify($timeless)['segment'] === 'activity_time_unverified',
    'Missing time provenance must not result in definitive abandonment.');
$missing = $base;
unset($missing['age_minutes']);
cartSegAssert(CartSegmentClassifier::classify($missing)['segment'] === 'activity_time_unverified', 'Unknown age must remain unverified.');
$unsafe = $base;
$unsafe['product_count'] = -1;
cartSegReject($unsafe, 60, 'cart_segment_invalid_input');
$unsafe = $base;
unset($unsafe['has_order']);
cartSegReject($unsafe, 60, 'cart_segment_missing_input');
cartSegReject($base, 5, 'cart_segment_invalid_threshold');

$source = file_get_contents(dirname(__DIR__) . '/pssalesforcebridge/src/Cart/CartSegmentClassifier.php');
foreach (['curl_', 'Db::', 'file_get_contents(', '->fire(', '->enqueue('] as $forbidden) {
    cartSegAssert(strpos($source, $forbidden) === false, 'Classifier must remain offline.');
}
cartSegAssert(strpos($source, "'outreach_authorized' => false") !== false, 'Fail-closed authorization invariant missing.');
echo "cart segmentation contract passed; registered/guest/anonymous, no sends.\n";
