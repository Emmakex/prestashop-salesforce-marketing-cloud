#!/usr/bin/env python3
"""Fail-closed G3 human-decision brief integrity check. Offline only.

The brief is a proposal, not a signature. Keep its eight rows synced with
the existing G3 packet and frozen schema fingerprints. No Salesforce access.
"""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DECISION_IDS = ["G3-%02d" % i for i in range(1, 9)]
SOURCE_PATHS = (
    "schemas/ABH_PST_SCHEMA_REVIEW.yaml",
    "schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml",
    "config/g3-pst-schema-review.json",
)
BLOCKED_FLAGS = (
    "data_360_upload_authorized",
    "data_360_ingestion_authorized",
    "growth_marketing_sends_authorized",
)


def validate_brief(brief, freeze, packet):
    errors = []
    if not isinstance(brief, str) or not isinstance(freeze, dict) or not isinstance(packet, dict):
        return ["g3_owner_brief_invalid_input"]
    if freeze.get("owner_signoff") != "pending" or freeze.get("review_only") is not True:
        errors.append("g3_owner_brief_unsigned_freeze_required")
    if freeze.get("schema_to_publish") != "undecided":
        errors.append("g3_owner_brief_schema_selection_not_approved")
    if any(freeze.get(key) is not False for key in BLOCKED_FLAGS):
        errors.append("g3_owner_brief_downstream_permissions_not_blocked")
    if packet.get("approval_state") != "pending_data_owner" or packet.get("schema_to_publish") != "undecided":
        errors.append("g3_owner_brief_packet_approved_prematurely")
    if any(packet.get(key) is not False for key in (
        "uploaded_to_tenant", "streams_approved",
        "real_data_ingestion_approved", "marketing_sends_approved",
    )):
        errors.append("g3_owner_brief_packet_permissions_not_blocked")

    open_items = packet.get("open_decisions")
    if (not isinstance(open_items, list)
        or [v.get("id") if isinstance(v, dict) else None for v in open_items] != DECISION_IDS
        or any(v.get("state") != "pending" for v in open_items if isinstance(v, dict))):
        errors.append("g3_owner_brief_decisions_not_pending")

    rows = re.findall(r"^\| \*\*(G3-\d\d) ·", brief, flags=re.MULTILINE)
    if rows != DECISION_IDS:
        errors.append("g3_owner_brief_decision_rows_not_exact")
    if not re.search(r"\*\*Estado: PENDIENTE DE APROBACIÓN · REVIEW ONLY · NO PUBLICAR\.\*\*", brief):
        errors.append("g3_owner_brief_not_marked_unsigned")
    if "issue #45" not in brief or "Marketing Cloud **Growth/Next + Data 360**" not in brief:
        errors.append("g3_owner_brief_wrong_provider_or_tracking")
    if any(text not in brief for text in (
        "no unir por email", "No hay decisiones aprobadas",
        "G4", "No se necesita SQL de producción",
        "No existe cifra de días aprobada",
    )):
        errors.append("g3_owner_brief_safety_disclaimer_missing")

    artifacts = freeze.get("artifacts")
    if not isinstance(artifacts, dict) or set(artifacts) != set(SOURCE_PATHS):
        return errors + ["g3_owner_brief_fingerprint_artifacts_changed"]
    for path in SOURCE_PATHS:
        row = artifacts[path]
        digest = row.get("sha256") if isinstance(row, dict) else None
        if not isinstance(digest, str) or re.fullmatch("[0-9a-f]{64}", digest) is None:
            errors.append("g3_owner_brief_invalid_fingerprint_" + path)
        elif digest not in brief or path not in brief:
            errors.append("g3_owner_brief_fingerprint_drift_" + path)
    return errors


def validate_repo(root=ROOT):
    try:
        brief = (root / "docs/G3_OWNER_DECISION_READY.md").read_text("utf-8")
        freeze = json.loads((root / "config/g3-pst-review-fingerprint-freeze.json").read_text("utf-8"))
        packet = json.loads((root / "config/g3-pst-schema-review.json").read_text("utf-8"))
    except (OSError, ValueError, UnicodeDecodeError):
        return ["g3_owner_brief_file_unavailable"]
    return validate_brief(brief, freeze, packet)


if __name__ == "__main__":
    errors = validate_repo()
    if errors:
        for error in errors:
            print("G3 owner decision brief FAIL: " + error)
        raise SystemExit(1)
    print("G3 owner decision brief PASS: 8 pending owner decisions; zero Salesforce authorization.")
