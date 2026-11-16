#!/usr/bin/env python3
"""G6 Growth-only native consent policy integrity; no Salesforce or PII access.

This CI policy does not certify tenant configuration or authorize sending.
It prevents source opt-in flags or direct consent DMO writes from silently
becoming an asserted permission to send during G7/G8 implementation.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / "config/g6-growth-native-consent-policy.json"

EXPECTED_TARGET = {
    "salesforce_product": "marketing_cloud_growth",
    "subscription_name": "Marketing",
    "channel": "Email",
    "message_purpose": "Promotional",
    "consent_authority": "salesforce_native_consent_service",
    "check": "native_send_time_consent_check",
}
EXPECTED_REQUIREMENTS = {
    "native_send_time_check_verified_in_tenant": False,
    "recipient_contact_point_association_verified": False,
    "subscription_business_scope_verified": False,
    "approved_existing_consent_evidence": False,
    "supported_consent_onboarding_completed": False,
    "source_local_suppression_required": True,
    "purchase_recheck_required": True,
    "missing_consent": "opt_out",
    "prestashop_newsletter_grants_consent": False,
    "prestashop_partner_optin_grants_consent": False,
    "crm_flags_auto_sync_to_growth": False,
    "direct_consent_dmo_write_allowed": False,
    "direct_consent_dlo_write_allowed": False,
    "test_mode_only": True,
    "marketing_sends_authorized": False,
    "data_cloud_ingestion_authorized": False,
}
EXPECTED_OPTIONS = [
    "Consent_tab_supported_import_after_data_owner_approval",
    "Consent_Request_in_approved_On_Demand_or_Automation_Flow",
    "Create_Consent_in_approved_Data_Cloud_Triggered_Flow",
    "Preference_Page_or_Consent_Status_component",
]
EXPECTED_PILOT = {
    "site": "Barcelona Confort",
    "installed_module": "0.7.1",
    "mode": "test",
    "new_module": "0.8.0_not_installed",
    "tenant_consent_observed": "Marketing_Email_ui_and_Core_channel_link_only",
}


def errors(policy):
    if not isinstance(policy, dict):
        return ["g6_policy_not_object"]
    expected = {
        "schema_version": 1,
        "scope": "growth_only",
        "status": "implementation_decision_not_tenant_certification",
        "target": EXPECTED_TARGET,
        "requirements": EXPECTED_REQUIREMENTS,
        "supported_onboarding_options": EXPECTED_OPTIONS,
        "current_pilot": EXPECTED_PILOT,
    }
    reasons = []
    for key, value in expected.items():
        if key not in policy or type(policy[key]) is not type(value):
            reasons.append("g6_invalid_" + key)
        elif policy[key] != value:
            reasons.append("g6_changed_" + key)
    if set(policy) != set(expected):
        reasons.append("g6_unrecognized_policy_fields")
    return reasons


def main():
    try:
        policy = json.loads(POLICY.read_text(encoding="utf8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        print("G6 native consent policy FAIL: missing or invalid manifest")
        return 1
    failures = errors(policy)
    if failures:
        for failure in failures:
            print("G6 native consent policy FAIL: " + failure)
        return 1
    print("G6 native send-time consent strategy PASS (policy only; tenant unverified, sends blocked)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
