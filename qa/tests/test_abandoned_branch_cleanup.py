"""Unit coverage for explicit, fail-closed abandoned duplicate branch cleanup."""
import unittest
from urllib.error import HTTPError

from qa.cleanup_abandoned_duplicate_branches import (
    AbandonedDuplicate,
    DUPLICATES,
    reconcile_one,
    ORPHANED_480,
    Orphaned480Branch,
    reconcile_orphan_480,
)


REPO = "walidatiyaai2025-gif/WalidSite"
CANDIDATE = DUPLICATES[0]
MERGE_SHA = "a" * 40


class FakeGitHubApi:
    def __init__(self, candidate=CANDIDATE):
        self.candidate = candidate
        self.abandoned = {
            "state": "closed",
            "merged": False,
            "head": {
                "ref": candidate.branch,
                "sha": candidate.sha,
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
        self.ref_values = [candidate.sha, candidate.sha]
        self.deleted = []
        self.requested = []

    def get(self, path):
        self.requested.append(path)
        if path.endswith("pulls/" + str(self.candidate.pr)):
            return self.abandoned
        if path.endswith("pulls/" + str(self.candidate.superseded_by)):
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

    def test_all_verified_branches_delete_only_when_unchanged(self):
        expected = {
            150: ("chore/145-guarded-abandoned-cleanup", 149),
            156: ("fix/setup-production-https-all-steps", 159),
            170: ("fix/168-setup-schema-mismatch-recovery-guard", 171),
            172: ("fix/169-schema-mismatch-recovery-guard", 171),
            337: ("fix/332-mobile-learning-unique-page-slugs", 336),
            # Closed-unmerged alternate PR #525 superseded by merged #523.
            525: ("fix/521-serialize-mobile-bookmark-quota", 523),
            # Accepted merged feature PR #506 supersedes closed PR #502.
            502: ("feat/480-account-privacy-journey-completion", 506),
        }
        for pr, (branch, successor) in expected.items():
            with self.subTest(pr=pr):
                candidate = next(x for x in DUPLICATES if x.pr == pr)
                self.assertEqual(candidate.branch, branch)
                self.assertEqual(candidate.superseded_by, successor)
                api = FakeGitHubApi(candidate)
                self.assertEqual(reconcile_one(candidate, api, REPO), "deleted")
                self.assertEqual(api.deleted, [
                    "repos/" + REPO + "/git/refs/heads/" + branch,
                ])

                # Even an allowlisted candidate is preserved when the source
                # changed, became active, or its accepted successor vanished.
                advanced = FakeGitHubApi(candidate)
                advanced.ref_values = [candidate.sha, "f" * 40]
                self.assertEqual(reconcile_one(candidate, advanced, REPO), "preserved")
                self.assertEqual(advanced.deleted, [])

                active = FakeGitHubApi(candidate)
                active.open_prs = [{"number": 999}]
                self.assertEqual(reconcile_one(candidate, active, REPO), "preserved")
                self.assertEqual(active.deleted, [])

                unmerged = FakeGitHubApi(candidate)
                unmerged.superseding["merged"] = False
                self.assertEqual(reconcile_one(candidate, unmerged, REPO), "preserved")
                self.assertEqual(unmerged.deleted, [])

    def test_never_deletes_unknown_or_protected_branch(self):
        unknown = AbandonedDuplicate(
            999, "fix/someone-else-branch", "f" * 40, 120,
        )
        self.assertEqual(reconcile_one(unknown, self.api, REPO), "preserved")
        self.assertEqual(self.api.requested, [])

        unknown_feature = AbandonedDuplicate(
            998, "feat/someone-else-active-work", "e" * 40, 506,
        )
        self.assertEqual(reconcile_one(unknown_feature, self.api, REPO), "preserved")
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




MAIN_SHA = "d" * 40


class FakeOrphanGitHubApi:
    def __init__(self, candidate):
        self.candidate = candidate
        self.main_refs = [MAIN_SHA, MAIN_SHA]
        self.orphan_refs = [candidate.sha, candidate.sha]
        self.successor = {
            "merged": True, "base": {"ref": "main"},
            "merge_commit_sha": MERGE_SHA,
        }
        self.merged_compare = {
            "merge_base_commit": {"sha": MERGE_SHA}, "status": "ahead",
        }
        self.history = {
            "merge_base_commit": {"sha": candidate.merge_base},
            "ahead_by": candidate.ahead, "status": "diverged",
        }
        self.witnesses = dict(candidate.main_witnesses)
        self.prs = []
        self.deleted = []
        self.reads = []

    def get(self, path):
        self.reads.append(path)
        if path.endswith("/git/ref/heads/main"):
            sha = self.main_refs.pop(0)
            return {"object": {"sha": sha}}
        if path.endswith("/pulls/" + str(self.candidate.superseded_by)):
            return self.successor
        if "/compare/" in path and "..." + MAIN_SHA in path:
            return self.merged_compare
        if "/compare/" in path and "..." + self.candidate.sha in path:
            return self.history
        if "/contents/" in path:
            filename = path.split("/contents/", 1)[1].split("?ref=", 1)[0]
            return {"sha": self.witnesses[filename]}
        if "/git/ref/heads/" in path:
            sha = self.orphan_refs.pop(0)
            if sha == "missing":
                raise HTTPError("https://api.github.com/", 404, "not found", {}, None)
            return {"object": {"sha": sha}}
        if "/pulls?" in path:
            return self.prs
        raise AssertionError("Unexpected GET: " + path)

    def delete(self, path):
        self.deleted.append(path)


class Orphan480BranchCleanupTest(unittest.TestCase):
    def setUp(self):
        self.candidate = ORPHANED_480[0]
        self.api = FakeOrphanGitHubApi(self.candidate)

    def run_candidate(self, candidate=None, api=None, main_sha=MAIN_SHA, repo=REPO):
        candidate = candidate or self.candidate
        api = api or self.api
        return reconcile_orphan_480(candidate, api, repo, main_sha)

    def test_every_sha_pinned_orphan_is_deleted_only_after_exact_checks(self):
        self.assertEqual(len(ORPHANED_480), 3)
        expected = {
            "fix/480-admin-audience-consent-time-parity": (2, 506),
            "feat/480-privacy-rights-requests": (5, 506),
            "feat/480-privacy-data-request-journey": (6, 506),
        }
        for candidate in ORPHANED_480:
            with self.subTest(branch=candidate.branch):
                self.assertEqual((candidate.ahead, candidate.superseded_by), expected[candidate.branch])
                api = FakeOrphanGitHubApi(candidate)
                self.assertEqual(self.run_candidate(candidate, api), "deleted")
                self.assertEqual(api.deleted, [
                    "repos/" + REPO + "/git/refs/heads/" + candidate.branch,
                ])
                self.assertEqual(sum("git/ref/heads/main" in x for x in api.reads), 2)
                self.assertEqual(sum("git/ref/heads/" + candidate.branch in x for x in api.reads), 2)
                self.assertEqual(sum("/contents/" in x for x in api.reads), len(candidate.main_witnesses))
                self.assertIn("state=all", next(x for x in api.reads if "/pulls?" in x))

    def test_unknown_or_malformed_candidates_do_not_touch_api(self):
        unknown = Orphaned480Branch(
            "feat/480-other", "f" * 40, self.candidate.merge_base, 3, 506,
            self.candidate.main_witnesses,
        )
        self.assertEqual(self.run_candidate(unknown), "preserved")
        self.assertEqual(self.run_candidate(main_sha="bad"), "preserved")
        self.assertEqual(self.run_candidate(repo="other/WalidSite"), "preserved")
        self.assertEqual(self.api.reads, [])
        self.assertEqual(self.api.deleted, [])

    def test_main_movement_fails_closed_before_or_immediately_before_deletion(self):
        for refs in ([("e" * 40), MAIN_SHA], [MAIN_SHA, "e" * 40]):
            with self.subTest(refs=refs):
                api = FakeOrphanGitHubApi(self.candidate)
                api.main_refs = list(refs)
                self.assertEqual(self.run_candidate(api=api), "preserved")
                self.assertEqual(api.deleted, [])

    def test_orphan_ref_advanced_or_missing(self):
        for refs, result in (
            (["e" * 40, self.candidate.sha], "preserved"),
            ([self.candidate.sha, "e" * 40], "preserved"),
            (["missing"], "already_absent"),
            ([self.candidate.sha, "missing"], "already_absent"),
        ):
            with self.subTest(refs=refs):
                api = FakeOrphanGitHubApi(self.candidate)
                api.orphan_refs = list(refs)
                self.assertEqual(self.run_candidate(api=api), result)
                self.assertEqual(api.deleted, [])

    def test_open_draft_or_historical_pr_blocks_orphan_deletion(self):
        for state, draft in (("open", False), ("open", True), ("closed", False)):
            with self.subTest(state=state, draft=draft):
                api = FakeOrphanGitHubApi(self.candidate)
                api.prs = [{"number": 999, "state": state, "draft": draft}]
                self.assertEqual(self.run_candidate(api=api), "preserved")
                self.assertEqual(api.deleted, [])

    def test_missing_or_not_reachable_successor_fails_closed(self):
        for case in ("unmerged", "wrong_base", "unreachable", "diverged"):
            with self.subTest(case=case):
                api = FakeOrphanGitHubApi(self.candidate)
                if case == "unmerged":
                    api.successor["merged"] = False
                elif case == "wrong_base":
                    api.successor["base"]["ref"] = "feature"
                elif case == "unreachable":
                    api.merged_compare["merge_base_commit"]["sha"] = "b" * 40
                else:
                    api.merged_compare["status"] = "diverged"
                self.assertEqual(self.run_candidate(api=api), "preserved")
                self.assertEqual(api.deleted, [])

    def test_branch_provenance_mutations_fail_closed(self):
        for case in ("wrong_base", "wrong_ahead", "wrong_status"):
            with self.subTest(case=case):
                api = FakeOrphanGitHubApi(self.candidate)
                if case == "wrong_base":
                    api.history["merge_base_commit"]["sha"] = "b" * 40
                elif case == "wrong_ahead":
                    api.history["ahead_by"] += 1
                else:
                    api.history["status"] = "ahead"
                self.assertEqual(self.run_candidate(api=api), "preserved")
                self.assertEqual(api.deleted, [])

    def test_any_main_code_or_test_witness_change_preserves_branch(self):
        for path, _ in self.candidate.main_witnesses:
            with self.subTest(path=path):
                api = FakeOrphanGitHubApi(self.candidate)
                api.witnesses[path] = "f" * 40
                self.assertEqual(self.run_candidate(api=api), "preserved")
                self.assertEqual(api.deleted, [])

if __name__ == "__main__":
    unittest.main()
