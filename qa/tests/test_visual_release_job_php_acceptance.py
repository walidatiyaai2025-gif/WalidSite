"""Release workflow must reject forged PHP ZIP proof before uploading candidates.

This uses disposable JSON receipts to execute the exact Python acceptance block
from the Release job; the real ZIP/PHP integration is separately tested by
test_visual_release_candidates.py and mandatory main CI.
"""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/release.yml"
VERSION = "1.2.3"
SHA = "a" * 40
DIGEST = "b" * 64
EXPECTED_NEGATIVE_REJECTIONS = 7


class ReleaseJobPhpAcceptanceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        start = "      - name: Verify real Update ZIP with PHP Ed25519 and archive safety boundary"
        end = "      - name: Retain unpublished inspection candidates only"
        cls.step = cls.workflow.split(start, 1)[1].split(end, 1)[0]
        cls.script = textwrap.dedent(
            cls.step.split("          python3 - <<'PY'\n", 1)[1]
            .split("\n          PY", 1)[0]
        )

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.folder = Path(self.tmp.name) / "dist/candidates"
        self.folder.mkdir(parents=True)
        self.manifest = {
            "schema": "walidsite.release-candidate.v1",
            "version": VERSION, "source_sha": SHA,
            "published": False, "publisher_signature_verified": False,
            "update": {"sha256": DIGEST},
        }
        self.proof = {
            "schema": "walidsite.signed-zip-ci-acceptance.v1",
            "version": VERSION, "source_sha": SHA,
            "artifact_sha256": DIGEST, "verified_files": 5,
            "negative_rejections": EXPECTED_NEGATIVE_REJECTIONS, "test_only_ephemeral_key": True,
            "publisher_signature_verified": False,
            "code_update_performed": False, "production_ready": False,
        }

    def run_gate(self):
        (self.folder / f"WalidSite-Release-Manifest-v{VERSION}.json").write_text(
            json.dumps(self.manifest), encoding="utf-8"
        )
        (self.folder / "signed-zip-acceptance.json").write_text(
            json.dumps(self.proof), encoding="utf-8"
        )
        return subprocess.run(
            [sys.executable, "-c", self.script],
            cwd=self.tmp.name,
            env={**os.environ, "CANDIDATE_VERSION": VERSION, "SOURCE_SHA": SHA},
            capture_output=True, text=True, check=False,
        )

    def test_real_php_probe_is_mandatory_before_upload(self):
        self.assertIn("set -euo pipefail", self.step)
        self.assertIn('test "${GITHUB_ACTIONS}" = "true"', self.step)
        self.assertIn("php qa/cpanel_signed_update_artifact_probe.php", self.step)
        self.assertIn("> dist/candidates/signed-zip-acceptance.json", self.step)
        self.assertLess(self.workflow.index("Verify real Update ZIP with PHP Ed25519"),
                        self.workflow.index("Retain unpublished inspection candidates only"))
        self.assertIn("exit 1", self.workflow.split("  release-gate:", 1)[1])
        # The production publisher secret may only be used by an explicit
        # manual inspection. Automatic workflow_run candidate generation must
        # never read this secret, sign a catalog or publish a GitHub Release.
        self.assertEqual(self.workflow.count("sign_trusted_update_release.php"), 1)
        before_manual, manual = self.workflow.split("  signed-web-inspection:", 1)
        manual, remaining = manual.split("  signed-android-inspection:", 1)
        self.assertNotIn("WALIDSITE_PUBLISHER_SECRET_KEY_B64", before_manual)
        self.assertNotIn("sign_trusted_update_release.php", before_manual)
        self.assertIn("if: github.event_name == 'workflow_dispatch'", manual)
        self.assertIn("WALIDSITE_PUBLISHER_SECRET_KEY_B64:", manual)
        self.assertIn("qa/prepare_signed_web_inspection.py", manual)
        self.assertIn("qa/verify_signed_web_inspection.php", manual)
        self.assertIn("SIGNED-UNPUBLISHED-Web-", manual)
        self.assertNotIn("gh release create", manual)
        self.assertIn("needs: [release-candidates, signed-web-inspection, signed-android-inspection]", remaining)
        self.assertIn("exit 1", remaining)

    def test_probe_and_both_workflows_require_the_same_seven_rejections(self):
        # The real PHP probe returns seven negative checks. A former manual
        # Release job asserted four while main CI asserted seven, making
        # workflow_dispatch fail on a correctly built source-pinned ZIP.
        php_probe = (ROOT / "qa/cpanel_signed_update_artifact_probe.php").read_text(
            encoding="utf-8"
        )
        push_ci = (ROOT / ".github/workflows/ci.yml").read_text(
            encoding="utf-8"
        )
        count = EXPECTED_NEGATIVE_REJECTIONS
        self.assertGreaterEqual(count, 7)  # Do not silently weaken ZIP checks.
        self.assertIn(f"'negative_rejections' => {count}", php_probe)
        self.assertIn(f"assert proof['negative_rejections'] == {count}", self.step)
        self.assertIn(f'assert proof["negative_rejections"] == {count}', push_ci)

    def test_real_release_receipt_accepts_only_unpublished_ephemeral_proof(self):
        self.assertEqual(self.run_gate().returncode, 0)
        for key, invalid in (
            ("source_sha", "f" * 40),
            ("version", "1.2.4"),
            ("artifact_sha256", "0" * 64),
            ("negative_rejections", EXPECTED_NEGATIVE_REJECTIONS - 1),
            ("verified_files", 0),
            ("test_only_ephemeral_key", False),
            ("publisher_signature_verified", True),
            ("code_update_performed", True),
            ("production_ready", True),
        ):
            with self.subTest(key=key):
                self.proof[key] = invalid
                self.assertNotEqual(self.run_gate().returncode, 0)
                self.proof[key] = {
                    "source_sha": SHA, "version": VERSION,
                    "artifact_sha256": DIGEST, "negative_rejections": EXPECTED_NEGATIVE_REJECTIONS,
                    "verified_files": 5, "test_only_ephemeral_key": True,
                    "publisher_signature_verified": False,
                    "code_update_performed": False, "production_ready": False,
                }[key]

        # A fabricated receipt claiming *more* rejections must also fail
        # rather than bypassing the exact contract with a minimum threshold.
        self.proof["negative_rejections"] = EXPECTED_NEGATIVE_REJECTIONS + 1
        self.assertNotEqual(self.run_gate().returncode, 0)
        self.proof["negative_rejections"] = EXPECTED_NEGATIVE_REJECTIONS

        self.manifest["published"] = True
        self.assertNotEqual(self.run_gate().returncode, 0)
        self.manifest["published"] = False
        self.manifest["publisher_signature_verified"] = True
        self.assertNotEqual(self.run_gate().returncode, 0)
        self.manifest["publisher_signature_verified"] = False
        (self.folder / "latest.json").write_text("{}", encoding="utf-8")
        self.assertNotEqual(self.run_gate().returncode, 0)


if __name__ == "__main__":
    unittest.main()
