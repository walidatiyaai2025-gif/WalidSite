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


def _php_code_mask(source: str) -> str:
    """Preserve offsets/newlines, blanking strings and comments in PHP source.

    A regex on raw text can count a commented-out Route::get as a deployed
    endpoint. Mask code-vs-data before admitting a route; the ORIGINAL source
    is used to parse its quoted literal URI once a real Route:: token exists.
    Recognize multiline quoted strings, block/line comments and heredocs.
    Importantly, only regions between actual PHP opening/closing tags
    execute. A raw Route::get line in an HTML fragment before <?php or
    after ?> must never satisfy the API contract.
    """
    masked = list(source)
    mode = "html"
    quote = ""
    heredoc = ""
    i = 0
    n = len(source)

    def blank(pos: int) -> None:
        if source[pos] not in "\r\n":
            masked[pos] = " "

    while i < n:
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""

        if mode == "html":
            # PHP's long opening tag requires whitespace after "php".
            # Ignore short/open-echo tags: a route in static HTML (or in
            # an echo expression) cannot be a Laravel declaration here.
            opening = re.match(r"<\?php(?=\s)", source[i:], flags=re.IGNORECASE)
            if opening is not None:
                for pos in range(i, i + opening.end()):
                    blank(pos)
                i += opening.end()
                mode = "code"
                continue
            blank(i)
            i += 1
            continue

        if mode == "code":
            if source.startswith("?>", i):
                blank(i)
                blank(i + 1)
                i += 2
                mode = "html"
                continue
            if source.startswith("<<<", i):
                marker = re.match(
                    r"""<<<[ \t]*(?:'([A-Za-z_]\w*)'|"([A-Za-z_]\w*)"|([A-Za-z_]\w*))""",
                    source[i:],
                )
                if marker is not None:
                    heredoc = next(group for group in marker.groups() if group)
                    for pos in range(i, i + marker.end()):
                        blank(pos)
                    i += marker.end()
                    mode = "heredoc"
                    continue
            if ch in ("'", '"'):
                quote = ch
                mode = "string"
                blank(i)
            elif ch == "/" and nxt == "*":
                blank(i)
                blank(i + 1)
                i += 2
                mode = "block"
                continue
            elif (ch == "/" and nxt == "/") or ch == "#":
                blank(i)
                if ch == "/":
                    blank(i + 1)
                    i += 2
                else:
                    i += 1
                mode = "line"
                continue
        elif mode == "string":
            blank(i)
            if ch == "\\" and i + 1 < n:
                blank(i + 1)
                i += 2
                continue
            if ch == quote:
                mode = "code"
        elif mode == "block":
            blank(i)
            if ch == "*" and nxt == "/":
                blank(i + 1)
                i += 2
                mode = "code"
                continue
        elif mode == "line":
            # In PHP a // or # comment ends at ?> even without a
            # newline. Its following lines are raw HTML until reopened.
            if source.startswith("?>", i):
                blank(i)
                blank(i + 1)
                i += 2
                mode = "html"
                continue
            if ch == "\n":
                mode = "code"
            else:
                blank(i)
        elif mode == "heredoc":
            # PHP permits indented closing markers. Do not treat a fake route
            # inside a multiline nowdoc/heredoc body as executable PHP.
            if i == 0 or source[i - 1] == "\n":
                end = re.match(
                    r"[ \t]*" + re.escape(heredoc) + r"(?=[,);\r\n])",
                    source[i:],
                )
                if end is not None:
                    for pos in range(i, i + end.end()):
                        blank(pos)
                    i += end.end()
                    mode = "code"
                    continue
            blank(i)
        i += 1
    return "".join(masked)


def php_routes(source: str) -> dict[str, list[str]]:
    found: dict[str, list[str]] = defaultdict(list)
    code = _php_code_mask(source)
    for match in LITERAL_ROUTE.finditer(source):
        # The regex deliberately extracts the URI from unmasked source.
        # Its Route:: token must independently be executable PHP, not a
        # comment, a quoted string, or a heredoc containing sample code.
        prefix = match.start(1) - len("Route::")
        if prefix < 0 or code[prefix:prefix + len("Route::")] != "Route::":
            continue
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
