#!/usr/bin/env python3
"""Offline fail-closed checks for G3-02/G3-07 field-level owner review.

The proposal does not authorize schema upload, record export or marketing.
"""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REGISTER = "config/g3-field-purpose-owner-decisions.json"
INVENTORY = "config/g3-pst-schema-review.json"
OBJECTS = {"PSCustomer", "PSOrder"}
PROPOSALS = {
    "consider_identity_only",
    "withhold_pending_purpose",
    "omit_by_default",
    "source_signal_not_consent",
    "review_business_necessity",
    "diagnostic_not_record_modified",
    "quarantine_unverified_history",
    "review_relationship_reference",
}
STRICT_PROPOSALS = {
    "PSCustomer": {
        "email": "withhold_pending_purpose",
        "first_name": "omit_by_default",
        "last_name": "omit_by_default",
        "newsletter_flag": "source_signal_not_consent",
        "partner_optin_flag": "source_signal_not_consent",
        "source_updated_local": "diagnostic_not_record_modified",
        "source_updated_utc": "quarantine_unverified_history",
    },
    "PSOrder": {
        "cart_source_key": "omit_by_default",
        "source_updated_local": "diagnostic_not_record_modified",
        "source_updated_utc": "quarantine_unverified_history",
    },
}
FIELD_KEYS = {
    "name", "sensitivity", "source_purpose_candidate", "minimization_proposal",
    "review_evidence_required", "decision", "approved_processing_purpose",
    "lawful_basis_verified", "retention_approved",
    "export_to_data_360_authorized", "growth_send_authorized",
}


def validate_review(register, inventory):
    problems = []
    if not isinstance(register, dict) or not isinstance(inventory, dict):
        return ["review_invalid_input"]
    expected = {
        "contract_version": 1,
        "merchant": "ABH",
        "target_product": "marketing_cloud_growth_data_360",
        "source_review_contract": INVENTORY,
        "state": "pending_data_privacy_owner",
        "purpose_signoff": "pending",
        "retention_signoff": "pending",
        "schema_selection": "undecided",
        "scope": "offline_field_level_review_only",
        "no_personal_data_examples": True,
        "data_cloud_schema_upload_authorized": False,
        "data_cloud_ingestion_authorized": False,
        "marketing_sends_authorized": False,
        "decisions": ["G3-02", "G3-07"],
    }
    if set(register) != set(expected) | {"field_decisions"}:
        problems.append("review_unknown_or_missing_top_level_setting")
    for key, wanted in expected.items():
        value = register.get(key)
        if type(value) is not type(wanted) or value != wanted:
            problems.append("review_unauthorized_" + key)
    if inventory.get("approval_state") != "pending_data_owner" or inventory.get(
        "schema_to_publish"
    ) != "undecided" or inventory.get("review_only") is not True:
        problems.append("review_source_not_pending")
    fields = register.get("field_decisions")
    source = inventory.get("field_inventory")
    if not isinstance(fields, dict) or set(fields) != OBJECTS or not isinstance(
        source, dict
    ) or set(source) != OBJECTS:
        return problems + ["review_object_scope_drift"]

    for obj in OBJECTS:
        rows, originals = fields[obj], source[obj]
        if not isinstance(rows, list) or not isinstance(originals, list):
            problems.append("review_rows_missing_" + obj)
            continue
        if len(rows) != 12 or len(originals) != 12:
            problems.append("review_field_count_drift_" + obj)
        names = [row.get("name") if isinstance(row, dict) else None for row in rows]
        original_names = [row.get("name") if isinstance(row, dict) else None for row in originals]
        if names != original_names or len(names) != len(set(names)):
            problems.append("review_field_order_drift_" + obj)
        for row, base in zip(rows, originals):
            if not isinstance(row, dict) or not isinstance(base, dict):
                problems.append("review_invalid_row_" + obj)
                continue
            name = base.get("name")
            if set(row) != FIELD_KEYS or row.get("sensitivity") != base.get(
                "sensitivity"
            ) or row.get("source_purpose_candidate") != base.get("purpose"):
                problems.append("review_source_classification_drift_" + obj)
            proposal = row.get("minimization_proposal")
            if proposal not in PROPOSALS:
                problems.append("review_unknown_proposal_" + obj)
            if name in STRICT_PROPOSALS[obj] and proposal != STRICT_PROPOSALS[obj][name]:
                problems.append("review_sensitive_field_misclassified_" + obj)
            if row.get("decision") != "pending" or row.get(
                "approved_processing_purpose"
            ) is not None:
                problems.append("review_premature_field_signoff_" + obj)
            for flag in ("lawful_basis_verified", "retention_approved",
                         "export_to_data_360_authorized", "growth_send_authorized"):
                if row.get(flag) is not False:
                    problems.append("review_unauthorized_field_" + obj + "_" + flag)
            evidence = row.get("review_evidence_required")
            if not isinstance(evidence, str) or not evidence.strip():
                problems.append("review_missing_evidence_request_" + obj)
            elif re.search(r"\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b", evidence):
                problems.append("review_possible_personal_data_in_evidence_" + obj)

    return problems


def validate_repository(root=ROOT):
    try:
        register = json.loads((root / REGISTER).read_text(encoding="utf-8"))
        inventory = json.loads((root / INVENTORY).read_text(encoding="utf-8"))
    except (OSError, ValueError, UnicodeError):
        return ["review_unreadable"]
    return validate_review(register, inventory)


if __name__ == "__main__":
    errors = validate_repository()
    if errors:
        for error in errors:
            print("G3 purpose/retention review FAIL: " + error)
        raise SystemExit(1)
    print("G3 field review PASS: 24 pending fields; 0 data exports; 0 sends; no owner approvals.")
