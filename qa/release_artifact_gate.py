#!/usr/bin/env python3
"""T026: fail-closed offline release artifact inventory verification.

This verifies local bytes and declarative metadata, not signing identities,
store approval, deployment, build reproducibility, or a real staging host.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
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


def verify(folder: Path, manifest_path: Path, source_sha: str | None = None) -> dict:
    """Verify a release inventory without reading outside the artifact directory."""
    folder = folder.resolve(strict=True)
    if not folder.is_dir() or manifest_path.is_symlink():
        raise ValueError("Artifact root must be a directory and manifest must not be a link")
    manifest_path = manifest_path.resolve(strict=True)
    if not manifest_path.is_file() or manifest_path.stat().st_size > MAX_MANIFEST_BYTES:
        raise ValueError("Invalid or oversized release manifest")
    try:
        obj = json.loads(manifest_path.read_text(encoding="utf-8"))
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
        path = folder / name
        if path.is_symlink() or not path.is_file():
            raise ValueError("Release artifact must be an existing regular file")
        if path.stat().st_size != size:
            raise ValueError("Release artifact size mismatch: " + name)
        h = hashlib.sha256()
        with path.open("rb") as stream:
            while chunk := stream.read(1024 * 1024):
                h.update(chunk)
        if h.hexdigest() != digest:
            raise ValueError("Release artifact checksum mismatch: " + name)
        results.append({"name": name, "kind": kind, "sha256": digest, "bytes": size})
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
