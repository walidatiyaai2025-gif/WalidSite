"""Source-level native package identity regression; not a signed store build."""
from pathlib import Path
import unittest

from qa.android_qa_build_metadata import metadata

ROOT = Path(__file__).resolve().parents[2]
CANDIDATE = "com.walidatiya.walidsite"


class NativePackageIdentityTest(unittest.TestCase):
    def test_android_identity_is_non_placeholder_and_bound_to_build_metadata(self):
        record = metadata(ROOT)
        self.assertEqual(record["package_id"], CANDIDATE)
        self.assertFalse(record["placeholder_package_id"])
        self.assertTrue(record["debug_qa_only"])
        self.assertFalse(record["distribution_ready"])

        renderer = (ROOT / "qa/render_android_screenshot.py").read_text(encoding="utf-8")
        self.assertIn(f'PACKAGE = "{CANDIDATE}"', renderer)
        self.assertNotIn('PACKAGE = "com.example.', renderer)

    def test_main_activity_namespace_and_ios_bundle_are_consistent(self):
        kotlin = ROOT / "mobile/android/app/src/main/kotlin"
        activity = kotlin / "com/walidatiya/walidsite/MainActivity.kt"
        self.assertTrue(activity.is_file())
        self.assertIn(f"package {CANDIDATE}", activity.read_text(encoding="utf-8"))
        self.assertFalse((kotlin / "com/example/waleed_tech_companion/MainActivity.kt").exists())

        project = (ROOT / "mobile/ios/Runner.xcodeproj/project.pbxproj").read_text(encoding="utf-8")
        self.assertGreaterEqual(project.count(
            f"PRODUCT_BUNDLE_IDENTIFIER = {CANDIDATE};"), 3)
        self.assertGreaterEqual(project.count(
            f"PRODUCT_BUNDLE_IDENTIFIER = {CANDIDATE}.RunnerTests;"), 3)
        self.assertNotIn("PRODUCT_BUNDLE_IDENTIFIER = com.example.", project)


if __name__ == "__main__":
    unittest.main()
