#!/usr/bin/env python3
"""G3 final owner handoff. Pending is NOT signed; no G4 permissions.

This guard exists to prevent "continuemos" or a passing CI from being
mistaken for written data-owner, privacy and Data 360 architecture signoff.
"""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SHA = "8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d"
SCHEMA = "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
EXPECTED_DECISIONS = [
    ("DATA_AND_PRIVACY", "data_owner_and_privacy",
     ("namespace_allocation_confirmed", "data_processing_purpose_approved",
      "lawful_basis_per_field_reviewed", "retention_policy_approved",
      "suppression_erasure_approved")),
    ("DATA_360_RELATIONSHIP", "data_360_architect",
     ("proposed_refresh_mode_approved", "source_revision_strategy_approved",
      "data_stream_categories_approved", "data_space_approved",
      "dmo_pk_fk_mapping_approved", "inactive_relationships_reviewed")),
    ("SCHEMA_AND_PREVIEW", "data_owner_and_data_360_administrator",
     ("schema_exact_sha_confirmed", "preview_in_pst_reviewed")),
]
DENIED = (
    "schema_upload", "create_data_streams", "save_dlo_dmo_mapping",
    "data_360_ingestion", "historical_backfill", "identity_stitching",
    "marketing_sends", "outbound_worker", "prestashop_production_mode",
)


def validate(handoff, closure, schema_bytes):
    errors = []
    if not isinstance(handoff, dict) or not isinstance(closure, dict):
        return ["g3_handoff_invalid_input"]
    expected = {
        "contract_version": 1,
        "merchant": "ABH",
        "provider": "marketing_cloud_growth",
        "data_platform": "data_cloud_data_360",
        "status": "pending_formal_signoff",
        "source": "existing_G3_contracts_and_research_not_new_approval",
        "installed_bc_module": "0.7.1",
        "installed_bc_mode": "test",
        "no_sql_access_or_reinstall_required": True,
    }
    for key, value in expected.items():
        if handoff.get(key) != value:
            errors.append("g3_handoff_invalid_" + key)
    technical = handoff.get("schema_technical_recommendation")
    if not isinstance(technical, dict) or technical != {
        "version": "v0.2", "path": SCHEMA, "sha256": SHA,
        "object_count": 2, "field_count": 22,
        "selection_formally_approved": False,
    }:
        errors.append("g3_handoff_schema_not_review_only")
    if not isinstance(schema_bytes, bytes) or hashlib.sha256(schema_bytes).hexdigest() != SHA:
        errors.append("g3_handoff_frozen_schema_mismatch")
    if closure.get("review_stage") != "technical_closure_candidate_not_owner_approval" \
            or closure.get("point_3_schema_finalization", {}).get("schema_approved") is not False \
            or closure.get("point_1_data_privacy", {}).get("export_to_data_360_authorized") is not False \
            or closure.get("point_2_customer_order_relationship", {}).get("dmo_relation_approved") is not False:
        errors.append("g3_handoff_prior_gate_changed")
    recommendation = handoff.get("technical_recommendations")
    safe = {
        "namespace": "registry_assigned_unique_per_installation_not_domain_or_db",
        "source_key": "source_namespace:shop_id:entity_type:entity_id",
        "invalid_scope": "quarantine",
        "destination_personal_data": "withheld",
        "newsletter_flags": "source_signals_not_send_consent",
        "historical_datetime": "withheld_until_provenance",
        "record_modified_field": None,
        "refresh_mode": "full_candidate_no_record_modified_not_authorized",
        "customer_order_source_relation": "same_namespace_and_shop_only",
        "salesforce_dmo_relation": "unmapped_until_architect_verifies_typed_keys",
        "temporary_topology": "C_containment_candidate_only",
        "order_person_segmentation_ready": False,
        "data_360_ingestion_ready": False,
        "cart_recovery_ready": False,
    }
    if recommendation != safe:
        errors.append("g3_handoff_recommendation_drift")
    decisions = handoff.get("decisions_to_sign")
    if not isinstance(decisions, list) or len(decisions) != len(EXPECTED_DECISIONS):
        errors.append("g3_handoff_missing_owner_signoffs")
    else:
        for idx, (decision_id, role, required_false) in enumerate(EXPECTED_DECISIONS):
            item = decisions[idx]
            if not isinstance(item, dict) or item.get("id") != decision_id \
                    or item.get("approver_role") != role or item.get("status") != "pending":
                errors.append("g3_handoff_unverified_decision_" + decision_id)
                continue
            record = item.get("record")
            if not isinstance(record, dict) \
                    or any(record.get(flag) is not False for flag in required_false) \
                    or any(record.get(k) is not None for k in (
                        "approver_identity", "signed_at_utc", "evidence_reference"
                    )):
                errors.append("g3_handoff_forged_signoff_" + decision_id)
                continue
            if decision_id == "SCHEMA_AND_PREVIEW" and (
                record.get("approved_schema_path") is not None
                or record.get("approved_schema_sha256") is not None
            ):
                errors.append("g3_handoff_premature_schema_selection")
    perms = handoff.get("authorizations")
    if not isinstance(perms, dict) or set(perms) != set(DENIED) \
            or any(perms.get(k) is not False for k in DENIED):
        errors.append("g3_handoff_unauthorized_g4_or_marketing")
    return sorted(set(errors))


def main():
    try:
        obj = json.loads(
            (ROOT / "config/g3-abh-signoff-handoff.json").read_text("utf-8")
        )
        closure = json.loads(
            (ROOT / "config/g3-abh-technical-closure-candidate.json").read_text("utf-8")
        )
        content = (ROOT / SCHEMA).read_bytes()
    except (OSError, ValueError, UnicodeError):
        raise SystemExit("G3 handoff: unreadable source")
    errors = validate(obj, closure, content)
    if errors:
        for error in errors:
            print("G3 handoff FAIL: " + error)
        raise SystemExit(1)
    print("G3 handoff PASS: technical proposal frozen; "
          "3 formal signoffs pending; G4, ingestion and sends denied.")


if __name__ == "__main__":
    main()
