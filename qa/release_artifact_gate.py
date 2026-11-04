#!/usr/bin/env python3
"""T026: fail-closed offline release artifact inventory verification.

This verifies local bytes and declarative metadata, not signing identities,
store approval, deployment, build reproducibility, or a real staging host.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
from contextlib import contextmanager
from pathlib import Path

SHA256 = re.compile(r"[0-9a-f]{64}\Z")
SHA40 = re.compile(r"[0-9a-f]{40}\Z")
VERSION = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?\Z")
KINDS = {"web-cpanel-zip": ".zip", "android-apk": ".apk",
         "android-aab": ".aab", "ios-ipa": ".ipa"}
MAX_MANIFEST_BYTES = 1024 * 1024


def artifact_name(value: object) -> bool:
    return (isinstance(value, str) and 1 <= len(value) <= 150
            and not value.startswith(".") and value not in (".", "..")
            and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._+-]*", value) is not None
            and ".." not in value)


def _file_fingerprint(metadata: os.stat_result) -> tuple[int, int, int, int, int]:
    return (metadata.st_dev, metadata.st_ino, metadata.st_size,
            metadata.st_mtime_ns, metadata.st_ctime_ns)


@contextmanager
def _stable_reader(path: str | Path, *, dir_fd: int | None = None):
    """Pin a regular file descriptor; never validate one path and read another.

    O_NONBLOCK prevents a swapped FIFO from hanging the release verifier.
    O_NOFOLLOW rejects a symlink introduced even after the preflight check.
    """
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                     dir_fd=dir_fd)
    except OSError as exc:
        raise ValueError("Cannot securely open release file") from exc
    with os.fdopen(fd, "rb") as stream:
        before = os.fstat(stream.fileno())
        if not stat.S_ISREG(before.st_mode):
            raise ValueError("Release input is not a regular file")
        yield stream, before
        after = os.fstat(stream.fileno())
        if _file_fingerprint(after) != _file_fingerprint(before):
            raise ValueError("Release input changed during verification")


def verify(folder: Path, manifest_path: Path, source_sha: str | None = None) -> dict:
    """Verify listed artifact bytes in one pinned directory, without following links.

    A publisher manifest may be located outside that directory; it too is
    read from a stable no-follow regular-file descriptor.
    """
    # The release gate is intentionally Linux/POSIX-only: without no-follow
    # descriptor operations a racy symlink swap must never yield a PASS.
    if not all(hasattr(os, name) for name in ("O_NOFOLLOW", "O_NONBLOCK", "O_DIRECTORY")):
        raise ValueError("Secure no-follow file verification is unavailable")
    # Never resolve an attacker-writable pathname before opening it.  A
    # symlink swapped between is_symlink() and resolve() would otherwise
    # redirect the trusted root despite O_NOFOLLOW on the resolved target.
    folder = Path(folder)
    # The manifest may live beside the artifacts, as documented. Keep its
    # original pathname: resolve() here would bypass O_NOFOLLOW on symlinks.
    with _stable_reader(manifest_path) as (stream, metadata):
        if not 0 < metadata.st_size <= MAX_MANIFEST_BYTES:
            raise ValueError("Invalid or oversized release manifest")
        raw_manifest = stream.read(MAX_MANIFEST_BYTES + 1)
        if not 0 < len(raw_manifest) <= MAX_MANIFEST_BYTES:
            raise ValueError("Invalid or oversized release manifest")
    try:
        obj = json.loads(raw_manifest.decode("utf-8"))
    except (UnicodeError, ValueError) as exc:
        raise ValueError("Invalid JSON release manifest") from exc
    if not isinstance(obj, dict) or set(obj) != {"schema", "version", "source_sha", "artifacts"}:
        raise ValueError("Unexpected release manifest schema")
    if obj["schema"] != "walidsite.release-artifact-inventory.v1":
        raise ValueError("Unsupported release manifest version")
    if not isinstance(obj["version"], str) or not VERSION.fullmatch(obj["version"]):
        raise ValueError("Version must be a three-part SemVer")
    if not isinstance(obj["source_sha"], str) or not SHA40.fullmatch(obj["source_sha"]):
        raise ValueError("Source revision must be a full lower-case commit SHA")
    if source_sha is not None and obj["source_sha"] != source_sha:
        raise ValueError("Release source does not match requested immutable commit")
    items = obj["artifacts"]
    if not isinstance(items, list) or not 1 <= len(items) <= 12:
        raise ValueError("Expected 1–12 explicitly inventoried release artifacts")
    try:
        root_fd = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    except OSError as exc:
        raise ValueError("Cannot securely open artifact root") from exc
    try:
        seen = set()
        results = []
        for item in items:
            if not isinstance(item, dict) or set(item) != {"name", "kind", "bytes", "sha256"}:
                raise ValueError("Invalid artifact record")
            name, kind, size, digest = (item[k] for k in ("name", "kind", "bytes", "sha256"))
            if (not artifact_name(name) or not isinstance(kind, str)
                    or kind not in KINDS or not name.endswith(KINDS[kind])):
                raise ValueError("Unsafe artifact name or mismatched file kind")
            if name in seen:
                raise ValueError("Duplicate release artifact")
            seen.add(name)
            if type(size) is not int or size <= 0 or not isinstance(digest, str) or not SHA256.fullmatch(digest):
                raise ValueError("Invalid artifact size or SHA-256")
            h = hashlib.sha256()
            with _stable_reader(name, dir_fd=root_fd) as (stream, metadata):
                if metadata.st_size != size:
                    raise ValueError("Release artifact size mismatch: " + name)
                while chunk := stream.read(1024 * 1024):
                    h.update(chunk)
            if h.hexdigest() != digest:
                raise ValueError("Release artifact checksum mismatch: " + name)
            results.append({"name": name, "kind": kind, "sha256": digest, "bytes": size})
    finally:
        os.close(root_fd)
    return {
        "version": obj["version"], "source_sha": obj["source_sha"],
        "artifact_integrity_verified": True, "artifacts": results,
        "reproducible_build_verified": False,
        "signing_identity_verified": False,
        "store_distribution_verified": False,
        "staging_deployment_verified": False,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Offline release artifact checksum gate")
    parser.add_argument("--dir", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--sha", help="Exact known source Git SHA", required=True)
    args = parser.parse_args(argv)
    try:
        report = verify(args.dir, args.manifest, args.sha)
    except (ValueError, OSError) as exc:
        print(json.dumps({"artifact_integrity_verified": False,
                          "production_ready": False,
                          "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(report, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
