#!/usr/bin/env python3
"""Independent Android APK byte/manifest QA. Never certifies distribution signing.

Requires an already compiled APK and Android SDK's aapt and apksigner.
Unit fixtures exercise the parser; only this CLI against real APK bytes
constitutes native artifact acceptance. No build or release occurs here.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import stat
import tempfile
from contextlib import contextmanager
import re
import subprocess
import sys
import zipfile
from pathlib import Path

HEX40 = re.compile(r"[0-9a-f]{40}\Z")
PACKAGE = re.compile(r"[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+\Z")
MAX_APK_BYTES = 512 * 1024 * 1024
MAX_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024
BADGING_PACKAGE = re.compile(
    r"^package:\s+name='([^']+)'\s+versionCode='([0-9]+)'\s+versionName='([^']+)'", re.M
)
PERMISSION = re.compile(r"^uses-permission(?:-sdk-[0-9]+)?:\s+name='([^']+)'", re.M)
SDK = re.compile(r"^(sdkVersion|targetSdkVersion):'([0-9]+)'", re.M)


class ApkAcceptanceError(ValueError):
    """Non-sensitive acceptance failure, suitable for CLI exit status."""


def _command(argv: list[str]) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(argv, capture_output=True, text=True,
                              timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ApkAcceptanceError("Android SDK inspection tool unavailable or timed out") from exc


def _inspect_zip(path: Path) -> str:
    if path.is_symlink() or not path.is_file() or path.suffix.lower() != ".apk":
        raise ApkAcceptanceError("Expected a regular APK file, not a link")
    size = path.stat().st_size
    if not 0 < size <= MAX_APK_BYTES:
        raise ApkAcceptanceError("APK size exceeds bounded acceptance limits")
    checksum = hashlib.sha256()
    with path.open("rb") as handle:
        while block := handle.read(1024 * 1024):
            checksum.update(block)
    try:
        with zipfile.ZipFile(path) as package:
            infos = package.infolist()
            names = [item.filename for item in infos]
            if len(names) != len(set(names)):
                raise ApkAcceptanceError("Duplicate APK ZIP entries")
            if not {"AndroidManifest.xml", "classes.dex"}.issubset(names):
                raise ApkAcceptanceError("APK is missing required native Android payload")
            if sum(item.file_size for item in infos) > MAX_UNCOMPRESSED_BYTES:
                raise ApkAcceptanceError("APK expanded size exceeds bounded acceptance limits")
            # Compare extraction destinations, not only central-directory
            # member strings. 'assets//x' and 'assets/x' can alias on disk;
            # 'assets/' and a regular file named 'assets' also collide.
            targets = set()
            regular_files = set()
            for item in infos:
                is_directory = item.is_dir()
                target = item.filename[:-1] if is_directory else item.filename
                parts = target.split("/")
                if (not target or item.filename.startswith("/")
                    or "\\" in item.filename
                    or any(part in ("", ".", "..") for part in parts)
                    or target in targets
                    or (is_directory and item.file_size != 0)
                    or (item.external_attr >> 16) & 0o170000 == 0o120000):
                    raise ApkAcceptanceError("Unsafe or colliding APK ZIP entry")
                targets.add(target)
                if not is_directory:
                    regular_files.add(target)

            # Even without explicit directory entries, a path cannot be both
            # a regular file and the parent of another member.
            for target in targets:
                components = target.split("/")
                for depth in range(1, len(components)):
                    if "/".join(components[:depth]) in regular_files:
                        raise ApkAcceptanceError("APK ZIP file and directory paths collide")
            if package.testzip() is not None:
                raise ApkAcceptanceError("APK ZIP CRC validation failed")
    except (zipfile.BadZipFile, EOFError, OSError) as exc:
        raise ApkAcceptanceError("Invalid native APK ZIP bytes") from exc
    return checksum.hexdigest()



def _fingerprint(info: os.stat_result) -> tuple[int, int, int, int, int]:
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


@contextmanager
def _frozen_apk(path: Path):
    """Freeze one regular APK before invoking any Android SDK process.

    Native tools only see the private snapshot, never a mutable input name.
    Reject source replacement or mutation before returning verified metadata.
    """
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    try:
        descriptor = os.open(path, flags)
    except OSError as exc:
        raise ApkAcceptanceError("Cannot pin a regular APK source") from exc
    try:
        before = os.fstat(descriptor)
        if (path.is_symlink() or not stat.S_ISREG(before.st_mode)
                or not 0 < before.st_size <= MAX_APK_BYTES):
            raise ApkAcceptanceError("Unsafe or unbounded APK source")
        with tempfile.TemporaryDirectory(prefix="walidsite-native-qa-") as directory:
            frozen = Path(directory) / "frozen.apk"
            total = 0
            with frozen.open("xb") as dest:
                os.chmod(frozen, 0o600)
                while block := os.read(descriptor, 1024 * 1024):
                    total += len(block)
                    if total > MAX_APK_BYTES:
                        raise ApkAcceptanceError("APK source exceeded bounded copy")
                    dest.write(block)
                dest.flush()
                os.fsync(dest.fileno())
            if total != before.st_size or _fingerprint(os.fstat(descriptor)) != _fingerprint(before):
                raise ApkAcceptanceError("APK source changed while being frozen")
            yield frozen
            try:
                after = os.stat(path, follow_symlinks=False)
            except OSError as exc:
                raise ApkAcceptanceError("APK source vanished during verification") from exc
            if _fingerprint(after) != _fingerprint(before):
                raise ApkAcceptanceError("APK source changed during native verification")
            if _inspect_zip(path) != _inspect_zip(frozen):
                raise ApkAcceptanceError("APK source bytes differ from verified snapshot")
    finally:
        os.close(descriptor)


def inspect(apk: Path, *, kind: str, expected_id: str,
            expected_version: str, expected_code: int, source_sha: str,
            aapt: str = "aapt", apksigner: str = "apksigner") -> dict:
    """Inspect real bytes and manifest; caller's source SHA is not attested."""
    if kind not in {"debug", "release-qa"} or not PACKAGE.fullmatch(expected_id):
        raise ApkAcceptanceError("Invalid build type or expected Android package ID")
    if (not expected_version or len(expected_version) > 100
            or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.+_-]*", expected_version)
            or type(expected_code) is not int or expected_code < 1
            or not HEX40.fullmatch(source_sha)):
        raise ApkAcceptanceError("Invalid expected native version or claimed source SHA")
    if kind == "release-qa" and expected_id.lower().startswith(
            ("com.example.", "org.example.", "net.example.")):
        raise ApkAcceptanceError("Release QA requires a real non-placeholder Android package ID")
    original = apk.absolute()
    with _frozen_apk(original) as frozen:
        return _inspect_frozen(frozen, kind=kind, expected_id=expected_id,
                               expected_version=expected_version, expected_code=expected_code,
                               source_sha=source_sha, aapt=aapt, apksigner=apksigner)


