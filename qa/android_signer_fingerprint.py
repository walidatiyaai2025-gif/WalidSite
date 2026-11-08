"""Canonical Android signing-certificate SHA-256 fingerprint validation.

keytool commonly emits AA:BB:... while apksigner prints a continuous digest.
Require exactly 32 bytes and never silently strip arbitrary delimiters.
"""
from __future__ import annotations

import re
import sys


def canonical_fingerprint(value: str) -> str:
    if re.fullmatch(r"[0-9A-Fa-f]{64}", value):
        return value.lower()
    if re.fullmatch(r"[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){31}", value):
        return value.replace(":", "").lower()
    raise ValueError("Invalid Android signer SHA-256 fingerprint format")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Expected a single Android signer SHA-256 fingerprint")
    try:
        print(canonical_fingerprint(sys.argv[1]))
    except ValueError as exc:
        raise SystemExit(str(exc)) from None
