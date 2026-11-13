#!/usr/bin/env python3
"""Offline, fail-closed G3 approval packet validator.

Checks review data vs both draft YAMLs. NEVER contacts Salesforce; neither
successful validation nor the manifest authorizes schema upload or ingestion.
"""
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
PACKET = ROOT / "config/g3-pst-schema-review.json"
OBJECTS = {"PSCustomer", "PSOrder"}
EXPECTED_DECISIONS = {"G3-01", "G3-02", "G3-03", "G3-04", "G3-05", "G3-06", "G3-07", "G3-08"}
SENSITIVITY = {
    "pseudonymous_identifier", "tenant_identifier", "source_identifier",
    "direct_personal_data", "raw_preference_flag", "source_state",
    "unqualified_timestamp", "commercial_data", "proposed_datetime",
}
NON_SENDABLE = {"newsletter_flag", "partner_optin_flag"}


def validate_packet(packet, baseline, candidate):
    errors = []
    if not isinstance(packet, dict):
        return ["packet_not_object"]
    for key, wanted in {
        "contract_version": 1, "merchant": "ABH",
        "target_product": "marketing_cloud_growth_data_360",
        "connector_alias": "PST", "source_api_name": "ABH_PrestaShop_Data_Cloud",
        "review_only": True, "approval_state": "pending_data_owner",
        "schema_to_publish": "undecided", "uploaded_to_tenant": False,
        "streams_approved": False, "real_data_ingestion_approved": False,
        "marketing_sends_approved": False, "field_decisions_are_proposals": True,
    }.items():
        if type(packet.get(key)) is not type(wanted) or packet.get(key) != wanted:
            errors.append("invalid_state_" + key)
    if packet.get("baseline_schema") != "schemas/ABH_PST_SCHEMA_REVIEW.yaml" or packet.get(
        "utc_candidate_schema"
    ) != "schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml":
        errors.append("schema_paths_changed")
    if packet.get("data_space_observed") != "default":
        errors.append("data_space_observation_changed")
    decisions = packet.get("open_decisions")
    if not isinstance(decisions, list) or {x.get("id") for x in decisions if isinstance(x, dict)} != EXPECTED_DECISIONS or len(decisions) != 8:
        errors.append("missing_or_duplicate_g3_decisions")
    else:
        for row in decisions:
            if row.get("state") != "pending" or not all(
                isinstance(row.get(k), str) and row[k].strip() for k in ("label", "owner", "evidence")
            ):
                errors.append("decision_not_documented_or_prematurely_approved")
    schema_defs = []
    for label, schema in (("v02", baseline), ("v03", candidate)):
        if not isinstance(schema, dict):
            errors.append("schema_" + label + "_invalid")
            schema_defs.append({})
            continue
        schema_defs.append(schema.get("components", {}).get("schemas", {}))
    prior, staged = schema_defs
    if set(prior) != OBJECTS or set(staged) != OBJECTS:
        errors.append("source_object_scope_changed")
        return errors
    inventory = packet.get("field_inventory")
    object_decisions = packet.get("object_decisions")
    if not isinstance(inventory, dict) or set(inventory) != OBJECTS or not isinstance(
        object_decisions, dict
    ) or set(object_decisions) != OBJECTS:
        return errors + ["packet_object_inventory_missing"]
    for obj in OBJECTS:
        proposal = object_decisions[obj]
        if not isinstance(proposal, dict):
            errors.append("object_proposal_missing_" + obj)
            continue
        if proposal.get("category_proposed") != ("Profile" if obj == "PSCustomer" else "Other"):
            errors.append("category_proposal_mismatch_" + obj)
        if proposal.get("primary_key_proposed") != "source_key":
            errors.append("primary_key_proposal_mismatch_" + obj)
        for field in ("record_modified_field", "refresh_mode", "dlo_dmo_mapping", "retention_policy", "privacy_signoff"):
            if proposal.get(field) != "pending":
                errors.append("unapproved_object_decision_" + obj + "_" + field)
        rows = inventory[obj]
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            errors.append("invalid_fields_" + obj)
            continue
        names = [r.get("name") for r in rows]
        if len(names) != len(set(names)) or len(names) != 12:
            errors.append("duplicate_or_missing_fields_" + obj)
        previous_fields = prior[obj].get("properties", {})
        candidate_fields = staged[obj].get("properties", {})
        if set(names) != set(candidate_fields) or set(previous_fields) != set(names) - {"source_updated_utc"}:
            errors.append("yaml_manifest_field_drift_" + obj)
        for row in rows:
            if row.get("sensitivity") not in SENSITIVITY or not isinstance(
                row.get("purpose"), str
            ) or not row["purpose"].strip() or not isinstance(
                row.get("review_note"), str
            ) or not row["review_note"].strip():
                errors.append("missing_field_provenance_" + obj)
        if not previous_fields.get("source_updated_local") == {"type": "string"}:
            errors.append("local_time_not_text_" + obj)
        if candidate_fields.get("source_updated_utc") != {"type": "string", "format": "date-time"}:
            errors.append("utc_candidate_not_date_time_" + obj)
        if "source_updated_utc" not in staged[obj].get("required", []):
            errors.append("utc_not_required_in_candidate_" + obj)
    customer = {r["name"]: r for r in inventory["PSCustomer"] if isinstance(r.get("name"), str)}
    for name in NON_SENDABLE:
        if customer.get(name, {}).get("sensitivity") != "raw_preference_flag" or customer.get(
            name, {}
        ).get("purpose") != "source_quality_only":
            errors.append("marketing_flag_misrepresented_" + name)
    for name in ("email", "first_name", "last_name"):
        if customer.get(name, {}).get("sensitivity") != "direct_personal_data":
            errors.append("customer_personal_data_unclassified_" + name)
    for obj in OBJECTS:
        fields = {r["name"]: r for r in inventory[obj] if isinstance(r.get("name"), str)}
        if fields.get("source_updated_utc", {}).get("sensitivity") != "proposed_datetime":
            errors.append("utc_provenance_gate_missing_" + obj)
        if fields.get("source_updated_local", {}).get("purpose") != "diagnostics_only":
            errors.append("local_timestamp_misused_" + obj)
    return errors


def read_packet(root=ROOT):
    return json.loads((root / "config/g3-pst-schema-review.json").read_text(encoding="utf-8"))


def read_schema(root, name):
    return yaml.safe_load((root / "schemas" / name).read_text(encoding="utf-8"))


def validate_repository(root=ROOT):
    try:
        p = read_packet(root)
        v02 = read_schema(root, "ABH_PST_SCHEMA_REVIEW.yaml")
        v03 = read_schema(root, "ABH_PST_SCHEMA_UTC_CANDIDATE.yaml")
    except (OSError, ValueError, yaml.YAMLError):
        return ["g3_review_packet_unreadable"]
    return validate_packet(p, v02, v03)


if __name__ == "__main__":
    issues = validate_repository()
    if issues:
        for problem in issues:
            print("G3 review packet FAIL: " + problem)
        raise SystemExit(1)
    print("G3 review packet PASS: 24 candidate fields, 8 pending decisions; NO schema approval or ingestion.")
