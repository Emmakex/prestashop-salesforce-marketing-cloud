#!/usr/bin/env python3
"""Fail-closed OpenAPI review gate. This does NOT certify Salesforce tenant acceptance."""
import copy
import json
import re
import subprocess
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
SCHEMA = ROOT / "schemas" / "ABH_PST_SCHEMA_REVIEW.yaml"
FIXTURE = ROOT / "tests" / "data-cloud-schema-fixture.php"
OBJECTS = {"PSCustomer", "PSOrder"}
RESERVED = {
    "date_id", "location_id", "dat_account_currency", "dat_exchange_rate",
    "pacing_period", "pacing_end_date", "row_count", "version",
}
NAME = re.compile(r"^[A-Za-z0-9_-]+$")
TYPE_RULES = {
    "string": {"", "email", "date", "date-time"},
    "number": {""},
    "boolean": {""},
}


class UniqueKeysLoader(yaml.SafeLoader):
    pass


def no_duplicate_keys(loader, node):
    pairs = loader.construct_pairs(node)
    found = set()
    output = {}
    for key, value in pairs:
        if not isinstance(key, str):
            raise ValueError("YAML contains non-string key")
        folded = key.casefold()
        if folded in found:
            raise ValueError("YAML contains duplicate case-insensitive key")
        found.add(folded)
        output[key] = value
    return output


UniqueKeysLoader.add_constructor(
    yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, no_duplicate_keys
)


def check(name, is_field=False):
    limit = 39 if is_field else 79
    if not isinstance(name, str) or not 1 <= len(name) <= limit or not NAME.fullmatch(name):
        raise ValueError("Invalid Salesforce schema object/field name")
    if is_field and ("__" in name or name.lower() in RESERVED):
        raise ValueError("Forbidden Salesforce field name")


def validate(schema, fixtures):
    if not isinstance(schema, dict) or schema.get("openapi") not in {"3.0.0", "3.0.1", "3.0.2", "3.0.3"}:
        raise ValueError("Expected OpenAPI 3.0.x")
    if not isinstance(schema.get("info"), dict) or not isinstance(schema.get("paths"), dict):
        raise ValueError("Invalid OpenAPI document structure")
    definitions = schema.get("components", {}).get("schemas")
    if not isinstance(definitions, dict) or set(definitions) != OBJECTS:
        raise ValueError("Only PSCustomer and PSOrder are reviewed; cart remains blocked")
    if not isinstance(fixtures, dict) or set(fixtures) != OBJECTS:
        raise ValueError("Fixtures do not match reviewed object list")

    for object_name, definition in definitions.items():
        check(object_name)
        if not isinstance(definition, dict) or definition.get("type") != "object":
            raise ValueError("Invalid top-level object")
        properties = definition.get("properties")
        if not isinstance(properties, dict) or not 1 <= len(properties) <= 1000:
            raise ValueError("Invalid property count")
        required = definition.get("required")
        if not isinstance(required, list) or not set(required).issubset(properties):
            raise ValueError("Missing or invalid required fields")
        if "source_key" not in required or "source_namespace" not in required:
            raise ValueError("Stable identity must be required")
        if object_name == "PSCustomer" and "customer_id" not in required:
            raise ValueError("Customer identity missing")
        if object_name == "PSOrder" and "order_id" not in required:
            raise ValueError("Order identity missing")
        fixture = fixtures[object_name]
        if not isinstance(fixture, dict) or set(fixture) != set(properties):
            raise ValueError("PHP mapper output and uploaded field list diverge")

        for name, definition_field in properties.items():
            check(name, is_field=True)
            if not isinstance(definition_field, dict) or set(definition_field) - {"type", "format"}:
                raise ValueError("Nested, compound, array or unsupported field definition")
            field_type = definition_field.get("type")
            field_format = definition_field.get("format", "")
            if field_type not in TYPE_RULES or field_format not in TYPE_RULES[field_type]:
                raise ValueError("Unsupported Data Cloud ingestion field type")
            value = fixture[name]
            if field_type == "string" and not isinstance(value, str):
                raise ValueError("Mapper string mismatch")
            if field_type == "boolean" and not isinstance(value, bool):
                raise ValueError("Mapper boolean mismatch")
            if field_type == "number" and (isinstance(value, bool) or not isinstance(value, (int, float))):
                raise ValueError("Mapper number mismatch")
            if field_format == "email" and value and not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", value):
                raise ValueError("Invalid synthetic email")

        if object_name == "PSCustomer" and any(
            k in properties for k in ("marketing_consent", "send_email", "consent_status")
        ):
            raise ValueError("Raw PrestaShop flags cannot establish marketing consent")
        if any(name.lower().endswith("recoveryurl") or name.lower().endswith("recovery_url") for name in properties):
            raise ValueError("Recovery URLs cannot enter Data Cloud ingestion schema")
    return True


