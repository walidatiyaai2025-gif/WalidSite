"""Regression tests: never delete a changed, active, or unverified branch."""
import unittest

from qa.cleanup_abandoned_branches import (
    Abandoned, matches_abandoned, matches_superseding, reconcile,
)


REPO = "walidatiyaai2025-gif/WalidSite"
ITEM = Abandoned(117, "fix/T007-local-owner-portrait", "c4cbab5dbbc5ccaf51b3c7e38e6459584299056d", (120,))


class FakeGitHub:
    def __init__(self):
        self.original = {
            "state": "closed", "merged": False, "merged_at": None,
            "head": {"ref": ITEM.branch, "sha": ITEM.sha, "repo": {"full_name": REPO}},
            "base": {"ref": "main", "repo": {"full_name": REPO}},
        }
        self.replacement = {
            "state": "closed", "merged": True, "merged_at": "2026-11-01T00:00:00Z",
            "base": {"ref": "main", "repo": {"full_name": REPO}},
        }
        self.live_sha = ITEM.sha
        self.ref_reads = 0
        self.move_on_second_read = False
        self.open_pr = False
        self.ref_error = False
        self.deleted = []

    def get(self, path):
        if path.endswith("/pulls/117"):
            return self.original
        if path.endswith("/pulls/120"):
            return self.replacement
        if "/git/ref/heads/" in path:
            self.ref_reads += 1
            if self.ref_error:
                raise RuntimeError("unreadable ref")
            if self.move_on_second_read and self.ref_reads == 2:
                return {"object": {"sha": "f" * 40}}
            return {"object": {"sha": self.live_sha}}
        raise AssertionError(f"Unexpected read: {path}")

    def has_open_pr(self, repo, branch):
        assert repo == REPO and branch == ITEM.branch
        return self.open_pr

    def delete(self, path):
        self.deleted.append(path)


class TestAbandonedBranchCleanup(unittest.TestCase):
    def run_cleanup(self, fake):
        return reconcile(REPO, fake, items=(ITEM,), audit=lambda _: None)

    def test_exact_abandoned_head_with_verified_replacement_is_deleted(self):
        fake = FakeGitHub()
        result = self.run_cleanup(fake)
        self.assertEqual(result, {"deleted": 1, "already_absent": 0, "preserved": 0, "errors": 0})
        self.assertEqual(fake.ref_reads, 2)
        self.assertEqual(fake.deleted, [
            f"repos/{REPO}/git/refs/heads/{ITEM.branch}"
        ])

    def test_open_or_merged_or_changed_original_pr_is_preserved(self):
        for field, value in (("state", "open"), ("merged", True), ("merged_at", "today")):
            with self.subTest(field=field):
                fake = FakeGitHub()
                fake.original[field] = value
                self.assertEqual(self.run_cleanup(fake)["preserved"], 1)
                self.assertEqual(fake.deleted, [])
        for area, field, value in (
            ("head", "sha", "f" * 40),
            ("head", "ref", "fix/unrelated"),
            ("head", "repo", {"full_name": "other/repo"}),
            ("base", "ref", "dev"),
            ("base", "repo", {"full_name": "other/repo"}),
        ):
            with self.subTest(area=area, field=field):
                fake = FakeGitHub()
                fake.original[area][field] = value
                self.assertEqual(self.run_cleanup(fake)["preserved"], 1)
                self.assertEqual(fake.deleted, [])

    def test_superseding_pr_must_really_merge_into_main(self):
        for area, field, value in (
            ("top", "merged", False),
            ("top", "state", "open"),
            ("top", "merged_at", None),
            ("base", "ref", "staging"),
            ("base", "repo", {"full_name": "other/repo"}),
        ):
            with self.subTest(field=field):
                fake = FakeGitHub()
                if area == "top":
                    fake.replacement[field] = value
                else:
                    fake.replacement[area][field] = value
                self.assertEqual(self.run_cleanup(fake)["preserved"], 1)
                self.assertFalse(fake.deleted)

    def test_advanced_ref_or_open_pr_or_race_preserves_branch(self):
        for change in ("moved", "open", "race", "unreadable"):
            with self.subTest(change=change):
                fake = FakeGitHub()
                if change == "moved":
                    fake.live_sha = "a" * 40
                elif change == "open":
                    fake.open_pr = True
                elif change == "race":
                    fake.move_on_second_read = True
                elif change == "unreadable":
                    fake.ref_error = True
                self.assertEqual(self.run_cleanup(fake)["preserved"], 1)
                self.assertEqual(fake.deleted, [])

    def test_invalid_repository_rejected_before_any_api_calls(self):
        with self.assertRaises(ValueError):
            reconcile("main", FakeGitHub(), (ITEM,))

    def test_identity_predicates_reject_missing_or_foreign_metadata(self):
        self.assertFalse(matches_abandoned({}, ITEM, REPO))
        self.assertFalse(matches_superseding({}, REPO))


if __name__ == "__main__":
    unittest.main()
