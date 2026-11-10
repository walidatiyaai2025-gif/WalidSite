"""Fail-closed public X.509 identity check for the configured Android release keystore.

Exports ONLY the public alias certificate via Java keytool, reads the store
password through keytool's environment mode (never argv), and compares its
SHA-256 to the owner-pinned distribution identity BEFORE a costly APK build.
Neither passwords nor private keystore bytes are emitted or uploaded.
"""
from __future__ import annotations

import argparse
import base64
import binascii
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

from qa.android_signer_fingerprint import canonical_fingerprint


class KeystoreIdentityError(ValueError):
    """Only intentionally redacted, fixed local errors escape."""


def _certificate_der(output: bytes) -> bytes:
    if len(output) > 20000:
        raise KeystoreIdentityError("Keystore public certificate output is oversized")
    try:
        text = output.decode("ascii").strip()
    except UnicodeDecodeError as exc:
        raise KeystoreIdentityError("Keystore public certificate output is invalid") from exc
    header, footer = "-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----"
    if not text.startswith(header) or not text.endswith(footer):
        raise KeystoreIdentityError("Keystore public certificate envelope is invalid")
    middle = text[len(header):-len(footer)].strip()
    lines = middle.splitlines()
    if not lines or any(re.fullmatch(r"[A-Za-z0-9+/=]{1,128}", line) is None for line in lines):
        raise KeystoreIdentityError("Keystore public certificate encoding is invalid")
    try:
        der = base64.b64decode("".join(lines), validate=True)
    except (ValueError, binascii.Error) as exc:
        raise KeystoreIdentityError("Keystore public certificate encoding is invalid") from exc
    if len(der) < 100 or len(der) > 16384 or der[0] != 0x30:
        raise KeystoreIdentityError("Keystore public certificate DER is invalid")
    return der


def attest(
    path: Path,
    alias: str,
    expected: str,
    *,
    runner=subprocess.run,
) -> dict[str, object]:
    try:
        expected_sha = canonical_fingerprint(expected)
    except ValueError as exc:
        raise KeystoreIdentityError("Configured signer fingerprint is malformed") from exc
    if not re.fullmatch(r"[A-Za-z0-9_.-]{1,100}", alias):
        raise KeystoreIdentityError("Configured signing alias is invalid")
    if not os.environ.get("WALIDSITE_ANDROID_STORE_PASSWORD"):
        raise KeystoreIdentityError("Release keystore password unavailable")
    try:
        info = path.lstat()
    except OSError as exc:
        raise KeystoreIdentityError("Release keystore unavailable") from exc
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or info.st_mode & 0o077 or info.st_size < 100 or info.st_size > 1048576
            or path.resolve() != path):
        raise KeystoreIdentityError("Release keystore physical file is unsafe")
    # The actual store password remains exclusively in the environment. Only
    # the PUBLIC leaf certificate is exported: NEVER a key or an entire P12.
    command = [
        "keytool", "-exportcert", "-rfc", "-storetype", "PKCS12",
        "-keystore", str(path), "-alias", alias,
        "-storepass:env", "WALIDSITE_ANDROID_STORE_PASSWORD",
    ]
    try:
        result = runner(command, capture_output=True, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise KeystoreIdentityError("Release certificate inspection unavailable") from exc
    if result.returncode != 0 or not isinstance(result.stdout, bytes):
        raise KeystoreIdentityError("Configured keystore certificate could not be verified")
    digest = hashlib.sha256(_certificate_der(result.stdout)).hexdigest()
    if digest != expected_sha:
        # An X.509 certificate fingerprint is PUBLIC verification data.
        # Avoid printing the alias, password, keytool stderr or keystore path.
        raise KeystoreIdentityError(
            "PIN_MISMATCH observed_public_certificate_sha256=" + digest
        )
    return {
        "schema": "walidsite.android-keystore-certificate-pin.v1",
        "certificate_sha256": digest,
        "pinned_identity_matched": True,
        "private_key_exported": False,
        "apk_signed_or_published": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verify the public certificate identity of an Android release keystore")
    parser.add_argument("--keystore", required=True)
    parser.add_argument("--alias", required=True)
    parser.add_argument("--expected", required=True)
    args = parser.parse_args(argv)
    try:
        proof = attest(Path(args.keystore), args.alias, args.expected)
    except KeystoreIdentityError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    print(json.dumps(proof, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
