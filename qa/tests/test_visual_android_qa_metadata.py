"""LEAD P0 Android QA build metadata regression; synthetic source files only."""
import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path

from qa.android_qa_build_metadata import AndroidBuildMetadataError, metadata, main


class AndroidQaBuildMetadataTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.pubspec = self.root / "mobile/pubspec.yaml"
        self.gradle = self.root / "mobile/android/app/build.gradle.kts"
        self.pubspec.parent.mkdir(parents=True)
        self.gradle.parent.mkdir(parents=True)
        self.pubspec.write_text("name: qa\nversion: 0.1.0+1\n", encoding="utf-8")
        self.gradle.write_text(
            'android { defaultConfig {\n  applicationId = "com.example.waleed_tech_companion"\n} }\n',
            encoding="utf-8",
        )

    def test_exact_native_package_and_version_are_from_files(self):
        actual = metadata(self.root)
        self.assertEqual(actual["package_id"], "com.example.waleed_tech_companion")
        self.assertEqual(actual["version_name"], "0.1.0")
        self.assertEqual(actual["version_code"], 1)
        self.assertTrue(actual["placeholder_package_id"])
        self.assertTrue(actual["debug_qa_only"])
        self.assertFalse(actual["distribution_ready"])

    def test_non_placeholder_identity_and_large_valid_version(self):
        self.gradle.write_text(
            'defaultConfig {\n applicationId = "com.walidsite.companion"\n}\n',
            encoding="utf-8",
        )
        self.pubspec.write_text("version: 1.2.3-rc.1+42\n", encoding="utf-8")
        result = metadata(self.root)
        self.assertEqual(result["version_name"], "1.2.3-rc.1")
        self.assertEqual(result["version_code"], 42)
        self.assertFalse(result["placeholder_package_id"])

    def test_ambiguous_or_missing_id_and_version_fail_closed(self):
        original = self.gradle.read_text(encoding="utf-8")
        for content in ("", original + original, "applicationId = rootProject.name\n"):
            with self.subTest(content=content):
                self.gradle.write_text(content, encoding="utf-8")
                with self.assertRaises(AndroidBuildMetadataError):
                    metadata(self.root)
        self.gradle.write_text(original, encoding="utf-8")
        for bad in ("version: 0.1.0\n", "version: 0.1.0+0\n",
                    "version: 0.1.0+2147483648\n", "version: 0.1.0+1\nversion: 0.2.0+2\n"):
            with self.subTest(bad=bad):
                self.pubspec.write_text(bad, encoding="utf-8")
                with self.assertRaises(AndroidBuildMetadataError):
                    metadata(self.root)

    def test_github_output_safe_and_no_false_release_claims(self):
        path = self.root / "native.outputs"
        with redirect_stdout(io.StringIO()) as stream:
            self.assertEqual(main(["--root", str(self.root), "--github-output", str(path)]), 0)
        values = json.loads(stream.getvalue())
        self.assertFalse(values["distribution_ready"])
        self.assertEqual(
            path.read_text(encoding="utf-8"),
            "package_id=com.example.waleed_tech_companion\n"
            "version_name=0.1.0\nversion_code=1\n",
        )

    def test_invalid_file_exits_without_writing_outputs(self):
        path = self.root / "native.outputs"
        self.pubspec.write_text("version: invalid\n", encoding="utf-8")
        with redirect_stderr(io.StringIO()) as stream:
            self.assertEqual(main(["--root", str(self.root), "--github-output", str(path)]), 2)
        self.assertFalse(path.exists())
        self.assertNotIn(str(self.root), stream.getvalue())


if __name__ == "__main__":
    unittest.main()
