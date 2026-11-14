#!/usr/bin/env python3
"""Growth + Data Cloud ONLY: enforce product focus without approving live use."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
EXPECTED = {
    "schema_version": 1,
    "repository": "Emmakex/prestashop-salesforce-marketing-cloud",
    "architecture": "growth_only_multi_shop",
    "delivery_policy": "growth_only_no_other_salesforce_product",
}
PILOT_EXPECTED = {
    "merchant": "ABH",
    "site": "Barcelona Confort",
    "provider": "marketing_cloud_growth",
    "salesforce_platform": "salesforce_core_lightning",
    "data_platform": "data_cloud_data_360",
    "route": "salesforce_core_rest_or_data_360_ingestion",
    "prestashop_runtime_mode_observed": "test",
    "cart_consent_mode_observed": "marketing_optin",
    "oauth_status": "certified_no_write",
    "data_cloud_token_exchange_status": "certified_no_write",
    "data_cloud_connector_alias": "PST",
    "data_cloud_source_api_name": "ABH_PrestaShop_Data_Cloud",
    "data_cloud_schema_status": "schema_required_unapproved",
    "customer_data_live_ingestion_authorized": False,
    "live_campaign_sends_authorized": False,
    "historical_backfill_authorized": False,
    "external_sender_provider": "not_implemented_for_pilot",
    "target_module_version": "0.8.0",
}
PROVIDER_EXPECTED = {
    "marketing_cloud_growth": {
        "pilot": True,
        "transport": "salesforce_core_rest_data_cloud",
        "enable_production": False,
    }
}

def validate_contract(record):
    if not isinstance(record, dict):
        return ["contract_not_object"]
    errors = []
    for key, expected in EXPECTED.items():
        if record.get(key) != expected:
            errors.append("contract_" + key)
    if record.get("supported_prestashop_families") != ["1.7.8.x", "8.x", "9.x"]:
        errors.append("versions_changed")
    pilot = record.get("pilot")
    if not isinstance(pilot, dict):
        return errors + ["pilot_not_object"]
    for key, expected in PILOT_EXPECTED.items():
        value = pilot.get(key)
        if type(value) is not type(expected) or value != expected:
            errors.append("pilot_" + key)
    if pilot.get("installed_module_version_observed") != "0.7.1":
        errors.append("bc_installed_release_overstated")
    if type(pilot.get("cart_abandon_minutes_observed")) is not int \
            or pilot.get("cart_abandon_minutes_observed") != 60:
        errors.append("bc_cart_minutes_changed")
    if record.get("current_delivery_gate") != "pst_schema_data_owner_approval":
        errors.append("delivery_gate_changed")
    if record.get("provider_registry") != PROVIDER_EXPECTED:
        errors.append("product_not_growth_only")
    return errors

REQUIRED_DOCS = {
    "AGENTS.md": ["Marketing Cloud Growth", "Data Cloud", "G3", "Test"],
    "docs/PROJECT_DEVELOPMENT_RULES.md": [
        "Marketing Cloud Growth", "Data Cloud", "G3", "BLOQUEADO",
    ],
    "docs/ABH_GROWTH_CANONICAL_TARGET.md": [
        "Marketing Cloud Growth", "Data Cloud", "Schema Required",
    ],
    "README.md": ["Marketing Cloud Growth", "Data Cloud", "AGENTS.md"],
    "ROADMAP.md": ["Marketing Cloud Growth", "Data Cloud", "G3"],
}
REQUIRED_GROWTH_FILES = [
    "pssalesforcebridge/pssalesforcebridge.php",
    "pssalesforcebridge/src/Growth/GrowthOnlyHookPreflight.php",
    "pssalesforcebridge/controllers/front/cron.php",
    "pssalesforcebridge/upgrade/upgrade-0.8.0.php",
]
FORBIDDEN_MODULE_FILES = [
    "pssalesforcebridge/src/Salesforce/AuthClient.php",
    "pssalesforcebridge/src/Salesforce/RestClient.php",
    "pssalesforcebridge/src/Salesforce/JourneyClient.php",
    "pssalesforcebridge/src/Customer/CustomerDataExtensionWriter.php",
    "pssalesforcebridge/src/Order/OrderDataExtensionWriter.php",
    "pssalesforcebridge/src/Queue/AbandonedCartJourneyProcessor.php",
    "pssalesforcebridge/src/Journey/JourneyEventAuditRepository.php",
    "pssalesforcebridge/controllers/front/recover.php",
]

def validate_repository(root=ROOT):
    try:
        contract = json.loads((root / "config/project-contract.json").read_text("utf-8"))
    except (OSError, UnicodeError, ValueError):
        return ["contract_unreadable"]
    errors = validate_contract(contract)
    for path, keywords in REQUIRED_DOCS.items():
        try:
            content = (root / path).read_text("utf-8")
        except (OSError, UnicodeError):
            errors.append("missing_doc_" + path)
            continue
        for keyword in keywords:
            if keyword not in content:
                errors.append("missing_doc_keyword_" + path + "_" + keyword)
    for path in REQUIRED_GROWTH_FILES:
        if not (root / path).is_file():
            errors.append("growth_module_file_missing_" + path)
    for path in FORBIDDEN_MODULE_FILES:
        if (root / path).exists():
            errors.append("old_adapter_file_still_shipped_" + path)
    module = root / "pssalesforcebridge/pssalesforcebridge.php"
    try:
        source = module.read_text("utf-8")
    except (OSError, UnicodeError):
        source = ""
    for forbidden in [
        "CustomerDataExtensionWriter", "JourneyClient", "RestClient",
        "processCustomerQueue(", "processAbandonedCartJourneyQueue(",
    ]:
        if forbidden in source:
            errors.append("old_adapter_function_still_loaded_" + forbidden)
    if " $this->version = '0.8.0';" not in source:
        errors.append("growth_only_module_version_mismatch")
    return errors

if __name__ == "__main__":
    problems = validate_repository()
    if problems:
        for problem in problems:
            print("Growth-only product focus FAIL: " + problem)
        raise SystemExit(1)
    print("Growth-only product focus PASS: Data Cloud, Test, no legacy adapter or sends.")
