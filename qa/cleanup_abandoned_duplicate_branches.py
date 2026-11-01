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
        or not candidate.branch.startswith(("fix/", "chore/"))
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


def main() -> int:
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    api = GitHubApi(os.environ.get("GH_TOKEN", ""))
    failures = 0
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
