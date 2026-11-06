#!/usr/bin/env python3
"""Fail-closed Android debug APK install/launch smoke on an explicitly selected device.

Requires a genuine APK, Android aapt/apksigner and authorized adb. It does NOT
certify visual fidelity, store signing or production readiness.
Run: python -m qa.android_device_smoke --help
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from qa.native_android_acceptance import ApkAcceptanceError, inspect

SERIAL = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
ANDROID_ID = re.compile(r"[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\Z")


class DeviceSmokeError(ValueError):
    """Safe rejection: never incorporate raw tool output, paths or device serials."""


def _command(argv: list[str], *, timeout: int = 15) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(argv, capture_output=True, text=True,
                              timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise DeviceSmokeError("Android device command unavailable or timed out") from exc


def _checked_adb(adb: str, serial: str, *arguments: str,
                 timeout: int = 15, stage: str = "unknown") -> str:
    result = _command([adb, "-s", serial, *arguments], timeout=timeout)
    if result.returncode:
        raise DeviceSmokeError("Android device command failed [" + stage + "]")
    return result.stdout.strip()


def _validate(apk: Path, package_id: str, expected_sha256: str,
              serial: str, confirm_install: bool) -> None:
    if not confirm_install:
        raise DeviceSmokeError("Explicit --confirm-install is required")
    if not SERIAL.fullmatch(serial) or not ANDROID_ID.fullmatch(package_id):
        raise DeviceSmokeError("Invalid Android serial or package identity")
    if not SHA256.fullmatch(expected_sha256):
        raise DeviceSmokeError("Expected 64-digit lowercase APK SHA256")
    if apk.is_symlink() or not apk.is_file() or apk.suffix.lower() != ".apk":
        raise DeviceSmokeError("Expected regular non-symlink APK")


def smoke(apk: Path, *, package_id: str, version_name: str,
          version_code: int, source_sha: str, expected_sha256: str,
          serial: str, confirm_install: bool, allow_replace: bool = False,
          adb: str = "adb", aapt: str = "aapt",
          apksigner: str = "apksigner") -> dict:
    """Inspect a private APK snapshot before any ADB mutation, then install/launch.

    The supplied source SHA is NOT authenticated by this device smoke.
    Existing app replacement requires a separate explicit opt-in.
    """
    _validate(apk, package_id, expected_sha256, serial, confirm_install)
    with tempfile.TemporaryDirectory(prefix="walidsite-device-qa-") as root:
        candidate = Path(root) / "candidate.apk"
        try:
            shutil.copyfile(apk, candidate)
        except OSError as exc:
            raise DeviceSmokeError("Unable to isolate APK for inspection") from exc
        receipt = inspect(candidate, kind="debug", expected_id=package_id,
                          expected_version=version_name,
                          expected_code=version_code, source_sha=source_sha,
                          aapt=aapt, apksigner=apksigner)
        if (not receipt.get("apk_integrity_verified")
                or not receipt.get("debug_signature_verified")
                or not receipt.get("apk_signature_cryptographically_valid")
                or receipt.get("apk_sha256") != expected_sha256
                or receipt.get("package_id") != package_id):
            raise DeviceSmokeError("APK signature, integrity or identity mismatch")
        # Also compare independent real bytes: a free-standing JSON receipt is not trusted.
        checksum = hashlib.sha256()
        with candidate.open("rb") as handle:
            for block in iter(lambda: handle.read(1024 * 1024), b""):
                checksum.update(block)
        if checksum.hexdigest() != expected_sha256:
            raise DeviceSmokeError("Copied APK bytes do not match expected SHA256")

        if _checked_adb(adb, serial, "get-state", stage="get-state") != "device":
            raise DeviceSmokeError("Selected Android device is not online")
        if _checked_adb(adb, serial, "shell", "getprop", "sys.boot_completed", stage="boot") != "1":
            raise DeviceSmokeError("Selected Android device has not finished booting")
        # Android 35 can return a nonzero exit code for `pm path` when an
        # application is simply absent on a clean emulator. Query the user-0
        # package list instead, whose empty successful result means absent.
        # Match the exact full identity, never a substring or an adb error.
        package_list = _checked_adb(
            adb, serial, "shell", "pm", "list", "packages",
            "--user", "0", package_id, stage="preinstall-query",
        )
        if package_list not in ("", "package:" + package_id):
            raise DeviceSmokeError("Unexpected device package query response")
        preinstalled = package_list == "package:" + package_id
        if preinstalled and not allow_replace:
            raise DeviceSmokeError("Existing app needs explicit --allow-replace")

        flags = ["-r"] if preinstalled else []
        install_output = _checked_adb(adb, serial, "install", *flags, str(candidate),
                                      timeout=180, stage="install")
        if "Success" not in install_output.splitlines():
            raise DeviceSmokeError("Android package installer did not confirm success")
        installed = _checked_adb(adb, serial, "shell", "pm", "path", package_id,
                                 stage="postinstall-query")
        if not installed.startswith("package:"):
            raise DeviceSmokeError("Installed Android package not found after install")
        launch_output = _checked_adb(adb, serial, "shell", "am", "start", "-W",
                                     "-n", package_id + "/.MainActivity", timeout=45,
                                     stage="launch")
        if ("Status: ok" not in launch_output.splitlines()
                or not any(line in {"Activity: " + package_id + "/.MainActivity",
                                    "Activity: " + package_id + "/" + package_id + ".MainActivity"}
                           for line in launch_output.splitlines())):
            raise DeviceSmokeError("Android launcher did not report expected activity")
        pid = _checked_adb(adb, serial, "shell", "pidof", package_id,
                           stage="process-check")
        if not pid or not all(part.isdecimal() and int(part) > 0 for part in pid.split()):
            raise DeviceSmokeError("Android app process not running after launch")
        return {
            "device_install_launch_verified": True,
            "apk_sha256": expected_sha256,
            "package_id": package_id,
            "version_name": version_name,
            "version_code": version_code,
            "replaced_existing_install": preinstalled,
            "source_sha_supplied_not_attested": source_sha,
            "physical_device_type_verified": False,
            "visual_fidelity_verified": False,
            "distribution_signing_identity_verified": False,
            "ios_build_verified": False,
            "production_ready": False,
        }


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="Real Android debug APK device smoke; modifies explicit device")
    p.add_argument("--apk", type=Path, required=True)
    p.add_argument("--package-id", required=True)
    p.add_argument("--version-name", required=True)
    p.add_argument("--version-code", type=int, required=True)
    p.add_argument("--source-sha", required=True)
    p.add_argument("--expected-sha256", required=True)
    p.add_argument("--serial", required=True, help="Explicit adb serial (never printed)")
    p.add_argument("--confirm-install", action="store_true", help="Authorize install on selected device")
    p.add_argument("--allow-replace", action="store_true", help="Also authorize existing app upgrade")
    p.add_argument("--adb", default="adb")
    p.add_argument("--aapt", default="aapt")
    p.add_argument("--apksigner", default="apksigner")
    args = p.parse_args(argv)
    try:
        value = smoke(args.apk, package_id=args.package_id,
                      version_name=args.version_name, version_code=args.version_code,
                      source_sha=args.source_sha, expected_sha256=args.expected_sha256,
                      serial=args.serial, confirm_install=args.confirm_install,
                      allow_replace=args.allow_replace, adb=args.adb,
                      aapt=args.aapt, apksigner=args.apksigner)
    except (DeviceSmokeError, ApkAcceptanceError, OSError, ValueError) as exc:
        print(json.dumps({"device_install_launch_verified": False,
                          "production_ready": False,
                          "error": type(exc).__name__,
                          "reason": str(exc) if isinstance(exc, DeviceSmokeError)
                          else "Android APK inspection or command rejected"}), file=sys.stderr)
        return 1
    print(json.dumps(value, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