def _inspect_frozen(apk: Path, *, kind: str, expected_id: str,
                    expected_version: str, expected_code: int, source_sha: str,
                    aapt: str, apksigner: str) -> dict:
    """Every SDK invocation observes exactly the frozen, privately copied APK."""
    apk = apk.absolute()
    digest = _inspect_zip(apk)
    badging = _command([aapt, "dump", "badging", str(apk)])
    perms = _command([aapt, "dump", "permissions", str(apk)])
    if badging.returncode or perms.returncode:
        raise ApkAcceptanceError("Android manifest inspection failed")
    match = BADGING_PACKAGE.search(badging.stdout)
    if match is None:
        raise ApkAcceptanceError("Missing APK package/version fields")
    app_id, code, version = match.groups()
    if app_id != expected_id or int(code) != expected_code or version != expected_version:
        raise ApkAcceptanceError("APK identity or compiled version does not match expected")
    if "application-debuggable" in badging.stdout and kind == "release-qa":
        raise ApkAcceptanceError("Release QA APK must not be debuggable")
    permissions = sorted(set(PERMISSION.findall(perms.stdout)))
    if "android.permission.INTERNET" not in permissions:
        raise ApkAcceptanceError("Compiled APK is missing the required INTERNET permission")
    # Release-freeze permission inventory is pinned to the real signed debug APK
    # produced from main. A transitive plugin cannot silently add sensitive
    # permissions while keeping the receipt's integrity_verified flag green.
    allowed_permissions = {
        "android.permission.INTERNET",
        expected_id + ".DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION",
    }
    if set(permissions) - allowed_permissions:
        raise ApkAcceptanceError("Compiled APK requests unapproved Android permissions")
    # Both QA kinds must be cryptographically valid APKs. A QA signature is
    # never evidence of store/distribution signing authority.
    signature = _command([apksigner, "verify", "--verbose", str(apk)])
    if signature.returncode != 0:
        raise ApkAcceptanceError("APK cryptographic signature verification failed")

    # Do not certify a native build when its manifest omits, duplicates, or
    # contradicts the actual minimum/target Android API levels.
    sdk_fields = SDK.findall(badging.stdout)
    if (len(sdk_fields) != 2
            or {key for key, _ in sdk_fields} != {"sdkVersion", "targetSdkVersion"}):
        raise ApkAcceptanceError("APK minimum/target SDK manifest is missing or ambiguous")
    sdks = {key: int(value) for key, value in sdk_fields}
    if (sdks["sdkVersion"] < 1 or sdks["targetSdkVersion"] < sdks["sdkVersion"]
            or sdks["targetSdkVersion"] > 1000):
        raise ApkAcceptanceError("APK minimum/target SDK manifest is inconsistent")

    # The bytes we report must be the very same bytes inspected by aapt and
    # verified by apksigner. In particular, do not accept an APK overwritten
    # by another process between the initial SHA and signature verification.
    if _inspect_zip(apk) != digest:
        raise ApkAcceptanceError("APK contents changed during native verification")
    return {
        "apk_integrity_verified": True,
        "apk_sha256": digest,
        "apk_bytes": apk.stat().st_size,
        "build_kind": kind,
        "package_id": app_id,
        "version_name": version,
        "version_code": int(code),
        "min_sdk": sdks.get("sdkVersion"),
        "target_sdk": sdks.get("targetSdkVersion"),
        "permissions": permissions,
        "debug_signature_verified": kind == "debug",
        "apk_signature_cryptographically_valid": True,
        "distribution_signing_identity_verified": False,
        "device_install_launch_verified": False,
        "ios_build_verified": False,
        "source_sha_supplied_not_attested": source_sha,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verify already-built Android APK native bytes")
    parser.add_argument("--apk", type=Path, required=True)
    parser.add_argument("--kind", choices=["debug", "release-qa"], required=True)
    parser.add_argument("--package-id", required=True)
    parser.add_argument("--version-name", required=True)
    parser.add_argument("--version-code", type=int, required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--aapt", default="aapt")
    parser.add_argument("--apksigner", default="apksigner")
    args = parser.parse_args(argv)
    try:
        result = inspect(args.apk, kind=args.kind, expected_id=args.package_id,
                         expected_version=args.version_name,
                         expected_code=args.version_code, source_sha=args.source_sha,
                         aapt=args.aapt, apksigner=args.apksigner)
    except (ApkAcceptanceError, OSError) as exc:
        print(json.dumps({"apk_integrity_verified": False,
                          "production_ready": False,
                          "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
