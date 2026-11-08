"""#1120: only pinned Gradle HTTP transport errors may trigger retry."""
from pathlib import Path
import os
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class GradleDistributionBootstrapTests(unittest.TestCase):
    def test_official_gradle_910_binary_is_exact_version_and_sha_pinned(self):
        text = (ROOT / "mobile/android/gradle/wrapper/gradle-wrapper.properties").read_text()
        self.assertIn("distributionUrl=https\\://services.gradle.org/distributions/gradle-9.1.0-bin.zip", text)
        # Official Gradle 9.1.0 binary checksum: gradle.org/release-checksums/
        self.assertIn("distributionSha256Sum=a17ddd85a26b6a7f5ddb71ff8b05fc5104c0202c6e64782429790c933686c806", text)
        self.assertNotIn("gradle-9.1.0-all.zip", text)
        self.assertEqual(len(re.findall(r"(?m)^distributionSha256Sum=", text)), 1)

    def test_compiler_and_signature_checks_remain_mandatory(self):
        helper = (ROOT / "qa/gradle_distribution_preflight.sh").read_text()
        self.assertIn("for attempt in 1 2 3; do", helper)
        self.assertIn("java[.]io[.]IOException", helper)
        self.assertIn("return 1", helper)
        self.assertNotIn("|| true", helper)
        self.assertNotIn("eval ", helper)
        ci = (ROOT / ".github/workflows/ci.yml").read_text()
        release = (ROOT / "qa/build_origin_bound_android_release.sh").read_text()
        self.assertIn("source qa/gradle_distribution_preflight.sh", ci)
        self.assertIn("source qa/gradle_distribution_preflight.sh", release)
        self.assertIn("with_gradle_distribution_retry flutter build apk --debug --no-pub", ci)
        self.assertIn("with_gradle_distribution_retry flutter build apk --release --no-pub", release)
        self.assertIn("qa/native_android_acceptance.py", ci)
        self.assertIn("apksigner", release)

    def _run_fake_flutter(self, kind):
        with tempfile.TemporaryDirectory(prefix="gradle-retry-") as d:
            root = Path(d)
            counter = root / "attempts"
            flutter = root / "flutter"
            flutter.write_text("""#!/usr/bin/env bash
set -euo pipefail
n=0
if [[ -f "$COUNT_FILE" ]]; then n="$(cat "$COUNT_FILE")"; fi
n=$((n+1))
printf '%s' "$n" > "$COUNT_FILE"
case "$STUB_KIND" in
  transport)
    if [[ "$n" -eq 1 ]]; then
      echo 'Exception in thread "main" java.io.IOException: Server returned HTTP response code: 504 for URL: https://github.com/gradle/gradle-distributions/releases/download/v9.1.0/gradle-9.1.0-bin.zip' >&2
      exit 1
    fi ;;
  compile)
    echo 'Execution failed for task :app:compileDebugKotlin' >&2; exit 1 ;;
  wrong_url)
    echo 'Exception in thread "main" java.io.IOException: Server returned HTTP response code: 504 for URL: https://other-domain.invalid/gradle-9.1.0-bin.zip' >&2
    exit 1 ;;
esac
echo 'BUILD SUCCESSFUL'
""")
            flutter.chmod(0o700)
            pause = root / "sleep"
            pause.write_text("#!/usr/bin/env bash\nexit 0\n")
            pause.chmod(0o700)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ["PATH"],
                       COUNT_FILE=str(counter), STUB_KIND=kind)
            command = "source qa/gradle_distribution_preflight.sh; with_gradle_distribution_retry flutter build apk --debug --no-pub"
            result = subprocess.run(["bash", "-euo", "pipefail", "-c", command],
                                    env=env, cwd=ROOT, capture_output=True, text=True,
                                    timeout=10, check=False)
            return result, int(counter.read_text()) if counter.exists() else 0

    def test_one_real_gradle_http_504_is_retryable_then_success(self):
        result, attempts = self._run_fake_flutter("transport")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(attempts, 2)
        self.assertIn("BUILD SUCCESSFUL", result.stdout)

    def test_compilation_failure_is_not_retried(self):
        result, attempts = self._run_fake_flutter("compile")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(attempts, 1)
        self.assertIn("not an approved Gradle transport retry", result.stderr)

    def test_unapproved_504_origin_is_not_retried(self):
        result, attempts = self._run_fake_flutter("wrong_url")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(attempts, 1)


if __name__ == "__main__":
    unittest.main()
