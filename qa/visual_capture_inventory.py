#!/usr/bin/env python3
"""Audit actual runtime screenshot diagnostics without manufacturing visual evidence.

Merge reports produced by qa/visual_diagnostics.py for a single checked-out SHA.
These reports are diagnostic observations, never permission to mark a screen as
matched. Only qa/validate_visual_fidelity.py admits real pixel-parity evidence.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

try:
    from qa.validate_visual_fidelity import audit as visual_audit, _route_matches
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from validate_visual_fidelity import audit as visual_audit, _route_matches


ROOT = Path(__file__).resolve().parents[1]
SHA40 = re.compile(r"[0-9a-f]{40}\Z")
SURFACES = {"WEB": 14, "ADMIN": 13, "MOBILE": 13}
STATES = {
    "mismatch", "renderer_failed", "oversized_png", "invalid_png",
    "wrong_dimensions", "source_copy_rejected", "exact_pixels_unattested",
}
MAX_REPORT_BYTES = 4 * 1024 * 1024


class InventoryError(ValueError):
    """Invalid, unpinned or internally inconsistent diagnostic evidence."""


def _load_json(path: Path) -> object:
    if not path.is_file() or path.is_symlink() or path.stat().st_size > MAX_REPORT_BYTES:
        raise InventoryError("Missing, unsafe or oversized JSON: " + str(path))
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError, UnicodeError) as exc:
        raise InventoryError("Unreadable JSON: " + str(path)) from exc


def _size(value: object) -> bool:
    return (isinstance(value, list) and len(value) == 2
            and all(type(part) is int and 0 < part <= 100000 for part in value))


def _screen_report(screen_id: str, contract: dict, data: object) -> dict:
    if not isinstance(data, dict):
        raise InventoryError(screen_id + ": invalid screen diagnostic")
    state = data.get("status")
    if state not in STATES:
        raise InventoryError(screen_id + ": unsupported diagnostic status")
    if (not _route_matches(contract["route"], data.get("route"))
            or data.get("locale") not in ("ar", "en")
            or type(data.get("device_pixel_ratio")) not in (int, float)
            or not 0.5 <= data["device_pixel_ratio"] <= 8
            or not _size(data.get("reference_size"))):
        raise InventoryError(screen_id + ": invalid route, locale, DPR or reference size")

    has_pixels = state in ("mismatch", "exact_pixels_unattested")
    if has_pixels:
        if (not _size(data.get("runtime_size"))
                or data["runtime_size"] != data["reference_size"]
                or data.get("total_pixels") != data["reference_size"][0] * data["reference_size"][1]
                or type(data.get("total_pixels")) is not int
                or type(data.get("mismatching_rgba_pixels")) is not int
                or data.get("runtime_capture") != screen_id + ".runtime.png"):
            raise InventoryError(screen_id + ": incomplete runtime pixel diagnostic")
        differences = data["mismatching_rgba_pixels"]
        if not 0 <= differences <= data["total_pixels"]:
            raise InventoryError(screen_id + ": invalid mismatch count")
        if state == "mismatch":
            if differences == 0 or data.get("difference_preview") != screen_id + ".difference-preview.png":
                raise InventoryError(screen_id + ": mismatch needs nonzero pixels and a diff preview")
        elif differences != 0 or "difference_preview" in data:
            raise InventoryError(screen_id + ": exact pixel claim conflicts with mismatch data")
    elif "mismatching_rgba_pixels" in data or "runtime_capture" in data:
        raise InventoryError(screen_id + ": failed renderer cannot report pixel success")
    if state == "wrong_dimensions" and (
        not _size(data.get("runtime_size"))
        or data["runtime_size"] == data["reference_size"]
    ):
        raise InventoryError(screen_id + ": wrong_dimensions needs unequal valid sizes")

    # Retain descriptive metadata only. Untrusted source reports never set
    # authoritative matched status, even when they claim zero differing pixels.
    clean = {
        "status": state,
        "route": data["route"],
        "locale": data["locale"],
        "device_pixel_ratio": data["device_pixel_ratio"],
    }
    if has_pixels:
        clean["mismatching_rgba_pixels"] = data["mismatching_rgba_pixels"]
        clean["total_pixels"] = data["total_pixels"]
    return clean


def inventory(root: Path, reports: list[Path] | tuple[Path, ...] = (),
              commit: str | None = None) -> tuple[dict, list[str]]:
    """Return a source-ledger inventory plus separately labelled diagnostics.

    A report can show an actual pixel mismatch, but never satisfy a missing
    ledger entry or turn 'exact_pixels_unattested' into visual acceptance.
    """
    root = root.resolve()
    if reports and (not isinstance(commit, str) or not SHA40.fullmatch(commit)):
        raise InventoryError("Pinned --commit SHA is required when reading runtime reports")
    if commit is not None and (not isinstance(commit, str) or not SHA40.fullmatch(commit)):
        raise InventoryError("Commit must be an exact lowercase Git SHA")

    contract = _load_json(root / "contracts/screens.json")
    ledger = _load_json(root / "design/visual-fidelity-evidence.json")
    if not isinstance(contract, dict) or not isinstance(contract.get("screens"), list):
        raise InventoryError("Invalid canonical screen contract")
    if not isinstance(ledger, dict) or not isinstance(ledger.get("screens"), dict):
        raise InventoryError("Invalid canonical evidence ledger")
    screens = contract["screens"]
    expected = {item["id"]: item for item in screens
                if isinstance(item, dict) and isinstance(item.get("id"), str)}
    expected_ids = {
        prefix + "-" + f"{num:02d}"
        for prefix, count in SURFACES.items() for num in range(1, count + 1)
    }
    if len(screens) != 40 or set(expected) != expected_ids or set(ledger["screens"]) != expected_ids:
        raise InventoryError("Expected canonical 14 WEB, 13 ADMIN and 13 MOBILE screens")
    visual_errors, matched, total = visual_audit(root)

    observations: dict[str, dict] = {}
    for path in reports:
        payload = _load_json(Path(path))
        if (not isinstance(payload, dict)
                or set(payload) != {"renderer_commit", "evidence_admitted", "real_runtime_required", "screens"}
                or payload["renderer_commit"] != commit
                or payload["evidence_admitted"] is not False
                or payload["real_runtime_required"] is not True
                or not isinstance(payload["screens"], dict)
                or not payload["screens"]):
            raise InventoryError("Unpinned, self-admitted or empty runtime report: " + str(path))
        for sid, data in payload["screens"].items():
            if sid not in expected or sid in observations:
                raise InventoryError("Unknown or duplicated screen report: " + str(sid))
            observations[sid] = _screen_report(sid, expected[sid], data)

    errors = list(visual_errors)
    for sid, diagnostic in observations.items():
        if (ledger["screens"][sid].get("status") == "matched"
                and diagnostic["status"] != "exact_pixels_unattested"):
            errors.append(sid + ": current runtime diagnostic contradicts matched evidence")

    groups = {}
    for prefix, count in SURFACES.items():
        identifiers = sorted(sid for sid in expected if sid.startswith(prefix + "-"))
        groups[prefix.lower()] = {
            "total": count,
            "matched_in_ledger": sum(ledger["screens"][sid].get("status") == "matched" for sid in identifiers),
            "diagnosed": sum(sid in observations for sid in identifiers),
            "mismatching": [sid for sid in identifiers if observations.get(sid, {}).get("status") == "mismatch"],
            "missing_diagnostics": [sid for sid in identifiers if sid not in observations],
        }

    return {
        "renderer_commit": commit,
        "diagnostics_are_not_visual_evidence": True,
        "canonical_screens": total,
        "matched_in_authoritative_ledger": matched,
        "pending_or_unverified": total - matched,
        "diagnosed": len(observations),
        "missing_diagnostics": sorted(set(expected) - set(observations)),
        "by_surface": groups,
        "observations": dict(sorted(observations.items())),
        "ready_for_visual_release": not errors and matched == 40,
    }, errors


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--report", action="append", type=Path, default=[],
                        help="Actual qa/visual_diagnostics.py report.json; repeat for each surface")
    parser.add_argument("--commit", help="Exact 40-hex renderer checkout SHA for all reports")
    parser.add_argument("--require-coverage", action="store_true",
                        help="Fail until every one of the 40 screens has diagnostic output")
    parser.add_argument("--require-complete", action="store_true",
                        help="Fail until all 40 have validated canonical matched evidence")
    args = parser.parse_args(argv)
    try:
        report, errors = inventory(args.root, args.report, args.commit)
    except (OSError, ValueError, TypeError, KeyError) as exc:
        print("VISUAL INVENTORY REJECTED: " + str(exc), file=sys.stderr)
        return 2
    report["audit_errors"] = errors
    print(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True))
    if errors:
        return 2
    if args.require_coverage and report["diagnosed"] != 40:
        return 1
    if args.require_complete and not report["ready_for_visual_release"]:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
