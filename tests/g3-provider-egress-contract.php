<?php
/** CI-only policy contract: no provider/mode can authorize sending in G3. */
require_once __DIR__ . '/runtime/G3CandidateProviderGate.php';

function g3GateCheck($condition, $reason)
{
    if (!$condition) {
        throw new RuntimeException('g3_gate_' . $reason);
    }
}
$growth = [
    'source_namespace' => 'growth_ci_abhtest',
    'merchant' => 'ABH',
    'provider' => 'marketing_cloud_growth',
    'mode' => 'test',
    'status' => 'blocked',
];
foreach (['test', 'production', '', 'invalid'] as $localMode) {
    g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
        $growth, $localMode
    ) === 'g3_growth_delivery_not_approved', 'growth_routed_by_mode');
}
g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
    null, 'production'
) === 'g3_provider_unbound', 'missing_binding_not_closed');

$bad = $growth;
$bad['provider'] = 'marketing_cloud_engagement';
g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
    $bad, 'production'
) === 'g3_abh_provider_mismatch', 'abh_engagement_fallback');

foreach (['', 'ready', 'unknown', 'sending'] as $badStatus) {
    $bad = $growth;
    $bad['status'] = $badStatus;
    g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
        $bad, 'production'
    ) === 'g3_binding_integrity_failure', 'unblocked_state');
}
foreach (['', 'bad:namespace', 'x', str_repeat('a', 33)] as $badNamespace) {
    $bad = $growth;
    $bad['source_namespace'] = $badNamespace;
    g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
        $bad, 'test'
    ) === 'g3_binding_integrity_failure', 'namespace_not_validated');
}
$other = $growth;
$other['merchant'] = 'ANOTHER';
$other['provider'] = 'marketing_cloud_engagement';
g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
    $other, 'production'
) === 'g3_tenant_delivery_not_certified', 'unauthorized_engagement_tenant');
$other['provider'] = 'unsupported_provider';
g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
    $other, 'production'
) === 'g3_provider_unrecognized', 'unknown_provider_fallback');
$other['provider'] = 'marketing_cloud_growth';
g3GateCheck(G3CandidateProviderGate::legacyOutboundReason(
    $other, 'production'
) === 'g3_tenant_delivery_not_certified', 'other_growth_unapproved');

g3GateCheck(G3CandidateProviderGate::prestashopOutboundReason()
    === 'g3_provider_unavailable', 'nonprestashop_context_not_closed');

echo "G3 provider egress policy: all outbound routes blocked (PHP 7.1+).\n";
