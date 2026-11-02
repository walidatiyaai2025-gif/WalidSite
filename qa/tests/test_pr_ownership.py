"""Deterministic admission-policy tests: no network or GitHub token required."""
import unittest
from qa.validate_pr_ownership import compare_proves_ancestor, inspect, issue_from_branch, issue_from_title

MAP = {"T007": 8, "T024": 25}
REPO = "walidatiyaai2025-gif/WalidSite"
SHA = "abc123"


def pr(number, branch, title, draft=False, sha=SHA):
    return {
        "number": number, "title": title, "draft": draft,
        "head": {"ref": branch, "sha": sha, "repo": {"full_name": REPO}},
        "base": {"ref": "main"},
    }


class AdmissionTests(unittest.TestCase):
    def run_gate(self, target, peers=None, branches=None, files=None, state="open", expected=SHA,
                 reconciled=None):
        return inspect(target, peers or [target], branches or [{"name": target["head"]["ref"]}],
                       state, files or {}, MAP, REPO, expected,
                       reconciled_branches=reconciled)

    def test_valid_exclusive_pr(self):
        candidate = pr(10, "fix/507-guard", "fix(#507): protect branches")
        self.assertEqual(self.run_gate(candidate), ([], []))

    def test_task_id_maps_to_real_issue(self):
        self.assertEqual(issue_from_branch("feat/T007-runtime-parity-final", MAP), 8)
        self.assertEqual(issue_from_title("feat(T007): screen parity", MAP), {8})
        candidate = pr(10, "feat/T007-runtime-parity-final", "feat(T007): screen parity")
        self.assertEqual(self.run_gate(candidate), ([], []))

    def test_duplicate_open_issue_pr_fails(self):
        candidate = pr(10, "feat/480-privacy-b", "feat(#480): privacy b")
        other = pr(11, "feat/480-privacy-a", "feat(#480): privacy a")
        errors, _ = self.run_gate(candidate, [candidate, other])
        self.assertTrue(any("Duplicate active PR" in e for e in errors))

    def test_competing_unmerged_branch_fails_even_without_pr(self):
        candidate = pr(10, "feat/480-privacy-b", "feat(#480): privacy b")
        errors, _ = self.run_gate(candidate, branches=[
            {"name": "feat/480-privacy-b"}, {"name": "feat/480-privacy-a"}])
        self.assertTrue(any("Competing branch" in e for e in errors))

    def test_reconciled_ancestor_branch_warns_but_does_not_block(self):
        candidate = pr(10, "feat/480-privacy-b", "feat(#480): privacy b")
        errors, warnings = self.run_gate(candidate, branches=[
            {"name": "feat/480-privacy-b"}, {"name": "feat/480-privacy-a"}],
            reconciled={"feat/480-privacy-a"})
        self.assertEqual(errors, [])
        self.assertTrue(any("RECONCILED ANCESTOR" in warning for warning in warnings))

    def test_compare_payload_only_accepts_proven_ancestor(self):
        self.assertTrue(compare_proves_ancestor({"status": "ahead", "behind_by": 0}))
        self.assertTrue(compare_proves_ancestor({"status": "identical", "behind_by": 0}))
        self.assertFalse(compare_proves_ancestor({"status": "diverged", "behind_by": 1}))
        self.assertFalse(compare_proves_ancestor({"status": "ahead", "behind_by": 2}))
        self.assertFalse(compare_proves_ancestor({}))

    def test_closed_issue_and_mismatch_fail(self):
        candidate = pr(10, "feat/507-guard", "fix(#506): not this")
        errors, _ = self.run_gate(candidate, state="closed")
        self.assertEqual(2, len(errors))

    def test_ref_not_on_tested_head_fails(self):
        candidate = pr(10, "fix/507-guard", "fix(#507): governance", sha="newhead")
        errors, _ = self.run_gate(candidate)
        self.assertTrue(any("head moved" in e for e in errors))

    def test_reject_noncanonical_branch_and_program_catch_all(self):
        malformed = pr(10, "feat/privacy-changes", "feat(#507): privacy")
        errors, _ = self.run_gate(malformed)
        self.assertTrue(any("Branch must" in e for e in errors))
        program = pr(10, "chore/1-governance", "chore(#1): governance")
        errors, _ = self.run_gate(program)
        self.assertTrue(any("catch-all" in e for e in errors))

    def test_concurrent_ready_file_collision_fails(self):
        candidate = pr(10, "feat/507-new", "feat(#507): update policy")
        other = pr(11, "feat/508-other", "feat(#508): update workflow")
        errors, _ = self.run_gate(candidate, [candidate, other], files={
            10: ["qa/shared.py"], 11: ["qa/shared.py"]})
        self.assertTrue(any("file ownership collision" in e for e in errors))

    def test_draft_overlap_warns_and_is_not_silent(self):
        candidate = pr(10, "feat/507-new", "feat(#507): update policy")
        other = pr(11, "feat/508-other", "feat(#508): draft", draft=True)
        errors, warnings = self.run_gate(candidate, [candidate, other], files={
            10: ["qa/shared.py"], 11: ["qa/shared.py"]})
        self.assertEqual(errors, [])
        self.assertTrue(any("DRAFT COLLISION" in warning for warning in warnings))

    def test_unrelated_legacy_duplicates_do_not_block(self):
        candidate = pr(10, "fix/507-guard", "fix(#507): governance")
        peers = [candidate, pr(11, "feat/480-a", "feat(#480): a"),
                 pr(12, "feat/480-b", "feat(#480): b")]
        self.assertEqual(self.run_gate(candidate, peers=peers), ([], []))


if __name__ == "__main__":
    unittest.main()
