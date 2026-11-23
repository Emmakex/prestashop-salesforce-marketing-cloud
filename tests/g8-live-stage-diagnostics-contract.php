<?php
/** Offline guard for protected live metadata stage diagnostics. */
$file=dirname(__DIR__).'/tests/live/g8-dlo-metadata-readonly.php';
$text=file_get_contents($file);
if (!is_string($text)
    || strpos($text,"'failure_stage'")===false
    || strpos($text,"'failure_code'")===false) {
    // Code assigns these with array key bracket syntax, not string-only tags.
    if (!is_string($text)
        || strpos($text,"['failure_stage']")===false
        || strpos($text,"['failure_code']")===false) {
        throw new RuntimeException('g8_stage_diagnostic_contract_missing');
    }
}
foreach([
    "'oauth_core'","'oauth_exchange'","'catalog_get'",
    "'metadata_resolve'","'metadata_customer_get'","'metadata_order_get'",
    "'canary_customer'","'canary_order'",
    "'g8_dlo_metadata_field_ambiguous'",
    "'g8_dlo_metadata_entity_ambiguous'",
    "'g8_metadata_http_status_403'",
    "'g8_metadata_http_status_404'",
    "'g8_metadata_http_status_0'",
    "in_array(",
    "'unclassified'"
] as $required) {
    if (strpos($text,$required)===false) {
        throw new RuntimeException('g8_stage_diagnostic_contract_missing_allowlist');
    }
}
if (preg_match('/echo\s+.*(?:getMessage|failure_code|failure_stage|token|origin)/i',
    $text)) {
    throw new RuntimeException('g8_stage_diagnostic_contract_stdout_leak');
}
echo "G8 safe live stage diagnostics PASS: only fixed codes and phases, never raw metadata failure.\n";
