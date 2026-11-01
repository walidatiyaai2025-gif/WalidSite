"""Unit coverage for explicit, fail-closed abandoned duplicate branch cleanup."""
import unittest
from urllib.error import HTTPError

from qa.cleanup_abandoned_duplicate_branches import (
    AbandonedDuplicate,
    DUPLICATES,
    reconcile_one,
)


REPO = "walidatiyaai2025-gif/WalidSite"
CANDIDATE = DUPLICATES[0]
MERGE_SHA = "a" * 40


class FakeGitHubApi:
    def __init__(self):
        self.abandoned = {
            "state": "closed",
            "merged": False,
            "head": {
                "ref": CANDIDATE.branch,
                "sha": CANDIDATE.sha,
                "repo": {"full_name": REPO},
            },
            "base": {"ref": "main"},
        }
        self.superseding = {
            "merged": True,
            "base": {"ref": "main"},
            "merge_commit_sha": MERGE_SHA,
        }
        self.comparison = {
            "status": "ahead",
            "merge_base_commit": {"sha": MERGE_SHA},
        }
        self.open_prs = []
        self.ref_values = [CANDIDATE.sha, CANDIDATE.sha]
        self.deleted = []
        self.requested = []

    def get(self, path):
        self.requested.append(path)
        if path.endswith("pulls/" + str(CANDIDATE.pr)):
            return self.abandoned
        if path.endswith("pulls/" + str(CANDIDATE.superseded_by)):
            return self.superseding
        if "/compare/" in path:
            return self.comparison
        if "/pulls?" in path:
            return self.open_prs
        if "/git/ref/heads/" in path:
            value = self.ref_values.pop(0)
            if value == "missing":
                raise HTTPError("https://api.github.com/", 404, "not found", {}, None)
            return {"object": {"sha": value}}
        raise AssertionError("Unexpected GET " + path)

    def delete(self, path):
        self.deleted.append(path)
        return None


class AbandonedBranchCleanupTest(unittest.TestCase):
    def setUp(self):
        self.api = FakeGitHubApi()

    def check_preserved(self):
        self.assertEqual(reconcile_one(CANDIDATE, self.api, REPO), "preserved")
        self.assertEqual(self.api.deleted, [])

    def test_exact_duplicate_with_verified_merged_successor_is_removed(self):
        self.assertEqual(reconcile_one(CANDIDATE, self.api, REPO), "deleted")
        self.assertEqual(
            self.api.deleted,
            ["repos/" + REPO + "/git/refs/heads/" + CANDIDATE.branch],
        )
        self.assertEqual(
            len([p for p in self.api.requested if "/git/ref/heads/" in p]), 2,
        )

    def test_never_deletes_unknown_or_protected_branch(self):
        unknown = AbandonedDuplicate(
            999, "fix/someone-else-branch", "f" * 40, 120,
        )
        self.assertEqual(reconcile_one(unknown, self.api, REPO), "preserved")
        self.assertEqual(self.api.requested, [])

    def test_closed_unmerged_provenance_is_mandatory(self):
        for field, value in (("merged", True), ("state", "open")):
            with self.subTest(field=field):
                api = FakeGitHubApi()
                api.abandoned[field] = value
                self.assertEqual(reconcile_one(CANDIDATE, api, REPO), "preserved")
                self.assertEqual(api.deleted, [])

    def test_exact_original_sha_and_same_repository_are_mandatory(self):
        for change in ("sha", "repo", "base", "ref"):
            with self.subTest(change=change):
                api = FakeGitHubApi()
                if change == "sha":
                    api.abandoned["head"]["sha"] = "f" * 40
                elif change == "repo":
                    api.abandoned["head"]["repo"]["full_name"] = "other/project"
                elif change == "base":
                    api.abandoned["base"]["ref"] = "feature"
                else:
                    api.abandoned["head"]["ref"] = "fix/other"
                self.assertEqual(reconcile_one(CANDIDATE, api, REPO), "preserved")
                self.assertEqual(api.deleted, [])

    def test_successor_must_be_merged_on_main_and_reachable(self):
        for change in ("merged", "base", "unreachable", "diverged"):
            with self.subTest(change=change):
                api = FakeGitHubApi()
                if change == "merged":
                    api.superseding["merged"] = False
                if change == "base":
                    api.superseding["base"]["ref"] = "feature"
                if change == "unreachable":
                    api.comparison["merge_base_commit"]["sha"] = "b" * 40
                if change == "diverged":
                    api.comparison["status"] = "diverged"
                self.assertEqual(reconcile_one(CANDIDATE, api, REPO), "preserved")
                self.assertEqual(api.deleted, [])

    def test_active_pr_blocks_deletion(self):
        self.api.open_prs = [{"number": 777}]
        self.check_preserved()

    def test_advanced_branch_is_never_deleted(self):
        self.api.ref_values = [CANDIDATE.sha, "f" * 40]
        self.check_preserved()

    def test_advanced_before_first_read_is_never_deleted(self):
        self.api.ref_values = ["f" * 40]
        self.check_preserved()

    def test_already_missing_branch_is_idempotent(self):
        self.api.ref_values = ["missing"]
        self.assertEqual(reconcile_one(CANDIDATE, self.api, REPO), "already_absent")
        self.assertEqual(self.api.deleted, [])

    def test_no_deletion_after_second_ref_disappears(self):
        self.api.ref_values = [CANDIDATE.sha, "missing"]
        self.assertEqual(reconcile_one(CANDIDATE, self.api, REPO), "already_absent")
        self.assertEqual(self.api.deleted, [])

    def test_malformed_repository_never_causes_any_request(self):
        self.assertEqual(
            reconcile_one(CANDIDATE, self.api, "owner/other/repo"), "preserved"
        )
        self.assertEqual(self.api.requested, [])


if __name__ == "__main__":
    unittest.main()
