#!/usr/bin/env python3
"""Offline static preflight of BC production-readonly timestamp audit SQL.

This is an additional guard against accidental unsafe SQL in the repository,
NOT a full SQL parser or permission substitute. No database access.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
QUERY = ROOT / "scripts/sql/bc_g3_timestamp_provenance_aggregate_readonly.sql"


def without_comments(sql):
    return "\n".join(line for line in sql.splitlines() if not line.lstrip().startswith("--"))


def validate(sql):
    problems = []
    if not isinstance(sql, str) or not sql.strip():
        return ["sql_missing"]
    content = without_comments(sql)
    upper = content.upper()
    statements = [s.strip() for s in content.split(";") if s.strip()]
    if len(statements) != 2 or not all(re.match(r"(?is)^SELECT\b", s) for s in statements):
        problems.append("must_have_exactly_two_select_statements")
    prohibited = (
        "UPDATE", "INSERT", "REPLACE", "DELETE", "DROP", "ALTER", "CREATE",
        "TRUNCATE", "CALL", "EXEC", "LOAD", "INTO", "OUTFILE", "DUMPFILE",
        "PROCEDURE", "LOCK", "UNLOCK", "GRANT", "REVOKE", "SET", "SHOW",
    )
    # Check SQL tokens excluding literal strings, so no keywords inside date windows.
    without_literals = re.sub(r"'(?:''|[^'])*'", "''", upper)
    for token in prohibited:
        if re.search(r"\b" + token + r"\b", without_literals):
            problems.append("forbidden_sql_token_" + token.lower())
    for token in ("EMAIL", "FIRSTNAME", "LASTNAME", "PASSWD", "PASSWORD",
                  "ID_CUSTOMER", "ID_ORDER", "SECURE_KEY", "TOKEN", "IP_ADDRESS"):
        if re.search(r"\b" + token + r"\b", without_literals):
            problems.append("personally_identifying_column_" + token.lower())
    if re.search(r"\bSELECT\s+\*", upper):
        problems.append("select_star_forbidden")
    for table in ("bc_configuration", "bc_customer", "bc_orders"):
        if not re.search(r"\bFROM\s+" + table + r"\b", content, re.I):
            problems.append("missing_source_" + table)
    if len(re.findall(r"\bWHERE\s+id_shop\s*=\s*1\b", content, re.I)) != 2:
        problems.append("both_source_tables_must_be_scoped_to_shop_1")
    if "WHERE c.name = 'PS_TIMEZONE'" not in content:
        problems.append("config_must_be_timezone_only")
    if "ELSE 'OTHER_ZONE_REVIEW'" not in content or "AS safe_value" not in content:
        problems.append("avoid_printing_unrecognized_config_value")
    if len(re.findall(r"\bCOUNT\(\*\)", content, re.I)) != 2:
        problems.append("aggregate_count_required")
    for marker in (
        "spring_gap_2025_2026", "autumn_overlap_2025_2026",
        "updated_future_session_15min", "updated_future_utc_15min",
        "updated_before_created", "date_upd_zero_string", "date_add_zero_string",
    ):
        if content.count("AS " + marker) != 2:
            problems.append("missing_both_" + marker)
    for marker in ("UTC_TIMESTAMP()", "NOW()", "TIMESTAMPDIFF("):
        if marker not in upper:
            problems.append("missing_clock_comparison_" + marker)
    if len(re.findall(r"\bUNION\s+ALL\b", upper)) != 1:
        problems.append("expected_two_source_union")
    return problems


if __name__ == "__main__":
    failed = validate(QUERY.read_text(encoding="utf-8"))
    if failed:
        for reason in failed:
            print("BC G3 timestamp audit FAIL: " + reason)
        raise SystemExit(1)
    print("BC G3 timestamp audit PASS: read-only aggregate shop-1 diagnostics; no timestamp provenance certified.")
