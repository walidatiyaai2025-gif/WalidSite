#!/usr/bin/env python3
"""Safely prune only explicitly reconciled, abandoned duplicate PR branches.

This is intentionally NOT a general stale-branch sweeper. An unmerged PR may
contain unique work, so every candidate needs an exact pinned head and a
specific later PR already merged into main. GitHub remains the only state source.
"""
from __future__ import annotations

from dataclasses import dataclass
import json
import os
import re
import sys
from urllib.error import HTTPError
from urllib.parse import quote
from urllib.request import Request, urlopen


@dataclass(frozen=True)
class AbandonedDuplicate:
    pr: int
    branch: str
    sha: str
    superseded_by: int


# Provenance independently established from the live GitHub PRs and refs on
# 2026-11-01. Never infer these records from matching issue title alone.
DUPLICATES = (
    AbandonedDuplicate(117, "fix/T007-local-owner-portrait", "c4cbab5dbbc5ccaf51b3c7e38e6459584299056d", 120),
    AbandonedDuplicate(118, "fix/T007-local-portrait-delivery", "031c9f745033ad33d7e39849816d243e5f3f8be1", 120),
    AbandonedDuplicate(122, "fix/T007-local-approved-portrait", "e3bff5cb5d5b48a0eb7f732d3182d00a9f7c633a", 120),
    AbandonedDuplicate(131, "fix/T010-editorial-approval-integrity", "97bd3cd8a79a0fcb2f14bc0ed56faf57c977246a", 134),
    AbandonedDuplicate(133, "fix/T010-unsaved-publication-metadata", "29af40455f9358764bc790870f438f3f51ccb9da", 134),
    AbandonedDuplicate(141, "fix/139-nested-encoded-citation-secrets", "6ac98f8e21ef99949cfd999e1eb906771b4e42db", 142),
    # Verified closed-unmerged PRs with newer accepted replacements on main.
    AbandonedDuplicate(150, "chore/145-guarded-abandoned-cleanup", "64974e45e859a2fe55615007c871878ba51a0cba", 149),
    AbandonedDuplicate(156, "fix/setup-production-https-all-steps", "5f8077063851664212865ca8771f1605f8963bec", 159),
    AbandonedDuplicate(170, "fix/168-setup-schema-mismatch-recovery-guard", "c7a19ce12cdf81e3f00b00ae42688f2b2dcf3f9a", 171),
    AbandonedDuplicate(172, "fix/169-schema-mismatch-recovery-guard", "a267bf9bf2d7f459ca16e0d8a87098292d37c717", 171),
    AbandonedDuplicate(337, "fix/332-mobile-learning-unique-page-slugs", "a23acce511542be2977082de530783a0201bcf97", 336),
    # PR #525 was closed unmerged; #523 delivered the same serialized 200-item
    # quota with stronger separate isolation/inactive-account regressions.
    AbandonedDuplicate(525, "fix/521-serialize-mobile-bookmark-quota", "098659b1ebb390bdbb5652bb23c85c25392557f3", 523),
    # Closed-unmerged privacy PR #502 was superseded by accepted bilingual
    # account-controls and contact-rights journey in merged PR #506.
    AbandonedDuplicate(502, "feat/480-account-privacy-journey-completion", "4eb0bf6d1f8399931d4e4f93efc15db5bb0fc836", 506),
)


class GitHubApi:
    """Minimal scoped REST client; no secrets or response bodies in errors."""

    def __init__(self, token: str):
        if not token:
            raise ValueError("Missing GITHUB_TOKEN")
        self.token = token

    def request(self, method: str, path: str):
        req = Request(
            "https://api.github.com/" + path.lstrip("/"),
            headers={
                "Authorization": "Bearer " + self.token,
                "Accept": "application/vnd.github+json",
                "X-GitHub-Api-Version": "2022-11-28",
            },
            method=method,
        )
        with urlopen(req, timeout=20) as response:
            data = response.read()
        return json.loads(data) if data else None

    def get(self, path: str):
        return self.request("GET", path)

    def delete(self, path: str):
        return self.request("DELETE", path)


def _sha(value: object) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{40}", value) is not None


