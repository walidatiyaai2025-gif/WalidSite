#!/usr/bin/env python3
"""Capture the five T007 Laravel screens and emit strict visual diagnostics.

This tool never edits the ADR-010 evidence ledger and never converts a mismatch
into acceptance. It exists so GitHub Actions can produce real browser pixels
for inspection before final zero-difference admission.
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

from PIL import Image, ImageChops, ImageEnhance

SCREEN_IDS = ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--locale", default="en", choices=("en", "ar"))
    parser.add_argument("--dpr", default=1.0, type=float)
    args = parser.parse_args()

    if len(args.commit) != 40 or any(c not in "0123456789abcdef" for c in args.commit):
        raise SystemExit("A full lowercase renderer commit SHA is required.")

    root = Path(__file__).resolve().parents[1]
    contracts = json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))
    screens = {item["id"]: item for item in contracts["screens"]}
    out = root / "qa/visual-diagnostics/T007"
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)

    report = {
        "renderer_commit": args.commit,
        "locale": args.locale,
        "device_pixel_ratio": args.dpr,
        "screens": {},
    }

    for screen_id in SCREEN_IDS:
        screen = screens[screen_id]
        reference_path = root / screen["reference_image"]
        runtime_path = out / f"{screen_id}.runtime.png"
        reference_copy = out / f"{screen_id}.reference.png"
        diff_path = out / f"{screen_id}.diff.png"

        with Image.open(reference_path) as source:
            reference = source.convert("RGBA")
            width, height = reference.size
            reference.save(reference_copy)

        env = dict(os.environ)
        env.update({
            "WALIDSITE_CAPTURE_PATH": str(runtime_path),
            "WALIDSITE_CAPTURE_WIDTH": str(width),
            "WALIDSITE_CAPTURE_HEIGHT": str(height),
            "WALIDSITE_CAPTURE_LOCALE": args.locale,
            "WALIDSITE_CAPTURE_DPR": str(args.dpr),
            "WALIDSITE_CAPTURE_ROUTE": screen["route"],
        })
        result = subprocess.run(
            [sys.executable, "qa/render_web_screenshot.py"],
            cwd=root,
            env=env,
            check=False,
        )
        if result.returncode != 0 or not runtime_path.is_file():
            report["screens"][screen_id] = {
                "route": screen["route"],
                "status": "render_failed",
                "reference_size": [width, height],
                "renderer_exit": result.returncode,
            }
            continue

        with Image.open(runtime_path) as source:
            runtime = source.convert("RGBA")

        if runtime.size != reference.size:
            report["screens"][screen_id] = {
                "route": screen["route"],
                "status": "wrong_dimensions",
                "reference_size": list(reference.size),
                "runtime_size": list(runtime.size),
            }
            continue

        mismatches = sum(
            actual != expected
            for actual, expected in zip(runtime.getdata(), reference.getdata())
        )
        difference = ImageChops.difference(runtime, reference).convert("RGB")
        # Amplify diagnostic differences only. This file is never admissible
        # evidence and is not referenced by the visual fidelity ledger.
        ImageEnhance.Contrast(difference).enhance(4.0).save(diff_path)
        total = width * height
        report["screens"][screen_id] = {
            "route": screen["route"],
            "status": "exact" if mismatches == 0 else "mismatch",
            "reference_size": [width, height],
            "mismatching_rgba_pixels": mismatches,
            "total_pixels": total,
            "mismatch_ratio": mismatches / total,
        }

    report_path = out / "report.json"
    report_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(report_path.read_text(encoding="utf-8"))
    # Diagnostic capture intentionally does not gate the PR. Final acceptance
    # remains qa/capture_visual_evidence.py + --require-complete evidence.
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
