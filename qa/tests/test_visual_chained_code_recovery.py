"""Execute the actual CI receipt gate for chained signed-code recovery.

These tests validate receipt rejection; the package job separately runs real
Setup/Update archives and disposable MariaDB through the PHP coordinator.
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
SHA = "a" * 40
VERSION = "0.1.2"


class ChainedCodeRecoveryReceiptTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        step = workflow.split("> dist/release-train/code-install-fixture-acceptance.json", 1)[1]
        cls.script = textwrap.dedent(
            step.split("python3 - <<'PY'\n", 1)[1].split("\n          PY", 1)[0]
        )

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.folder = Path(self.tmp.name) / "dist/release-train"
        self.folder.mkdir(parents=True)
        self.proof = {
            "schema": "walidsite.disposable-code-install-acceptance.v1",
            "source_sha": SHA,
            "version": VERSION,
            "same_build_setup_and_update": True,
            "signed_code_cutover_fixture": True,
            "disposable_mariadb_restore": True,
            "failed_health_pointer_reverted": True,
            "late_populated_restore_blocked": True,
            "chained_code_only_fixture_tested": True,
            "signed_cron_code_apply_fixture_tested": True,
            "chained_health_failure_previous_pointer_restored": True,
            "chained_health_recovery_cleared_maintenance": True,
            "chained_health_recovery_retry_succeeded": True,
            "chained_tampered_previous_code_keeps_maintenance_and_blocks_retry": True,
            "chained_fixture_source_sha_not_git_attested": True,
            "real_https_host_verified": False,
            "independent_foreign_writer_isolation": False,
            "publisher_signature_verified_for_production": False,
            "production_ready": False,
        }

    def run_gate(self):
        (self.folder / "code-install-fixture-acceptance.json").write_text(
            json.dumps(self.proof), encoding="utf-8"
        )
        return subprocess.run(
            [sys.executable, "-c", self.script], cwd=self.tmp.name,
            env={**os.environ, "SOURCE_SHA": SHA, "VERSION": VERSION},
            capture_output=True, text=True,
        )

    def test_complete_fixture_receipt_is_accepted(self):
        self.assertEqual(self.run_gate().returncode, 0)

    def test_pointer_restore_without_successful_retry_is_rejected(self):
        self.proof["chained_health_recovery_retry_succeeded"] = False
        self.assertNotEqual(self.run_gate().returncode, 0)
        del self.proof["chained_health_recovery_retry_succeeded"]
        self.assertNotEqual(self.run_gate().returncode, 0)

    def test_wrong_source_and_unproved_restore_fail_closed(self):
        for key, invalid in (
            ("source_sha", "b" * 40),
            ("version", "0.1.3"),
            ("chained_health_recovery_cleared_maintenance", False),
            ("chained_health_failure_previous_pointer_restored", False),
            ("disposable_mariadb_restore", False),
        ):
            with self.subTest(key=key):
                previous = self.proof[key]
                self.proof[key] = invalid
                self.assertNotEqual(self.run_gate().returncode, 0)
                self.proof[key] = previous

    def test_tampered_chained_release_must_be_quarantined(self):
        self.proof["chained_tampered_previous_code_keeps_maintenance_and_blocks_retry"] = False
        self.assertNotEqual(self.run_gate().returncode, 0)
        del self.proof["chained_tampered_previous_code_keeps_maintenance_and_blocks_retry"]
        self.assertNotEqual(self.run_gate().returncode, 0)

    def test_fixture_cannot_be_promoted_to_host_or_publisher_acceptance(self):
        for key in (
            "real_https_host_verified", "independent_foreign_writer_isolation",
            "publisher_signature_verified_for_production", "production_ready",
        ):
            with self.subTest(key=key):
                self.proof[key] = True
                self.assertNotEqual(self.run_gate().returncode, 0)
                self.proof[key] = False
