#!/usr/bin/env python3
"""Compare sandbox SQL table definitions to exact PrestaShop 0.7.1 DDL."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "pssalesforcebridge/pssalesforcebridge.php"
MIRROR = ROOT / "tests/integration/g3_prestashop_071_mirror_schema.sql"
TABLES = ("sfmc_queue", "sfmc_customer_sync", "sfmc_order_sync")
TICK = chr(96)


def canonical(segment):
    return re.sub(r"\s+", " ", segment.replace(TICK, "").strip().rstrip(",")).lower()


def definitions(ddl):
    out = {}
    for raw in ddl.splitlines():
        line = canonical(raw)
        if not line:
            continue
        if line.startswith(("primary key", "unique key", "key ")):
            name = "index:" + line.split("(")[0].strip()
        elif re.match(r"^[a-z_]+\s+", line):
            name = "column:" + line.split()[0]
        else:
            raise ValueError("unexpected_sql_definition")
        if name in out:
            raise ValueError("duplicate_sql_definition")
        out[name] = line
    return out


def validate(source, mirror):
    errors = []
    for name in TABLES:
        real = re.search(
            r"CREATE TABLE IF NOT EXISTS " + TICK + r"\{\$prefix\}" + name
            + TICK + r" \((.*?)\) ENGINE=\{\$engine\}", source, re.DOTALL
        )
        replica = re.search(
            r"CREATE TABLE g3_" + name + r" \((.*?)\) ENGINE=InnoDB",
            mirror, re.DOTALL | re.IGNORECASE
        )
        if not real or not replica:
            errors.append("missing_table_" + name)
            continue
        try:
            expected = definitions(real.group(1))
            actual = definitions(replica.group(1))
        except ValueError:
            errors.append("invalid_table_contract_" + name)
            continue
        if expected != actual:
            errors.append("schema_drift_" + name)
    if len(re.findall(r"CREATE TABLE\s+g3_sfmc_", mirror, re.I)) != 3:
        errors.append("mirror_scope_invalid")
    if "ENGINE=InnoDB" not in mirror:
        errors.append("mirror_engine_not_transactional")
    if "DO NOT" not in mirror and "NEVER" not in mirror:
        errors.append("missing_test_only_warning")
    return errors


if __name__ == "__main__":
    errors = validate(SOURCE.read_text(encoding="utf-8"), MIRROR.read_text(encoding="utf-8"))
    if errors:
        for error in errors:
            print("G3 0.7.1 mirror FAIL: " + error)
        raise SystemExit(1)
    print("G3 PrestaShop 0.7.1 queue/ledger DDL mirror PASS; CI-only, no module DDL.")
