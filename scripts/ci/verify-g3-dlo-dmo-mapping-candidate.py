#!/usr/bin/env python3
"""G3-05/06: fail-closed Data 360 mapping PREVIEW, never tenant mapping."""
import json
from pathlib import Path
import yaml

ROOT = Path(__file__).resolve().parents[2]
CANDIDATE = "config/g3-pst-dlo-dmo-mapping-candidate.json"
REVIEW = "config/g3-pst-schema-review.json"
SCHEMA_02 = "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
SCHEMA_03 = "schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml"

EXPECTED_DMOS = {
    "PSCustomer": ("Profile", "ssot__Individual__dlm"),
    "PSOrder": ("Other", "ssot__SalesOrder__dlm"),
}
EXPECTED_REF = {
    "source_foreign_key": "PSOrder.customer_source_key",
    "source_parent_key": "PSCustomer.source_key",
    "cardinality": "one_customer_to_many_orders_candidate",
    "match_dimensions": ["source_namespace", "shop_id", "customer_source_key"],
    "dmo_target_field_options": [
        "ssot__SoldToCustomerId__c", "ssot__BillToContactId__c"
    ],
    "selected_dmo_target_field": None,
    "dmo_relationship_verified_in_tenant": False,
    "missing_parent_action": "quarantine_not_merge",
    "empty_foreign_key_action": "quarantine_not_merge",
    "cross_scope_action": "quarantine_not_merge",
    "email_dedupe_authorized": False,
    "cart_stream_relationship_authorized": False,
    "automatic_identity_stitching_authorized": False,
}
EXPECTED_ROOT = {
    "contract_version": 1,
    "merchant": "ABH",
    "target_product": "marketing_cloud_growth_data_360",
    "connector_alias": "PST",
    "source_api_name": "ABH_PrestaShop_Data_Cloud",
    "schema_version_selected": "undecided",
    "data_space_observed": "default",
    "data_space_approved": False,
    "owner_signoff": "pending",
    "tenant_dmo_catalog_verified": False,
    "dlo_dmo_mapping_save_authorized": False,
    "identity_resolution_authorized": False,
    "data_360_ingestion_authorized": False,
    "growth_marketing_sends_authorized": False,
}
REFS = [
    "https://developer.salesforce.com/docs/data/data-cloud-ref/guide/c360dm-individual-dmo.html",
    "https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-sales-order-dmo.html",
    "https://help.salesforce.com/s/articleView?id=c360_a_data_mapping.htm&type=5",
]
OBJECT_KEYS = {
    "data_stream_category_proposal", "dlo_primary_key_proposal",
    "dmo_candidate", "dmo_primary_key_candidate",
    "dmo_primary_key_mapping_verified_in_tenant",
    "field_dispositions", "category_approved", "mapping_approved",
}


def validate(mapping, inventory, schemas):
    errors = []
    if not isinstance(mapping, dict) or not isinstance(inventory, dict):
        return ["g3_mapping_invalid_source"]
    if set(mapping) != set(EXPECTED_ROOT) | {"objects", "relation_proposal", "vendor_refs"}:
        errors.append("g3_mapping_root_scope_changed")
    for name, wanted in EXPECTED_ROOT.items():
        value = mapping.get(name)
        if type(value) is not type(wanted) or value != wanted:
            errors.append("g3_mapping_gate_invalid_" + name)
    if mapping.get("vendor_refs") != REFS:
        errors.append("g3_mapping_sources_changed")
    if mapping.get("relation_proposal") != EXPECTED_REF:
        errors.append("g3_mapping_relation_approval_or_scope_changed")
    if inventory.get("review_only") is not True or inventory.get("approval_state") != "pending_data_owner":
        errors.append("g3_mapping_inventory_approved_without_owner")
    if inventory.get("schema_to_publish") != "undecided":
        errors.append("g3_mapping_schema_selected_without_owner")
    proposal = mapping.get("objects")
    field_inventory = inventory.get("field_inventory")
    if not isinstance(proposal, dict) or set(proposal) != set(EXPECTED_DMOS):
        return errors + ["g3_mapping_objects_changed"]
    if not isinstance(field_inventory, dict) or set(field_inventory) != set(EXPECTED_DMOS):
        return errors + ["g3_mapping_inventory_objects_changed"]
    if not isinstance(schemas, dict) or set(schemas) != {"v0.2", "v0.3"}:
        return errors + ["g3_mapping_schema_versions_changed"]

    for obj, (category, dmo) in EXPECTED_DMOS.items():
        item = proposal[obj]
        if not isinstance(item, dict) or set(item) != OBJECT_KEYS:
            errors.append("g3_mapping_object_fields_changed_" + obj)
            continue
        if item.get("data_stream_category_proposal") != category:
            errors.append("g3_mapping_category_changed_" + obj)
        if item.get("dlo_primary_key_proposal") != "source_key":
            errors.append("g3_mapping_dlo_pk_changed_" + obj)
        if item.get("dmo_candidate") != dmo or item.get("dmo_primary_key_candidate") != "ssot__Id__c":
            errors.append("g3_mapping_dmo_target_changed_" + obj)
        for gate in ("dmo_primary_key_mapping_verified_in_tenant",
                     "category_approved", "mapping_approved"):
            if item.get(gate) is not False:
                errors.append("g3_mapping_premature_approval_" + obj)
        rows = field_inventory[obj]
        if not isinstance(rows, list) or len(rows) != 12 or any(not isinstance(row, dict) for row in rows):
            errors.append("g3_mapping_inventory_malformed_" + obj)
            continue
        names = [row.get("name") for row in rows]
        expected = {field: (
            "pk_candidate_no_dmo_write" if field == "source_key"
            else "foreign_reference_candidate_no_dmo_write" if obj == "PSOrder" and field == "customer_source_key"
            else "withheld_pending_owner"
        ) for field in names}
        if not isinstance(item.get("field_dispositions"), dict) or item["field_dispositions"] != expected:
            errors.append("g3_mapping_field_dispositions_changed_" + obj)
        for version, schema in schemas.items():
            try:
                properties = schema["components"]["schemas"][obj]["properties"]
                required = schema["components"]["schemas"][obj]["required"]
            except (KeyError, TypeError):
                errors.append("g3_mapping_yaml_shape_invalid_" + obj)
                continue
            allowed = names if version == "v0.3" else [
                field for field in names if field != "source_updated_utc"
            ]
            if list(properties) != allowed or "source_key" not in required:
                errors.append("g3_mapping_yaml_field_or_pk_drift_" + obj + "_" + version)
    return errors


def validate_files(root=ROOT):
    try:
        mapping = json.loads((root / CANDIDATE).read_text("utf-8"))
        inventory = json.loads((root / REVIEW).read_text("utf-8"))
        schemas = {
            "v0.2": yaml.safe_load((root / SCHEMA_02).read_text("utf-8")),
            "v0.3": yaml.safe_load((root / SCHEMA_03).read_text("utf-8")),
        }
    except (OSError, ValueError, UnicodeError, yaml.YAMLError):
        return ["g3_mapping_sources_unreadable"]
    return validate(mapping, inventory, schemas)


if __name__ == "__main__":
    issues = validate_files()
    if issues:
        for issue in issues:
            print("G3 DLO/DMO candidate FAIL: " + issue)
        raise SystemExit(1)
    print("G3 DLO/DMO review PASS: 24 source fields accounted, 2 DMO PK proposals, 0 mappings authorized.")
