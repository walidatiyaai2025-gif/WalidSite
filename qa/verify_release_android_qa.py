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
        h = hashlib.sha256()
        data = bytearray() if not verify_zip else None
        while block := stream.read(1024 * 1024):
            h.update(block)
            if data is not None:
                data.extend(block)
        if verify_zip:
            stream.seek(0)
            try:
                with zipfile.ZipFile(stream) as apk:
                    if apk.testzip() is not None or len(apk.infolist()) < 2:
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
        return (bytes(data) if data is not None else None), h.hexdigest(), before.st_size


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
        try:
            identity = json.loads(identity_bytes.decode("utf-8"))
            proof = json.loads(proof_bytes.decode("utf-8"))
        except (UnicodeError, ValueError) as exc:
            raise ValueError("Invalid Android QA verification JSON") from exc
        if not isinstance(identity, dict) or not isinstance(proof, dict):
            raise ValueError("Android QA verification requires object receipts")
        if sidecar_bytes != (digest + "  " + APK_NAME + "\n").encode("ascii"):
            raise ValueError("Actual APK bytes do not match sha256 sidecar")

        expected = metadata(checkout)
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
        "version_code": expected["version_code"],
        "android_qa_receipt_checked": True,
        "production_signing_verified": False,
        "device_launch_verified": False,
        "ios_build_verified": False,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dir", required=True, type=Path)
    parser.add_argument("--checkout", required=True, type=Path)
    parser.add_argument("--sha", required=True)
    args = parser.parse_args(argv)
    try:
        result = verify(args.dir, args.checkout, args.sha)
    except (ValueError, OSError, zipfile.LargeZipFile) as exc:
        print(json.dumps({"android_qa_receipt_checked": False,
                          "production_ready": False,
                          "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
