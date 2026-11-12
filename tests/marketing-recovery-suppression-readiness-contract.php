<?php

require_once dirname(__DIR__) . '/pssalesforcebridge/src/Marketing/RecoverySuppressionReadiness.php';

use Emmakex\PrestaShopSalesforceMarketingCloud\Marketing\RecoverySuppressionReadiness;

function readinessAssert($value, $message)
{
    if (!$value) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
function readinessRejected(array $signal, $error)
{
    try {
        RecoverySuppressionReadiness::assess($signal);
        readinessAssert(false, 'Invalid signal accepted.');
    } catch (RuntimeException $exception) {
        readinessAssert($exception->getMessage() === $error,
            'Unexpected exception: ' . $exception->getMessage());
    }
}

$ready = [
    'customer_active' => true,
    'customer_deleted' => false,
    'first_party_newsletter' => true,
    'newsletter_provenance_verified' => true,
    'email_matches_current_source' => true,
    'namespace_verified' => true,
    'subscriber_key_matched' => true,
    'cart_activity_time_verified' => true,
    'cart_in_recovery_window' => true,
    'cart_has_order' => false,
    'message_purpose_approved' => true,
    'publication_list_required' => true,
    'all_subscribers_status' => 'Active',
    'publication_list_status' => 'Active',
    'suppression_state' => 'clear',
];

$noIssues = RecoverySuppressionReadiness::assess($ready);
readinessAssert($noIssues['candidate_after_independent_checks'] === true,
    'Fully evidenced signals should reach review candidate status.');
readinessAssert($noIssues['status'] === 'review_only'
    && $noIssues['marketing_send_authorized'] === false
    && $noIssues['outbound_authorized'] === false
    && $noIssues['data_cloud_ingestion_authorized'] === false,
    'Even fully evidenced review cannot authorize live sends.');

$cases = [
    ['all_subscribers_status', 'Unsubscribed', 'sfmc_all_subscribers_not_active'],
    ['all_subscribers_status', 'Held', 'sfmc_all_subscribers_not_active'],
    ['all_subscribers_status', 'Unknown', 'sfmc_all_subscribers_not_active'],
    ['publication_list_status', 'Unsubscribed', 'sfmc_publication_not_active'],
    ['suppression_state', 'suppressed', 'sfmc_suppression_not_clear'],
    ['suppression_state', 'unknown', 'sfmc_suppression_not_clear'],
    ['first_party_newsletter', false, 'first_party_newsletter_off'],
    ['newsletter_provenance_verified', false, 'newsletter_provenance_unverified'],
    ['namespace_verified', false, 'contact_identity_unverified'],
    ['subscriber_key_matched', false, 'contact_identity_unverified'],
    ['cart_has_order', true, 'cart_converted'],
    ['cart_activity_time_verified', false, 'cart_freshness_unverified_or_expired'],
    ['message_purpose_approved', false, 'message_purpose_unapproved'],
    ['email_matches_current_source', false, 'email_changed'],
    ['customer_deleted', true, 'source_customer_unavailable'],
];
foreach ($cases as $test) {
    $signal = $ready;
    $signal[$test[0]] = $test[1];
    $result = RecoverySuppressionReadiness::assess($signal);
    readinessAssert(!$result['candidate_after_independent_checks']
        && in_array($test[2], $result['reasons'], true)
        && !$result['marketing_send_authorized'],
        'Fail-closed case did not block: ' . $test[0]);
}
$withoutList = $ready;
$withoutList['publication_list_required'] = false;
$withoutList['publication_list_status'] = 'NotApplicable';
readinessAssert(RecoverySuppressionReadiness::assess($withoutList)['candidate_after_independent_checks'] === true,
    'Reviewed case without publication list should be a candidate.');
$withoutList['publication_list_status'] = 'Active';
readinessAssert(RecoverySuppressionReadiness::assess($withoutList)['candidate_after_independent_checks'] === false,
    'Unapproved publication-list scope should be rejected.');
$invalid = $ready;
$invalid['email'] = 'customer@example.invalid';
readinessRejected($invalid, 'recovery_readiness_unknown_signal');
$invalid = $ready;
$invalid['newsletter_provenance_verified'] = 1;
readinessRejected($invalid, 'recovery_readiness_boolean_signal_required');
$invalid = $ready;
$invalid['all_subscribers_status'] = 'ACTIVE';
readinessRejected($invalid, 'recovery_readiness_invalid_status');
$invalid = $ready;
unset($invalid['suppression_state']);
readinessRejected($invalid, 'recovery_readiness_status_signal_required');

$src = file_get_contents(dirname(__DIR__)
    . '/pssalesforcebridge/src/Marketing/RecoverySuppressionReadiness.php');
foreach (['curl_', 'Db::', 'file_get_contents(', '->fire(', '->enqueue('] as $forbidden) {
    readinessAssert(strpos($src, $forbidden) === false, 'Review contract must have no side effects.');
}
echo "SFMC suppression readiness contract passed, fail-closed and no sends.\n";
