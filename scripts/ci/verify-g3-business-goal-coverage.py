#!/usr/bin/env python3
"""ABH G3-08 business-goal coverage preflight; offline and fail-closed.

This is NOT the already-existing YAML validity/fingerprint validator.
It answers a distinct delivery question: does the schema under owner review
cover customer campaigns, abandoned carts and purchase suppression?
No Salesforce requests, account/PII access, status mutations or signoffs.
"""
import hashlib
import json
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
SCHEMAS = {
    "v0.2": "schemas/ABH_PST_SCHEMA_REVIEW.yaml",
    "v0.3": "schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml",
}
EXPECTED_GATES = ["G3-%02d" % x for x in range(1, 9)]
EXACT_OBJECTS = {"PSCustomer", "PSOrder"}
FIELD_COUNTS = {"v0.2": 22, "v0.3": 24}


def review(packet, freeze, field_owner, mapping, contract, schema_docs, blobs):
    errors = []
    if not all(isinstance(x, dict) for x in (packet, freeze, field_owner, mapping, contract)):
        return None, ["g3_goals_invalid_manifest"]

    pilot = contract.get("pilot", {})
    if not isinstance(pilot, dict) or any(pilot.get(k) != value for k, value in {
        "merchant": "ABH",
        "provider": "marketing_cloud_growth",
        "data_platform": "data_cloud_data_360",
        "engagement_for_pilot": False,
        "prestashop_runtime_mode_observed": "test",
        "data_cloud_schema_status": "schema_required_unapproved",
        "customer_data_live_ingestion_authorized": False,
        "live_campaign_sends_authorized": False,
        "historical_backfill_authorized": False,
    }.items()):
        errors.append("g3_goals_wrong_provider_or_pilot_state")
    if contract.get("current_delivery_gate") != "pst_schema_data_owner_approval":
        errors.append("g3_goals_unexpected_delivery_gate")

    if (packet.get("merchant") != "ABH"
        or packet.get("target_product") != "marketing_cloud_growth_data_360"
        or packet.get("approval_state") != "pending_data_owner"
        or packet.get("schema_to_publish") != "undecided"
        or packet.get("review_only") is not True):
        errors.append("g3_goals_packet_not_pending")
    for key in ("uploaded_to_tenant", "streams_approved", "real_data_ingestion_approved",
                "marketing_sends_approved"):
        if packet.get(key) is not False:
            errors.append("g3_goals_packet_gate_" + key)

    decisions = packet.get("open_decisions")
    if (not isinstance(decisions, list)
        or [x.get("id") if isinstance(x, dict) else None for x in decisions] != EXPECTED_GATES
        or any(not isinstance(x, dict) or x.get("state") != "pending" for x in decisions)):
        errors.append("g3_goals_owner_gates_not_pending")

    if (freeze.get("owner_signoff") != "pending"
        or freeze.get("schema_to_publish") != "undecided"
        or freeze.get("review_only") is not True
        or freeze.get("approved_by") is not None
        or freeze.get("approved_at") is not None):
        errors.append("g3_goals_fingerprint_not_unsigned")
    for key in ("data_360_upload_authorized", "data_360_ingestion_authorized",
                "growth_marketing_sends_authorized"):
        if freeze.get(key) is not False:
            errors.append("g3_goals_fingerprint_gate_" + key)

    if (field_owner.get("state") != "pending_data_privacy_owner"
        or field_owner.get("purpose_signoff") != "pending"
        or field_owner.get("retention_signoff") != "pending"
        or field_owner.get("schema_selection") != "undecided"):
        errors.append("g3_goals_field_privacy_unsigned")
    for key in ("data_cloud_schema_upload_authorized", "data_cloud_ingestion_authorized",
                "marketing_sends_authorized"):
        if field_owner.get(key) is not False:
            errors.append("g3_goals_privacy_gate_" + key)

    field_manifest = field_owner.get("field_decisions")
    if not isinstance(field_manifest, dict) or set(field_manifest) != EXACT_OBJECTS:
        errors.append("g3_goals_invalid_field_inventory")
    else:
        for obj in sorted(EXACT_OBJECTS):
            fields = field_manifest[obj]
            if not isinstance(fields, list) or len(fields) != 12:
                errors.append("g3_goals_invalid_12_fields_" + obj)
                continue
            if any(not isinstance(f, dict)
                   or f.get("decision") != "pending"
                   or f.get("export_to_data_360_authorized") is not False
                   or f.get("growth_send_authorized") is not False
                   or f.get("retention_approved") is not False
                   or f.get("lawful_basis_verified") is not False for f in fields):
                errors.append("g3_goals_unapproved_field_" + obj)

    if (mapping.get("merchant") != "ABH"
        or mapping.get("target_product") != "marketing_cloud_growth_data_360"
        or mapping.get("schema_version_selected") != "undecided"
        or mapping.get("owner_signoff") != "pending"
        or mapping.get("tenant_dmo_catalog_verified") is not False):
        # A real *metadata read* is not an approved PST DLO->DMO mapping.
        errors.append("g3_goals_mapping_not_unsigned")
    for key in ("dlo_dmo_mapping_save_authorized", "identity_resolution_authorized",
                "data_360_ingestion_authorized", "growth_marketing_sends_authorized"):
        if mapping.get(key) is not False:
            errors.append("g3_goals_mapping_gate_" + key)
    rel = mapping.get("relation_proposal")
    if (not isinstance(rel, dict)
        or rel.get("selected_dmo_target_field") is not None
        or rel.get("dmo_relationship_verified_in_tenant") is not False
        or rel.get("email_dedupe_authorized") is not False
        or rel.get("cart_stream_relationship_authorized") is not False):
        errors.append("g3_goals_unapproved_customer_order_join")

    if not isinstance(schema_docs, dict) or set(schema_docs) != set(SCHEMAS):
        return None, errors + ["g3_goals_missing_schema_versions"]
    if not isinstance(blobs, dict) or set(blobs) != set(SCHEMAS):
        return None, errors + ["g3_goals_missing_schema_files"]

    actual = {}
    frozen = freeze.get("artifacts", {})
    if not isinstance(frozen, dict):
        frozen = {}
    for version, filename in SCHEMAS.items():
        doc, raw = schema_docs.get(version), blobs.get(version)
        if not isinstance(doc, dict) or not isinstance(raw, bytes):
            errors.append("g3_goals_invalid_schema_" + version)
            continue
        objects = doc.get("components", {}).get("schemas", {})
        if not isinstance(objects, dict) or set(objects) != EXACT_OBJECTS:
            errors.append("g3_goals_unreviewed_object_added_" + version)
            continue
        if any(not isinstance(row, dict)
               or not isinstance(row.get("properties"), dict)
               for row in objects.values()):
            errors.append("g3_goals_invalid_properties_" + version)
            continue
        count = sum(len(row["properties"]) for row in objects.values())
        if count != FIELD_COUNTS[version]:
            errors.append("g3_goals_field_count_drift_" + version)
        frozen_item = frozen.get(filename, {})
        digest = hashlib.sha256(raw).hexdigest()
        if not isinstance(frozen_item, dict) or frozen_item.get("sha256") != digest:
            errors.append("g3_goals_schema_not_frozen_" + version)
        actual[version] = {"source_objects": sorted(objects), "field_count": count,
                           "sha256": digest, "cart_stream_defined": False}

    if errors:
        return None, errors

    # Fail-closed business capability map: the current v0.2/v0.3 drafts
    # are only customer/order *source* objects. Existing local sfmc_cart
    # does not automatically create a Salesforce PSCart Data Stream, and
    # a native PrestaShop conversion check is not a certified Growth send.
    return {
        "merchant": "ABH",
        "product": "marketing_cloud_growth_data_360",
        "status": "review_only_not_ready_for_g3_08",
        "schema_selection": "undecided",
        "g3_decisions_pending": EXPECTED_GATES,
        "candidate_schemas": actual,
        "business_capabilities": {
            "customer_marketing_campaign": {
                "status": "blocked",
                "reason": "identity_purpose_consent_suppression_and_growth_sends_unapproved",
            },
            "abandoned_cart_recovery": {
                "status": "blocked",
                "reason": "no_pst_cart_source_schema_or_approved_growth_cart_flow",
            },
            "customer_order_segmentation_in_data_360": {
                "status": "blocked",
                "reason": "order_person_dmo_mapping_not_verified_or_approved",
            },
            "converted_cart_suppression": {
                "status": "local_source_precheck_candidate_only",
                "reason": "native_prestashop_order_recheck_not_growth_end_to_end_certification",
            },
        },
        "g3_08_schema_approval_ready": False,
        "g4_schema_upload_authorized": False,
        "data_360_ingestion_authorized": False,
        "growth_campaign_send_authorized": False,
    }, []


def validate_repository(root=ROOT):
    try:
        packet = json.loads((root / "config/g3-pst-schema-review.json").read_text("utf-8"))
        freeze = json.loads((root / "config/g3-pst-review-fingerprint-freeze.json").read_text("utf-8"))
        field_owner = json.loads((root / "config/g3-field-purpose-owner-decisions.json").read_text("utf-8"))
        mapping = json.loads((root / "config/g3-pst-dlo-dmo-mapping-candidate.json").read_text("utf-8"))
        contract = json.loads((root / "config/project-contract.json").read_text("utf-8"))
        blobs = {v: (root / path).read_bytes() for v, path in SCHEMAS.items()}
        docs = {v: yaml.safe_load(blob) for v, blob in blobs.items()}
    except (OSError, ValueError, UnicodeError, yaml.YAMLError):
        return None, ["g3_goals_sources_unreadable"]
    return review(packet, freeze, field_owner, mapping, contract, docs, blobs)


if __name__ == "__main__":
    report, problems = validate_repository()
    if problems:
        for problem in problems:
            print("G3 business coverage FAIL: " + problem)
        raise SystemExit(1)
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
