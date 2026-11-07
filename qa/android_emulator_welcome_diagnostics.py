#!/usr/bin/env python3
"""Genuine, source-pinned MOBILE-01 emulator pixels; diagnostics, NEVER visual acceptance.

The installed APK must already have passed qa.android_device_smoke on the same
fresh Android 35 emulator. This only captures the public unauthenticated Welcome
screen and never sends user data or produces an accepted visual-fidelity ledger.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import time
from pathlib import Path
from typing import Callable

from PIL import Image

from qa.render_android_screenshot import PACKAGE, render
from qa.visual_diagnostics import _compare_rgba

SHA = re.compile(r"[0-9a-f]{40}\Z")
SERIAL = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\Z")
REFERENCE = Path("assets/screen-reference/mobile_welcome.png")


class WelcomeDiagnosticError(ValueError):
    """Safe error type: raw ADB/UI output and the device serial stay private."""


def adb_command(serial: str, *args: str) -> str:
    try:
        result = subprocess.run(
            ["adb", "-s", serial, *args], capture_output=True, text=True,
            timeout=45, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise WelcomeDiagnosticError("Android emulator operation unavailable") from exc
    if result.returncode:
        raise WelcomeDiagnosticError("Android emulator operation failed")
    return result.stdout.strip()


def collect(
    root: Path, output_dir: Path, *, source_sha: str, checked_sha: str,
    serial: str, command: Callable[..., str] = adb_command,
    capture: Callable[..., Path] = render,
    sleeper: Callable[[float], None] = time.sleep,
) -> dict:
    """Require real Welcome identity *and* bytes, then compute exact RGBA mismatch."""
    if not SHA.fullmatch(source_sha) or source_sha != checked_sha:
        raise WelcomeDiagnosticError("Capture source SHA does not match checkout")
    if not SERIAL.fullmatch(serial):
        raise WelcomeDiagnosticError("Invalid explicitly selected emulator")
    ref = root / REFERENCE
    if ref.is_symlink() or not ref.is_file():
        raise WelcomeDiagnosticError("Immutable Welcome reference unavailable")
    if output_dir.is_symlink() or not output_dir.is_dir():
        raise WelcomeDiagnosticError("Trusted diagnostics directory unavailable")
    runtime = output_dir / "welcome-runtime.png"
    diff = output_dir / "welcome-difference.png"
    receipt = output_dir / "welcome-diagnostics.json"
    if any(path.exists() or path.is_symlink() for path in (runtime, diff, receipt)):
        raise WelcomeDiagnosticError("Refusing to overwrite prior Android visual evidence")

    try:
        with Image.open(ref) as opened:
            if opened.format != "PNG":
                raise WelcomeDiagnosticError("Invalid immutable Welcome reference")
            width, height = opened.size
            if not 240 <= width <= 4096 or not 400 <= height <= 4096:
                raise WelcomeDiagnosticError("Unsupported native reference geometry")
            reference = opened.convert("RGBA")
        # A fresh emulator is resized to the original bitmap *physical* pixels,
        # not CSS/device-independent points. DPR=1 is explicitly configured here.
        command(serial, "shell", "wm", "size", f"{width}x{height}")
        command(serial, "shell", "wm", "density", "160")
        command(serial, "shell", "am", "force-stop", PACKAGE)
        start = command(serial, "shell", "am", "start", "-W", "-n",
                        PACKAGE + "/.MainActivity")
        if ("Status: ok" not in start.splitlines()
                or not any(PACKAGE + "/.MainActivity" in row
                           or PACKAGE + "/" + PACKAGE + ".MainActivity" in row
                           for row in start.splitlines() if row.startswith("Activity: "))):
            raise WelcomeDiagnosticError("Emulator failed to relaunch canonical Welcome activity")
        if not command(serial, "shell", "pidof", PACKAGE).strip().isdigit():
            raise WelcomeDiagnosticError("Flutter process absent after emulator restart")
        sleeper(2.0)
        env = {
            "WALIDSITE_CAPTURE_ROUTE": "/welcome",
            "WALIDSITE_CAPTURE_LOCALE": "en",
            "WALIDSITE_MOBILE_ADB_SERIAL": serial,
            "WALIDSITE_CAPTURE_WIDTH": str(width),
            "WALIDSITE_CAPTURE_HEIGHT": str(height),
            "WALIDSITE_CAPTURE_DPR": "1",
            "WALIDSITE_CAPTURE_PATH": str(runtime.resolve()),
        }
        # The existing hardened native renderer verifies foreground activity,
        # public Welcome semantics and geometry both before and after ADB PNG.
        produced = capture(env=env)
        if produced != runtime.resolve() and produced != runtime:
            raise WelcomeDiagnosticError("Native capture wrote unexpected destination")
        if not runtime.is_file() or runtime.is_symlink():
            raise WelcomeDiagnosticError("Native Welcome screenshot unavailable")
        with Image.open(runtime) as opened:
            if opened.format != "PNG" or opened.size != (width, height):
                raise WelcomeDiagnosticError("Actual native screenshot dimensions invalid")
            actual = opened.convert("RGBA")
        mismatches, total = _compare_rgba(reference, actual, diff)
        proof = {
            "schema": "walidsite.mobile-01-emulator-visual-diagnostic.v1",
            "source_sha": source_sha,
            "screen_id": "MOBILE-01",
            "route": "/welcome",
            "locale": "en",
            "device_kind": "disposable_android_emulator",
            "native_resolution": [width, height],
            "configured_dpr": 1,
            "reference_image": str(REFERENCE),
            "runtime_capture": runtime.name,
            "runtime_png_sha256": hashlib.sha256(runtime.read_bytes()).hexdigest(),
            "mismatching_rgba_pixels": mismatches,
            "total_pixels": total,
            "status": "mismatch" if mismatches else "exact_pixels_unattested",
            "evidence_admitted": False,
            "visual_fidelity_verified": False,
            "physical_device_type_verified": False,
            "production_ready": False,
        }
        with receipt.open("x", encoding="utf-8") as out:
            json.dump(proof, out, ensure_ascii=False, sort_keys=True)
            out.write("\n")
        return proof
    except Exception:
        # Never leave a half-captured image that downstream callers might
        # mistake for a device-attested artifact.
        for path in (receipt, runtime, diff):
            if path.exists() and not path.is_symlink():
                path.unlink()
        raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--serial", required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    try:
        result = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=root, capture_output=True, text=True,
            timeout=10, check=True,
        )
        proof = collect(
            root, args.output_dir.resolve(), source_sha=args.source_sha,
            checked_sha=result.stdout.strip(), serial=args.serial,
        )
    except Exception:
        print("MOBILE-01 REAL EMULATOR VISUAL DIAGNOSTICS NOT VERIFIED",
              file=sys.stderr)
        return 1
    print(json.dumps(proof, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
