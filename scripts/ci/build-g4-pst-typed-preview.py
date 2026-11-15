#!/usr/bin/env python3
"""G4: produce an offline, parser-compatible PST v0.4 preview (never upload).

G3 owner direction is acknowledged once. This script has NO Salesforce client,
HTTP request, SQL, tokens, customer records, event worker or send capability.
It does not turn owner acceptance into missing field-level/legal or tenant proof.
"""
import copy
import hashlib
import json
import os
import re
import subprocess
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
BASE_PATH = "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
TYPED_PATH = "schemas/ABH_PST_TYPED_IDENTITY_CANDIDATE.yaml"
ACCEPTANCE_PATH = "config/g3-owner-acceptance.json"
BASE_SHA = "8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d"
TYPED_SHA = "2a0eb79ebb664c9465374584a033b75828f0b180e2a28af5558e7cbb1ba8dd0f"
OBJECTS = ("PSCustomer", "PSOrder")
OWNER_KEYS = (
    "data_privacy_direction",
    "typed_customer_order_architecture_direction",
    "schema_review_direction",
)
DENIED_KEYS = (
    "third_party_signatures_inferred",
    "field_level_lawful_bases_invented",
    "pst_preview_performed_in_salesforce",
    "schema_upload_authorized",
    "data_cloud_ingestion_authorized",
    "marketing_sends_authorized",
    "prestashop_production_mode_authorized",
)
OUTPUT_DIR = ROOT / "build" / "g4-pst-typed-preview"


def _sha(value):
    if not isinstance(value, bytes):
        raise ValueError("g4_expected_bytes")
    return hashlib.sha256(value).hexdigest()


def build_preview(frozen_bytes, typed_bytes, acceptance):
    if _sha(frozen_bytes) != BASE_SHA or _sha(typed_bytes) != TYPED_SHA:
        raise ValueError("g4_source_schema_hash_changed")
    if not isinstance(acceptance, dict) or (
        acceptance.get("contract_version") != 1
        or acceptance.get("merchant") != "ABH"
        or acceptance.get("product") != "marketing_cloud_growth_data_cloud"
        or acceptance.get("evidence_kind") != "explicit_owner_statement_in_project_conversation"
        or acceptance.get("next_development_gate") != "g4_typed_pst_schema_preview_offline"
        or acceptance.get("schema_prepared_for_preview") != "v0.4"
        or acceptance.get("schema_source_sha256") != TYPED_SHA
        or acceptance.get("owner_accepts_g3_decisions") != {
            key: True for key in OWNER_KEYS
        }
        or acceptance.get("limits") != {key: False for key in DENIED_KEYS}
    ):
        raise ValueError("g4_owner_acceptance_or_safety_invalid")

    base = yaml.safe_load(frozen_bytes)
    typed = yaml.safe_load(typed_bytes)
    if not isinstance(base, dict) or not isinstance(typed, dict):
        raise ValueError("g4_schema_not_object")
    if base.get("openapi") != "3.0.3" or typed.get("openapi") != "3.0.3":
        raise ValueError("g4_invalid_openapi_version")
    if base.get("paths") != {} or typed.get("paths") != {}:
        raise ValueError("g4_schema_routes_forbidden")
    original = base.get("components", {}).get("schemas", {})
    proposed = typed.get("components", {}).get("schemas", {})
    if set(original) != set(OBJECTS) or set(proposed) != set(OBJECTS):
        raise ValueError("g4_only_customer_order")
    field_count = 0
    for name in OBJECTS:
        previous, candidate = original[name], proposed[name]
        if previous.get("type") != "object" or candidate.get("type") != "object":
            raise ValueError("g4_schema_object_type_invalid")
        if candidate.get("required") != previous.get("required"):
            raise ValueError("g4_required_fields_drift")
        base_fields = previous.get("properties", {})
        typed_fields = candidate.get("properties", {})
        if list(base_fields) != list(typed_fields):
            raise ValueError("g4_field_inventory_or_order_changed")
        for field, prior in base_fields.items():
            proposed_field = typed_fields[field]
            if not isinstance(proposed_field, dict) or (
                set(proposed_field) - {"type", "format", "pattern", "description"}
            ) or (proposed_field.get("type") != prior.get("type")) or (
                proposed_field.get("format") != prior.get("format")
            ):
                raise ValueError("g4_schema_field_type_drift")
            # The preview strips review-only pattern/description. The PHP v0.4
            # mapper's typed value-level PK/FK checks remain separately tested.
            field_count += 1
    if field_count != 22:
        raise ValueError("g4_expected_22_fields")
    for name, field, identifier in (
        ("PSCustomer", "source_key", ":customer:"),
        ("PSOrder", "source_key", ":order:"),
        ("PSOrder", "customer_source_key", ":customer:"),
        ("PSOrder", "cart_source_key", ":cart:"),
    ):
        if identifier not in proposed[name]["properties"][field].get("pattern", ""):
            raise ValueError("g4_missing_typed_identity_constraint")
    # Preserve the accepted flat OpenAPI field contract. No additional
    # objects, cart stream, route, PII values or Salesforce calls.
    output = copy.deepcopy(base)
    output["info"] = {
        "title": "ABH PST v0.4 typed keys - offline G4 review only",
        "version": "0.4.0-review",
    }
    generated = yaml.safe_dump(
        output, allow_unicode=False, sort_keys=False
    ).encode("utf-8")
    manifest = {
        "stage": "g4_offline_pst_schema_preview",
        "product": "marketing_cloud_growth_data_cloud",
        "version": "v0.4",
        "source_sha256": TYPED_SHA,
        "baseline_sha256": BASE_SHA,
        "preview_sha256": _sha(generated),
        "objects": list(OBJECTS),
        "field_count": field_count,
        "owner_acceptance_recorded": True,
        "legal_and_tenant_signoff_inferred": False,
        "schema_upload_authorized": False,
        "data_cloud_ingestion_authorized": False,
        "marketing_sends_authorized": False,
        "production_mode_authorized": False,
    }
    return generated, manifest


