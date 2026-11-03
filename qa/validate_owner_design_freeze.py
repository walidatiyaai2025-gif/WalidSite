#!/usr/bin/env python3
"""Fail closed if an owner-frozen WEB-01/03/04 visual template has changed.

The owner signed off the current *design/code* on 2026-11-03. This is not
independent screenshot or decoded RGBA parity evidence, which is tracked by
design/visual-fidelity-evidence.json and qa/validate_visual_fidelity.py.
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

MANIFEST_PATH = "design/owner-approved-web-design-freeze.json"
SCREENS = {
    "WEB-01": (
        "/",
        {
            "backend/resources/views/welcome.blade.php",
            "backend/resources/views/public/owner-landing-2026.blade.php",
        },
    ),
    "WEB-03": (
        "/expertise",
        {
            "backend/resources/views/public/expertise-screen.blade.php",
            "backend/resources/views/public/expertise-2026-showcase.blade.php",
            "backend/resources/views/public/expertise-2026-icon.blade.php",
        },
    ),
    "WEB-04": (
        "/projects",
        {
            "backend/resources/views/public/projects.blade.php",
            "backend/resources/views/public/projects-2026-live.blade.php",
        },
    ),
}
OWNER_APPROVED_SHA = "cf5b72f129d9daa11f3aa1d55a9d6d52740eeb0b"
OWNER_APPROVAL_ID = "owner-2026-11-03-web-01-03-04"


def git_blob_sha1(data: bytes) -> str:
    """Same content identity as GitHub's sha on GET /contents/:path."""
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


def audit(root: Path) -> list[str]:
    errors: list[str] = []
    root = root.resolve()
    try:
        manifest = json.loads((root / MANIFEST_PATH).read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        return [f"Missing/unreadable owner design freeze: {exc}"]

    if not isinstance(manifest, dict):
        return ["Owner freeze manifest must be an object"]
    expected_header = {
        "schema_version": 1,
        "approval_id": OWNER_APPROVAL_ID,
        "approval_date": "2026-11-03",
        "approved_main_sha": OWNER_APPROVED_SHA,
        "acceptance_type": "owner_visual_and_current_code_signoff",
        "runtime_zero_rgba_comparison": "not_asserted",
        "preserve_visual_evidence_ledger": "design/visual-fidelity-evidence.json",
    }
    for field, expected in expected_header.items():
        if manifest.get(field) != expected:
            errors.append(f"Owner freeze metadata {field} changed without an approved revision")

    if not isinstance(manifest.get("screens"), dict) or set(manifest["screens"]) != set(SCREENS):
        return errors + ["Owner freeze must cover exactly WEB-01, WEB-03, WEB-04"]

    seen: set[str] = set()
    for screen_id, (route, required_paths) in SCREENS.items():
        screen = manifest["screens"][screen_id]
        if not isinstance(screen, dict):
            errors.append(f"{screen_id}: screen lock must be an object")
            continue
        if screen.get("route") != route or screen.get("status") != "owner_approved_frozen":
            errors.append(f"{screen_id}: route or owner approval state has drifted")
        sources = screen.get("files")
        if not isinstance(sources, dict) or set(sources) != required_paths:
            errors.append(f"{screen_id}: frozen template inventory changed")
            continue

        for relpath, data in sources.items():
            if relpath in seen:
                errors.append(f"{screen_id}: duplicate frozen file {relpath}")
                continue
            seen.add(relpath)
            if not isinstance(data, dict) or set(data) != {"git_blob_sha1"}:
                errors.append(f"{screen_id}: invalid file-hash metadata for {relpath}")
                continue
            expected_sha = data["git_blob_sha1"]
            if not isinstance(expected_sha, str) or re.fullmatch(r"[0-9a-f]{40}", expected_sha) is None:
                errors.append(f"{screen_id}: invalid blob SHA for {relpath}")
                continue
            path = (root / relpath).resolve()
            if not path.is_relative_to(root) or not path.is_file():
                errors.append(f"{screen_id}: missing/unsafe owner-frozen template {relpath}")
                continue
            actual_sha = git_blob_sha1(path.read_bytes())
            if actual_sha != expected_sha:
                errors.append(
                    f"{screen_id}: frozen design changed in {relpath} "
                    f"(approved {expected_sha}; actual {actual_sha}). "
                    "Stop. Do not re-style an owner-approved screen without NEW explicit owner approval."
                )
    return errors


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    errors = audit(root)
    if errors:
        for error in errors:
            print("FAIL OWNER DESIGN FREEZE:", error, file=sys.stderr)
        return 1
    print(
        "PASS: owner-approved WEB-01, WEB-03 and WEB-04 source design freeze; "
        "7 canonical templates match approved main@cf5b72f1. "
        "Owner visual sign-off only: decoded-RGBA pixel comparison remains separately pending."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
