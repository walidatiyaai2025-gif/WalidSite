#!/usr/bin/env python3
"""Fail-closed same-source Android QA attachment gate for UNPUBLISHED release candidates.

Checks immutable bytes downloaded from a green, SHA-pinned GitHub Actions run.
The Android CI job separately verifies aapt/apksigner; this inspection does
NOT certify a publisher, production signing, device launch, or store release.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import hashlib
import json
import math
import os
from pathlib import Path
import re
import stat
import sys
import zipfile

from qa.android_qa_build_metadata import metadata

SHA = re.compile(r"[a-f0-9]{40}\Z")
APK_NAME = "WalidSite-Android-debug-QA.apk"
PROOF_NAME = "android-qa-verification.json"
IDENTITY_NAME = "android-qa-build-metadata.json"
CHECKSUM_NAME = APK_NAME + ".sha256"
EXPECTED = {APK_NAME, PROOF_NAME, IDENTITY_NAME, CHECKSUM_NAME}
MAX_APK_BYTES = 536870912
MAX_JSON_BYTES = 65536
MAX_RELEASE_ZIP_BYTES = 1073741824
MAX_BUILD_INFO_BYTES = 1048576
SEMVER = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")


def strict_receipt_json(raw: bytes) -> object:
    """Parse bounded release evidence with one unambiguous JSON meaning.

    Python's default parser silently accepts duplicate object keys, NaN,
    Infinity, and overflowing exponents. Independent PHP/publisher readers
    may interpret such bytes differently; no release receipt may do so.
    """
    def no_duplicates(pairs: list[tuple[str, object]]) -> dict:
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("Duplicate release evidence JSON member")
            result[key] = value
        return result

    def no_constants(_value: str) -> object:
        raise ValueError("Non-standard release evidence JSON constant")

    def finite_float(value: str) -> float:
        number = float(value)
        if not math.isfinite(number):
            raise ValueError("Non-finite release evidence JSON number")
        return number

    try:
        return json.loads(raw.decode("utf-8"),
                          object_pairs_hook=no_duplicates,
                          parse_constant=no_constants,
                          parse_float=finite_float)
    except (UnicodeError, ValueError, RecursionError) as exc:
        raise ValueError("Invalid or ambiguous release evidence JSON") from exc



def site_release_version(checkout: Path) -> str:
    """The exact checked-in Laravel release version, never an HTTP/env claim."""
    version_file = checkout / "backend/VERSION"
    if version_file.is_symlink() or not version_file.is_file():
        raise ValueError("Laravel release version source unavailable")
    if version_file.stat().st_size > 32:
        raise ValueError("Laravel release version exceeds identity bound")
    try:
        version = version_file.read_text(encoding="ascii").strip()
    except (UnicodeError, OSError) as exc:
        raise ValueError("Laravel release version cannot be read") from exc
    if not SEMVER.fullmatch(version):
        raise ValueError("Laravel version is not stable SemVer")
    return version


@contextmanager
def pinned_directory(path: Path):
    """Never resolve a symlink before opening the signed-run artifact root."""
    if not hasattr(os, "O_NOFOLLOW") or not hasattr(os, "O_DIRECTORY"):
        raise ValueError("Secure no-follow artifact inspection is unavailable")
    path = path.absolute()
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_DIRECTORY)
    except OSError as exc:
        raise ValueError("Cannot open isolated Android artifact directory") from exc
    try:
        before = os.fstat(fd)
        named = os.stat(path, follow_symlinks=False)
        if (not stat.S_ISDIR(before.st_mode) or not stat.S_ISDIR(named.st_mode)
                or (before.st_dev, before.st_ino) != (named.st_dev, named.st_ino)):
            raise ValueError("Android artifact directory identity changed")
        yield fd
        named = os.stat(path, follow_symlinks=False)
        if (not stat.S_ISDIR(named.st_mode)
                or (before.st_dev, before.st_ino) != (named.st_dev, named.st_ino)):
            raise ValueError("Android artifact directory changed during inspection")
    finally:
        os.close(fd)


def hash_bounded_stream(stream, maximum: int, *, retain: bool) -> tuple[bytes | None, str, int]:
    """Hash bounded bytes even if an opened regular file grows after fstat.

    The before/after inode check below still rejects any mutation. This
    additional in-flight cap prevents unbounded reads, hashing and memory
    allocation while an attacker appends to a previously-small file.
    """
    if maximum < 1:
        raise ValueError("Invalid artifact stream byte budget")
    digest = hashlib.sha256()
    data = bytearray() if retain else None
    total = 0
    while block := stream.read(min(1024 * 1024, maximum - total + 1)):
        total += len(block)
        if total > maximum:
            raise ValueError("Artifact stream exceeds bounded byte budget")
        digest.update(block)
        if data is not None:
            data.extend(block)
    return (bytes(data) if data is not None else None), digest.hexdigest(), total


MAX_ZIP_ENTRIES = 20000


def verify_bounded_zip_members(archive: zipfile.ZipFile, maximum: int) -> None:
    """Reject expansive ZIP metadata before CRC testing decompresses entries.

    Compressed archive bytes are independently bounded by read_verified.
    The expanded byte ceiling is intentionally higher to accommodate
    ordinary compressed Laravel assets while ruling out ZIP bombs.
    """
    entries = archive.infolist()
    if not 2 <= len(entries) <= MAX_ZIP_ENTRIES:
        raise ValueError("ZIP member count outside bounded acceptance")
    total = 0
    expanded_limit = maximum * 4
    for member in entries:
        if (member.file_size < 0 or member.compress_size < 0
                or member.file_size > expanded_limit):
            raise ValueError("ZIP member expands past byte budget")
        total += member.file_size
        if total > expanded_limit:
            raise ValueError("ZIP expands past cumulative byte budget")


def read_verified(fd: int, name: str, maximum: int, *, verify_zip: bool = False):
    """Read and hash one pinned single-link regular inode and its named path."""
    flags = os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_NONBLOCK", 0)
    try:
        descriptor = os.open(name, flags, dir_fd=fd)
    except OSError as exc:
        raise ValueError("Cannot open trusted Android artifact member") from exc
    with os.fdopen(descriptor, "rb") as stream:
        before = os.fstat(stream.fileno())
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or before.st_size < 1 or before.st_size > maximum):
            raise ValueError("Android artifact file is not a bounded regular inode")
        data, digest, total = hash_bounded_stream(
            stream, maximum, retain=not verify_zip
        )
        if verify_zip:
            stream.seek(0)
            try:
                with zipfile.ZipFile(stream) as apk:
                    verify_bounded_zip_members(apk, maximum)
                    if apk.testzip() is not None:
                        raise ValueError("APK has damaged CRC or no compiled contents")
            except (zipfile.BadZipFile, EOFError) as exc:
                raise ValueError("APK ZIP is invalid") from exc
        after = os.fstat(stream.fileno())
        named = os.stat(name, dir_fd=fd, follow_symlinks=False)
        identity = ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns")
        if (not stat.S_ISREG(named.st_mode) or named.st_nlink != 1
                or any(getattr(before, key) != getattr(after, key)
                       or getattr(before, key) != getattr(named, key)
                       for key in identity)):
            raise ValueError("Android artifact file changed while verified")
        if total != before.st_size:
            raise ValueError("Artifact stream length changed while verified")
        return data, digest, total


def verify(directory: Path, checkout: Path, source_sha: str) -> dict:
    if not SHA.fullmatch(source_sha):
        raise ValueError("Invalid exact source SHA for Android attachment")
    with pinned_directory(directory) as fd:
        if set(os.listdir(fd)) != EXPECTED:
            raise ValueError("Incomplete or unexpected Android QA artifact inventory")
        identity_bytes, _, _ = read_verified(fd, IDENTITY_NAME, MAX_JSON_BYTES)
        proof_bytes, _, _ = read_verified(fd, PROOF_NAME, MAX_JSON_BYTES)
        sidecar_bytes, _, _ = read_verified(fd, CHECKSUM_NAME, 256)
        _, digest, size = read_verified(fd, APK_NAME, MAX_APK_BYTES, verify_zip=True)
        identity = strict_receipt_json(identity_bytes)
        proof = strict_receipt_json(proof_bytes)
        if not isinstance(identity, dict) or not isinstance(proof, dict):
            raise ValueError("Android QA verification requires object receipts")
        if sidecar_bytes != (digest + "  " + APK_NAME + "\n").encode("ascii"):
            raise ValueError("Actual APK bytes do not match sha256 sidecar")

        expected = metadata(checkout)
        site_version = site_release_version(checkout)
        if expected["version_name"] != site_version:
            raise ValueError("Native QA version differs from installed site release")
        for field in ("package_id", "version_name", "version_code"):
            if (identity.get(field) != expected[field]
                    or proof.get(field) != expected[field]):
                raise ValueError("Compiled APK does not match checked-in mobile identity")
        for field in ("debug_qa_only", "placeholder_package_id", "distribution_ready"):
            if identity.get(field) is not expected[field]:
                raise ValueError("Android QA source identity is not a release credential")
        if (proof.get("apk_sha256") != digest
                or type(proof.get("apk_bytes")) is not int
                or proof["apk_bytes"] != size
                or proof.get("source_sha_supplied_not_attested") != source_sha
                or proof.get("build_kind") != "debug"
                or proof.get("apk_integrity_verified") is not True
                or proof.get("apk_signature_cryptographically_valid") is not True
                or proof.get("debug_signature_verified") is not True
                or proof.get("distribution_signing_identity_verified") is not False
                or proof.get("device_install_launch_verified") is not False
                or proof.get("ios_build_verified") is not False
                or proof.get("production_ready") is not False):
            raise ValueError("Native QA proof does not belong to this unpublished commit")
    return {
        "schema": "walidsite.android-release-attachment.v1",
        "source_sha": source_sha,
        "apk": APK_NAME,
        "apk_sha256": digest,
        "bytes": size,
        "package_id": expected["package_id"],
        "version_name": expected["version_name"],
        "site_release_version": site_version,
        "version_code": expected["version_code"],
        "android_qa_receipt_checked": True,
        "production_signing_verified": False,
        "device_launch_verified": False,
        "ios_build_verified": False,
        "production_ready": False,
    }


def verify_bundle(directory: Path, checkout: Path, source_sha: str,
                  candidates: Path) -> dict:
    """One receipt for the *actual* native, Setup and Update inspection bytes.

    Only permits unpublished test-signed ZIP proof. Never promotes a catalog,
    verifies a real publisher, installs code, or claims device acceptance.
    """
    android = verify(directory, checkout, source_sha)
    version = android["site_release_version"]
    with pinned_directory(candidates) as fd:
        manifest_file = f"WalidSite-Release-Manifest-v{version}.json"
        notes_file = f"WalidSite-Release-Notes-v{version}.md"
        manifest_bytes, manifest_sha, _ = read_verified(fd, manifest_file, MAX_JSON_BYTES)
        # Independently validate the immutable candidate document bytes, not
        # just the archive checksums within the manifest. A changed or missing
        # document sidecar must fail the unified web/native release receipt.
        for name, digest in ((manifest_file, manifest_sha), (notes_file, None)):
            if digest is None:
                _, digest, _ = read_verified(fd, name, MAX_JSON_BYTES)
            checksum_bytes, _, _ = read_verified(fd, name + ".sha256", 256)
            if checksum_bytes != f"{digest}  {name}\n".encode("ascii"):
                raise ValueError("Release document SHA256 sidecar mismatch")
        php_bytes, _, _ = read_verified(fd, "signed-zip-acceptance.json", MAX_JSON_BYTES)
        manifest = strict_receipt_json(manifest_bytes)
        php = strict_receipt_json(php_bytes)
        if (not isinstance(manifest, dict) or not isinstance(php, dict)
                or manifest.get("schema") != "walidsite.release-candidate.v1"
                or manifest.get("product") != "walidsite"
                or manifest.get("source_sha") != source_sha
                or manifest.get("version") != version
                or manifest.get("published") is not False
                or manifest.get("production_ready") is not False
                or manifest.get("publisher_signature_verified") is not False
                or not isinstance(manifest.get("acceptance"), dict)
                or not manifest["acceptance"]
                or any(v is not False for v in manifest["acceptance"].values())):
            raise ValueError("Release candidate identity or unpublished status rejected")
        digests = {}
        for kind in ("setup", "update"):
            info = manifest.get(kind)
            name = f"WalidSite-{kind.capitalize()}-v{version}.zip"
            if (not isinstance(info, dict) or info.get("file") != name
                    or not isinstance(info.get("sha256"), str)
                    or not re.fullmatch(r"[a-f0-9]{64}", info["sha256"])
                    or type(info.get("bytes")) is not int):
                raise ValueError("Release archive manifest is incomplete")
            _, digest, size = read_verified(fd, name, MAX_RELEASE_ZIP_BYTES, verify_zip=True)
            checksum_bytes, _, _ = read_verified(fd, name + ".sha256", 256)
            if (digest != info["sha256"] or size != info["bytes"]
                    or checksum_bytes != f"{digest}  {name}\n".encode("ascii")):
                raise ValueError("Release ZIP identity differs from manifest or sidecar")
            digests[kind] = digest
        # Build Info must describe these exact verified ZIP bytes and source,
        # not just carry an independently checksummed but unrelated document.
        build_name = f"WalidSite-Build-Info-v{version}.json"
        build_bytes, build_digest, _ = read_verified(fd, build_name, MAX_BUILD_INFO_BYTES)
        build_sidecar, _, _ = read_verified(fd, build_name + ".sha256", 256)
        if build_sidecar != f"{build_digest}  {build_name}\n".encode("ascii"):
            raise ValueError("Build Info SHA256 sidecar mismatch")
        build_info = strict_receipt_json(build_bytes)
        described = manifest.get("build_info")
        dependencies = build_info.get("production_dependencies") if isinstance(build_info, dict) else None
        migrations = build_info.get("migration_sha256") if isinstance(build_info, dict) else None
        if (not isinstance(described, dict)
                or not isinstance(build_info, dict)
                or described.get("file") != build_name
                or described.get("sha256") != build_digest
                or build_info.get("schema") != "walidsite.unpublished-build-info.v1"
                or build_info.get("product") != "walidsite"
                or build_info.get("version") != version
                or build_info.get("source_sha") != source_sha
                or build_info.get("setup_sha256") != digests["setup"]
                or build_info.get("update_sha256") != digests["update"]
                or not isinstance(build_info.get("composer_lock_sha256"), str)
                or not re.fullmatch(r"[a-f0-9]{64}", build_info["composer_lock_sha256"])
                or described.get("composer_lock_sha256") != build_info["composer_lock_sha256"]
                or not isinstance(dependencies, list)
                or type(described.get("production_dependencies")) is not int
                or described["production_dependencies"] != len(dependencies)
                or not isinstance(migrations, dict)
                or type(described.get("migration_files")) is not int
                or described["migration_files"] != len(migrations)
                or build_info.get("minimum_php") != manifest["update"].get("min_php")
                or build_info.get("publisher_signature_verified") is not False
                or build_info.get("upgrade_compatibility_verified") is not False
                or build_info.get("production_ready") is not False):
            raise ValueError("Build Info does not match source-pinned release archives")
        if (php.get("schema") != "walidsite.signed-zip-ci-acceptance.v1"
                or php.get("version") != version
                or php.get("source_sha") != source_sha
                or php.get("artifact_sha256") != digests["update"]
                or type(php.get("verified_files")) is not int
                or php["verified_files"] < 4
                or php.get("negative_rejections") != 7
                or php.get("test_only_ephemeral_key") is not True
                or php.get("publisher_signature_verified") is not False
                or php.get("code_update_performed") is not False
                or php.get("production_ready") is not False):
            raise ValueError("PHP ZIP/Ed25519 negative acceptance proof mismatch")
    return {
        "schema": "walidsite.unpublished-full-release-candidate.v1",
        "source_sha": source_sha,
        "version": version,
        "android_package_id": android["package_id"],
        "android_version_code": android["version_code"],
        "android_apk_sha256": android["apk_sha256"],
        "setup_sha256": digests["setup"],
        "update_sha256": digests["update"],
        "build_info_sha256": build_digest,
        "native_and_web_version_match": True,
        "same_sha_assets_verified": True,
        "php_zip_negative_rejections": php["negative_rejections"],
        "test_only_ephemeral_key": True,
        "publisher_signature_verified": False,
        "android_distribution_signing_verified": False,
        "device_launch_verified": False,
        "code_update_performed": False,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dir", required=True, type=Path)
    parser.add_argument("--checkout", required=True, type=Path)
    parser.add_argument("--sha", required=True)
    parser.add_argument("--candidates", type=Path, help="Require unified actual Setup/Update/native proof")
    args = parser.parse_args(argv)
    try:
        result = (verify_bundle(args.dir, args.checkout, args.sha, args.candidates)
                  if args.candidates is not None
                  else verify(args.dir, args.checkout, args.sha))
    except (ValueError, OSError, zipfile.LargeZipFile) as exc:
        print(json.dumps({"android_qa_receipt_checked": False,
                          "production_ready": False,
                          "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
