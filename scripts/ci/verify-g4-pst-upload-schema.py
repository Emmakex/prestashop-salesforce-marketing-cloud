#!/usr/bin/env python3
"""G4 PST schema release guard: validate the exact YAML for the existing connector.

No Salesforce client, network, customer data, tokens or tenant mutation. The
single-purpose artifact is handed to the tenant admin for explicit UI preview.
"""
import hashlib
import importlib.util
import json
import os
import subprocess
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
UPLOADED = ROOT / "schemas/ABH_PST_G4_SCHEMA_UPLOAD.yaml"
OUTDIR = ROOT / "build/g4-pst-typed-preview"
GENERATOR = ROOT / "scripts/ci/build-g4-pst-typed-preview.py"

spec = importlib.util.spec_from_file_location("g4_preview_generator", GENERATOR)
g4 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g4)


def check_release(release_bytes, preview_bytes, acceptance):
    if not isinstance(release_bytes, bytes):
        raise ValueError("g4_upload_schema_expected_bytes")
    if not isinstance(acceptance, dict) or (
        acceptance.get("owner_accepts_g3_decisions") != {
            key: True for key in g4.OWNER_KEYS
        } or acceptance.get("limits") != {
            key: False for key in g4.DENIED_KEYS
        }
    ):
        raise ValueError("g4_upload_owner_record_invalid")
    doc = yaml.safe_load(release_bytes)
    preview = yaml.safe_load(preview_bytes)
    if not isinstance(doc, dict) or set(doc) != {
        "openapi", "info", "paths", "components"
    } or doc["openapi"] != "3.0.3" or doc["paths"] != {}:
        raise ValueError("g4_upload_oas_structure_invalid")
    if doc["info"] != {
        "title": "ABH PrestaShop Data Cloud PST Typed Identity v0.4",
        "version": "0.4.0",
    }:
        raise ValueError("g4_upload_version_invalid")
    if doc["components"] != preview["components"]:
        raise ValueError("g4_upload_fields_not_exact_preview")
    objects = doc["components"]["schemas"]
    if set(objects) != {"PSCustomer", "PSOrder"}:
        raise ValueError("g4_upload_object_set_invalid")
    for name, spec in objects.items():
        if spec["type"] != "object" or (
            name == "PSCustomer" and "source_key" not in spec["required"]
        ) or (
            name == "PSOrder" and "source_key" not in spec["required"]
        ):
            raise ValueError("g4_upload_identity_not_required")
    # This file defines data shape only; stream categories, Record Modified,
    # refresh semantics, PK selection, DLO->DMO maps and Data Space are NOT
    # implicitly configured or claimed by the schema.
    return {
        "stage": "g4_pst_v04_upload_schema_ready_for_ui_preview",
        "source": "ABH_PrestaShop_Data_Cloud",
        "schema_sha256": hashlib.sha256(release_bytes).hexdigest(),
        "candidate_sha256": g4.TYPED_SHA,
        "objects": ["PSCustomer", "PSOrder"],
        "fields": 22,
        "owner_direction_accepted": True,
        "uploaded_to_salesforce": False,
        "data_streams_deployed": False,
        "dlo_dmo_mapping_deployed": False,
        "data_cloud_ingestion_authorized": False,
        "marketing_sends_authorized": False,
    }


def main():
    base = (ROOT / g4.BASE_PATH).read_bytes()
    typed = (ROOT / g4.TYPED_PATH).read_bytes()
    owner = json.loads((ROOT / g4.ACCEPTANCE_PATH).read_text("utf-8"))
    preview, _ = g4.build_preview(base, typed, owner)
    release = UPLOADED.read_bytes()
    manifest = check_release(release, preview, owner)
    for version in ("1.7.8.11", "8.2.8", "9.1.5"):
        env = dict(os.environ)
        env["G4_PST_FIXTURE_PS_VERSION"] = version
        fixture = json.loads(subprocess.check_output(
            ["php", str(ROOT / "tests/g4-pst-typed-payload-fixture.php")],
            cwd=str(ROOT), env=env
        ))
        g4.validate_synthetic_payload(release, fixture)
    OUTDIR.mkdir(parents=True, exist_ok=True)
    (OUTDIR / "ABH_PST_G4_SCHEMA_UPLOAD.yaml").write_bytes(release)
    (OUTDIR / "handoff.json").write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print("G4 PST upload handoff PASS: 2 objects, 22 fields, "
          "typed payload parity in PS 1.7/8/9, SHA256=" + manifest["schema_sha256"])
    print("Schema only; no Salesforce UI action, data streams, records or sends.")


if __name__ == "__main__":
    main()
