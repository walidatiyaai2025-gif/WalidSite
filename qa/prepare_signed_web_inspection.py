#!/usr/bin/env python3
"""Stage a SHA-pinned, UNSIGNED Release candidate for manual Ed25519 inspection.

The output is private, immutable input for the existing PHP publisher signer.
This neither publishes a GitHub Release nor authorizes a live code update.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat

from qa.verify_release_android_qa import (
    MAX_JSON_BYTES, MAX_RELEASE_ZIP_BYTES, pinned_directory,
    read_verified, strict_receipt_json,
)

SHA = re.compile(r"[a-f0-9]{40}\\Z")
SEMVER = re.compile(r"(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\Z")
FIELDS = ("product", "version", "source_sha", "artifact_sha256",
          "artifact_size", "min_php", "artifact_file")


def prepare(candidates: Path, destination: Path, version: str, source_sha: str) -> dict:
    if not SEMVER.fullmatch(version) or not SHA.fullmatch(source_sha):
        raise ValueError("Invalid release source/version")
    if (destination.is_symlink() or not destination.is_dir()
            or destination.stat().st_mode & 0o077
            or list(destination.iterdir())):
        raise ValueError("Publisher inspection directory must be empty, private 0700")
    original_name = f"WalidSite-Update-v{version}.zip"
    publisher_name = f"WalidSite-update-v{version}.zip"
    manifest_name = f"WalidSite-Release-Manifest-v{version}.json"
    notes_name = f"WalidSite-Release-Notes-v{version}.md"

    with pinned_directory(candidates) as fd:
        manifest_raw, manifest_hash, _ = read_verified(fd, manifest_name, MAX_JSON_BYTES)
        manifest_checksum, _, _ = read_verified(fd, manifest_name + ".sha256", 256)
        notes_raw, notes_hash, _ = read_verified(fd, notes_name, MAX_JSON_BYTES)
        notes_checksum, _, _ = read_verified(fd, notes_name + ".sha256", 256)
        if (manifest_checksum != f"{manifest_hash}  {manifest_name}\\n".encode("ascii")
                or notes_checksum != f"{notes_hash}  {notes_name}\\n".encode("ascii")):
            raise ValueError("Candidate manifest/notes checksums rejected")
        manifest = strict_receipt_json(manifest_raw)
        if (not isinstance(manifest, dict)
                or manifest.get("schema") != "walidsite.release-candidate.v1"
                or manifest.get("product") != "walidsite"
                or manifest.get("version") != version
                or manifest.get("source_sha") != source_sha
                or manifest.get("published") is not False
                or manifest.get("publisher_signature_verified") is not False
                or manifest.get("production_ready") is not False):
            raise ValueError("Publisher candidate identity rejected")
        update = manifest.get("update")
        if (not isinstance(update, dict)
                or update.get("file") != original_name
                or update.get("publisher_upload_name") != publisher_name
                or update.get("min_php") != "8.3.0"
                or type(update.get("bytes")) is not int
                or not 0 < update["bytes"] <= MAX_RELEASE_ZIP_BYTES
                or not isinstance(update.get("sha256"), str)
                or not re.fullmatch(r"[a-f0-9]{64}", update["sha256"])):
            raise ValueError("Candidate update publisher metadata rejected")
        source_checksum, _, _ = read_verified(fd, original_name + ".sha256", 256)
        if source_checksum != f"{update['sha256']}  {original_name}\\n".encode("ascii"):
            raise ValueError("Candidate Update checksum rejected")
        # This checks actual ZIP CRC/expanded limits before any key is used.
        _, verified_hash, size = read_verified(fd, original_name, MAX_RELEASE_ZIP_BYTES,
                                               verify_zip=True)
        if verified_hash != update["sha256"] or size != update["bytes"]:
            raise ValueError("Candidate ZIP bytes differ from reviewed manifest")

        try:
            notes = notes_raw.decode("utf-8")
        except UnicodeError as exc:
            raise ValueError("Candidate release notes are invalid UTF-8") from exc
        if (not 1 <= len(notes_raw) <= 2048
                or any(ord(ch) < 32 and ch not in ("\\n",) for ch in notes)
                or any(ord(ch) in range(0x202a, 0x202f) or ord(ch) in range(0x2066, 0x206a)
                       for ch in notes)):
            raise ValueError("Candidate notes cannot be signed safely")

        # The second descriptor is pinned and rehashed while copying to a
        # separate owned inode. A changed candidate is rejected even when a
        # SHA sidecar was previously inspected.
        flags = os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_NONBLOCK", 0)
        incoming_fd = os.open(original_name, flags, dir_fd=fd)
        try:
            baseline = os.fstat(incoming_fd)
            if (not stat.S_ISREG(baseline.st_mode)
                    or baseline.st_nlink != 1 or baseline.st_size != size):
                raise ValueError("Candidate ZIP inode unsafe")
            target = destination / publisher_name
            hasher = hashlib.sha256()
            copied = 0
            with os.fdopen(incoming_fd, "rb", closefd=False) as incoming:
                with target.open("xb") as output:
                    os.chmod(target, 0o600)
                    while chunk := incoming.read(1024 * 1024):
                        copied += len(chunk)
                        if copied > size:
                            raise ValueError("Candidate ZIP grew during private copy")
                        hasher.update(chunk)
                        output.write(chunk)
                    output.flush()
                    os.fsync(output.fileno())
            current = os.fstat(incoming_fd)
            named = os.stat(original_name, dir_fd=fd, follow_symlinks=False)
            fields = ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns")
            if (copied != size or hasher.hexdigest() != verified_hash
                    or any(getattr(baseline, key) != getattr(current, key)
                           or getattr(baseline, key) != getattr(named, key)
                           for key in fields)
                    or named.st_nlink != 1 or not stat.S_ISREG(named.st_mode)):
                raise ValueError("Candidate ZIP changed during private copy")
        finally:
            os.close(incoming_fd)

    payload = {
        "product": "walidsite",
        "version": version,
        "source_sha": source_sha,
        "artifact_sha256": verified_hash,
        "artifact_size": size,
        "min_php": "8.3.0",
        "artifact_file": publisher_name,
        "release_notes": notes,
    }
    path = destination / "unsigned-payload.json"
    with path.open("x", encoding="utf-8") as output:
        os.chmod(path, 0o600)
        json.dump(payload, output, ensure_ascii=False, separators=(",", ":"))
        output.write("\\n")
    return {
        "schema": "walidsite.signed-web-inspection-input.v1",
        "source_sha": source_sha,
        "version": version,
        "artifact_sha256": verified_hash,
        "unsigned_payload_prepared": True,
        "publisher_signature_verified": False,
        "published": False,
        "production_ready": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidates", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--sha", required=True)
    args = parser.parse_args()
    try:
        receipt = prepare(args.candidates, args.output_dir, args.version, args.sha)
    except (OSError, ValueError):
        print(json.dumps({"unsigned_payload_prepared": False, "production_ready": False}))
        return 1
    print(json.dumps(receipt, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
