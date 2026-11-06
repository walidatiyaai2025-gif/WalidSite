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


def _assert_named_file_identity(path: str | Path, opened: os.stat_result,
                                *, dir_fd: int | None = None) -> None:
    """Require the named path to still reference our single-link open inode."""
    try:
        named = os.stat(path, dir_fd=dir_fd, follow_symlinks=False)
    except OSError as exc:
        raise ValueError("Release input pathname changed during verification") from exc
    if (not stat.S_ISREG(named.st_mode) or not stat.S_ISREG(opened.st_mode)
            # A release artifact or manifest writable by other local users
            # is not a trusted immutable candidate, even when its hash matches.
            or (named.st_mode & 0o022) != 0 or (opened.st_mode & 0o022) != 0
            or named.st_nlink != 1 or opened.st_nlink != 1
            or (named.st_dev, named.st_ino) != (opened.st_dev, opened.st_ino)):
        raise ValueError("Release input pathname changed during verification")


def _assert_named_directory_identity(directory: Path, descriptor: int) -> None:
    """An opened directory FD is not sufficient after its path is exchanged."""
    try:
        opened = os.fstat(descriptor)
        named = os.stat(directory, follow_symlinks=False)
    except OSError as exc:
        raise ValueError("Release artifact directory changed during verification") from exc
    if (not stat.S_ISDIR(opened.st_mode) or not stat.S_ISDIR(named.st_mode)
            # An untrusted principal must not be able to replace the release
            # pathname immediately after verification has completed.
            or (opened.st_mode & 0o022) != 0 or (named.st_mode & 0o022) != 0
            or (opened.st_dev, opened.st_ino) != (named.st_dev, named.st_ino)):
        raise ValueError("Release artifact directory changed during verification")


@contextmanager
def _stable_manifest_reader(path: Path):
    """Pin the private manifest parent as well as the manifest inode.

    A no-follow file descriptor alone cannot prevent a substituted parent
    directory from serving a different manifest after the verifier returns.
    """
    parent = path.parent
    if not path.name or path.name in (".", ".."):
        raise ValueError("Invalid release manifest filename")
    try:
        fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    except OSError as exc:
        raise ValueError("Cannot securely open manifest directory") from exc
    try:
        _assert_named_directory_identity(parent, fd)
        with _stable_reader(path.name, dir_fd=fd) as item:
            yield item
        _assert_named_directory_identity(parent, fd)
    finally:
        os.close(fd)


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
        _assert_named_file_identity(path, before, dir_fd=dir_fd)
        yield stream, before
        after = os.fstat(stream.fileno())
        if _file_fingerprint(after) != _file_fingerprint(before):
            raise ValueError("Release input changed during verification")
        # A renamed/replaced pathname can reference different bytes even when
        # the original descriptor was unchanged. Never attest the stale name.
        _assert_named_file_identity(path, after, dir_fd=dir_fd)


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
    with _stable_manifest_reader(Path(manifest_path)) as (stream, metadata):
        if not 0 < metadata.st_size <= MAX_MANIFEST_BYTES:
            raise ValueError("Invalid or oversized release manifest")
        raw_manifest = stream.read(MAX_MANIFEST_BYTES + 1)
        if not 0 < len(raw_manifest) <= MAX_MANIFEST_BYTES:
            raise ValueError("Invalid or oversized release manifest")
        # Keep both the manifest inode AND its parent directory pinned until
        # every referenced artifact has been hashed.  Returning a release
        # receipt after closing either descriptor permits an intra-verification
        # manifest/parent swap that the former short read-only scope missed.
        return _verify_manifest_payload(folder, raw_manifest, source_sha)


def _unique_json_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Reject duplicate semantic JSON keys at every object nesting level.

    JSON escapes are decoded before object_pairs_hook is invoked, so two
    differently escaped spellings of e.g. source_sha cannot disagree across
    independent release metadata consumers.
    """
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate release manifest key")
        result[key] = value
    return result


def _reject_json_constant(value: str) -> object:
    # Python accepts NaN/Infinity by default even though JSON does not.
    raise ValueError("Non-finite release manifest JSON value")


def _verify_manifest_payload(folder: Path, raw_manifest: bytes,
                             source_sha: str | None) -> dict:
    """Verify the inventory while the caller still owns manifest descriptors."""
    try:
        obj = json.loads(raw_manifest.decode("utf-8"),
                         object_pairs_hook=_unique_json_object,
                         parse_constant=_reject_json_constant)
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
        _assert_named_directory_identity(folder, root_fd)
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
        # The open FD pins the directory for relative reads, but a swapped
        # path would cause the next release consumer to see another directory.
        _assert_named_directory_identity(folder, root_fd)
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
