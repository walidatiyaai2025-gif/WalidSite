#!/usr/bin/env python3
"""T024 source-level API contract-to-Laravel route parity.

This is a fail-closed static inventory gate, NOT proof that an HTTP request
reaches a controller, passes authorization, or succeeds against a database.
Runtime API/E2E evidence is tracked separately under T024/#25.
"""
from __future__ import annotations

import argparse
import json
import re
from collections import defaultdict
from pathlib import Path

try:
    from qa.t024_readiness import openapi_operations
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from t024_readiness import openapi_operations

ROOT = Path(__file__).resolve().parents[1]
# Anchor to PHP statements, rather than matching commented-out examples.
# Non-literal/dynamic paths intentionally fail parity instead of pretending
# they satisfy a statically specified public contract.
LITERAL_ROUTE = re.compile(
    r"""(?m)^[ \t]*Route::(get|post|put|patch|delete|head|options|trace)"""
    r"""[ \t]*\([ \t\r\n]*(['"])(/[^'"\r\n]+)\2"""
)
PARAMETER = re.compile(r"\{[A-Za-z_][A-Za-z_0-9]*\}")


def operation_key(path: str, method: str) -> str:
    """Compare URL shapes while accepting {id} vs {provider} placeholders."""
    if not path.startswith("/") or "//" in path or "?" in path or "#" in path:
        raise ValueError("Unexpected API route path")
    return method.lower() + " " + PARAMETER.sub("{}", path)


def php_routes(source: str) -> dict[str, list[str]]:
    found: dict[str, list[str]] = defaultdict(list)
    for match in LITERAL_ROUTE.finditer(source):
        method, _, path = match.groups()
        found[operation_key(path, method)].append(method.upper() + " " + path)
    if not found:
        raise ValueError("No literal Laravel routes found")
    return dict(found)


def audit(root: Path = ROOT) -> tuple[dict, list[str]]:
    """Check every OpenAPI operation against a literal Laravel declaration."""
    report: dict = {
        "scope": "static Laravel route registrations only; NO HTTP or E2E certification",
        "api_runtime_verified": False,
        "contract_operations": 0,
        "registered_route_shapes": 0,
        "matched_operations": 0,
        "missing_operations": [],
        "duplicate_registrations": [],
        "parameter_aliases": [],
        "uncontracted_registrations": [],
    }
    try:
        source = (root / "api/openapi-contract.yaml").read_text(encoding="utf-8")
        php = (root / "backend/routes/api.php").read_text(encoding="utf-8")
        _, declared = openapi_operations(source)
        registered = php_routes(php)
        expected = {
            operation_key(path, method): method.upper() + " " + path
            for item in declared
            for path, method in [item.rsplit("#", 1)]
        }
        if len(expected) != len(declared):
            raise ValueError("OpenAPI contains duplicate normalized route shapes")
    except (OSError, UnicodeError, ValueError) as exc:
        return report, ["Cannot establish API source parity: " + str(exc)]

    missing = sorted(expected[k] for k in expected.keys() - registered.keys())
    duplicates = sorted(
        item
        for entries in registered.values() if len(entries) > 1
        for item in entries
    )
    aliases = sorted(
        expected[k] + " => " + registered[k][0]
        for k in expected.keys() & registered.keys()
        if expected[k] != registered[k][0]
    )
    extra = sorted(
        entry for key, entries in registered.items()
        if key not in expected for entry in entries
    )
    report.update({
        "contract_operations": len(declared),
        "registered_route_shapes": len(registered),
        "matched_operations": len(declared) - len(missing),
        "missing_operations": missing,
        "duplicate_registrations": duplicates,
        "parameter_aliases": aliases,
        "uncontracted_registrations": extra,
    })
    errors = []
    if missing:
        errors.append("Missing registered Laravel routes for contracted API operations")
    if duplicates:
        errors.append("Duplicate/shadowed literal Laravel route declarations")
    return report, errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args(argv)
    report, errors = audit(args.root)
    report["audit_errors"] = errors
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
