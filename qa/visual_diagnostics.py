#!/usr/bin/env python3
"""Produce REAL screenshot mismatch diagnostics for any canonical v6 screen.

This is an inspection tool, NOT evidence admission. Only the independent
qa/capture_visual_evidence.py zero-difference gate can update the ledger.
A capture plan executes trusted local renderer commands, not untrusted JSON.
"""
import argparse
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, UnidentifiedImageError

try:
    from qa.capture_visual_batch import validate_plan, verified_head, CapturePlanError
    from qa.validate_visual_fidelity import audit
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from capture_visual_batch import validate_plan, verified_head, CapturePlanError
    from validate_visual_fidelity import audit


MAX_RENDERED_PNG_BYTES = 64 * 1024 * 1024


def _compare_rgba(reference: Image.Image, actual: Image.Image, diff_path: Path) -> tuple[int, int]:
    """Count all differing RGBA pixels; save a binary preview, not an acceptance mask."""
    pixels = list(zip(reference.getdata(), actual.getdata()))
    count = sum(left != right for left, right in pixels)
    if count:
        # Opaque white = pixel differs in any R/G/B/A channel. No tolerance.
        diagnostic = Image.new("L", reference.size)
        diagnostic.putdata([255 if a != b else 0 for a, b in pixels])
        diagnostic.save(diff_path, format="PNG")
    return count, len(pixels)


def diagnose(root: Path, plan: object, commit: str, *, runner=subprocess.run,
             dry_run: bool = False, group: str | None = None) -> dict:
    """Never edit design/visual-fidelity-evidence.json or source PNGs."""
    root = root.resolve()
    # Group names are a finite, trusted set: no user-controlled path segments.
    if group is not None and group not in ("web", "admin", "mobile"):
        raise CapturePlanError("Unknown diagnostic output group")
    # Complete plan validation and real Git SHA first: invalid plans never execute.
    entries = validate_plan(root, plan)
    if not verified_head(root, commit):
        raise CapturePlanError("Renderer SHA does not match checked-out Git HEAD")
    issues, _, _ = audit(root)
    if issues:
        raise CapturePlanError("Existing reference or ledger integrity failed")

    report = {
        "renderer_commit": commit,
        "evidence_admitted": False,
        "real_runtime_required": True,
        "screens": {},
    }
    if dry_run:
        for item in entries:
            report["screens"][item["screen_id"]] = {"status": "planned_only"}
        return report

    qa_dir = root / "qa"
    parent = qa_dir / "visual-diagnostics"
    target = parent / (commit + ("-" + group if group else ""))
    if qa_dir.is_symlink() or parent.is_symlink() or target.exists() or target.is_symlink():
        raise CapturePlanError("Refusing unsafe or existing diagnostics destination")
    parent.mkdir(parents=True, exist_ok=True)
    if not parent.resolve().is_relative_to(qa_dir.resolve()):
        raise CapturePlanError("Diagnostics directory escapes QA root")
    target.mkdir()

    screens = {
        screen["id"]: screen
        for screen in json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))["screens"]
    }
    for item in entries:
        screen_id = item["screen_id"]
        ref_path = root / screens[screen_id]["reference_image"]
        info = {
            "route": item["route"],
            "locale": item["locale"],
            "device_pixel_ratio": item["dpr"],
            "status": "renderer_failed",
        }
        report["screens"][screen_id] = info
        with Image.open(ref_path) as im:
            reference = im.convert("RGBA")
        width, height = reference.size
        info["reference_size"] = [width, height]

        with tempfile.TemporaryDirectory(prefix="runtime-", dir=target) as work:
            output = Path(work) / "runtime.png"
            env = dict(os.environ)
            env.update({
                "WALIDSITE_CAPTURE_PATH": str(output),
                "WALIDSITE_CAPTURE_WIDTH": str(width),
                "WALIDSITE_CAPTURE_HEIGHT": str(height),
                "WALIDSITE_CAPTURE_LOCALE": item["locale"],
                "WALIDSITE_CAPTURE_DPR": str(item["dpr"]),
                "WALIDSITE_CAPTURE_ROUTE": item["route"],
            })
            # A diagnostic comparison is meaningful only if the REAL source
            # checkout remains pinned across the entire renderer invocation.
            # Check even when the renderer fails or times out: a partial
            # process must not alter source for subsequent screen captures.
            try:
                try:
                    result = runner(
                        item["renderer_command"], cwd=root, env=env,
                        timeout=item["timeout"], check=False,
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                    )
                except (OSError, subprocess.TimeoutExpired):
                    info["status"] = "renderer_failed"
                    continue
            finally:
                if not verified_head(root, commit):
                    raise CapturePlanError(
                        "Renderer modified checked-out Git source during diagnostics"
                    )
            if result.returncode != 0 or output.is_symlink() or not output.is_file():
                info["status"] = "renderer_failed"
                continue
            if output.stat().st_size > MAX_RENDERED_PNG_BYTES:
                info["status"] = "oversized_png"
                continue
            try:
                with Image.open(output) as im:
                    if im.format != "PNG":
                        info["status"] = "invalid_png"
                        continue
                    actual = im.convert("RGBA")
            except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError):
                info["status"] = "invalid_png"
                continue

            info["runtime_size"] = list(actual.size)
            if actual.size != reference.size:
                info["status"] = "wrong_dimensions"
                continue
            if output.read_bytes() == ref_path.read_bytes():
                info["status"] = "source_copy_rejected"
                continue

            # A successful image is an independently produced renderer OUTPUT;
            # its provenance must still be established by actual runtime audit.
            output_copy = target / f"{screen_id}.runtime.png"
            output_copy.write_bytes(output.read_bytes())
            difference = target / f"{screen_id}.difference-preview.png"
            mismatches, total = _compare_rgba(reference, actual, difference)
            info["mismatching_rgba_pixels"] = mismatches
            info["total_pixels"] = total
            info["runtime_capture"] = output_copy.name
            if mismatches:
                info["status"] = "mismatch"
                info["difference_preview"] = difference.name
            else:
                info["status"] = "exact_pixels_unattested"

    # Diagnostic report is intentionally separate from the evidence ledger.
    (target / "report.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    return report


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--group", choices=("web", "admin", "mobile"))
    args = parser.parse_args(argv)
    try:
        plan = json.loads(args.plan.read_text(encoding="utf-8"))
        report = diagnose(Path(__file__).resolve().parents[1], plan,
                          args.commit, dry_run=args.dry_run, group=args.group)
    except (OSError, ValueError) as exc:
        print("DIAGNOSTIC PLAN REJECTED: " + str(exc), file=sys.stderr)
        return 2
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    return 0 if args.dry_run or all(
        item["status"] == "exact_pixels_unattested"
        for item in report["screens"].values()
    ) else 1


if __name__ == "__main__":
    sys.exit(main())
