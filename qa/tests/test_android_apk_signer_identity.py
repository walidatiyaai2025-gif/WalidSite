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
    "Number of signers: 1\n"
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

    def test_verbose_apksigner_signer_count_and_line_endings(self):
        # Both Unix and Windows SDK apksigner output must be handled, but the
        # actual compiled certificate must still be the one pinned in CI.
        for stdout in (SUCCESS, SUCCESS.replace("\n", "\r\n")):
            with self.subTest(line_endings=repr(stdout[-3:])):
                proof = attest(self.apk, "apksigner", PIN, self.preflight,
                               runner=self.runner(stdout=stdout))
                self.assertEqual(PIN, proof["apk_certificate_sha256"])

    def test_multiple_reported_signers_rejected_even_with_only_one_cert_line(self):
        for reported in ("0", "2", "9"):
            with self.subTest(reported=reported):
                stdout = SUCCESS.replace("Number of signers: 1",
                                         "Number of signers: " + reported)
                with self.assertRaisesRegex(ApkSignerIdentityError,
                        "APK_SIGNER_COUNT_INVALID observed_public_signer_count=" + reported):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_missing_duplicated_or_untrusted_signer_count_is_rejected(self):
        for stdout in (
            SUCCESS.replace("Number of signers: 1\n", ""),
            SUCCESS + "Number of signers: 1\n",
            SUCCESS.replace("Number of signers: 1", "Number of signers: secret"),
            SUCCESS.replace("Number of signers: 1", "Number of signers: 9999"),
        ):
            with self.subTest(tail=repr(stdout[-35:])):
                with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNER_COUNT_UNAVAILABLE"):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_sdk_versioned_rotated_certificate_format_requires_independent_review(self):
        rotated = SUCCESS.replace(
            "Signer #1 certificate ", "Signer (minSdkVersion=33, maxSdkVersion=35) certificate "
        )
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNER_ROTATION_UNSUPPORTED"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(rotated))

    def test_source_stamp_does_not_count_as_distribution_signer(self):
        stamped = SUCCESS + (
            "Source Stamp Signer certificate SHA-256 digest: " + OTHER + "\n"
        )
        proof = attest(self.apk, "apksigner", PIN, self.preflight,
                       runner=self.runner(stamped))
        self.assertEqual(PIN, proof["apk_certificate_sha256"])

    def test_indented_or_malformed_certificate_headings_are_not_normalized(self):
        # Preserve the original anchored apksigner output contract: a
        # leading space does not transform malformed output into evidence.
        for stdout in (
            SUCCESS.replace("Signer #1", "  Signer #1"),
            SUCCESS.replace("Signer #1 certificate", "\tSigner #1 certificate"),
        ):
            with self.subTest(indented=repr(stdout[-90:])):
                with self.assertRaisesRegex(ApkSignerIdentityError,
                        "APK_SIGNER_COUNT_INVALID"):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_crlf_duplicate_signer_digest_fails_closed(self):
        stdout = SUCCESS.replace("\n", "\r\n")
        stdout += f"Signer #1 certificate SHA-256 digest: {PIN}\r\n"
        with self.assertRaisesRegex(ApkSignerIdentityError,
                "APK_SIGNER_DIGEST_AMBIGUOUS"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(stdout))

    def test_modern_v30_single_verified_signer_matches_preflight(self):
        # Newer apksigner can print V3.0 Signer instead of Signer #1 while
        # still reporting exactly one cryptographically verified signer.
        modern = SUCCESS.replace("Signer #1 certificate ", "V3.0 Signer: certificate ")
        for stdout in (modern, modern.replace("\n", "\r\n")):
            with self.subTest(line_endings=repr(stdout[-3:])):
                receipt = attest(self.apk, "apksigner", PIN, self.preflight,
                                 runner=self.runner(stdout))
                self.assertEqual(PIN, receipt["apk_certificate_sha256"])
                self.assertTrue(receipt["keystore_preflight_matches_apk"])
                self.assertFalse(receipt["production_published"])

    def test_modern_v30_debug_wrong_pin_and_tampering_fail_closed(self):
        modern = SUCCESS.replace("Signer #1 certificate ", "V3.0 Signer: certificate ")
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNED_WITH_DEBUG_CERTIFICATE"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(modern.replace("CN=WalidSite Release", "CN=Android Debug")))
        with self.assertRaisesRegex(ApkSignerIdentityError,
                "APK_SIGNER_PIN_MISMATCH observed_public_apk_certificate_sha256=" + OTHER):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(modern.replace(PIN, OTHER)))
        with self.assertRaisesRegex(ApkSignerIdentityError,
                "APK_SIGNATURE_CRYPTOGRAPHICALLY_INVALID"):
            attest(self.apk, "apksigner", PIN, self.preflight,
                   runner=self.runner(modern, status=1))

    def test_mixed_or_multiple_apksigner_certificate_formats_fail_closed(self):
        modern = SUCCESS.replace("Signer #1 certificate ", "V3.0 Signer: certificate ")
        for stdout in (
            modern + f"Signer #1 certificate SHA-256 digest: {PIN}\n",
            SUCCESS + f"V3.0 Signer: certificate SHA-256 digest: {PIN}\n",
            modern + f"V3.1 Signer: certificate SHA-256 digest: {OTHER}\n",
            modern + f"V3.0 Signer: certificate SHA-256 digest: {PIN}\n",
            modern.replace("Number of signers: 1", "Number of signers: 2"),
            modern + f"Signer #2 certificate SHA-256 digest: {OTHER}\n",
        ):
            with self.subTest(tail=repr(stdout[-90:])):
                with self.assertRaises(ApkSignerIdentityError):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_modern_malformed_or_missing_certificate_metadata_stays_blocked(self):
        modern = SUCCESS.replace("Signer #1 certificate ", "V3.0 Signer: certificate ")
        for stdout in (
            modern.replace("V3.0 Signer:", "  V3.0 Signer:"),
            modern.replace(PIN, "bad-signature-digest"),
            modern.replace("V3.0 Signer: certificate DN: CN=WalidSite Release, O=Example\n", ""),
            modern.replace("V3.0 Signer: certificate ", "V4.0 Signer: certificate "),
        ):
            with self.subTest(tail=repr(stdout[-90:])):
                with self.assertRaises(ApkSignerIdentityError):
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout))

    def test_modern_source_stamp_is_not_distribution_signer(self):
        modern = SUCCESS.replace("Signer #1 certificate ", "V3.0 Signer: certificate ")
        stamped = modern + f"Source Stamp Signer certificate SHA-256 digest: {OTHER}\n"
        receipt = attest(self.apk, "apksigner", PIN, self.preflight,
                         runner=self.runner(stamped))
        self.assertEqual(PIN, receipt["apk_certificate_sha256"])

    def test_unknown_or_mixed_verified_signer_shapes_are_redacted_and_fail_closed(self):
        mixed = SUCCESS + f"V3.0 Signer: certificate SHA-256 digest: {PIN}\\n"
        alternative = SUCCESS.replace("Signer #1 certificate ",
                                      "Signer #2 certificate ")
        unfamiliar = SUCCESS.replace("Signer #1 certificate ",
                                     "V4.0 Signer: certificate ")
        cases = (
            (mixed, ("public_legacy_headers=3", "public_v30_headers=1",
                     "public_other_indexed=0", "public_other_versioned=0",
                     "public_cert_heading_count=4")),
            (alternative, ("public_legacy_headers=0", "public_v30_headers=0",
                           "public_other_indexed=3", "public_other_versioned=0",
                           "public_cert_heading_count=3")),
            (unfamiliar, ("public_legacy_headers=0", "public_v30_headers=0",
                          "public_other_indexed=0", "public_other_versioned=3",
                          "public_cert_heading_count=3")),
        )
        for stdout, expected_counts in cases:
            with self.subTest(shape=expected_counts):
                with self.assertRaises(ApkSignerIdentityError) as caught:
                    attest(self.apk, "apksigner", PIN, self.preflight,
                           runner=self.runner(stdout, stderr="DO_NOT_LOG_PASSWORD"))
                diagnostic = str(caught.exception)
                self.assertIn("APK_SIGNER_COUNT_INVALID observed_public_signer_count=1",
                              diagnostic)
                for label in expected_counts:
                    self.assertIn(label, diagnostic)
                for private in ("DO_NOT_LOG_PASSWORD", str(self.apk), "CN=WalidSite",
                                "Fixture Only", OTHER, PIN):
                    self.assertNotIn(private, diagnostic)

    def test_any_invalid_crypto_result_fails_even_when_stdout_contains_pin(self):
        with self.assertRaisesRegex(ApkSignerIdentityError, "APK_SIGNATURE_CRYPTOGRAPHICALLY_INVALID"):
            attest(self.apk, "apksigner", PIN, self.preflight, runner=self.runner(status=1))

    def test_two_signers_or_missing_cert_must_never_be_accepted(self):
        for stdout in (
            SUCCESS + f"Signer #2 certificate SHA-256 digest: {PIN}\n",
            SUCCESS + "Signer #2 certificate DN: CN=second\n",
            "Number of signers: 1\nVerified using v2 scheme: true\n",
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