def reconcile_one(candidate: AbandonedDuplicate, api, repo: str) -> str:
    """Return deleted/already_absent/preserved; on API faults fail closed."""
    if (
        candidate not in DUPLICATES
        or not _sha(candidate.sha)
        or not candidate.branch.startswith(("feat/", "fix/", "chore/"))
        or not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo)
    ):
        return "preserved"

    prefix = "repos/" + repo + "/"
    abandoned = api.get(prefix + "pulls/" + str(candidate.pr))
    superseding = api.get(prefix + "pulls/" + str(candidate.superseded_by))
    if not isinstance(abandoned, dict) or not isinstance(superseding, dict):
        return "preserved"
    head = abandoned.get("head") or {}
    base = abandoned.get("base") or {}
    if (
        abandoned.get("state") != "closed"
        or abandoned.get("merged") is not False
        or head.get("ref") != candidate.branch
        or head.get("sha") != candidate.sha
        or (head.get("repo") or {}).get("full_name") != repo
        or base.get("ref") != "main"
    ):
        return "preserved"

    sup_base = superseding.get("base") or {}
    merge_sha = superseding.get("merge_commit_sha")
    if (
        superseding.get("merged") is not True
        or sup_base.get("ref") != "main"
        or not _sha(merge_sha)
    ):
        return "preserved"
    comparison = api.get(prefix + "compare/" + merge_sha + "...main")
    if (
        not isinstance(comparison, dict)
        or (comparison.get("merge_base_commit") or {}).get("sha") != merge_sha
        or comparison.get("status") not in ("identical", "ahead")
    ):
        return "preserved"

    ref_path = prefix + "git/ref/heads/" + candidate.branch
    try:
        current = api.get(ref_path)
    except HTTPError as error:
        if error.code == 404:
            return "already_absent"
        raise
    if (current.get("object") or {}).get("sha") != candidate.sha:
        return "preserved"

    # A closed historical PR is not evidence no *new* work is using the branch.
    owner = repo.split("/")[0]
    open_path = prefix + "pulls?state=open&per_page=100&head=" + quote(
        owner + ":" + candidate.branch, safe=""
    )
    if api.get(open_path) != []:
        return "preserved"

    # Re-read immediately before deleting. Never force-update a changed ref.
    try:
        latest = api.get(ref_path)
    except HTTPError as error:
        if error.code == 404:
            return "already_absent"
        raise
    if (latest.get("object") or {}).get("sha") != candidate.sha:
        return "preserved"

    api.delete(prefix + "git/refs/heads/" + candidate.branch)
    return "deleted"



@dataclass(frozen=True)
class Orphaned480Branch:
    """An independently audited orphan without a historical source PR."""

    branch: str
    sha: str
    merge_base: str
    ahead: int
    superseded_by: int
    # SHA-1 blob witnesses for every W2 file originally touched by this branch.
    # Any later edit to a witness deliberately requires a fresh human audit.
    main_witnesses: tuple[tuple[str, str], ...]


# Three orphan heads audited against main on 2026-11-03. Their old commits
# carry no surviving behavior absent from main. See Issue #718 for evidence.
# Keep this narrower than the legacy abandoned-PR allowlist above.
ORPHANED_480 = (
    Orphaned480Branch(
        "fix/480-admin-audience-consent-time-parity",
        "f3263c14b84cff7e9331cd2acab5a220467ccf4b",
        "e9751b0eaf0bbe4cb36568f9f7ec178ccf09b7cd", 2, 506,
        (
            ("backend/routes/web.php", "8ec5c9130aefba22164b273b5271a04bd7870873"),
            ("backend/tests/Feature/Web/AdminOperationsJourneyTest.php", "47431ec2413d6d00a8f9cfc96d154e6bf5ba4d98"),
        ),
    ),
    Orphaned480Branch(
        "feat/480-privacy-rights-requests",
        "3ac76582673a46779dc614801d2eb927534aab25",
        "120c31d7e0db630311d52866db52e9d624330951", 5, 506,
        (
            ("backend/routes/web.php", "8ec5c9130aefba22164b273b5271a04bd7870873"),
            ("backend/resources/views/layouts/site.blade.php", "f7bb04c019d2faa0824952ee9cc853e5bfa94e0e"),
            ("backend/resources/views/public/contact.blade.php", "844cd96dd42f4be653053a1c4552b13492014c1e"),
            ("backend/resources/views/public/privacy.blade.php", "f29900d670bf2a2fbf730ffb07892dd725854d86"),
            ("backend/tests/Feature/Web/PrivacyRightsRequestJourneyTest.php", "4bb517c8ffec54391832379d569299f75d7374ef"),
        ),
    ),
    Orphaned480Branch(
        "feat/480-privacy-data-request-journey",
        "0a59fea2eec883543029ca38743228205f950012",
        "120c31d7e0db630311d52866db52e9d624330951", 6, 506,
        (
            ("backend/routes/web.php", "8ec5c9130aefba22164b273b5271a04bd7870873"),
            ("backend/resources/views/layouts/site.blade.php", "f7bb04c019d2faa0824952ee9cc853e5bfa94e0e"),
            ("backend/resources/views/public/contact.blade.php", "844cd96dd42f4be653053a1c4552b13492014c1e"),
            ("backend/resources/views/public/privacy.blade.php", "f29900d670bf2a2fbf730ffb07892dd725854d86"),
            ("backend/resources/views/public/terms.blade.php", "cebc35745d404c07cc6bcce848c2479f988d7824"),
            ("backend/tests/Feature/Web/PrivacyDataRequestJourneyTest.php", "7275d46282eee17340ae8fcde20417d66b4296f3"),
        ),
    ),
)


