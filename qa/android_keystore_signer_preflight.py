#!/usr/bin/env python3
"""Fail-closed release keystore alias attestation before costly Flutter compilation.

The pinned SHA-256 comes from GitHub Actions configuration, never from the
keystore itself. Keytool runs with a password ENV reference, not a secret
command-line argument. Success does NOT prove the final APK signer or deployment.
"""
from __future__ import annotations

import json
import os
import re
import stat
import subprocess
import sys
from pathlib import Path
from typing import Callable

from qa.android_signer_fingerprint import canonical_fingerprint


class KeystorePreflightError(ValueError):
    """Only fixed, non-secret diagnostics can reach CI output."""


def fingerprint_from_keytool(stdout: str) -> str:
    if not isinstance(stdout, str) or len(stdout) > 32768:
        raise KeystorePreflightError("Keystore certificate output is unavailable")
    fingerprints = re.findall(
        r"(?im)^\s*SHA256:\s*([0-9a-fA-F:]{64,95})\s*$", stdout
    )
    if len(fingerprints) != 1:
        raise KeystorePreflightError("Keystore certificate identity is ambiguous")
    try:
        return canonical_fingerprint(fingerprints[0])
    except ValueError:
        raise KeystorePreflightError("Keystore certificate fingerprint is malformed") from None


def inspect(
    keystore: Path,
    alias: str,
    pinned_sha256: str,
    *,
    runner: Callable[..., subprocess.CompletedProcess] = subprocess.run,
) -> dict[str, object]:
    try:
        expected = canonical_fingerprint(pinned_sha256)
    except ValueError:
        raise KeystorePreflightError("Approved Android signer pin is malformed") from None
    if not isinstance(alias, str) or not 1 <= len(alias) <= 128 or any(
        ord(char) < 32 or ord(char) == 127 for char in alias
    ):
        raise KeystorePreflightError("Release signer alias is unavailable")
    if not keystore.is_absolute() or keystore.is_symlink() or keystore.parent.is_symlink():
        raise KeystorePreflightError("Private release keystore path is unsafe")
    try:
        before = keystore.lstat()
        parent = keystore.parent.lstat()
    except OSError:
        raise KeystorePreflightError("Private release keystore is unavailable") from None
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
        or stat.S_IMODE(before.st_mode) != 0o600
        or not 100 <= before.st_size <= 1024 * 1024
        or not stat.S_ISDIR(parent.st_mode)
        or stat.S_IMODE(parent.st_mode) != 0o700):
        raise KeystorePreflightError("Private release keystore permissions are unsafe")
    if not os.environ.get("WALIDSITE_ANDROID_STORE_PASSWORD"):
        raise KeystorePreflightError("Private Android keystore password unavailable")

    # keytool reads the password by the fixed environment-variable name. It is
    # never placed in argv, a temporary command file, a log or our result.
    command = [
        "keytool", "-J-Duser.language=en", "-J-Duser.country=US",
        "-list", "-v", "-keystore", str(keystore), "-alias", alias,
        "-storepass:env", "WALIDSITE_ANDROID_STORE_PASSWORD",
    ]
    environment = os.environ.copy()
    environment["LC_ALL"] = "C"
    try:
        completed = runner(
            command, capture_output=True, text=True, timeout=45,
            check=False, env=environment,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise KeystorePreflightError("Private keystore certificate check unavailable") from None
    if completed.returncode != 0:
        raise KeystorePreflightError("Private keystore alias certificate could not be verified")
    actual = fingerprint_from_keytool(completed.stdout)
    try:
        after = keystore.lstat()
    except OSError:
        raise KeystorePreflightError("Private keystore identity changed") from None
    if any(getattr(before, field) != getattr(after, field) for field in (
        "st_dev", "st_ino", "st_mode", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns"
    )):
        raise KeystorePreflightError("Private keystore identity changed")
    if actual != expected:
        raise KeystorePreflightError(
            "Private keystore alias certificate differs from approved Android signer pin"
        )
    return {
        "schema": "walidsite.android-keystore-preflight.v1",
        "alias_certificate_matches_approved_pin": True,
        "independent_apk_signature_still_required": True,
        "production_ready": False,
    }


def main() -> int:
    try:
        proof = inspect(
            Path(os.environ["WALIDSITE_ANDROID_KEYSTORE_PATH"]),
            os.environ["WALIDSITE_ANDROID_KEY_ALIAS"],
            os.environ["WALIDSITE_ANDROID_SIGNER_SHA256"],
        )
    except KeystorePreflightError as error:
        print("Android signing preflight BLOCKED: " + str(error), file=sys.stderr)
        return 1
    except Exception:
        print("Android signing preflight BLOCKED: unverified keystore", file=sys.stderr)
        return 1
    print(json.dumps(proof, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
