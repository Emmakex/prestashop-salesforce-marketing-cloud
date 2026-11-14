#!/usr/bin/env python3
"""Offline, fail-closed G3 technical closure -> G4 READINESS ONLY.

The user has already provided source configuration, timestamps and tenant
metadata. This contract does not reopen discovery, pretend to sign as a data
owner/architect, or access PrestaShop/Salesforce. It pins one conservative
review path and gives three precise residual approval actions.
"""
import hashlib
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
FILE = "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
DIGEST = "8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d"
STEPS = [
    "confirm_namespace_final_purposes_retention_and_privacy",
    "approve_customer_order_tenant_relationship_and_refresh",
    "approve_exact_schema_sha_and_PST_preview_without_publishing",
]
PROFILE_SCHEMA = {
    "version": "v0.2",
    "file": FILE,
    "sha256": DIGEST,
    "field_count": 22,
    "objects": ["PSCustomer", "PSOrder"],
    "reason": "exclude_unverified_historical_utc_field; data_owner_may_request_a_new_version",
    "final_schema_approved": False,
    "schema_to_publish": None,
}
PRIVACY = {
    "technical_disposition": "deny_external_data_and_sends_until_field_approval",
    "namespace_key_contract": "source_namespace:shop_id:entity_type:entity_id",
    "namespace_registry_operator_approved": False,
    "global_namespace_reserved_for_abh": False,
    "customer_email_name_export": "withheld",
    "raw_optins_are_marketing_consent": False,
    "historical_customer_order_backfill": "excluded",
    "raw_historical_datetime_as_record_modified": False,
    "new_observation_timestamp_utc_is_source_modified": False,
    "record_modified_field": None,
    "refresh_mode": "not_enabled",
    "retention_days": None,
    "privacy_owner_signoff": "pending",
    "approved_processing_purpose": None,
    "erasure_and_suppression_signoff": "pending",
    "export_to_data_360_authorized": False,
    "growth_sends_authorized": False,
}
RELATION = {
    "local_parent": "PSCustomer.source_key",
    "local_child": "PSOrder.customer_source_key",
    "match_dimensions": ["source_namespace", "shop_id"],
    "invalid_relationship_action": "quarantine_without_export",
    "join_by_email_authorized": False,
    "dmo_relation_strategy": "no_person_stitching_until_tenant_mapping_confirmed",
    "dmo_linked_target": None,
    "dmo_relation_approved": False,
    "data_360_architect_signoff": "pending",
    "dlo_dmo_mapping_save_authorized": False,
}
FINAL = {
    "technical_candidate_sha_verified_against_frozen_review": True,
    "field_names_verified_against_existing_inventory": True,
    "salesforce_pst_preview_confirmed_by_owner": False,
    "data_owner_schema_signoff": "pending",
    "data_360_architect_schema_signoff": "pending",
    "schema_approved": False,
    "g4_publication_authorized": False,
    "data_360_ingestion_authorized": False,
    "marketing_campaign_send_authorized": False,
}


