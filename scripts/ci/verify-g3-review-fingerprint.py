#!/usr/bin/env python3
"""Fail-closed, offline SHA256 snapshot of G3 draft review artifacts.

This verifies review integrity, not human authorization. Never connects to
Salesforce, PrestaShop, a database, or an outside service.
"""
import hashlib
import importlib.util
import json
import re
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
FREEZE = ROOT / "config/g3-pst-review-fingerprint-freeze.json"
REVIEW = ROOT / "scripts/ci/verify-g3-schema-review.py"
spec = importlib.util.spec_from_file_location("g3_review_validator", REVIEW)
review_validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review_validator)

SOURCES = {
    "schemas/ABH_PST_SCHEMA_REVIEW.yaml": ("v0.2", 22),
    "schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml": ("v0.3", 24),
    "config/g3-pst-schema-review.json": ("pending_owner_review", 24),
}
DECISIONS = ["G3-%02d" % i for i in range(1, 9)]
HEX_SHA256 = re.compile(r"^[a-f0-9]{64}$")


def validate_snapshot(freeze, data):
    problems = []
    if not isinstance(freeze, dict):
        return ["invalid_freeze"]
    expected_metadata = {
        "contract_version": 1,
        "merchant": "ABH",
        "target_product": "marketing_cloud_growth_data_360",
        "connector_alias": "PST",
        "review_only": True,
        "owner_signoff": "pending",
        "approved_by": None,
        "approved_at": None,
        "schema_to_publish": "undecided",
        "data_360_upload_authorized": False,
        "data_360_ingestion_authorized": False,
        "growth_marketing_sends_authorized": False,
        "open_decision_ids": DECISIONS,
    }
    for key, expected in expected_metadata.items():
        actual = freeze.get(key)
        if type(actual) is not type(expected) or actual != expected:
            problems.append("unauthorized_state_" + key)
    files = freeze.get("artifacts")
    if not isinstance(files, dict) or set(files) != set(SOURCES):
        return problems + ["review_artifact_list_mismatch"]
    if not isinstance(data, dict) or set(data) != set(SOURCES):
        return problems + ["review_file_set_mismatch"]

    parsed = {}
    for path, (version, field_count) in SOURCES.items():
        record = files.get(path)
        blob = data.get(path)
        if not isinstance(record, dict) or set(record) != {"sha256", "candidate_version", "field_count"}:
            problems.append("invalid_file_record_" + path)
            continue
        if record.get("candidate_version") != version or type(record.get("field_count")) is not int or record["field_count"] != field_count:
            problems.append("field_or_version_drift_" + path)
        digest = record.get("sha256")
        if not isinstance(digest, str) or not HEX_SHA256.fullmatch(digest):
            problems.append("invalid_digest_" + path)
        if not isinstance(blob, bytes):
            problems.append("file_unreadable_" + path)
            continue
        if hashlib.sha256(blob).hexdigest() != digest:
            problems.append("file_fingerprint_changed_" + path)
        try:
            parsed[path] = json.loads(blob) if path.endswith(".json") else yaml.safe_load(blob)
        except (ValueError, yaml.YAMLError, UnicodeDecodeError):
            problems.append("file_parse_failed_" + path)

    if len(parsed) != len(SOURCES):
        return problems
    packet = parsed["config/g3-pst-schema-review.json"]
    v02 = parsed["schemas/ABH_PST_SCHEMA_REVIEW.yaml"]
    v03 = parsed["schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml"]
    problems.extend("review_" + err for err in review_validator.validate_packet(packet, v02, v03))
    if not isinstance(packet, dict):
        return problems + ["review_not_object"]
    decision_list = packet.get("open_decisions")
    if not isinstance(decision_list, list) or [
        row.get("id") if isinstance(row, dict) else None
        for row in decision_list
    ] != DECISIONS:
        problems.append("g3_decision_ids_not_in_order")
    for path, expected_count in [
        ("schemas/ABH_PST_SCHEMA_REVIEW.yaml", 22),
        ("schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml", 24),
    ]:
        schema = parsed[path]
        if not isinstance(schema, dict):
            problems.append("schema_not_object_" + path)
            continue
        objects = schema.get("components", {}).get("schemas", {})
        if not isinstance(objects, dict) or set(objects) != {"PSCustomer", "PSOrder"}:
            problems.append("schema_object_scope_changed_" + path)
            continue
        if sum(len(obj.get("properties", {})) for obj in objects.values()) != expected_count:
            problems.append("schema_fields_changed_" + path)
    return problems


def read_repository(root=ROOT):
    try:
        freeze = json.loads((root / "config/g3-pst-review-fingerprint-freeze.json").read_text("utf-8"))
        files = {path: (root / path).read_bytes() for path in SOURCES}
        return freeze, files
    except (OSError, ValueError):
        return None, None


def validate_repository(root=ROOT):
    freeze, files = read_repository(root)
    return validate_snapshot(freeze, files)


if __name__ == "__main__":
    issues = validate_repository()
    if issues:
        for issue in issues:
            print("G3 review fingerprint FAIL: " + issue)
        raise SystemExit(1)
    print("G3 review fingerprint PASS: v0.2/v0.3 + 24-field packet pinned; 8 decisions pending; NO Salesforce action.")
