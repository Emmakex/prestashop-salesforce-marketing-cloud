#!/usr/bin/env python3
"""Fail-closed check of *recorded* ABH provider and activation decisions.

This job is offline and does NOT prove live Salesforce tenant settings,
PrestaShop runtime configuration, lawful consent or campaign readiness.
A deliberate contract change requires a reviewed architecture decision.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "config" / "project-contract.json"

EXPECTED = {
    "schema_version": 1,
    "repository": "Emmakex/prestashop-salesforce-marketing-cloud",
    "architecture": "reusable_multi_merchant_provider_adapters",
}
PILOT_EXPECTED = {
    "merchant": "ABH",
    "site": "Barcelona Confort",
    "provider": "marketing_cloud_growth",
    "salesforce_platform": "salesforce_core_lightning",
    "data_platform": "data_cloud_data_360",
    "route": "salesforce_core_rest_or_data_360_ingestion",
    "engagement_for_pilot": False,
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
}
PROVIDER_EXPECTED = {
    ("marketing_cloud_growth", "pilot"): True,
    ("marketing_cloud_growth", "enable_production"): False,
    ("marketing_cloud_engagement", "pilot"): False,
    ("marketing_cloud_engagement", "enable_for_abh"): False,
}


def validate_contract(record):
    """Return errors, not secrets nor original data."""
    errors = []
    if not isinstance(record, dict):
        return ["contract_not_object"]
    for field, wanted in EXPECTED.items():
        if record.get(field) != wanted:
            errors.append("contract_" + field)
    versions = record.get("supported_prestashop_families")
    if versions != ["1.7.8.x", "8.x", "9.x"]:
        errors.append("prestashop_compatibility_changed")
    pilot = record.get("pilot")
    if not isinstance(pilot, dict):
        return errors + ["pilot_not_object"]
    for field, wanted in PILOT_EXPECTED.items():
        observed = pilot.get(field)
        if type(observed) is not type(wanted) or observed != wanted:
            errors.append("pilot_" + field)
    if type(pilot.get("cart_abandon_minutes_observed")) is not int or pilot.get(
        "cart_abandon_minutes_observed"
    ) != 60:
        errors.append("pilot_cart_abandon_minutes_observed")
    if pilot.get("installed_module_version_observed") != "0.7.1":
        errors.append("pilot_installed_module_version_observed")
    if record.get("current_delivery_gate") != "pst_schema_data_owner_approval":
        errors.append("delivery_gate_changed")
    providers = record.get("provider_registry")
    if not isinstance(providers, dict):
        return errors + ["provider_registry_not_object"]
    for (name, key), value in PROVIDER_EXPECTED.items():
        data = providers.get(name, {})
        if not isinstance(data, dict) or data.get(key) is not value:
            errors.append("provider_" + name + "_" + key)
    if providers.get("marketing_cloud_growth", {}).get("transport") != "core_rest_data360":
        errors.append("growth_transport_invalid")
    if providers.get("marketing_cloud_engagement", {}).get("transport") != "legacy_adapter_only":
        errors.append("engagement_transport_invalid")
    return errors


REQUIRED_DOCUMENT_MARKERS = {
    "AGENTS.md": [
        "Marketing Cloud Growth", "Data Cloud / Data 360", "NO Marketing Cloud Engagement",
        "PS_SFMC_MODE=test", "G3",
    ],
    "docs/PROJECT_DEVELOPMENT_RULES.md": [
        "G3 esquema PST", "Marketing Cloud Growth", "BLOQUEADO", "AGENTS.md",
    ],
    "docs/ABH_GROWTH_CANONICAL_TARGET.md": [
        "Marketing Cloud Growth", "NOT Engagement", "Schema Required",
    ],
    "docs/PROJECT_DRIFT_POSTMORTEM.md": [
        "PR #36", "PR #40", "PR #41",
    ],
    "README.md": [
        "Marketing Cloud Growth", "AGENTS.md",
    ],
    "ROADMAP.md": [
        "ABH Growth + Data 360", "docs/PROJECT_DEVELOPMENT_RULES.md",
    ],
}


def validate_repository(root=ROOT):
    errors = []
    try:
        record = json.loads((root / "config/project-contract.json").read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        return ["project_contract_missing_or_invalid"]
    errors.extend(validate_contract(record))
    for path, markers in REQUIRED_DOCUMENT_MARKERS.items():
        try:
            body = (root / path).read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            errors.append("document_missing_" + path)
            continue
        for marker in markers:
            if marker not in body:
                errors.append("missing_canonical_marker_" + path + "_" + marker)
    return errors


if __name__ == "__main__":
    problems = validate_repository()
    if problems:
        for problem in problems:
            print("ABH provider focus FAIL: " + problem)
        raise SystemExit(1)
    print("ABH provider focus PASS: Growth + Data 360, Test only; G3 schema approval next.")
