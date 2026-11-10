"""Signed APK inspection never trusts the keystore preflight or raw tool output."""
from __future__ import annotations

from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from qa.android_signed_apk_identity import SignedApkIdentityError, verify, main

EXPECTED = "ab" * 32
OTHER = "cd" * 32


class SignedApkIdentityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.apk = root / "test.apk"
        self.apk.write_bytes(b"A" * 2048)
        self.signer = root / "apksigner"
        self.signer.write_text("fixture binary")
        self.calls = []

    def runner(self, args, **kwargs):
        self.calls.append((args, kwargs))
        return subprocess.CompletedProcess(args, 0, (
            "Verifies\n"
            "Signer #1 certificate DN: CN=WalidSite Release, O=Test\n"
            "Signer #1 certificate SHA-256 digest: " + EXPECTED + "\n"
            "Signer #1 certificate SHA-1 digest: foo\n"
        ), "UNTRUSTED_TOOL_MESSAGE")

    def test_real_archive_signer_matches_independent_owner_pin(self):
        info = verify(self.apk, self.signer, ":".join(["AB"] * 32), runner=self.runner)
        self.assertTrue(info["cryptographic_verification_passed"])
        self.assertTrue(info["pinned_identity_matched"])
        self.assertFalse(info["published"])
        self.assertFalse(info["production_host_verified"])
        self.assertEqual(EXPECTED, info["actual_public_certificate_sha256"])
        args, kw = self.calls[0]
        self.assertEqual([str(self.signer), "verify", "--verbose", "--print-certs", str(self.apk)], args)
        self.assertTrue(kw["capture_output"])
        self.assertEqual(45, kw["timeout"])
        self.assertNotIn("UNTRUSTED", str(info))

    def test_mismatching_actual_certificate_reports_only_public_digest(self):
        with self.assertRaises(SignedApkIdentityError) as caught:
            verify(self.apk, self.signer, OTHER, runner=self.runner)
        self.assertEqual(
            "APK_SIGNER_MISMATCH actual_public_certificate_sha256=" + EXPECTED,
            str(caught.exception),
        )
        self.assertNotIn("WalidSite Release", str(caught.exception))
        self.assertNotIn(str(self.apk), str(caught.exception))
        self.assertNotIn("UNTRUSTED", str(caught.exception))

    def test_other_signers_debug_and_ambiguous_results_rejected(self):
        base = self.runner([], capture_output=True).stdout
        bad = [
            base + "Signer #2 certificate SHA-256 digest: " + OTHER + "\n",
            base + "Signer #1 certificate SHA-256 digest: " + EXPECTED + "\n",
            base.replace("CN=WalidSite Release", "CN=Android Debug"),
            base.replace("SHA-256 digest: " + EXPECTED, "SHA-256 digest: xx"),
            "",
            "Signer #1 certificate SHA-256 digest: " + EXPECTED + "\n" * 40000,
        ]
        for output in bad:
            with self.subTest(length=len(output)):
                with self.assertRaises(SignedApkIdentityError):
                    verify(
                        self.apk, self.signer, EXPECTED,
                        runner=lambda args, **k: subprocess.CompletedProcess(args, 0, output, "PRIVATE"),
                    )

    def test_invalid_cryptographic_result_does_not_leak_stderr(self):
        with self.assertRaises(SignedApkIdentityError) as caught:
            verify(
                self.apk, self.signer, EXPECTED,
                runner=lambda args, **k: subprocess.CompletedProcess(args, 1, "PRIVATE", "KEYSTORE_PASSWORD"),
            )
        self.assertNotIn("KEYSTORE_PASSWORD", str(caught.exception))
        self.assertNotIn("PRIVATE", str(caught.exception))

    def test_invalid_paths_and_pin_fail_before_apksigner(self):
        def forbidden(*args, **kwargs):
            self.fail("Unsafe input invoked apksigner")
        with self.assertRaises(SignedApkIdentityError):
            verify(self.apk, self.signer, "not-sha", runner=forbidden)
        self.apk.unlink()
        with self.assertRaises(SignedApkIdentityError):
            verify(self.apk, self.signer, EXPECTED, runner=forbidden)
        self.apk.write_bytes(b"A" * 100)
        with self.assertRaises(SignedApkIdentityError):
            verify(self.apk, self.signer, EXPECTED, runner=forbidden)
        self.apk.unlink()
        self.apk.write_bytes(b"A" * 2048)
        self.signer.unlink()
        with self.assertRaises(SignedApkIdentityError):
            verify(self.apk, self.signer, EXPECTED, runner=forbidden)

    def test_native_verifier_failure_or_timeout_is_not_signature_success(self):
        with self.assertRaises(SignedApkIdentityError):
            verify(
                self.apk, self.signer, EXPECTED,
                runner=lambda args, **kw: (_ for _ in ()).throw(subprocess.TimeoutExpired(args, 45)),
            )
        with self.assertRaises(SignedApkIdentityError):
            verify(
                self.apk, self.signer, EXPECTED,
                runner=lambda args, **kw: (_ for _ in ()).throw(OSError("private path")),
            )


if __name__ == "__main__":
    unittest.main()
