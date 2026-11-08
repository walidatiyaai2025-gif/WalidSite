"""Read-only legacy cPanel VERSION classifier for issue #1120.

This is evidence classification, NEVER an update authorization.  The existing
TrustedInstalledVersion remains the sole version authority for signed updates.
A two-line legacy file must not be silently normalized on a live host.
"""
from __future__ import annotations

from datetime import datetime
import re
from pathlib import Path
import json
import sys

SEMVER = re.compile(r"(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)", re.ASCII)
STAMP = re.compile(r"20[0-9]{2}-[01][0-9]-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]:[0-5][0-9]\\+00:00", re.ASCII)


def classify_version_bytes(contents: bytes) -> dict:
    """Inspect only tightly bounded VERSION data; do not update any files."""
    if not contents or len(contents) > 128 or b"\\r" in contents or b"\\x00" in contents:
        raise ValueError("VERSION identity is malformed or oversized")
    try:
        raw = contents.decode("ascii")
    except UnicodeDecodeError as exc:
        raise ValueError("VERSION must be ASCII") from exc
    if not raw.endswith("\\n"):
        raise ValueError("VERSION requires a final LF")
    lines = raw[:-1].split("\\n")
    if len(lines) == 1 and SEMVER.fullmatch(lines[0]):
        return {"format": "canonical_semver", "version": lines[0],
                "current_reader_compatible": True, "automatic_adoption_authorized": False}
    if (len(lines) == 2 and lines[0] == "0.1.0"
            and STAMP.fullmatch(lines[1])):
        try:
            # Reject impossible calendar dates, not just syntactically valid input.
            datetime.fromisoformat(lines[1])
        except ValueError as exc:
            raise ValueError("Legacy VERSION timestamp is invalid") from exc
        return {"format": "legacy_0_1_0_timestamp", "version": lines[0],
                "current_reader_compatible": False, "automatic_adoption_authorized": False}
    raise ValueError("Unrecognized VERSION identity: adoption requires independent review")


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        raise SystemExit("Usage: python -m qa.legacy_version_preflight VERSION-PATH")
    source = Path(argv[1])
    if source.is_symlink() or not source.is_file() or source.stat().st_size > 128:
        raise SystemExit("Unsafe VERSION source")
    try:
        result = classify_version_bytes(source.read_bytes())
    except ValueError as exc:
        raise SystemExit(str(exc)) from None
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
