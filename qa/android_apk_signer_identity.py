#!/usr/bin/env python3
"""Independently attest the *compiled* Android APK signer after keystore preflight.

The keystore alias public certificate is verified before Flutter builds. This
separate check must verify the actual APK and bind its sole signer to that pin.
On failure, emit only a categorical reason and (if trustworthy) the PUBLIC
compiled certificate SHA-256. Never print apksigner output, paths or secrets.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

from qa.android_signer_fingerprint import canonical_fingerprint


class ApkSignerIdentityError(ValueError):
    """Redacted, non-secret failure (public signer fingerprint may be included)."""


def _unique_json_keys(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ApkSignerIdentityError("KEYSTORE_PREFLIGHT_INVALID")
        result[key] = value
    return result


def attest(
    apk: Path, apksigner: str, expected: str, preflight: Path,
    *, runner=subprocess.run,
) -> dict[str, object]:
    try:
        pin = canonical_fingerprint(expected)
    except ValueError as exc:
        raise ApkSignerIdentityError("SIGNER_PIN_INVALID") from exc
    try:
        if preflight.is_symlink() or not preflight.is_file():
            raise ApkSignerIdentityError("KEYSTORE_PREFLIGHT_INVALID")
        payload = json.loads(preflight.read_text(encoding="utf-8"), object_pairs_hook=_unique_json_keys)
    except (OSError, UnicodeError, ValueError, TypeError) as exc:
        raise ApkSignerIdentityError("KEYSTORE_PREFLIGHT_INVALID") from exc
    if (not isinstance(payload, dict)
            or payload.get("schema") != "walidsite.android-keystore-certificate-pin.v1"
            or payload.get("pinned_identity_matched") is not True
            or payload.get("certificate_sha256") != pin
            or payload.get("private_key_exported") is not False
            or payload.get("apk_signed_or_published") is not False):
        raise ApkSignerIdentityError("KEYSTORE_PREFLIGHT_INVALID")
    try:
        if (apk.is_symlink() or not apk.is_file() or apk.suffix.lower() != ".apk"
                or not 0 < apk.stat().st_size <= 512 * 1024 * 1024):
            raise ApkSignerIdentityError("RELEASE_APK_INVALID")
    except OSError as exc:
        raise ApkSignerIdentityError("RELEASE_APK_INVALID") from exc
    try:
        result = runner(
            [apksigner, "verify", "--verbose", "--print-certs", str(apk)],
            capture_output=True, text=True, timeout=45, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ApkSignerIdentityError("APK_SIGNATURE_TOOL_UNAVAILABLE") from exc
    if result.returncode != 0:
        raise ApkSignerIdentityError("APK_SIGNATURE_CRYPTOGRAPHICALLY_INVALID")
    if not isinstance(result.stdout, str) or len(result.stdout) > 32768:
        raise ApkSignerIdentityError("APK_SIGNER_OUTPUT_INVALID")
    # apksigner --verbose reports the number of *APK distribution* signers.
    # Do not infer cardinality from certificate lines alone. In particular,
    # source-stamp or SDK-versioned/rotated certificate headings are not proof
    # that this product was signed by exactly one approved certificate.
    # splitlines() handles either LF or CRLF without accepting arbitrary text.
    # Preserve leading whitespace; indented/malformed signer headings must
    # never become trusted simply because the platform uses CRLF endings.
    lines = result.stdout.splitlines()
    count_values = [
        line[len("Number of signers:"):].strip()
        for line in lines if line.startswith("Number of signers:")
    ]
    if len(count_values) != 1 or re.fullmatch(r"(?:0|[1-9][0-9]{0,2})", count_values[0]) is None:
        raise ApkSignerIdentityError("APK_SIGNER_COUNT_UNAVAILABLE")
    reported_count = int(count_values[0])
    if reported_count != 1:
        raise ApkSignerIdentityError(
            f"APK_SIGNER_COUNT_INVALID observed_public_signer_count={reported_count}"
        )
    # Android SDK versions use two public renderings for a verified certificate:
    # older "Signer #1 certificate ..." and modern
    # "V3.0 Signer: certificate ...". The latter is NOT evidence of rotation:
    # require the native verified signer count to be one, and allow exactly one
    # unambiguous rendering with a single certificate SHA-256 that matches the
    # independently approved private-keystore preflight. Refuse SDK-targeted
    # rotation / V3.1 rather than guessing which certificate an older phone uses.
    if any(line.startswith("Signer (minSdkVersion=")
           or line.startswith(("V3.1 Signer:", "V3.2 Signer:"))
           for line in lines):
        raise ApkSignerIdentityError("APK_SIGNER_ROTATION_UNSUPPORTED")
    legacy, modern = "Signer #1 certificate ", "V3.0 Signer: certificate "
    candidate_lines = [
        line for line in lines
        if (re.match(r"^Signer #[0-9]+ certificate ", line)
            or re.match(r"^V[0-9]+(?:\.[0-9]+)? Signer: certificate ", line))
    ]
    # A legitimate single signer cannot have certificates from two different
    # apksigner presentation families. Also reject any other signer index or
    # unfamiliar scheme instead of silently ignoring a second public cert.
    families = {prefix for prefix in (legacy, modern)
                if any(line.startswith(prefix) for line in candidate_lines)}
    if (len(families) != 1 or not candidate_lines
            or any(not line.startswith(next(iter(families)))
                   for line in candidate_lines)):
        # This diagnostic reports only fixed vocabulary and bounded numbers:
        # never dump apksigner stdout, certificate DN, arbitrary headers,
        # keystore path, password, or any unknown textual value. A modern SDK
        # can combine V3.0 and legacy signer headings for a single APK signer.
        # Such a combination remains BLOCKED until its actual public shape is
        # known and independently tested against the pinned certificate.
        exact_legacy = sum(line.startswith(legacy) for line in candidate_lines)
        exact_modern = sum(line.startswith(modern) for line in candidate_lines)
        other_indexed = sum(
            bool(re.match(r"^Signer #[0-9]+ certificate ", line))
            and not line.startswith(legacy)
            for line in candidate_lines
        )
        other_versioned = sum(
            bool(re.match(r"^V[0-9]+(?:\.[0-9]+)? Signer: certificate ", line))
            and not line.startswith(modern)
            for line in candidate_lines
        )
        possible_certificate_headings = sum(
            "certificate " in line.lower()
            and ("signer" in line.lower() or "signing" in line.lower())
            for line in lines
        )
        bounded = lambda n: min(n, 99)
        raise ApkSignerIdentityError(
            f"APK_SIGNER_COUNT_INVALID observed_public_signer_count={reported_count}"
            f" public_legacy_headers={bounded(exact_legacy)}"
            f" public_v30_headers={bounded(exact_modern)}"
            f" public_other_indexed={bounded(other_indexed)}"
            f" public_other_versioned={bounded(other_versioned)}"
            f" public_cert_heading_count={bounded(possible_certificate_headings)}"
        )
    prefix = next(iter(families))
    # Do not normalize whitespace or accept malformed/indented signer headings.
    if any(line != line.lstrip() and (
        line.lstrip().startswith("Signer #")
        or re.match(r"^V[0-9]+(?:\.[0-9]+)? Signer:", line.lstrip()))
        for line in lines):
        raise ApkSignerIdentityError("APK_SIGNER_COUNT_INVALID")
    digests = [
        line[len(prefix + "SHA-256 digest: "):]
        for line in candidate_lines if line.startswith(prefix + "SHA-256 digest: ")
    ]
    if len(digests) != 1 or not re.fullmatch(r"[a-fA-F0-9]{64}", digests[0]):
        raise ApkSignerIdentityError("APK_SIGNER_DIGEST_AMBIGUOUS")
    actual = digests[0].lower()
    subjects = [
        line[len(prefix + "DN: "):]
        for line in candidate_lines if line.startswith(prefix + "DN: ")
    ]
    if len(subjects) != 1:
        raise ApkSignerIdentityError("APK_SIGNER_SUBJECT_AMBIGUOUS")
    if re.search(r"(?i)(?:^|,\s*)CN=Android Debug(?:,|$)", subjects[0]):
        raise ApkSignerIdentityError("APK_SIGNED_WITH_DEBUG_CERTIFICATE")
    if actual != pin:
        # Public certificate fingerprint: safe to disclose, unlike raw keytool,
        # keystore, password or untrusted apksigner output. Required to diagnose
        # Gradle signing selection versus GitHub Actions' trusted pin.
        raise ApkSignerIdentityError(
            "APK_SIGNER_PIN_MISMATCH observed_public_apk_certificate_sha256=" + actual
        )
    return {
        "schema": "walidsite.compiled-apk-signer-attestation.v1",
        "apk_certificate_sha256": actual,
        "keystore_preflight_matches_apk": True,
        "apk_signature_verified": True,
        "production_published": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apk", required=True, type=Path)
    parser.add_argument("--apksigner", required=True)
    parser.add_argument("--expected", required=True)
    parser.add_argument("--preflight", required=True, type=Path)
    args = parser.parse_args(argv)
    try:
        receipt = attest(args.apk, args.apksigner, args.expected, args.preflight)
    except ApkSignerIdentityError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    print(json.dumps(receipt, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