def reconcile_orphan_480(candidate: Orphaned480Branch, api, repo: str, tested_main_sha: str) -> str:
    """Fail-closed one-time reconciliation, callable only after green main CI."""
    if (
        candidate not in ORPHANED_480
        or not re.fullmatch(r"walidatiyaai2025-gif/WalidSite", repo)
        or not candidate.branch.startswith(("feat/480-", "fix/480-"))
        or not all(_sha(sha) for sha in (candidate.sha, candidate.merge_base, tested_main_sha))
        or not candidate.main_witnesses
        or any(not _sha(sha) for _, sha in candidate.main_witnesses)
    ):
        return "preserved"

    prefix = "repos/" + repo + "/"
    ref_path = prefix + "git/ref/heads/" + candidate.branch
    try:
        current = api.get(ref_path)
    except HTTPError as error:
        if error.code == 404:
            return "already_absent"
        raise
    if (current.get("object") or {}).get("sha") != candidate.sha:
        return "preserved"

    main_ref_path = prefix + "git/ref/heads/main"
    main_ref = api.get(main_ref_path)
    if (main_ref.get("object") or {}).get("sha") != tested_main_sha:
        return "preserved"

    successor = api.get(prefix + "pulls/" + str(candidate.superseded_by))
    if (
        not isinstance(successor, dict)
        or successor.get("merged") is not True
        or (successor.get("base") or {}).get("ref") != "main"
        or not _sha(successor.get("merge_commit_sha"))
    ):
        return "preserved"
    merge_sha = successor["merge_commit_sha"]
    merged_compare = api.get(prefix + "compare/" + merge_sha + "..." + tested_main_sha)
    if (
        not isinstance(merged_compare, dict)
        or (merged_compare.get("merge_base_commit") or {}).get("sha") != merge_sha
        or merged_compare.get("status") not in ("identical", "ahead")
    ):
        return "preserved"

    # The reviewed branch history and exact accepted main code/test blobs must
    # still match. A later rewrite (even if it looks equivalent) blocks deletion.
    history = api.get(prefix + "compare/" + tested_main_sha + "..." + candidate.sha)
    if (
        not isinstance(history, dict)
        or (history.get("merge_base_commit") or {}).get("sha") != candidate.merge_base
        or history.get("ahead_by") != candidate.ahead
        or history.get("status") != "diverged"
    ):
        return "preserved"
    for path, witness_sha in candidate.main_witnesses:
        current_file = api.get(prefix + "contents/" + path + "?ref=" + tested_main_sha)
        if not isinstance(current_file, dict) or current_file.get("sha") != witness_sha:
            return "preserved"

    owner = repo.split("/")[0]
    prs_path = prefix + "pulls?state=all&per_page=100&head=" + quote(
        owner + ":" + candidate.branch, safe=""
    )
    # These are explicitly *orphan* branches; unexpected historic or open PR
    # provenance needs a new audit. Draft PRs are included in state=all.
    if api.get(prs_path) != []:
        return "preserved"

    # Two reads of BOTH main and the orphan ref immediately before DELETE.
    if (api.get(main_ref_path).get("object") or {}).get("sha") != tested_main_sha:
        return "preserved"
    try:
        latest = api.get(ref_path)
    except HTTPError as error:
        if error.code == 404:
            return "already_absent"
        raise
    if (latest.get("object") or {}).get("sha") != candidate.sha:
        return "preserved"

    api.delete(prefix + "git/refs/heads/" + candidate.branch)
    return "deleted"


def main() -> int:
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    api = GitHubApi(os.environ.get("GH_TOKEN", ""))
    failures = 0
    if os.environ.get("RECONCILE_480_ORPHANS") == "1":
        tested_main_sha = os.environ.get("GITHUB_SHA", "")
        for candidate in ORPHANED_480:
            try:
                outcome = reconcile_orphan_480(candidate, api, repo, tested_main_sha)
                print(f"Orphan {candidate.branch}: {outcome}")
                if outcome == "preserved":
                    failures += 1
            except Exception as error:
                print(f"Orphan {candidate.branch}: preserved (API error: {type(error).__name__})")
                failures += 1
        return 1 if failures else 0

    for candidate in DUPLICATES:
        try:
            outcome = reconcile_one(candidate, api, repo)
            print(f"PR #{candidate.pr} {candidate.branch}: {outcome}")
        except Exception as error:
            # Do not print bearer tokens, GitHub response bodies, URLs or
            # exception details; a failure never authorizes deletion.
            print(f"PR #{candidate.pr} {candidate.branch}: preserved (API error: {type(error).__name__})")
            failures += 1
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
