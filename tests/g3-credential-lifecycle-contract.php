<?php
/**
 * G3 offline install/uninstall privacy boundary tests, PHP >= 7.1.
 * Does not contact any shop, database or Salesforce environment.
 */
require_once __DIR__ . '/runtime/G3CandidateCredentialLifecycle.php';

function g3CredentialAssert($condition, $name)
{
    if (!$condition) {
        throw new RuntimeException('g3_credential_' . $name);
    }
}
$secrets = G3CandidateCredentialLifecycle::sensitiveKeys();
$defaults = G3CandidateCredentialLifecycle::retainedDefaults();

g3CredentialAssert(count($secrets) === 9 && count(array_unique($secrets)) === 9,
    'secret_allowlist_changed');
g3CredentialAssert(in_array('PS_SFMC_CLIENT_SECRET', $secrets, true)
    && in_array('PS_SFMC_CRON_TOKEN', $secrets, true)
    && in_array('PS_SFMC_CLIENT_ID', $secrets, true)
    && in_array('PS_SFMC_ACCOUNT_ID', $secrets, true),
    'missing_credential');
g3CredentialAssert(in_array('PS_SFMC_CUSTOMER_DE_KEY', $secrets, true)
    && in_array('PS_SFMC_ORDER_DE_KEY', $secrets, true)
    && in_array('PS_SFMC_JOURNEY_EVENT_DEFINITION_KEY', $secrets, true),
    'legacy_egress_target_leak');
g3CredentialAssert(in_array('PS_SFMC_AUTH_BASE_URI', $secrets, true)
    && in_array('PS_SFMC_REST_BASE_URI', $secrets, true),
    'legacy_destination_leak');
g3CredentialAssert($defaults['PS_SFMC_CART_ABANDON_MINUTES'] === 60
    && $defaults['PS_SFMC_RETENTION_QUEUE_DAYS'] === 30
    && $defaults['PS_SFMC_CART_CONSENT_MODE'] === 'marketing_optin',
    'safe_default_changed');
g3CredentialAssert(isset($defaults['PS_SFMC_RETENTION_LAST_RUN_AT'])
    && array_intersect(array_keys($defaults), $secrets) === [],
    'operational_state_mixed_with_credentials');
g3CredentialAssert(!array_key_exists('PS_SFMC_MODE', $defaults),
    'reinstall_must_force_test_independently');
g3CredentialAssert(G3CandidateCredentialLifecycle::assertDisposableRuntime() === false
    && G3CandidateCredentialLifecycle::scrubLegacySecrets() === false
    && G3CandidateCredentialLifecycle::installSafeDefaults() === false,
    'unsupported_runtime_not_blocked');
echo "G3 candidate privacy: secrets separated, Test only, no external runtime.\n";
