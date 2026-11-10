"""Independent verification of final Android APK bytes against the owner-pinned signer.

The keystore preflight only proves the configured *keystore* certificate.
Android Gradle may still emit an APK signed by another identity. Verify the
actual archive with apksigner and report only the public X.509 fingerprint,
never the full signer DN, private key, alias, paths, or raw tool output.
"""
from __future__ import annotations

from pathlib import Path
import re
import subprocess
import sys

from qa.android_signer_fingerprint import canonical_fingerprint


class SignedApkIdentityError(ValueError):
    """Safe fixed diagnostic or bounded public certificate digest only."""


def verify(
    apk: Path,
    apksigner: Path,
    expected: str,
    *,
    runner=subprocess.run,
) -> dict[str, object]:
    try:
        pinned = canonical_fingerprint(expected)
    except ValueError as exc:
        raise SignedApkIdentityError("Approved Android signer pin is invalid") from exc
    if not apk.is_file() or apk.is_symlink() or apk.stat().st_size < 1024:
        raise SignedApkIdentityError("Signed APK is unavailable")
    if not apksigner.is_file() or apksigner.is_symlink():
        raise SignedApkIdentityError("Independent Android verifier is unavailable")

    try:
        result = runner(
            [str(apksigner), "verify", "--verbose", "--print-certs", str(apk)],
            capture_output=True, text=True, timeout=45, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise SignedApkIdentityError("Native APK signature verification unavailable") from exc
    # Never reflect stderr/stdout if an untrusted process fails.
    if result.returncode != 0 or not isinstance(result.stdout, str):
        raise SignedApkIdentityError("Native APK cryptographic signature is invalid")
    if len(result.stdout) > 32768:
        raise SignedApkIdentityError("Native APK signer certificate output is oversized")

    fingerprints = re.findall(
        r"(?im)^Signer #1 certificate SHA-256 digest: ([a-fA-F0-9]{64})\s*$",
        result.stdout,
    )
    all_signers = re.findall(
        r"(?im)^Signer #(\d+) certificate SHA-256 digest:", result.stdout,
    )
    if len(fingerprints) != 1 or len(all_signers) != 1 or all_signers != ["1"]:
        raise SignedApkIdentityError("Native APK signer identity is ambiguous")
    if re.search(
        r"(?im)^Signer #1 certificate DN:.*\bCN=Android Debug(?:,|\s*$)",
        result.stdout,
    ):
        raise SignedApkIdentityError("Debug Android signer is not a production identity")

    actual = fingerprints[0].lower()
    if actual != pinned:
        # A public APK's certificate hash is already publicly observable. It
        # is safe to report, unlike keys, store passwords or its raw subject DN.
        raise SignedApkIdentityError(
            "APK_SIGNER_MISMATCH actual_public_certificate_sha256=" + actual
        )
    return {
        "schema": "walidsite.final-apk-cryptographic-signer.v1",
        "actual_public_certificate_sha256": actual,
        "pinned_identity_matched": True,
        "cryptographic_verification_passed": True,
        "production_host_verified": False,
        "published": False,
    }


def main(argv: list[str] | None = None) -> int:
    if argv is None:
        argv = sys.argv[1:]
    if len(argv) != 3:
        print("Expected APK path, apksigner path and public fingerprint", file=sys.stderr)
        return 2
    try:
        verify(Path(argv[0]), Path(argv[1]), argv[2])
    except SignedApkIdentityError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    print("Release APK signer verified against independently pinned public identity")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
