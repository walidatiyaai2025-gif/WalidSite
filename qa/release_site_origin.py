#!/usr/bin/env python3
"""Fail-closed, *static* origin agreement for a staged Android/Web build.

This cannot prove that the host is deployed or that the compiled APK uses the
argument at runtime. Those independent gates remain mandatory for promotion.
"""
from __future__ import annotations

import argparse
import ipaddress
import json
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit

SHA = re.compile(r"[a-f0-9]{40}\Z")
SEMVER = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")
LABEL = re.compile(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\Z")
RESERVED_SUFFIXES = (
    ".localhost", ".local", ".test", ".example", ".invalid", ".internal",
    ".lan", ".home", ".home.arpa", ".onion",
)


class ReleaseOriginError(ValueError):
    """An origin or immutable release identity is not eligible for signing."""


def canonical_public_origin(value: str) -> str:
    if not isinstance(value, str) or not 12 <= len(value) <= 260:
        raise ReleaseOriginError("Production HTTPS origin is missing or oversized")
    if not value.startswith("https://") or any(ord(c) < 33 or ord(c) > 126 for c in value):
        raise ReleaseOriginError("Production origin must be an ASCII HTTPS URL")
    try:
        url = urlsplit(value)
        host = url.hostname
        port = url.port
    except ValueError as exc:
        raise ReleaseOriginError("Malformed production origin") from exc
    if (url.scheme != "https" or not host or url.username is not None
            or url.password is not None or port is not None
            or url.query or url.fragment or url.path):
        raise ReleaseOriginError("Production origin is not a bare HTTPS hostname")
    if host != host.lower() or len(host) > 253 or value != "https://" + host:
        raise ReleaseOriginError("Production origin is not canonical")
    try:
        ipaddress.ip_address(host.strip("[]"))
    except ValueError:
        pass
    else:
        raise ReleaseOriginError("Production origin must be a public DNS name")
    if (host == "localhost" or "." not in host or host.endswith(RESERVED_SUFFIXES)
            or host.startswith("localhost.") or host.endswith(".")
            or not all(LABEL.fullmatch(part) for part in host.split("."))):
        raise ReleaseOriginError("Production origin is reserved or invalid")
    # This gate is not DNS resolution or SSRF attestation: only the approved
    # domain and an independent live HTTPS receipt can promote a release.
    if not re.fullmatch(r"[a-z]{2,63}", host.rsplit(".", 1)[1]):
        raise ReleaseOriginError("Production origin has an invalid public suffix")
    return value


def build_receipt(*, origin: str, approved_origin: str,
                  source_sha: str, version: str, site_version: str) -> dict:
    canonical = canonical_public_origin(origin)
    if canonical != canonical_public_origin(approved_origin):
        raise ReleaseOriginError("APK origin differs from approved website origin")
    if not SHA.fullmatch(source_sha):
        raise ReleaseOriginError("Release must be pinned to an exact Git SHA")
    if not SEMVER.fullmatch(version) or version != site_version.strip():
        raise ReleaseOriginError("Android and checked-in Web release versions disagree")
    return {
        "schema": "walidsite.android-site-origin-preflight.v1",
        "source_sha": source_sha,
        "web_version": version,
        "build_argument_origin": canonical,
        "approved_origin_matches": True,
        "origin_is_approved_configuration_only": True,
        "live_https_verified": False,
        "web_deployment_sha_verified": False,
        "apk_runtime_origin_verified": False,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--approved-origin", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--site-version-file", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if args.site_version_file.stat().st_size > 64:
            raise ReleaseOriginError("Invalid checked-in version file")
        site_version = args.site_version_file.read_text(encoding="ascii")
        result = build_receipt(
            origin=args.origin, approved_origin=args.approved_origin,
            source_sha=args.source_sha, version=args.version,
            site_version=site_version,
        )
    except (ReleaseOriginError, OSError, UnicodeError, ValueError):
        # Do not echo a supplied URL, filesystem path or secret to logs.
        print('ANDROID SITE ORIGIN PREFLIGHT: BLOCKED', file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
