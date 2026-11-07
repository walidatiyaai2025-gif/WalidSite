"""Release inspection accepts only a previously SHA-pinned, debug-only native APK.

No fixture "signature" is presented as real Android signing; those native
checks must run in the independently green main Android CI job.
"""
import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from qa.android_qa_build_metadata import metadata
from qa.verify_release_android_qa import (
    APK_NAME, CHECKSUM_NAME, IDENTITY_NAME, PROOF_NAME, hash_bounded_stream,
    pinned_directory, read_verified, verify_bounded_zip_members, verify, verify_bundle,
    site_release_version, strict_receipt_json,
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

    def test_pinned_stream_enforces_actual_bytes_not_just_initial_file_size(self):
        # Simulate a regular file growing after the fstat bound check:
        # the streaming reader must reject at the first excess byte.
        payload = b"0123456789"
        for retain in (True, False):
            with self.subTest(retain=retain):
                kept, digest, count = hash_bounded_stream(
                    io.BytesIO(payload), len(payload), retain=retain
                )
                self.assertEqual(count, len(payload))
                self.assertEqual(digest, hashlib.sha256(payload).hexdigest())
                self.assertEqual(kept, payload if retain else None)
                with self.assertRaisesRegex(ValueError, "bounded byte budget"):
                    hash_bounded_stream(io.BytesIO(payload + b"x"), len(payload),
                                        retain=retain)
        with self.assertRaisesRegex(ValueError, "byte budget"):
            hash_bounded_stream(io.BytesIO(payload), 0, retain=True)

    def test_zip_crc_validation_rejects_expansive_archive_before_decompression(self):
        with pinned_directory(self.folder) as fd:
            # The normal APK fixture has two bounded ZIP members.
            read_verified(fd, APK_NAME, self.apk.stat().st_size, verify_zip=True)

        # This is just a few KiB compressed, but contains MiBs of output.
        # Reject based on ZIP metadata before testzip() decompresses it.
        with zipfile.ZipFile(self.apk, "w", zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("AndroidManifest.xml", b"manifest")
            archive.writestr("classes.dex", b"A" * (2 * 1024 * 1024))
        self.assertLess(self.apk.stat().st_size, 100000)
        with pinned_directory(self.folder) as fd:
            with self.assertRaisesRegex(ValueError, "byte budget"):
                read_verified(fd, APK_NAME, self.apk.stat().st_size,
                              verify_zip=True)

    def test_zip_member_count_and_expanded_budget_are_enforced(self):
        source = io.BytesIO()
        with zipfile.ZipFile(source, "w", zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("a", b"A" * 100)
            archive.writestr("b", b"B" * 100)
        with zipfile.ZipFile(io.BytesIO(source.getvalue())) as archive:
            verify_bounded_zip_members(archive, 50)
            with self.assertRaisesRegex(ValueError, "cumulative byte budget"):
                verify_bounded_zip_members(archive, 49)
        source = io.BytesIO()
        with zipfile.ZipFile(source, "w") as archive:
            archive.writestr("only", b"x")
        with zipfile.ZipFile(io.BytesIO(source.getvalue())) as archive:
            with self.assertRaisesRegex(ValueError, "member count"):
                verify_bounded_zip_members(archive, 100)

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

    def test_strict_release_json_parser_rejects_ambiguous_receipts(self):
        for raw in (
            b'{"source_sha":"a","source_sha":"a"}',
            b'{"nested":{"ok":true,"ok":true}}',
            b'{"otherwise_valid":true,"extra":NaN}',
            b'{"otherwise_valid":true,"extra":Infinity}',
            b'{"otherwise_valid":true,"extra":-Infinity}',
            b'{"otherwise_valid":true,"extra":1e999}',
        ):
            with self.subTest(raw=raw), self.assertRaises(ValueError):
                strict_receipt_json(raw)
        self.assertEqual(strict_receipt_json(
            b'{"nested":{"ok":true},"duration":0.5}'
        ), {"nested": {"ok": True}, "duration": 0.5})

    def test_android_qa_artifacts_reject_duplicate_and_nonfinite_json(self):
        self.attempt()
        for name in (IDENTITY_NAME, PROOF_NAME):
            document = self.folder / name
            original = document.read_text(encoding="utf-8")
            self.assertTrue(original.rstrip().endswith("}"))
            for extra in (
                f',"package_id":"{PACKAGE}"',
                ',"unused_value":NaN',
                ',"unused_value":Infinity',
                ',"unused_value":-Infinity',
                ',"unused_value":1e999',
            ):
                with self.subTest(receipt=name, extra=extra):
                    document.write_text(
                        original.rstrip()[:-1] + extra + "}", encoding="utf-8"
                    )
                    with self.assertRaises(ValueError):
                        self.attempt()
                    document.write_text(original, encoding="utf-8")
        self.attempt()

    def test_unified_release_artifacts_reject_duplicate_identity_json(self):
        self.make_candidate()
        self.verify_candidate()
        manifest = self.release_dir / "WalidSite-Release-Manifest-v1.2.3.json"
        php_proof = self.release_dir / "signed-zip-acceptance.json"
        build_info = self.release_dir / "WalidSite-Build-Info-v1.2.3.json"
        documents = (manifest, php_proof, build_info)
        sidecars = tuple(self.release_dir / (p.name + ".sha256")
                         for p in (manifest, build_info))
        originals = {path: path.read_bytes() for path in (*documents, *sidecars)}

        def update_checksum(document):
            digest = hashlib.sha256(document.read_bytes()).hexdigest()
            sidecar = self.release_dir / (document.name + ".sha256")
            sidecar.write_text(f"{digest}  {document.name}\n", encoding="ascii")
            return digest

        for document in documents:
            with self.subTest(document=document.name):
                try:
                    original = document.read_text(encoding="utf-8")
                    document.write_text(
                        original.rstrip()[:-1] +
                        f',"source_sha":"{SHA}"' + "}", encoding="utf-8"
                    )
                    if document == manifest:
                        update_checksum(manifest)
                    if document == build_info:
                        new_digest = update_checksum(build_info)
                        current_manifest = json.loads(manifest.read_text())
                        current_manifest["build_info"]["sha256"] = new_digest
                        manifest.write_text(json.dumps(current_manifest),
                                            encoding="utf-8")
                        update_checksum(manifest)
                    # All hashes are updated to match the tampered bytes:
                    # this must fail because the parsed identity is ambiguous,
                    # not simply because a SHA-256 sidecar is outdated.
                    with self.assertRaises(ValueError):
                        verify_bundle(self.folder, self.checkout, SHA,
                                      self.release_dir)
                finally:
                    for path, original in originals.items():
                        path.write_bytes(original)
        self.verify_candidate()

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
        self.build_info = {
            "schema": "walidsite.unpublished-build-info.v1",
            "product": "walidsite", "version": "1.2.3",
            "source_sha": SHA,
            "setup_sha256": archives["setup"]["sha256"],
            "update_sha256": archives["update"]["sha256"],
            "composer_lock_sha256": "b" * 64,
            "production_dependencies": [{"name": "laravel/framework", "version": "v12.0.0"}],
            "migration_sha256": {"2026_01_01_create_table.php": "c" * 64},
            "minimum_php": "8.3.0",
            "publisher_signature_verified": False,
            "upgrade_compatibility_verified": False,
            "production_ready": False,
        }
        self.release_manifest["update"]["min_php"] = "8.3.0"
        build_name = "WalidSite-Build-Info-v1.2.3.json"
        build_bytes = (json.dumps(self.build_info, sort_keys=True) + "\n").encode("utf-8")
        (directory / build_name).write_bytes(build_bytes)
        build_hash = hashlib.sha256(build_bytes).hexdigest()
        (directory / (build_name + ".sha256")).write_text(
            build_hash + "  " + build_name + "\n", encoding="ascii"
        )
        self.release_manifest["build_info"] = {
            "file": build_name, "sha256": build_hash,
            "composer_lock_sha256": "b" * 64,
            "production_dependencies": 1, "migration_files": 1,
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
        self.assertEqual(receipt["build_info_sha256"], self.release_manifest["build_info"]["sha256"])
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

    def test_unified_release_rejects_build_info_tampering_and_forged_identity(self):
        build_name = "WalidSite-Build-Info-v1.2.3.json"
        for tamper in ("missing_sidecar", "changed_bytes", "forged_source",
                       "forged_update", "false_production_claim"):
            with self.subTest(tamper=tamper):
                self.make_candidate()
                self.verify_candidate()
                document = self.release_dir / build_name
                sidecar = self.release_dir / (build_name + ".sha256")
                if tamper == "missing_sidecar":
                    sidecar.unlink()
                elif tamper == "changed_bytes":
                    with document.open("ab") as stream:
                        stream.write(b"UNAPPROVED CHANGE")
                else:
                    modified = dict(self.build_info)
                    if tamper == "forged_source":
                        modified["source_sha"] = "f" * 40
                    elif tamper == "forged_update":
                        modified["update_sha256"] = "0" * 64
                    else:
                        modified["production_ready"] = True
                    data = (json.dumps(modified, sort_keys=True) + "\n").encode()
                    document.write_bytes(data)
                    digest = hashlib.sha256(data).hexdigest()
                    sidecar.write_text(digest + "  " + build_name + "\n", encoding="ascii")
                    # A malicious bundle rehashing all unsigned documents
                    # must still fail because cross-archive identity disagrees.
                    self.release_manifest["build_info"]["sha256"] = digest
                    manifest_file = self.release_dir / "WalidSite-Release-Manifest-v1.2.3.json"
                    manifest_bytes = json.dumps(self.release_manifest).encode()
                    manifest_file.write_bytes(manifest_bytes)
                    (self.release_dir / (manifest_file.name + ".sha256")).write_text(
                        hashlib.sha256(manifest_bytes).hexdigest()
                        + "  " + manifest_file.name + "\n", encoding="ascii"
                    )
                with self.assertRaises(ValueError):
                    verify_bundle(self.folder, self.checkout, SHA, self.release_dir)
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
        self.assertIn("qa/verify_exact_main_release_ci.py", workflow)
        self.assertIn("actions/runs/${ci_run_id}/jobs?per_page=100", workflow)
        verifier = (Path(__file__).resolve().parents[2] /
                    "qa/verify_exact_main_release_ci.py").read_text()
        for name in ("build-android-qa-apk", "android-emulator-install-launch",
                     "verify-source-pinned-release-bundle"):
            self.assertIn(f'"{name}"', verifier)
        self.assertIn("gh run download", workflow)
        self.assertIn("qa/verify_release_android_qa.py", workflow)
        self.assertIn("--candidates dist/candidates", workflow)
        self.assertIn("unified-release-evidence.json", workflow)
        self.assertIn("native_and_web_version_match", workflow)
        self.assertIn("WalidSite-Android-debug-QA-${SOURCE_SHA}", workflow)
        self.assertIn("head_sha=${TESTED_SHA}&event=push", workflow)
        self.assertIn("CI_RUN_ID: ${{ steps.verified-ci.outputs.ci_run_id }}", workflow)
        self.assertIn('gh run download "$CI_RUN_ID"', workflow)
        self.assertNotIn('gh run download "$run_id"', workflow)
        self.assertLess(workflow.index("Attach same-commit Android debug QA APK"),
                        workflow.index("Retain unpublished inspection candidates only"))
        self.assertIn("exit 1", workflow.split("  release-gate:", 1)[1])
        self.assertNotIn("gh release create", workflow)
        ci = (Path(__file__).resolve().parents[2] /
              ".github/workflows/ci.yml").read_text()
        self.assertIn("verify-source-pinned-release-bundle:", ci)
        self.assertIn("needs: [required-ci-gate, build-cpanel-package, build-android-qa-apk, android-emulator-install-launch]", ci)
        self.assertIn("name: WalidSite-Android-emulator-launch-${{ github.sha }}", ci)
        self.assertIn("assert emulator['device_install_launch_verified'] is True", ci)
        self.assertIn("assert emulator['apk_sha256'] == proof['android_apk_sha256']", ci)
        self.assertIn("assert emulator['production_ready'] is False", ci)
        self.assertIn("WalidSite-UNPUBLISHED-release-train-${{ github.sha }}", ci)
        self.assertIn(
            "name: WalidSite-UNPUBLISHED-release-train-"
            "${{ github.event_name == 'pull_request' && github.event.pull_request.head.sha || github.sha }}",
            ci,
        )
        self.assertIn(
            "ref: ${{ github.event_name == 'pull_request' && github.event.pull_request.head.sha || github.sha }}",
            ci,
        )
        self.assertIn("WalidSite-Android-debug-QA-${{ github.sha }}", ci)
        self.assertIn("--candidates dist/source-proof/release/release-train", ci)
        self.assertIn("WalidSite-UNPUBLISHED-unified-build-proof-", ci)
        self.assertIn("proof['native_and_web_version_match'] is True", ci)
        self.assertIn("proof['production_ready'] is False", ci)


if __name__ == "__main__":
    unittest.main()
