#!/usr/bin/env python3
"""ADR-010: immutable design references are binding; never claim unproven pixel parity.

This is a fail-closed evidence validator, NOT a screenshot-rendering substitute.
The actual web or Flutter UI must be rendered independently before marking matched.
"""
import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

from PIL import Image, UnidentifiedImageError


def _json(path):
    return json.loads(path.read_text(encoding="utf-8"))


def _route_matches(contract_route: str, actual: str) -> bool:
    """Bind evidence to the exact canonical screen, not merely a path prefix."""
    if (
        not isinstance(contract_route, str) or not isinstance(actual, str)
        or not actual.startswith("/") or actual.startswith("//")
        or any(c in actual for c in ("?", "#", "\\", "%"))
        or any(ord(c) < 32 or ord(c) == 127 for c in actual)
        or any(part in (".", "..") for part in actual.split("/"))
    ):
        return False
    pattern = re.escape(contract_route)
    pattern = re.sub(r"\\\{[^{}]+\\\}", r"[^/]+", pattern)
    return re.fullmatch(pattern, actual) is not None


def audit(root: Path, require_complete: bool = False):
    root = root.resolve()
    errors = []
    matched = 0
    screens = _json(root / "contracts/screens.json")["screens"]
    ledger = _json(root / "design/visual-fidelity-evidence.json")
    manifest = _json(root / "MANIFEST.json")
    integrity = {item["path"]: item for item in manifest["integrityItems"]}

    expected = {screen["id"]: screen for screen in screens}
    records = ledger.get("screens")
    if len(screens) != 40 or len(expected) != 40:
        errors.append("Expected exactly 40 unique v6 screen IDs")
    if ledger.get("policy_version") != 1 or ledger.get("pixel_comparison") != (
        "zero mismatching RGBA pixels at reference PNG dimensions"
    ):
        errors.append("Missing strict ADR-010 visual policy version / zero-difference rule")
    if not isinstance(records, dict) or set(records) != set(expected):
        errors.append("Evidence ledger must map exactly all 40 canonical screen IDs")
        records = records if isinstance(records, dict) else {}

    seen_refs = set()
    for screen_id, screen in expected.items():
        record = records.get(screen_id)
        if not isinstance(record, dict):
            errors.append(f"{screen_id}: missing ledger record")
            continue
        reference = screen["reference_image"]
        if (
            record.get("reference_image") != reference
            or not reference.startswith("assets/screen-reference/")
            or not reference.endswith(".png")
            or reference in seen_refs
        ):
            errors.append(f"{screen_id}: reference drift, invalid path or duplicate")
            continue
        seen_refs.add(reference)
        reference_file = (root / reference).resolve()
        if not reference_file.is_relative_to(root / "assets/screen-reference"):
            errors.append(f"{screen_id}: unsafe reference path")
            continue
        if not reference_file.is_file():
            errors.append(f"{screen_id}: missing approved reference image")
            continue

        integrity_item = integrity.get(reference)
        raw = reference_file.read_bytes()
        if (
            integrity_item is None
            or len(raw) != integrity_item["size_bytes"]
            or hashlib.sha256(raw).hexdigest() != integrity_item["sha256"]
        ):
            errors.append(f"{screen_id}: immutable v6 reference hash mismatch")
            continue

        try:
            with Image.open(reference_file) as image:
                if image.format != "PNG" or image.width == 0 or image.height == 0:
                    raise ValueError("Invalid approved reference PNG")
                ref_size = image.size
        except (UnidentifiedImageError, OSError, ValueError) as exc:
            errors.append(f"{screen_id}: malformed reference PNG: {exc}")
            continue

        status = record.get("status")
        if status == "pending":
            if set(record) != {"reference_image", "status"}:
                errors.append(f"{screen_id}: pending evidence must not claim capture or overrides")
            continue

        if status != "matched":
            errors.append(f"{screen_id}: status must be pending or matched (not self-declared accepted)")
            continue

        required = {
            "reference_image", "status", "capture", "renderer_commit",
            "capture_command", "route", "locale", "device_pixel_ratio",
        }
        if set(record) != required:
            errors.append(f"{screen_id}: matched entry needs exact real capture metadata; no masks/tolerances")
            continue

        capture = record["capture"]
        capture_dir = (root / "qa/visual-captures").resolve()
        if not isinstance(capture, str) or capture != f"qa/visual-captures/{screen_id}.png":
            # Reject malformed ledger values before passing them to pathlib.
            errors.append(f"{screen_id}: capture must use this exact canonical screen ID and PNG path")
            continue
        screenshot = (root / capture).resolve()
        if not screenshot.is_relative_to(capture_dir) or not screenshot.is_file():
            errors.append(f"{screen_id}: rendered screenshot is missing / unsafe")
            continue
        if (
            not isinstance(record["renderer_commit"], str)
            or not re.fullmatch(r"[0-9a-f]{40}", record["renderer_commit"])
            or not isinstance(record["capture_command"], str)
            or len(record["capture_command"].strip()) < 12
            or "<" in record["capture_command"]
            or not _route_matches(screen.get("route"), record["route"])
            or not isinstance(record["locale"], str)
            or record["locale"] not in ("ar", "en")
            or type(record["device_pixel_ratio"]) not in (int, float)
            or not 0.5 <= record["device_pixel_ratio"] <= 8
            or any(size / record["device_pixel_ratio"] != int(size / record["device_pixel_ratio"]) for size in ref_size)
        ):
            errors.append(f"{screen_id}: invalid/placeholder screenshot provenance")
            continue

        # A byte-for-byte copy of the approved mockup is not independent
        # runtime evidence. Pixel parity alone cannot prove provenance, but
        # this explicit fail-closed check prevents the simplest false claim.
        if screenshot.read_bytes() == raw:
            errors.append(f"{screen_id}: capture is a byte-for-byte copy of its reference, not runtime evidence")
            continue

        try:
            with Image.open(reference_file) as image:
                reference_pixels = image.convert("RGBA")
            with Image.open(screenshot) as image:
                if image.format != "PNG":
                    raise ValueError("capture is not a PNG")
                captured_pixels = image.convert("RGBA")

            if reference_pixels.size != captured_pixels.size:
                errors.append(
                    f"{screen_id}: viewport mismatch {captured_pixels.size} != {ref_size}"
                )
                continue

            # Compare full RGBA pixel tuples; ImageChops.getbbox on RGBA can
            # accidentally miss RGB differences when alpha difference is zero.
            mismatched = sum(
                expected_pixel != actual_pixel
                for expected_pixel, actual_pixel in zip(
                    reference_pixels.getdata(), captured_pixels.getdata()
                )
            )
            if mismatched:
                errors.append(f"{screen_id}: {mismatched} mismatching pixels (zero tolerance)")
            else:
                matched += 1
        except (UnidentifiedImageError, OSError, ValueError) as exc:
            errors.append(f"{screen_id}: invalid captured screenshot: {exc}")

    if require_complete and matched != len(expected):
        errors.append(
            f"Final visual release gate: {len(expected) - matched}/{len(expected)} "
            "screens lack verified runtime pixel-parity evidence"
        )
    return errors, matched, len(expected)


def main(argv=None):
    parser = argparse.ArgumentParser(description="Validate 40 immutable visual references and capture evidence")
    parser.add_argument(
        "--require-complete",
        action="store_true",
        help="Fail release acceptance if any of the 40 screens is still pending or invalid",
    )
    args = parser.parse_args(argv)
    root = Path(__file__).resolve().parents[1]
    errors, matched, total = audit(root, require_complete=args.require_complete)
    if errors:
        print("FAIL ADR-010 visual fidelity evidence:\n" + "\n".join(errors))
        return 1
    print(
        f"PASS ADR-010: {total} immutable screen references mapped; "
        f"{matched}/{total} captured at exact pixel parity; "
        f"{total - matched} PENDING (not visually approved)"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
