"""Real Git provenance regressions for the visual capture admission boundary.

This tests the checkout, not UI pixel equivalence. No synthetic screenshot is
treated as evidence of any of the 40 actual application screen references.
"""
import subprocess
import tempfile
import unittest
from pathlib import Path

from qa.capture_visual_evidence import verified_source_checkout
from qa.capture_visual_batch import verified_head


class CaptureCheckoutProvenanceTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        for folder in ("qa", "design", "contracts", "qa/visual-captures"):
            (self.root / folder).mkdir(parents=True, exist_ok=True)
        self.source = self.root / "qa/render_web_screenshot.py"
        self.source.write_text("print('real tracked renderer')\n", encoding="utf-8")
        self.ledger = self.root / "design/visual-fidelity-evidence.json"
        self.ledger.write_text('{"screens": {}}\n', encoding="utf-8")
        (self.root / "contracts/screens.json").write_text(
            '{"screens": []}\n', encoding="utf-8"
        )
        self.git("init", "-q")
        self.git("add", ".")
        self.git("-c", "user.email=qa@example.test",
                 "-c", "user.name=Visual QA", "commit", "-qm",
                 "tracked source and pending evidence")
        self.sha = self.git("rev-parse", "HEAD").strip()

    def git(self, *args):
        return subprocess.run(
            ["git", *args], cwd=self.root, check=True,
            capture_output=True, text=True, timeout=10
        ).stdout

    def test_exact_head_and_clean_source_are_required_for_both_entrypoints(self):
        self.assertTrue(verified_source_checkout(self.root, self.sha))
        self.assertTrue(verified_head(self.root, self.sha))
        self.assertFalse(verified_source_checkout(self.root, "0" * 40))
        self.assertFalse(verified_head(self.root, "not-a-sha"))

    def test_modified_staged_and_untracked_source_are_never_attested(self):
        self.source.write_text("print('tampered renderer')\n", encoding="utf-8")
        self.assertFalse(verified_source_checkout(self.root, self.sha))
        self.git("add", "qa/render_web_screenshot.py")
        self.assertFalse(verified_source_checkout(self.root, self.sha))
        self.git("reset", "--hard", "HEAD")
        self.assertTrue(verified_source_checkout(self.root, self.sha))
        extra = self.root / "qa/uncommitted_runtime_renderer.py"
        extra.write_text("print('not part of HEAD')\n", encoding="utf-8")
        self.assertFalse(verified_source_checkout(self.root, self.sha))
        extra.unlink()
        self.assertTrue(verified_source_checkout(self.root, self.sha))

    def test_reference_contract_and_noncanonical_capture_are_not_allowlisted(self):
        contract = self.root / "contracts/screens.json"
        contract.write_text('{"screens": [{"id": "fake"}]}\n', encoding="utf-8")
        self.assertFalse(verified_source_checkout(self.root, self.sha))
        self.git("checkout", "--", "contracts/screens.json")
        path = self.root / "qa/visual-captures/OTHER-01.png"
        path.write_bytes(b"not a canonical runtime screenshot")
        self.assertFalse(verified_source_checkout(self.root, self.sha))
        path.unlink()

    def test_resumable_only_allows_ledger_and_named_canonical_capture_pngs(self):
        self.ledger.write_text('{"screens": {"WEB-01": "matched"}}\n', encoding="utf-8")
        web = self.root / "qa/visual-captures/WEB-01.png"
        admin = self.root / "qa/visual-captures/ADMIN-13.png"
        mobile = self.root / "qa/visual-captures/MOBILE-09.png"
        for path in (web, admin, mobile):
            path.write_bytes(b"synthetic status placeholder -- never accepted as pixel proof")
        self.assertTrue(verified_source_checkout(self.root, self.sha))
        self.git("add", "design/visual-fidelity-evidence.json")
        self.assertTrue(verified_source_checkout(self.root, self.sha))
        (self.root / "qa/visual-captures/WEB-15.png").write_bytes(b"invalid ID")
        self.assertFalse(verified_source_checkout(self.root, self.sha))

    def test_subtree_changes_are_detected_even_if_head_sha_remains_unchanged(self):
        self.git("config", "user.email", "qa@example.test")
        self.git("config", "user.name", "Visual QA")
        self.source.write_text("print('committed updated source')\n", encoding="utf-8")
        self.git("add", "qa/render_web_screenshot.py")
        self.git("commit", "-qm", "new source commit")
        new_sha = self.git("rev-parse", "HEAD").strip()
        self.assertNotEqual(self.sha, new_sha)
        self.assertFalse(verified_source_checkout(self.root, self.sha))
        self.assertTrue(verified_source_checkout(self.root, new_sha))


if __name__ == "__main__":
    unittest.main()