def negative_cases(schema, fixtures):
    alterations = []
    bad = copy.deepcopy(schema)
    bad["components"]["schemas"]["PSCartEvent"] = {"type": "object", "properties": {"event_time": {"type": "string"}}}
    alterations.append(bad)
    bad = copy.deepcopy(schema)
    bad["components"]["schemas"]["PSCustomer"]["properties"]["row_count"] = {"type": "string"}
    alterations.append(bad)
    bad = copy.deepcopy(schema)
    bad["components"]["schemas"]["PSOrder"]["properties"]["total_paid"]["type"] = "object"
    alterations.append(bad)
    bad = copy.deepcopy(schema)
    del bad["components"]["schemas"]["PSCustomer"]["required"]
    alterations.append(bad)
    bad = copy.deepcopy(schema)
    bad["components"]["schemas"]["PSOrder"]["properties"]["source_key"] = {"type": "array", "items": {"type": "string"}}
    alterations.append(bad)
    for bad in alterations:
        try:
            validate(bad, fixtures)
        except ValueError:
            pass
        else:
            raise AssertionError("Validator accepted a deliberately invalid schema")


def main():
    schema = yaml.load(SCHEMA.read_text(encoding="utf-8"), Loader=UniqueKeysLoader)
    raw_fixtures = subprocess.check_output(["php", str(FIXTURE)], cwd=str(ROOT))
    fixtures = json.loads(raw_fixtures)
    validate(schema, fixtures)
    # Enforce exact parity between YAML, PHP mapper fixtures and batch contract.
    allowed = json.loads(subprocess.check_output(
        ["php", str(ROOT / "tests" / "data-cloud-batch-fields.php")], cwd=str(ROOT)))
    definitions = schema["components"]["schemas"]
    if set(allowed) != OBJECTS:
        raise ValueError("Payload builder objects drifted from PST schema")
    for object_name in OBJECTS:
        actual = {field: spec["type"]
                  for field, spec in definitions[object_name]["properties"].items()}
        if allowed[object_name] != actual:
            raise ValueError("Payload builder field names/types drifted from schema")

    # Compare the optional v0.3 UTC candidate against the unchanged v0.2 review.
    # Exactly one date-time field is proposed per source object. No uploads.
    candidate_path = ROOT / "schemas" / "ABH_PST_SCHEMA_UTC_CANDIDATE.yaml"
    candidate = yaml.load(candidate_path.read_text(encoding="utf-8"), Loader=UniqueKeysLoader)
    staged_fixtures = json.loads(subprocess.check_output(
        ["php", str(ROOT / "tests" / "data-cloud-utc-candidate-fixture.php")],
        cwd=str(ROOT)))
    if candidate.get("openapi") != schema.get("openapi"):
        raise ValueError("UTC candidate must retain same OpenAPI major/minor version")
    candidate_objects = candidate.get("components", {}).get("schemas", {})
    base_objects = schema["components"]["schemas"]
    if set(candidate_objects) != OBJECTS or set(staged_fixtures) != OBJECTS:
        raise ValueError("UTC candidate must have exactly PSCustomer and PSOrder")
    for obj in OBJECTS:
        props = candidate_objects[obj].get("properties", {})
        baseline = base_objects[obj].get("properties", {})
        if set(props) != set(baseline) | {"source_updated_utc"}:
            raise ValueError("UTC candidate field inventory drift")
        if candidate_objects[obj].get("required") != base_objects[obj].get("required") + ["source_updated_utc"]:
            raise ValueError("UTC candidate must require source modification UTC alongside primary identities")
        for field, defn in baseline.items():
            if props.get(field) != defn:
                raise ValueError("UTC candidate must not alter previous field types")
        if props["source_updated_utc"] != {"type": "string", "format": "date-time"}:
            raise ValueError("UTC candidate timestamp field must be ISO 8601 date-time")
        check(obj)
        if set(staged_fixtures[obj]) != set(props):
            raise ValueError("Staged PHP mapper does not match proposed UTC candidate")
        for field, spec in props.items():
            check(field, is_field=True)
            value = staged_fixtures[obj][field]
            typ = spec["type"]
            if typ == "string" and not isinstance(value, str):
                raise ValueError("UTC candidate staged string field mismatch")
            if typ == "number" and (isinstance(value, bool) or not isinstance(value, (int, float))):
                raise ValueError("UTC candidate staged number field mismatch")
            if typ == "boolean" and not isinstance(value, bool):
                raise ValueError("UTC candidate staged boolean field mismatch")
        if not re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z",
                            staged_fixtures[obj]["source_updated_utc"]):
            raise ValueError("Staged UTC value not valid Zulu timestamp")

    negative_cases(schema, fixtures)
    print("ABH PST schema review gate passed: 2 flat objects, 22 fields; no Data Cloud writes.")
    print("This is offline structural validation only, NOT Salesforce tenant acceptance.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, TypeError, AssertionError, yaml.YAMLError, subprocess.CalledProcessError) as exc:
        print("ABH PST schema review gate failed: " + str(exc), file=sys.stderr)
        sys.exit(1)