def review(profile, snapshot, packet, field_owner, mapping, project, yaml_bytes):
    errors = []
    if not all(isinstance(obj, dict) for obj in
               (profile, snapshot, packet, field_owner, mapping, project)):
        return None, ["g3_technical_invalid_inputs"]

    exact = {
        "contract_version": 1,
        "merchant": "ABH",
        "product": "marketing_cloud_growth_data_360",
        "source": "existing_g3_evidence_not_new_discovery",
        "review_stage": "technical_closure_candidate_not_owner_approval",
        "selected_for_technical_review": PROFILE_SCHEMA,
        "point_1_data_privacy": PRIVACY,
        "point_2_customer_order_relationship": RELATION,
        "point_3_schema_finalization": FINAL,
        "only_remaining_owner_actions": STEPS,
        "production_impact": "none",
        "module_installed_version": "0.7.1",
        "module_mode": "test",
        "shop_sql_access_required": False,
        "new_module_installation_required": False,
    }
    for key, expected in exact.items():
        if profile.get(key) != expected:
            errors.append("g3_technical_profile_drift_" + key)

    # The pinned technical candidate must be exactly the frozen file bytes;
    # schema versions / hash cannot be substituted or silently rewritten.
    if not isinstance(yaml_bytes, bytes) or hashlib.sha256(yaml_bytes).hexdigest() != DIGEST:
        errors.append("g3_technical_candidate_hash_invalid")
        parsed = None
    else:
        try:
            parsed = yaml.safe_load(yaml_bytes)
        except yaml.YAMLError:
            parsed = None
    if snapshot.get("artifacts", {}).get(FILE, {}).get("sha256") != DIGEST:
        errors.append("g3_technical_frozen_hash_mismatch")
    if snapshot.get("owner_signoff") != "pending" or snapshot.get("approved_by") is not None \
            or snapshot.get("approved_at") is not None \
            or snapshot.get("schema_to_publish") != "undecided":
        errors.append("g3_technical_fingerprint_not_unsigned")
    if any(snapshot.get(k) is not False for k in
           ("data_360_upload_authorized", "data_360_ingestion_authorized",
            "growth_marketing_sends_authorized")):
        errors.append("g3_technical_snapshot_unapproved_gate_modified")

    if packet.get("schema_to_publish") != "undecided" \
            or packet.get("approval_state") != "pending_data_owner" \
            or packet.get("review_only") is not True \
            or packet.get("uploaded_to_tenant") is not False \
            or packet.get("streams_approved") is not False \
            or packet.get("real_data_ingestion_approved") is not False \
            or packet.get("marketing_sends_approved") is not False:
        errors.append("g3_technical_packet_gate_modified")
    decisions = packet.get("open_decisions")
    if not isinstance(decisions, list) or [
        (row.get("id"), row.get("state")) if isinstance(row, dict) else None
        for row in decisions
    ] != [("G3-%02d" % i, "pending") for i in range(1, 9)]:
        errors.append("g3_technical_owner_decisions_unverified")

    if field_owner.get("state") != "pending_data_privacy_owner" \
            or field_owner.get("purpose_signoff") != "pending" \
            or field_owner.get("retention_signoff") != "pending" \
            or field_owner.get("schema_selection") != "undecided":
        errors.append("g3_technical_privacy_signoff_not_pending")
    if any(field_owner.get(k) is not False for k in (
        "data_cloud_schema_upload_authorized",
        "data_cloud_ingestion_authorized",
        "marketing_sends_authorized",
    )):
        errors.append("g3_technical_privacy_permissions_changed")

    if mapping.get("owner_signoff") != "pending" \
            or mapping.get("schema_version_selected") != "undecided" \
            or mapping.get("dlo_dmo_mapping_save_authorized") is not False \
            or mapping.get("data_360_ingestion_authorized") is not False \
            or mapping.get("identity_resolution_authorized") is not False:
        errors.append("g3_technical_mapping_signoff_or_permission_changed")
    relation = mapping.get("relation_proposal")
    if not isinstance(relation, dict) \
            or relation.get("selected_dmo_target_field") is not None \
            or relation.get("dmo_relationship_verified_in_tenant") is not False \
            or relation.get("email_dedupe_authorized") is not False:
        errors.append("g3_technical_unverified_dmo_relationship")

    pilot = project.get("pilot")
    if not isinstance(pilot, dict) or any(pilot.get(k) != expected for k, expected in {
        "merchant": "ABH", "provider": "marketing_cloud_growth",
        "data_platform": "data_cloud_data_360",
        "engagement_for_pilot": False,
        "installed_module_version_observed": "0.7.1",
        "prestashop_runtime_mode_observed": "test",
        "data_cloud_schema_status": "schema_required_unapproved",
        "customer_data_live_ingestion_authorized": False,
        "live_campaign_sends_authorized": False,
        "historical_backfill_authorized": False,
    }.items()) or project.get("current_delivery_gate") != "pst_schema_data_owner_approval":
        errors.append("g3_technical_project_protection_changed")

    schema = parsed.get("components", {}).get("schemas") if isinstance(parsed, dict) else None
    inventory = packet.get("field_inventory")
    field_decisions = field_owner.get("field_decisions")
    if not isinstance(schema, dict) or set(schema) != {"PSCustomer", "PSOrder"} \
            or not isinstance(inventory, dict) or not isinstance(field_decisions, dict):
        errors.append("g3_technical_schema_inventory_invalid")
    else:
        count = 0
        for obj in ("PSCustomer", "PSOrder"):
            definition = schema[obj]
            fields = definition.get("properties") if isinstance(definition, dict) else None
            entries = inventory.get(obj)
            privacy = field_decisions.get(obj)
            if not isinstance(fields, dict) or not isinstance(entries, list) \
                    or not isinstance(privacy, list):
                errors.append("g3_technical_object_invalid_" + obj)
                continue
            count += len(fields)
            proposed = [entry.get("name") for entry in entries if isinstance(entry, dict)]
            reviewed = [entry.get("name") for entry in privacy if isinstance(entry, dict)]
            if len(proposed) != 12 or len(reviewed) != 12 \
                    or len(set(proposed)) != 12 or set(proposed) != set(reviewed) \
                    or set(fields) != set(proposed) - {"source_updated_utc"}:
                errors.append("g3_technical_field_inventory_mismatch_" + obj)
            if any(not isinstance(entry, dict)
                   or entry.get("decision") != "pending"
                   or entry.get("lawful_basis_verified") is not False
                   or entry.get("retention_approved") is not False
                   or entry.get("export_to_data_360_authorized") is not False
                   or entry.get("growth_send_authorized") is not False
                   for entry in privacy):
                errors.append("g3_technical_premature_field_approval_" + obj)
            # v0.2 is a REVIEW baseline. Its email/name properties still
            # exist in YAML, but MUST NOT be mistaken for export permission.
        if count != 22:
            errors.append("g3_technical_unreviewed_field_count")

    if errors:
        return None, sorted(set(errors))

    return {
        "stage": "g3_technical_review_consolidated",
        "schema_candidate": "v0.2_sha256_verified",
        "objects": ["PSCustomer", "PSOrder"],
        "technical_tracks_consolidated": 3,
        "remaining_owner_actions": STEPS,
        "signoffs_obtained": False,
        "g3_formally_closed": False,
        "g4_pst_publication_authorized": False,
        "data_360_ingestion_authorized": False,
        "marketing_sends_authorized": False,
        "next_engineering_work": "g4_read_only_preflight_without_publication",
    }, []


def load_and_review(root=ROOT):
    try:
        read = lambda path: json.loads((root / path).read_text("utf-8"))
        return review(
            read("config/g3-abh-technical-closure-candidate.json"),
            read("config/g3-pst-review-fingerprint-freeze.json"),
            read("config/g3-pst-schema-review.json"),
            read("config/g3-field-purpose-owner-decisions.json"),
            read("config/g3-pst-dlo-dmo-mapping-candidate.json"),
            read("config/project-contract.json"),
            (root / FILE).read_bytes(),
        )
    except (OSError, ValueError, UnicodeError):
        return None, ["g3_technical_source_not_readable"]


if __name__ == "__main__":
    report, failures = load_and_review()
    if failures:
        for failure in failures:
            print("G3 technical closure FAIL: " + failure)
        raise SystemExit(1)
    print(json.dumps(report, indent=2, sort_keys=True))
