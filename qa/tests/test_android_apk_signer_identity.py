"""Adversarial unit coverage for compiled APK signer provenance; no keystore secrets."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from qa.android_apk_signer_identity import ApkSignerIdentityError, attest, main


PIN = "a1" * 32
OTHER = "b2" * 32
SUCCESS = (
    "Verifies\n"
    "Verified using v1 scheme (JAR signing): true\n"
    "Verified using v2 scheme (APK Signature Scheme v2): true\n"
    "Signer #1 certificate DN: CN=WalidSite Release, O=Example\n"
    f"Signer #1 certificate SHA-256 digest: {PIN}\n"
    "Signer #1 certificate SHA-1 digest: 12345\n"
)


class ApkSignerIdentityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.apk = root / "release.apk"
        self.apk.write_bytes(b"test-fixture-not-a-real-apk")
        self.preflight = root / "private-certificate-proof.json"
        self.preflight.write_text(json.dumps({
            "schema": "walidsite.android-keystore-certificate-pin.v1",
            "certificate_sha256": PIN,
            "pinned_identity_matched": True,
            "private_key_exported": False,
            "apk_signed_or_published": False,
        }))
        self.commands = []

    def runner(self, stdout=SUCCESS, status=0, stderr="SENSITIVE_PATH_AND_SECRET"):
        def run(argv, **kwargs):
            self.commands.append((argv, kwargs))
            return subprocess.CompletedProcess(argv, status, stdout, stderr)
        return run

    def test_verified_actual_apk_and_private_keystore_same_pin(self):
        receipt = attest(self.apk, "/android/sdk/apksigner", PIN,
                         self.preflight, runner=self.runner())
        self.assertEqual(PIN, receipt["apk_certificate_sha256"])
        self.assertTrue(receipt["keystore_preflight_matches_apk"])
        self.assertTrue(receipt["apk_signature_verified"])
        self.assertFalse(receipt["production_published"])
        args, options = self.commands[0]
        self.assertEqual(["/android/sdk/apksigner", "verify", "--verbose",
                          "--print-certs", str(self.apk)], args)
        self.assertEqual(45, options["timeout"])
        self.assertTrue(options["capture_output"])
        self.assertTrue(options["text"])
        self.assertFalse(options["check"])

    def test_compiled_mismatch_exposes_public_cert_only(self):
        stdout = SUCCESS.replace(PIN, OTHER)
        with self.assertRaisesRegex(ApkSignerIdentityError,
                "APK_SIGNER_PIN_MISMATCH observed_public_apk_certificate_sha256=" + OTHER) as caught:
            attest(self.apk, "apksigner", PIN, self.preflight, runner=self.runner(stdout))
        self.assertNotIn("SENSITIVE", str(caught.exception))
        self.assertNotIn(str(self.apk), str(caught.exception))

    def test_any_invalid_crypto_result_fails_even_when_stdout_contains_pin(self):
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNATURE_CRYPTOGRAPHICALLY_INVALID"):
            attest(self.apk, "apksigner", PIN, self.preflight, runner=self.runner(status=1))

    def test_two_signers_or_missing_cert_must_never_be_accepted(self):
        for stdout in (
            SUCCESS + f"Signer #2 certificate SHA-256 digest: {PIN}\n",
            SUCCESS + "Signer #2 certificate DN: CN=second\n",
            "Verified using v2 scheme: true\n",
        ):
            with self.subTest(stdout=stdout[-100:]):
                with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNER_COUNT_INVALID"):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_duplicate_digest_and_malformed_digest_fail(self):
        for stdout in (
            SUCCESS + f"Signer #1 certificate SHA-256 digest: {PIN}\n",
            SUCCESS.replace(PIN, "BAD_HEX"),
            SUCCESS.replace(PIN, PIN[:-1]),
        ):
            with self.subTest(stdout=stdout[-80:]):
                with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNER_DIGEST_AMBIGUOUS"):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_debug_certificate_is_rejected_even_if_pin_matches(self):
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNED_WITH_DEBUG_CERTIFICATE"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(SUCCESS.replace("CN=WalidSite Release",
                                                      "CN=Android Debug")))

    def test_untrusted_keystore_receipt_never_invokes_apksigner(self):
        def never_run(*args, **kwargs):
            self.fail("no verifier process before a trusted matching preflight")
        invalid = [
            "{}",
            '{"schema":"walidsite.android-keystore-certificate-pin.v1",'
            '"certificate_sha256":"' + PIN + '","certificate_sha256":"' + PIN + '"}',
            json.dumps({"schema": "walidsite.android-keystore-certificate-pin.v1",
                        "certificate_sha256": OTHER, "pinned_identity_matched": True,
                        "private_key_exported": False, "apk_signed_or_published": False}),
            json.dumps({"schema": "walidsite.android-keystore-certificate-pin.v1",
                        "certificate_sha256": PIN, "pinned_identity_matched": 1,
                        "private_key_exported": False, "apk_signed_or_published": False}),
        ]
        for item in invalid:
            self.preflight.write_text(item)
            with self.subTest(item=item[:50]):
                with self.assertRaisesRegex(ApkSignerIdentityError, "KEYSTORE_PREFLIGHT_INVALID"):
                    attest(self.apk, "apksigner", PIN, self.preflight, runner=never_run)

    def test_unsafe_apk_and_preflight_symlinks_are_rejected(self):
        prelink = self.preflight.with_name("preflight-link.json")
        prelink.symlink_to(self.preflight)
        with self.assertRaisesRegex(ApkSignerIdentityError, "KEYSTORE_PREFLIGHT_INVALID"):
            attest(self.apk, "apksigner", PIN, prelink, runner=self.runner())
        apklink = self.apk.with_name("signed-link.apk")
        apklink.symlink_to(self.apk)
        with self.assertRaisesRegex(ApkSignerIdentityError, "RELEASE_APK_INVALID"):
            attest(apklink, "apksigner", PIN, self.preflight, runner=self.runner())
        with self.assertRaisesRegex(ApkSignerIdentityError, "SIGNER_PIN_INVALID"):
            attest(self.apk, "apksigner", "invalid", self.preflight, runner=self.runner())

    def test_timeout_and_unavailable_command_are_categorical(self):
        def timeout(*args, **kwargs):
            raise subprocess.TimeoutExpired(["apksigner"], timeout=45)
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNATURE_TOOL_UNAVAILABLE"):
            attest(self.apk, "apksigner", PIN, self.preflight, runner=timeout)
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNATURE_TOOL_UNAVAILABLE"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=lambda *a, **k: (_ for _ in ()).throw(OSError("private")))

    def test_oversize_untrusted_verifier_output_never_leaks(self):
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNER_OUTPUT_INVALID"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(SUCCESS + "SECRET" * 10000))


if __name__ == "__main__":
    unittest.main()
