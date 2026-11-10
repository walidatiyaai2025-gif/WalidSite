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
    # Reject multiple signers or malformed/duplicate signer certificate fields.
    # Do not treat "Source Stamp Signer" as an APK distribution signer.
    # apksigner is Java-backed; line endings may be LF or CRLF regardless
    # of the OS. Treat CRLF as syntax, never as a second signer. A source
    # stamp is NOT a distribution signer and is intentionally ignored here.
    certificates = re.findall(
        r"(?m)^Signer #([1-9][0-9]*) certificate [^\r\n]*\r?$",
        result.stdout,
    )
    if not certificates or set(certificates) != {"1"}:
        # Diagnostic information is constrained to NON-SECRET numbers derived
        # from public certificate field headings. No raw stdout/stderr, DN,
        # paths, PIN, keystore data or passwords can enter the message.
        observed_ids = sorted(set(certificates), key=int)
        ids = ",".join(observed_ids[:4]) if observed_ids else "none"
        if len(observed_ids) > 4:
            ids += ",more"
        candidate_lines = len(re.findall(
            r"(?m)^\\s*Signer\\s+#[0-9]+\\s+certificate\\b", result.stdout,
        ))
        raise ApkSignerIdentityError(
            "APK_SIGNER_COUNT_INVALID observed_public_signer_ids=" + ids
            + " certificate_fields=" + str(min(len(certificates), 999))
            + " candidate_field_lines=" + str(min(candidate_lines, 999))
        )
    digests = re.findall(
        r"(?im)^Signer #1 certificate SHA-256 digest:\s*([0-9a-f]{64})\s*$",
        result.stdout,
    )
    if len(digests) != 1:
        raise ApkSignerIdentityError("APK_SIGNER_DIGEST_AMBIGUOUS")
    actual = digests[0].lower()
    if re.search(r"(?im)^Signer #1 certificate DN:.*CN=Android Debug(?:,|$)", result.stdout):
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
