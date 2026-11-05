"""Release inspection accepts only a previously SHA-pinned, debug-only native APK.

No fixture "signature" is presented as real Android signing; those native
checks must run in the independently green main Android CI job.
"""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from qa.verify_release_android_qa import (
    APK_NAME, CHECKSUM_NAME, IDENTITY_NAME, PROOF_NAME, verify,
)

SHA = "a" * 40
PACKAGE = "com.walidatiya.walidsite"


class ReleaseAndroidQaAttachmentTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.checkout = self.root / "checkout"
        self.folder = self.root / "candidate"
        (self.checkout / "mobile/android/app").mkdir(parents=True)
        (self.checkout / "mobile/pubspec.yaml").write_text(
            "name: waleed_tech_companion\nversion: 1.2.3+7\n", encoding="utf-8"
        )
        (self.checkout / "mobile/android/app/build.gradle.kts").write_text(
            'defaultConfig {\n  applicationId = "' + PACKAGE + '"\n}\n',
            encoding="utf-8",
        )
        self.folder.mkdir()
        self.apk = self.folder / APK_NAME
        with zipfile.ZipFile(self.apk, "w", zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("AndroidManifest.xml", b"manifest-bytes-for-fixture")
            archive.writestr("classes.dex", b"compiled-code-for-fixture")
        digest = hashlib.sha256(self.apk.read_bytes()).hexdigest()
        self.identity = {
            "package_id": PACKAGE, "version_name": "1.2.3", "version_code": 7,
            "placeholder_package_id": False,
            "debug_qa_only": True, "distribution_ready": False,
        }
        self.proof = {
            "apk_sha256": digest, "apk_bytes": self.apk.stat().st_size,
            "source_sha_supplied_not_attested": SHA,
            "package_id": PACKAGE, "version_name": "1.2.3", "version_code": 7,
            "build_kind": "debug", "apk_integrity_verified": True,
            "apk_signature_cryptographically_valid": True,
            "debug_signature_verified": True,
            "distribution_signing_identity_verified": False,
            "device_install_launch_verified": False,
            "ios_build_verified": False, "production_ready": False,
        }
        (self.folder / CHECKSUM_NAME).write_text(
            digest + "  " + APK_NAME + "\n", encoding="ascii"
        )

    def attempt(self):
        (self.folder / IDENTITY_NAME).write_text(json.dumps(self.identity), encoding="utf-8")
        (self.folder / PROOF_NAME).write_text(json.dumps(self.proof), encoding="utf-8")
        return verify(self.folder, self.checkout, SHA)

    def test_same_sha_native_debug_receipt_does_not_claim_production_release(self):
        result = self.attempt()
        self.assertEqual(result["source_sha"], SHA)
        self.assertEqual(result["apk_sha256"], self.proof["apk_sha256"])
        self.assertTrue(result["android_qa_receipt_checked"])
        for field in ("production_signing_verified", "device_launch_verified",
                      "ios_build_verified", "production_ready"):
            self.assertFalse(result[field])

    def test_tampering_source_identity_signature_or_binary_fails_closed(self):
        for field, invalid in (
            ("package_id", "com.example.injection"),
            ("version_name", "9.9.9"),
            ("version_code", 8),
            ("source_sha_supplied_not_attested", "f" * 40),
            ("build_kind", "release-qa"),
            ("apk_signature_cryptographically_valid", False),
            ("debug_signature_verified", False),
            ("distribution_signing_identity_verified", True),
            ("device_install_launch_verified", True),
            ("ios_build_verified", True),
            ("production_ready", True),
            ("apk_sha256", "0" * 64),
            ("apk_bytes", 0),
        ):
            with self.subTest(field=field):
                original = self.proof[field]
                self.proof[field] = invalid
                with self.assertRaises(ValueError):
                    self.attempt()
                self.proof[field] = original

        self.identity["distribution_ready"] = True
        with self.assertRaises(ValueError):
            self.attempt()
        self.identity["distribution_ready"] = False
        self.attempt()
        with self.apk.open("ab") as stream:
            stream.write(b"tamper after QA proof")
        with self.assertRaises(ValueError):
            self.attempt()

    def test_missing_files_extra_files_symlinks_and_hardlinks_are_rejected(self):
        self.attempt()
        extra = self.folder / "latest.json"
        extra.write_text("{}", encoding="utf-8")
        with self.assertRaises(ValueError):
            self.attempt()
        extra.unlink()
        sidecar = self.folder / CHECKSUM_NAME
        sidecar.unlink()
        with self.assertRaises(ValueError):
            self.attempt()
        digest = self.proof["apk_sha256"]
        sidecar.write_text(digest + "  " + APK_NAME + "\n", encoding="ascii")
        self.attempt()

        original = self.folder / "saved-identity.json"
        original.write_text(json.dumps(self.identity), encoding="utf-8")
        (self.folder / IDENTITY_NAME).unlink()
        (self.folder / IDENTITY_NAME).symlink_to(original)
        with self.assertRaises(ValueError):
            self.attempt()
        (self.folder / IDENTITY_NAME).unlink()
        original.unlink()
        self.attempt()
        alias = self.root / "hardlink.apk"
        alias.hardlink_to(self.apk)
        with self.assertRaises(ValueError):
            self.attempt()

    def test_release_workflow_requires_native_ci_before_qa_attachment(self):
        workflow = (Path(__file__).resolve().parents[2] /
                    ".github/workflows/release.yml").read_text()
        self.assertIn("actions: read", workflow)
        self.assertIn('.name == "build-android-qa-apk"', workflow)
        self.assertIn("gh run download", workflow)
        self.assertIn("qa/verify_release_android_qa.py", workflow)
        self.assertIn("WalidSite-Android-debug-QA-${SOURCE_SHA}", workflow)
        self.assertIn("head_sha=${SOURCE_SHA}&event=push", workflow)
        self.assertLess(workflow.index("Attach same-commit Android debug QA APK"),
                        workflow.index("Retain unpublished inspection candidates only"))
        self.assertIn("exit 1", workflow.split("  release-gate:", 1)[1])
        self.assertNotIn("gh release create", workflow)


if __name__ == "__main__":
    unittest.main()
