"""#1120: production signer preflight fixtures never use a real keystore or secret."""
from __future__ import annotations

import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from qa.android_keystore_signer_preflight import (
    KeystorePreflightError, fingerprint_from_keytool, inspect,
)

PIN = "ab" * 32
KEYTOOL_PIN = ":".join(["AB"] * 32)


class AndroidKeystoreSignerPreflightTest(unittest.TestCase):
    def setUp(self):
        self.working = tempfile.TemporaryDirectory()
        self.addCleanup(self.working.cleanup)
        self.private = Path(self.working.name) / "private"
        self.private.mkdir(mode=0o700)
        self.keystore = self.private / "release.keystore"
        self.keystore.write_bytes(b"fixture-not-a-real-keystore" * 12)
        self.keystore.chmod(0o600)
        self.env = patch.dict(os.environ, {"WALIDSITE_ANDROID_STORE_PASSWORD": "test-private"})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.calls = []

    def runner(self, args, **kwargs):
        self.calls.append((args, kwargs))
        return SimpleNamespace(
            returncode=0,
            stdout="Alias name: production\nCertificate fingerprints:\n SHA256: " + KEYTOOL_PIN + "\n",
        )

    def test_matched_alias_attests_only_the_keystore_not_the_built_apk(self):
        result = inspect(self.keystore, "production", PIN, runner=self.runner)
        self.assertEqual(result["schema"], "walidsite.android-keystore-preflight.v1")
        self.assertTrue(result["alias_certificate_matches_approved_pin"])
        self.assertTrue(result["independent_apk_signature_still_required"])
        self.assertFalse(result["production_ready"])
        self.assertEqual(len(self.calls), 1)
        arguments, options = self.calls[0]
        self.assertIn("-storepass:env", arguments)
        self.assertIn("WALIDSITE_ANDROID_STORE_PASSWORD", arguments)
        self.assertNotIn("test-private", arguments)
        self.assertEqual(options["timeout"], 45)
        self.assertTrue(options["capture_output"])
        self.assertNotIn("test-private", str(result))

    def test_different_real_certificate_rejected_without_repinning(self):
        with self.assertRaisesRegex(KeystorePreflightError, "differs from approved"):
            inspect(self.keystore, "production", "cd" * 32, runner=self.runner)
        self.assertEqual(len(self.calls), 1)

    def test_keytool_single_canonical_fingerprint_is_required(self):
        self.assertEqual(fingerprint_from_keytool("SHA256: " + KEYTOOL_PIN), PIN)
        for invalid in (
            "", "SHA256: zz", "SHA256: " + KEYTOOL_PIN + "\nSHA256: " + KEYTOOL_PIN,
            "SHA256: " + ":".join(["AA"] * 31), "SHA256: " + PIN + "bad",
        ):
            with self.subTest(value=invalid[:45]):
                with self.assertRaises(KeystorePreflightError):
                    fingerprint_from_keytool(invalid)

    def test_bad_pinned_identity_and_private_path_fail_before_keytool(self):
        for expected in ("", "aa:bb", "ab" * 31):
            with self.subTest(expected=expected):
                with self.assertRaises(KeystorePreflightError):
                    inspect(self.keystore, "production", expected, runner=self.runner)
        self.keystore.chmod(0o644)
        with self.assertRaisesRegex(KeystorePreflightError, "permissions"):
            inspect(self.keystore, "production", PIN, runner=self.runner)
        self.keystore.chmod(0o600)
        linked = self.private / "linked"
        linked.symlink_to(self.keystore)
        with self.assertRaisesRegex(KeystorePreflightError, "unsafe"):
            inspect(linked, "production", PIN, runner=self.runner)
        self.assertEqual(self.calls, [])

    def test_failed_keytool_never_exposes_stderr_or_password(self):
        def failed(args, **kwargs):
            return SimpleNamespace(
                returncode=1, stdout="private", stderr="test-private",
            )
        with self.assertRaisesRegex(KeystorePreflightError, "could not be verified") as error:
            inspect(self.keystore, "production", PIN, runner=failed)
        self.assertNotIn("test-private", str(error.exception))

    def test_missing_password_blocks_without_running_keytool(self):
        os.environ.pop("WALIDSITE_ANDROID_STORE_PASSWORD")
        with self.assertRaisesRegex(KeystorePreflightError, "password unavailable"):
            inspect(self.keystore, "production", PIN, runner=self.runner)
        self.assertFalse(self.calls)


if __name__ == "__main__":
    unittest.main()
