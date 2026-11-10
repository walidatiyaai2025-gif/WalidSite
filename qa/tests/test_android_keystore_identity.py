"""No real secret/keystore in CI fixtures; inspect public keytool certificates only."""
import base64
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from qa.android_keystore_identity import (
    KeystoreIdentityError, _certificate_der, attest,
)

DER = b"\x30" + (b"\x01" * 255)
FINGERPRINT = hashlib.sha256(DER).hexdigest()


def public_pem(der: bytes = DER) -> bytes:
    body = base64.b64encode(der).decode("ascii")
    lines = "\n".join(body[i:i + 64] for i in range(0, len(body), 64))
    return ("-----BEGIN CERTIFICATE-----\n"
            + lines + "\n-----END CERTIFICATE-----\n").encode("ascii")


class KeystoreCertificateIdentityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.key = Path(self.temp.name) / "release-keystore.p12"
        self.key.write_bytes(b"private-placeholder" * 30)
        self.key.chmod(0o600)

    @patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": "fixture-not-production"})
    def test_public_certificate_digest_matches_pin_without_password_in_argv(self):
        seen = []
        def runner(argv, **kwargs):
            seen.extend(argv)
            self.assertTrue(kwargs["capture_output"])
            self.assertEqual(30, kwargs["timeout"])
            return subprocess.CompletedProcess(argv, 0, public_pem(), b"NEVER_PRINT")
        receipt = attest(self.key, "walidsite-release", FINGERPRINT, runner=runner)
        self.assertEqual("walidsite.android-keystore-certificate-pin.v1", receipt["schema"])
        self.assertTrue(receipt["pinned_identity_matched"])
        self.assertFalse(receipt["private_key_exported"])
        self.assertFalse(receipt["apk_signed_or_published"])
        self.assertEqual(FINGERPRINT, receipt["certificate_sha256"])
        self.assertIn("-storepass:env", seen)
        self.assertIn("WALIDSITE_ANDROID_STORE_PASSWORD", seen)
        self.assertNotIn("fixture-not-production", " ".join(seen))
        self.assertNotIn("-export", seen)
        self.assertIn("-exportcert", seen)

    @patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": "fixture"}, clear=False)
    def test_identity_mismatch_reports_only_public_certificate_sha(self):
        with self.assertRaisesRegex(KeystoreIdentityError, "PIN_MISMATCH observed_public_certificate_sha256=" + FINGERPRINT) as caught:
            attest(
                self.key, "walidsite-release", "f" * 64,
                runner=lambda argv, **k: subprocess.CompletedProcess(argv, 0, public_pem(), b"SECRET_ERROR"),
            )
        self.assertNotIn("SECRET_ERROR", str(caught.exception))
        self.assertNotIn(str(self.key), str(caught.exception))

    @patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": "fixture"}, clear=False)
    def test_invalid_certificates_never_become_signer_identity(self):
        bad = [
            b"",
            b"FAKE",
            public_pem() + public_pem(),
            b"-----BEGIN CERTIFICATE-----\n!!!!\n-----END CERTIFICATE-----",
            b"-----BEGIN PRIVATE KEY-----\nSECRET\n-----END PRIVATE KEY-----",
            b"\xff" * 100,
            b"x" * 30000,
        ]
        for payload in bad:
            with self.subTest(payload_length=len(payload)):
                with self.assertRaises(KeystoreIdentityError):
                    attest(
                        self.key, "walidsite-release", FINGERPRINT,
                        runner=lambda argv, **k: subprocess.CompletedProcess(argv, 0, payload, b"SECRET"),
                    )

    @patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": "fixture"}, clear=False)
    def test_keytool_error_is_fully_redacted(self):
        with self.assertRaises(KeystoreIdentityError) as caught:
            attest(
                self.key, "walidsite-release", FINGERPRINT,
                runner=lambda argv, **k: subprocess.CompletedProcess(argv, 1, b"", b"SECRET: wrong store password"),
            )
        self.assertNotIn("SECRET", str(caught.exception))
        self.assertNotIn("wrong store password", str(caught.exception))

    @patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": "fixture"}, clear=False)
    def test_bad_alias_weak_permissions_symlink_and_bad_pin_fail_before_keytool(self):
        def never_run(*args, **kwargs):
            self.fail("No keytool process may start for an unsafe request")
        for alias in ("", "../../etc/passwd", "alias with spaces", "x" * 101):
            with self.assertRaises(KeystoreIdentityError):
                attest(self.key, alias, FINGERPRINT, runner=never_run)
        with self.assertRaises(KeystoreIdentityError):
            attest(self.key, "good", "bad-digest", runner=never_run)
        self.key.chmod(0o644)
        with self.assertRaises(KeystoreIdentityError):
            attest(self.key, "good", FINGERPRINT, runner=never_run)
        self.key.chmod(0o600)
        link = Path(self.temp.name) / "alias.p12"
        link.symlink_to(self.key)
        with self.assertRaises(KeystoreIdentityError):
            attest(link, "good", FINGERPRINT, runner=never_run)

    def test_missing_store_password_is_fatal_before_keytool(self):
        with patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": ""}):
            with self.assertRaises(KeystoreIdentityError):
                attest(self.key, "walidsite-release", FINGERPRINT, runner=lambda *a, **k: self.fail("keytool called"))

    def test_pem_parser_requires_single_bounded_certificate(self):
        self.assertEqual(DER, _certificate_der(public_pem()))
        with self.assertRaises(KeystoreIdentityError):
            _certificate_der(b"-----BEGIN CERTIFICATE-----\nMDAw\n-----END CERTIFICATE-----")


if __name__ == "__main__":
    unittest.main()
