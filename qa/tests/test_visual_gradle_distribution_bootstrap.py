"""#1120: Android Gradle download retries never weaken checksum or build gates."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class GradleDistributionBootstrapTests(unittest.TestCase):
    def test_exact_official_gradle_910_binary_is_version_and_sha_pinned(self):
        text = (ROOT / "mobile/android/gradle/wrapper/gradle-wrapper.properties").read_text()
        self.assertIn("distributionUrl=https\\://services.gradle.org/distributions/gradle-9.1.0-bin.zip", text)
        # Official Gradle 9.1.0 -bin checksum from gradle.org/release-checksums/
        self.assertIn("distributionSha256Sum=a17ddd85a26b6a7f5ddb71ff8b05fc5104c0202c6e64782429790c933686c806", text)
        self.assertNotIn("gradle-9.1.0-all.zip", text)
        self.assertEqual(len(re.findall(r"(?m)^distributionSha256Sum=", text)), 1)

    def test_only_bootstrap_is_retryable_not_compiler_or_signing(self):
        script = (ROOT / "qa/gradle_distribution_preflight.sh").read_text()
        self.assertIn("set -euo pipefail", script)
        self.assertIn("for attempt in 1 2 3; do", script)
        self.assertIn("bash ./gradlew --version", script)
        self.assertIn("exit 1", script)
        self.assertNotIn("flutter build", script)
        ci = (ROOT / ".github/workflows/ci.yml").read_text()
        release = (ROOT / "qa/build_origin_bound_android_release.sh").read_text()
        self.assertIn("bash qa/gradle_distribution_preflight.sh", ci)
        self.assertIn("bash qa/gradle_distribution_preflight.sh", release)
        self.assertIn("flutter build apk --debug --no-pub", ci)
        self.assertIn("flutter build apk --release --no-pub", release)
        self.assertIn("qa/native_android_acceptance.py", ci)


if __name__ == "__main__":
    unittest.main()
