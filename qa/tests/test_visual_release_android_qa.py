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

from qa.android_qa_build_metadata import metadata
from qa.verify_release_android_qa import (
    APK_NAME, CHECKSUM_NAME, IDENTITY_NAME, PROOF_NAME, verify, verify_bundle,
    site_release_version,
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
        (self.checkout / "backend").mkdir()
        (self.checkout / "backend/VERSION").write_text("1.2.3\n", encoding="ascii")
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

    def make_candidate(self):
        directory = self.root / "candidate-releases"
        directory.mkdir()
        archives = {}
        for kind in ("Setup", "Update"):
            name = f"WalidSite-{kind}-v1.2.3.zip"
            target = directory / name
            with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
                archive.writestr("application/bootstrap/app.php", b"<?php return 1;")
                archive.writestr("public_html/index.php", b"<?php echo 1;")
            digest = hashlib.sha256(target.read_bytes()).hexdigest()
            (directory / (name + ".sha256")).write_text(
                digest + "  " + name + "\n", encoding="ascii"
            )
            archives[kind.lower()] = {
                "file": name, "sha256": digest, "bytes": target.stat().st_size,
            }
        self.release_manifest = {
            "schema": "walidsite.release-candidate.v1",
            "product": "walidsite", "version": "1.2.3",
            "source_sha": SHA, "published": False,
            "production_ready": False, "publisher_signature_verified": False,
            "setup": archives["setup"], "update": archives["update"],
            "acceptance": {
                "fresh_install_on_live_host": False,
                "signed_publisher_release": False,
            },
        }
        self.php = {
            "schema": "walidsite.signed-zip-ci-acceptance.v1",
            "source_sha": SHA, "version": "1.2.3",
            "artifact_sha256": archives["update"]["sha256"],
            "verified_files": 5, "negative_rejections": 7,
            "test_only_ephemeral_key": True,
            "publisher_signature_verified": False,
            "code_update_performed": False, "production_ready": False,
        }
        self.release_dir = directory
        (directory / "WalidSite-Release-Notes-v1.2.3.md").write_text(
            "# WalidSite v1.2.3 - UNPUBLISHED candidate\n",
            encoding="utf-8",
        )
        return directory

    def verify_candidate(self):
        manifest_name = "WalidSite-Release-Manifest-v1.2.3.json"
        (self.release_dir / manifest_name).write_text(
            json.dumps(self.release_manifest), encoding="utf-8"
        )
        for name in (manifest_name, "WalidSite-Release-Notes-v1.2.3.md"):
            document = self.release_dir / name
            (self.release_dir / (name + ".sha256")).write_text(
                hashlib.sha256(document.read_bytes()).hexdigest()
                + "  " + name + "\n", encoding="ascii"
            )
        (self.release_dir / "signed-zip-acceptance.json").write_text(
            json.dumps(self.php), encoding="utf-8"
        )
        self.attempt()
        return verify_bundle(self.folder, self.checkout, SHA, self.release_dir)

    def test_combined_site_native_release_manifest_binds_real_archives(self):
        self.make_candidate()
        receipt = self.verify_candidate()
        self.assertEqual(receipt["schema"], "walidsite.unpublished-full-release-candidate.v1")
        self.assertEqual(receipt["version"], "1.2.3")
        self.assertEqual(receipt["source_sha"], SHA)
        self.assertEqual(receipt["android_apk_sha256"], self.proof["apk_sha256"])
        self.assertEqual(receipt["update_sha256"], self.php["artifact_sha256"])
        self.assertEqual(receipt["php_zip_negative_rejections"], 7)
        self.assertTrue(receipt["native_and_web_version_match"])
        self.assertTrue(receipt["same_sha_assets_verified"])
        self.assertFalse(receipt["production_ready"])
        self.assertFalse(receipt["publisher_signature_verified"])

    def test_mismatched_web_native_version_and_forged_release_evidence_rejected(self):
        self.make_candidate()
        self.verify_candidate()
        version = self.checkout / "backend/VERSION"
        version.write_text("1.2.4\n", encoding="ascii")
        with self.assertRaises(ValueError):
            self.verify_candidate()
        version.write_text("1.2.3\n", encoding="ascii")
        for key, value in (
            ("source_sha", "f" * 40),
            ("version", "1.2.4"),
            ("published", True),
            ("publisher_signature_verified", True),
            ("production_ready", True),
        ):
            original = self.release_manifest[key]
            self.release_manifest[key] = value
            with self.subTest(field=key), self.assertRaises(ValueError):
                self.verify_candidate()
            self.release_manifest[key] = original
        for key, value in (
            ("artifact_sha256", "0" * 64),
            ("test_only_ephemeral_key", False),
            ("negative_rejections", 4),
            ("publisher_signature_verified", True),
            ("code_update_performed", True),
            ("production_ready", True),
        ):
            original = self.php[key]
            self.php[key] = value
            with self.subTest(field=key), self.assertRaises(ValueError):
                self.verify_candidate()
            self.php[key] = original
        self.verify_candidate()
        with (self.release_dir / "WalidSite-Update-v1.2.3.zip").open("ab") as output:
            output.write(b"altered")
        with self.assertRaises(ValueError):
            self.verify_candidate()

    def test_unified_bundle_rejects_tampered_or_missing_document_checksums(self):
        manifest_name = "WalidSite-Release-Manifest-v1.2.3.json"
        notes_name = "WalidSite-Release-Notes-v1.2.3.md"
        for name in (manifest_name, notes_name):
            with self.subTest(document=name):
                self.make_candidate()
                self.verify_candidate()
                document = self.release_dir / name
                sidecar = self.release_dir / (name + ".sha256")
                # A valid parseable manifest or plausible notes still fail
                # once their immutable, SHA-pinned release bytes change.
                with document.open("ab") as stream:
                    stream.write(b"UNAPPROVED CHANGE")
                with self.assertRaises(ValueError):
                    verify_bundle(self.folder, self.checkout, SHA, self.release_dir)
                self.verify_candidate()
                sidecar.unlink()
                with self.assertRaises(ValueError):
                    verify_bundle(self.folder, self.checkout, SHA, self.release_dir)
                self.release_dir.joinpath(name).unlink()
                self.release_dir.joinpath("signed-zip-acceptance.json").unlink()
                # Restore the release directory for the next independent case.
                import shutil
                shutil.rmtree(self.release_dir)

    def test_real_repository_native_and_web_versions_match(self):
        source_root = Path(__file__).resolve().parents[2]
        self.assertEqual(metadata(source_root)["version_name"],
                         site_release_version(source_root))

    def test_release_workflow_requires_native_ci_before_qa_attachment(self):
        workflow = (Path(__file__).resolve().parents[2] /
                    ".github/workflows/release.yml").read_text()
        self.assertIn("actions: read", workflow)
        self.assertIn('.name == "build-android-qa-apk"', workflow)
        self.assertIn("gh run download", workflow)
        self.assertIn("qa/verify_release_android_qa.py", workflow)
        self.assertIn("--candidates dist/candidates", workflow)
        self.assertIn("unified-release-evidence.json", workflow)
        self.assertIn("native_and_web_version_match", workflow)
        self.assertIn("WalidSite-Android-debug-QA-${SOURCE_SHA}", workflow)
        self.assertIn("head_sha=${SOURCE_SHA}&event=push", workflow)
        self.assertLess(workflow.index("Attach same-commit Android debug QA APK"),
                        workflow.index("Retain unpublished inspection candidates only"))
        self.assertIn("exit 1", workflow.split("  release-gate:", 1)[1])
        self.assertNotIn("gh release create", workflow)
        ci = (Path(__file__).resolve().parents[2] /
              ".github/workflows/ci.yml").read_text()
        self.assertIn("verify-source-pinned-release-bundle:", ci)
        self.assertIn("needs: [required-ci-gate, build-cpanel-package, build-android-qa-apk]", ci)
        self.assertIn("WalidSite-UNPUBLISHED-release-train-${{ github.sha }}", ci)
        self.assertIn("WalidSite-Android-debug-QA-${{ github.sha }}", ci)
        self.assertIn("--candidates dist/source-proof/release/release-train", ci)
        self.assertIn("WalidSite-UNPUBLISHED-unified-build-proof-", ci)
        self.assertIn("proof['native_and_web_version_match'] is True", ci)
        self.assertIn("proof['production_ready'] is False", ci)


if __name__ == "__main__":
    unittest.main()
