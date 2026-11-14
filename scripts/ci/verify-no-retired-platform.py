#!/usr/bin/env python3
"""Fail closed if the current source tree reintroduces retired connectors.

No history rewrite, no production data access, and no network calls.
Salesforce Growth + Data Cloud is the only permitted product.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
TEXT_EXTENSIONS = {".php", ".py", ".md", ".yml", ".yaml", ".json", ".sql", ".sh", ".txt"}
OMIT_PARTS = {".git", ".github-cache", "build", "__pycache__", ".pytest_cache"}
TOKENS = [
    "eng" + "agement",
    "jour" + "ney",
    "exact" + "target",
    "so" + "ap",
    "data" + "_extension",
    "all" + " subscribers",
    "all" + "_subscribers",
    "all" + "subscribers",
    "publication" + " list",
    "publication" + "_list",
    "subscriber" + "key",
    "subscriber" + "_key",
    "installed" + " package",
]
PATTERNS = [re.compile(re.escape(token), re.I) for token in TOKENS]
PATTERNS.append(re.compile(r"data[ _-]*exten" + "sion", re.I))

def inspect(root=ROOT):
    violations = []
    for path in sorted(root.rglob("*")):
        if any(part in OMIT_PARTS for part in path.relative_to(root).parts):
            continue
        if not path.is_file() or path.suffix.lower() not in TEXT_EXTENSIONS:
            continue
        relative = path.relative_to(root).as_posix()
        if any(rule.search(relative) for rule in PATTERNS):
            violations.append(relative + ": filename is from a retired product")
            continue
        try:
            lines = path.read_text("utf-8").splitlines()
        except (UnicodeError, OSError):
            violations.append(relative + ": unreadable text")
            continue
        for number, line in enumerate(lines, 1):
            if any(rule.search(line) for rule in PATTERNS):
                violations.append(relative + ":" + str(number) + ": retired integration reference")
    return violations

if __name__ == "__main__":
    errors = inspect()
    for item in errors:
        print("Growth source boundary FAIL: " + item)
    if errors:
        raise SystemExit(1)
    print("Growth source boundary PASS: single Salesforce product, no retired routes or docs.")
