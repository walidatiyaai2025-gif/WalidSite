"""Offline adversarial contract tests; these do NOT assert a native build ran."""
import io
import json
import tempfile
import unittest
import warnings
import zipfile
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from unittest.mock import patch
from subprocess import CompletedProcess

from qa.native_android_acceptance import ApkAcceptanceError, inspect, main

SHA = "a" * 40
BADGING = """package: name='com.example.waleed_tech_companion' versionCode='1' versionName='0.1.0'
sdkVersion:'23'
targetSdkVersion:'36'
application-label:'WalidSite'
"""
PERMISSIONS = "uses-permission: name='android.permission.INTERNET'\n"


class NativeAndroidAcceptanceTest(unittest.TestCase):
    def setUp(self):
        self.t = tempfile.TemporaryDirectory()
        self.addCleanup(self.t.cleanup)
        self.apk = Path(self.t.name) / "test.apk"
        with zipfile.ZipFile(self.apk, "w") as archive:
            archive.writestr("AndroidManifest.xml", b"synthetic, not a real Android manifest")
            archive.writestr("classes.dex", b"synthetic, not Dalvik bytecode")
        self.args = dict(kind="debug", expected_id="com.example.waleed_tech_companion",
                         expected_version="0.1.0", expected_code=1, source_sha=SHA)

    @staticmethod
    def command(args):
        if args[0] == "aapt":
            return CompletedProcess(args, 0, BADGING if args[2] == "badging" else PERMISSIONS, "")
        return CompletedProcess(args, 0, "Verified using v2 scheme: true", "")

    @staticmethod
    def release_command(args):
        result = NativeAndroidAcceptanceTest.command(args)
        if args[0] == "aapt" and args[2] == "badging":
            result.stdout = result.stdout.replace(
                "com.example.waleed_tech_companion", "com.walidsite.qa")
        return result

    def test_synthetic_parser_requires_tools_and_does_not_claim_distribution(self):
        with patch("qa.native_android_acceptance._command", side_effect=self.command) as proc:
            result = inspect(self.apk, **self.args)
        self.assertEqual(proc.call_count, 3)
        self.assertTrue(result["apk_integrity_verified"])
        self.assertTrue(result["debug_signature_verified"])
        self.assertEqual(result["package_id"], self.args["expected_id"])
        self.assertEqual(result["permissions"], ["android.permission.INTERNET"])
        self.assertEqual(result["min_sdk"], 23)
        self.assertEqual(result["target_sdk"], 36)
        self.assertFalse(result["production_ready"])
        self.assertFalse(result["distribution_signing_identity_verified"])
        self.assertFalse(result["device_install_launch_verified"])
        self.assertEqual(result["source_sha_supplied_not_attested"], SHA)

    def test_release_qa_requires_valid_signature_and_non_debuggable_manifest(self):
        release_args = {**self.args, "kind": "release-qa",
                        "expected_id": "com.walidsite.qa"}

        def unsigned(args):
            if args[0] == "apksigner":
                return CompletedProcess(args, 1, "", "DOES NOT VERIFY")
            return self.release_command(args)

        with patch("qa.native_android_acceptance._command", side_effect=unsigned):
            with self.assertRaisesRegex(ApkAcceptanceError, "signature"):
                inspect(self.apk, **release_args)

        with patch("qa.native_android_acceptance._command",
                   side_effect=self.release_command):
            result = inspect(self.apk, **release_args)
        self.assertTrue(result["apk_integrity_verified"])
        self.assertTrue(result["apk_signature_cryptographically_valid"])
        self.assertEqual(result["package_id"], "com.walidsite.qa")
        self.assertFalse(result["debug_signature_verified"])
        self.assertFalse(result["production_ready"])
        self.assertFalse(result["distribution_signing_identity_verified"])

        def debuggable(args):
            base = self.release_command(args)
            if args[0] == "aapt" and args[2] == "badging":
                base.stdout += "application-debuggable\n"
            return base

        with patch("qa.native_android_acceptance._command", side_effect=debuggable):
            with self.assertRaisesRegex(ApkAcceptanceError, "debuggable"):
                inspect(self.apk, **release_args)

    def test_release_qa_rejects_placeholder_package_identity_before_native_tools(self):
        for package in ("com.example.waleed_tech_companion",
                        "org.example.walidsite", "net.example.test_app"):
            with self.subTest(package=package):
                with patch("qa.native_android_acceptance._command") as command:
                    with self.assertRaisesRegex(ApkAcceptanceError, "non-placeholder"):
                        inspect(self.apk, **{**self.args, "kind": "release-qa",
                                             "expected_id": package})
                    command.assert_not_called()
        # Development-only debug APKs may retain a visibly untrusted ID.
        with patch("qa.native_android_acceptance._command", side_effect=self.command):
            self.assertFalse(inspect(self.apk, **self.args)["production_ready"])

    def test_apk_replacement_during_signature_verification_fails_integrity_gate(self):
        def replaced_on_signature(args):
            result = self.command(args)
            if args[0] == "apksigner":
                # A distinct, structurally valid APK now occupies the same
                # pathname after the earlier SHA/CRC and native inspection.
                with zipfile.ZipFile(self.apk, "w") as archive:
                    archive.writestr("AndroidManifest.xml", b"changed native manifest")
                    archive.writestr("classes.dex", b"changed compiled code")
            return result

        with patch("qa.native_android_acceptance._command",
                   side_effect=replaced_on_signature):
            with self.assertRaisesRegex(ApkAcceptanceError, "changed"):
                inspect(self.apk, **self.args)

    def test_missing_duplicated_zero_and_inverted_sdk_levels_fail_closed(self):
        invalid = {
            "missing_min": BADGING.replace("sdkVersion:'23'\n", ""),
            "missing_target": BADGING.replace("targetSdkVersion:'36'\n", ""),
            "duplicate_min": BADGING + "sdkVersion:'23'\n",
            "duplicate_target": BADGING + "targetSdkVersion:'36'\n",
            "zero_min": BADGING.replace("sdkVersion:'23'", "sdkVersion:'0'"),
            "inverted": BADGING.replace("targetSdkVersion:'36'", "targetSdkVersion:'22'"),
            "overflow": BADGING.replace("targetSdkVersion:'36'", "targetSdkVersion:'1001'"),
        }
        for name, badging in invalid.items():
            with self.subTest(case=name):
                def command(args):
                    if args[0] == "aapt" and args[2] == "badging":
                        return CompletedProcess(args, 0, badging, "")
                    return self.command(args)

                with patch("qa.native_android_acceptance._command", side_effect=command):
                    with self.assertRaisesRegex(ApkAcceptanceError, "SDK"):
                        inspect(self.apk, **self.args)

    def test_identity_version_and_permission_fail_closed(self):
        for key, value in (("expected_id", "com.other.site"),
                           ("expected_version", "0.2.0"),
                           ("expected_code", 2), ("source_sha", "f" * 39),
                           ("expected_code", True)):
            with self.subTest(key=key):
                with patch("qa.native_android_acceptance._command", side_effect=self.command):
                    with self.assertRaises(ApkAcceptanceError):
                        inspect(self.apk, **{**self.args, key: value})
        with patch("qa.native_android_acceptance._command", side_effect=lambda args:
                   CompletedProcess(args, 0, BADGING if args[2] == "badging" else "", "")
                   if args[0] == "aapt" else CompletedProcess(args, 0, "", "")):
            with self.assertRaisesRegex(ApkAcceptanceError, "INTERNET"):
                inspect(self.apk, **self.args)

    def test_debug_bad_signature_and_missing_sdk_fail_closed(self):
        def unsigned(args):
            result = self.command(args)
            if args[0] == "apksigner":
                result.returncode = 1
            return result
        with patch("qa.native_android_acceptance._command", side_effect=unsigned):
            with self.assertRaisesRegex(ApkAcceptanceError, "signature"):
                inspect(self.apk, **self.args)
        with patch("qa.native_android_acceptance.subprocess.run", side_effect=OSError("missing")):
            with self.assertRaises(ApkAcceptanceError):
                inspect(self.apk, **self.args)

    def test_zip_tamper_duplicate_traversal_and_symlink_fail_before_aapt(self):
        cases = [
            [("AndroidManifest.xml", b"a")],
            [("AndroidManifest.xml", b"a"), ("classes.dex", b"b"),
             ("classes.dex", b"duplicate")],
            [("AndroidManifest.xml", b"a"), ("classes.dex", b"b"),
             ("../private", b"payload")],
        ]
        for entries in cases:
            with self.subTest(entries=[e[0] for e in entries]):
                with warnings.catch_warnings():
                    warnings.filterwarnings("ignore", message="Duplicate name")
                    with zipfile.ZipFile(self.apk, "w") as archive:
                        for name, contents in entries:
                            archive.writestr(name, contents)
                with patch("qa.native_android_acceptance._command", side_effect=AssertionError("called")):
                    with self.assertRaises(ApkAcceptanceError):
                        inspect(self.apk, **self.args)
        self.apk.unlink()
        other = Path(self.t.name) / "other.apk"
        other.write_bytes(b"not-an-apk")
        self.apk.symlink_to(other)
        with self.assertRaises(ApkAcceptanceError):
            inspect(self.apk, **self.args)

    def test_aliases_and_file_directory_collisions_fail_before_crc_or_sdk(self):
        required = [("AndroidManifest.xml", b"manifest"), ("classes.dex", b"dex")]
        bad_entries = {
            "double_separator": [("assets//icon.png", b"x")],
            "literal_dot": [("assets/./icon.png", b"x")],
            "parent_segment": [("assets/../icon.png", b"x")],
            "empty_directory_segment": [("assets//", b"")],
            "file_directory_alias": [("assets", b"x"), ("assets/", b"")],
            "implicit_parent_is_file": [("assets", b"x"), ("assets/icon.png", b"y")],
            "required_file_directory_alias": [("AndroidManifest.xml/", b"")],
            "directory_with_bytes": [("assets/", b"not a directory")],
        }
        for case, extra in bad_entries.items():
            with self.subTest(case=case):
                with zipfile.ZipFile(self.apk, "w") as archive:
                    for name, contents in required + extra:
                        archive.writestr(name, contents)
                # Invalid paths must be refused before costly decompression
                # or any native inspection, even if ZIP member CRCs are valid.
                with patch.object(zipfile.ZipFile, "testzip",
                                  side_effect=AssertionError("unsafe member was inflated")):
                    with patch("qa.native_android_acceptance._command",
                               side_effect=AssertionError("native tool reached")):
                        with self.assertRaisesRegex(
                            ApkAcceptanceError, "Unsafe|collide"
                        ):
                            inspect(self.apk, **self.args)

    def test_canonical_directory_entry_remains_accepted_by_synthetic_probe(self):
        with zipfile.ZipFile(self.apk, "w") as archive:
            archive.writestr("AndroidManifest.xml", b"synthetic manifest")
            archive.writestr("classes.dex", b"synthetic bytecode")
            archive.writestr("META-INF/", b"")
            archive.writestr("META-INF/NOTICE.SF", b"synthetic signature")
        # This test validates only ZIP structural acceptance. Native SDK
        # responses are synthetic, not a signed distribution artifact.
        with patch("qa.native_android_acceptance._command", side_effect=self.command):
            receipt = inspect(self.apk, **self.args)
        self.assertTrue(receipt["apk_integrity_verified"])
        self.assertFalse(receipt["production_ready"])

    def test_cli_fails_closed_without_exposing_user_paths(self):
        with redirect_stderr(io.StringIO()) as stream:
            code = main(["--apk", str(self.apk), "--kind", "debug",
                         "--package-id", self.args["expected_id"],
                         "--version-name", "0.1.0", "--version-code", "1",
                         "--source-sha", SHA])
        self.assertEqual(code, 1)
        failure = json.loads(stream.getvalue())
        self.assertFalse(failure["production_ready"])
        self.assertNotIn(str(self.apk), stream.getvalue())
        with patch("qa.native_android_acceptance._command", side_effect=self.command):
            with redirect_stdout(io.StringIO()) as output:
                self.assertEqual(main(["--apk", str(self.apk), "--kind", "debug",
                                       "--package-id", self.args["expected_id"],
                                       "--version-name", "0.1.0", "--version-code", "1",
                                       "--source-sha", SHA]), 0)
        self.assertFalse(json.loads(output.getvalue())["production_ready"])


if __name__ == "__main__":
    unittest.main()