def validate_synthetic_payload(preview_bytes, fixture):
    """Proof that the actual PHP v0.4 payload matches the preview field contract.

    This is local synthetic parity, not an API roundtrip or consent approval.
    """
    schema = yaml.safe_load(preview_bytes)
    definitions = schema["components"]["schemas"]
    if not isinstance(fixture, dict) or set(fixture) != set(OBJECTS):
        raise ValueError("g4_payload_object_set_drift")
    for name in OBJECTS:
        payload = fixture[name]
        if not isinstance(payload, dict) or set(payload) != {"data"} or (
            not isinstance(payload["data"], list) or len(payload["data"]) != 1
            or not isinstance(payload["data"][0], dict)
        ):
            raise ValueError("g4_payload_envelope_invalid")
        row = payload["data"][0]
        fields = definitions[name]["properties"]
        if list(row) != list(fields):
            raise ValueError("g4_payload_field_order_or_names_mismatch")
        for key, spec in fields.items():
            value = row[key]
            typ = spec["type"]
            if not ((typ == "string" and isinstance(value, str))
                    or (typ == "boolean" and type(value) is bool)
                    or (typ == "number" and type(value) in (int, float))):
                raise ValueError("g4_payload_field_type_mismatch")
        namespace = row["source_namespace"]
        shop = row["shop_id"]
        entity = "customer" if name == "PSCustomer" else "order"
        identifier = row["customer_id"] if entity == "customer" else row["order_id"]
        if not (re.fullmatch(r"[a-z][a-z0-9_]{5,31}", namespace)
                and type(shop) is int and shop > 0
                and type(identifier) is int and identifier > 0
                and row["source_key"] == f"{namespace}:{shop}:{entity}:{identifier}"):
            raise ValueError("g4_payload_not_typed_or_scoped")
        if name == "PSCustomer" and (
            row["email"] != "synthetic@fixture.example.invalid"
            or row["first_name"] != "Synthetic"
            or row["last_name"] != "Fixture"
        ):
            raise ValueError("g4_payload_not_synthetic")
        if name == "PSOrder":
            parent = fixture["PSCustomer"]["data"][0]
            if row["customer_source_key"] != parent["source_key"] or (
                row["cart_source_key"] != f"{namespace}:{shop}:cart:91"
            ) or row["paid_flag"] is not False:
                raise ValueError("g4_payload_fk_or_paid_semantics_invalid")
        if "source_updated_utc" in row or "marketing_consent" in row:
            raise ValueError("g4_payload_fabricated_utc_or_consent")
    return True


def main():
    frozen = (ROOT / BASE_PATH).read_bytes()
    typed = (ROOT / TYPED_PATH).read_bytes()
    owner = json.loads((ROOT / ACCEPTANCE_PATH).read_text("utf-8"))
    generated, manifest = build_preview(frozen, typed, owner)
    # The actual PHP v0.4 encoder must match this YAML on every supported
    # PrestaShop family; metadata-only comparisons are not enough.
    fixtures = []
    for ps_version in ("1.7.8.11", "8.2.8", "9.1.5"):
        env = dict(os.environ)
        env["G4_PST_FIXTURE_PS_VERSION"] = ps_version
        raw = subprocess.check_output(
            ["php", str(ROOT / "tests/g4-pst-typed-payload-fixture.php")],
            cwd=str(ROOT), env=env
        )
        fixture = json.loads(raw)
        validate_synthetic_payload(generated, fixture)
        fixtures.append(fixture)
    if fixtures[0] != fixtures[1] or fixtures[0] != fixtures[2]:
        raise ValueError("g4_payload_prestashop_family_drift")
    manifest["typed_payload_parity"] = "verified_synthetic_3_prestashop_families"
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    (OUTPUT_DIR / "pst-v04-g4-preview-only.yaml").write_bytes(generated)
    (OUTPUT_DIR / "manifest.json").write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print("G4 PST typed preview PASS: v0.4 / 2 objects / 22 fields; "
          "owner direction registered; no upload, ingestion or sends.")
    print("Review artifact: build/g4-pst-typed-preview/")


if __name__ == "__main__":
    main()
