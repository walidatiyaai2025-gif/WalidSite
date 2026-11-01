#!/usr/bin/env python3
"""One-shot, fail-closed cleanup of independently superseded PR branches.

Only invoked by CI's *verified merged PR* cleanup job. This is a finite
allowlist, not a generic prune command. GitHub API must affirm provenance,
replacement merges, live ref identity, and zero open PRs before deletion.
"""
from __future__ import annotations

import json
import os
import subprocess
from dataclasses import dataclass
from urllib.parse import quote


@dataclass(frozen=True)
class Abandoned:
    pr: int
    branch: str
    sha: str
    superseded_by: tuple[int, ...]


ALLOWLIST = (
    Abandoned(117, "fix/T007-local-owner-portrait", "c4cbab5dbbc5ccaf51b3c7e38e6459584299056d", (120,)),
    Abandoned(118, "fix/T007-local-portrait-delivery", "031c9f745033ad33d7e39849816d243e5f3f8be1", (120,)),
    Abandoned(122, "fix/T007-local-approved-portrait", "e3bff5cb5d5b48a0eb7f732d3182d00a9f7c633a", (120,)),
    Abandoned(131, "fix/T010-editorial-approval-integrity", "97bd3cd8a79a0fcb2f14bc0ed56faf57c977246a", (130, 134)),
    Abandoned(133, "fix/T010-unsaved-publication-metadata", "29af40455f9358764bc790870f438f3f51ccb9da", (134,)),
    Abandoned(141, "fix/139-nested-encoded-citation-secrets", "6ac98f8e21ef99949cfd999e1eb906771b4e42db", (140, 142)),
)


class GitHub:
    @staticmethod
    def _run(*args: str) -> str:
        return subprocess.run(
            ["gh", "api", *args], check=True, text=True, capture_output=True
        ).stdout

    def get(self, path: str) -> dict:
        return json.loads(self._run(path))

    def has_open_pr(self, repo: str, branch: str) -> bool:
        owner = repo.split("/", 1)[0]
        # --paginate avoids a false "no PR" when the first page is full.
        return bool(self._run(
            "--paginate", "-X", "GET", f"repos/{repo}/pulls",
            "-f", "state=open", "-f", f"head={owner}:{branch}",
            "--jq", ".[].number",
        ).strip())

    def delete(self, path: str) -> None:
        self._run("-X", "DELETE", path)


def matches_abandoned(pr: dict, item: Abandoned, repo: str) -> bool:
    return (
        pr.get("state") == "closed"
        and pr.get("merged") is False
        and not pr.get("merged_at")
        and pr.get("head", {}).get("ref") == item.branch
        and pr.get("head", {}).get("sha") == item.sha
        and (pr.get("head", {}).get("repo") or {}).get("full_name") == repo
        and pr.get("base", {}).get("ref") == "main"
        and (pr.get("base", {}).get("repo") or {}).get("full_name") == repo
    )


def matches_superseding(pr: dict, repo: str) -> bool:
    return (
        pr.get("state") == "closed"
        and pr.get("merged") is True
        and bool(pr.get("merged_at"))
        and pr.get("base", {}).get("ref") == "main"
        and (pr.get("base", {}).get("repo") or {}).get("full_name") == repo
    )


def reconcile(repo: str, api: GitHub, items=ALLOWLIST, audit=print) -> dict[str, int]:
    if repo.count("/") != 1 or not all(repo.split("/")):
        raise ValueError("Explicit owner/repository required")
    counts = {"deleted": 0, "already_absent": 0, "preserved": 0, "errors": 0}
    for item in items:
        ref = f"repos/{repo}/git/ref/heads/{quote(item.branch, safe='/')}"
        delete_ref = f"repos/{repo}/git/refs/heads/{quote(item.branch, safe='/')}"
        try:
            original = api.get(f"repos/{repo}/pulls/{item.pr}")
            if not matches_abandoned(original, item, repo):
                audit(f"PRESERVE {item.branch}: closed-unmerged PR identity mismatch")
                counts["preserved"] += 1
                continue
            if not all(matches_superseding(api.get(f"repos/{repo}/pulls/{number}"), repo)
                       for number in item.superseded_by):
                audit(f"PRESERVE {item.branch}: replacement merge unverified")
                counts["preserved"] += 1
                continue
            try:
                live = api.get(ref)
            except Exception as exc:
                # A 404 can mean absent; it can also mean an API/permission
                # failure. Never infer success or attempt a delete.
                audit(f"PRESERVE {item.branch}: ref unreadable ({type(exc).__name__})")
                counts["preserved"] += 1
                continue
            if live.get("object", {}).get("sha") != item.sha:
                audit(f"PRESERVE {item.branch}: ref moved from pinned head")
                counts["preserved"] += 1
                continue
            if api.has_open_pr(repo, item.branch):
                audit(f"PRESERVE {item.branch}: open PR still references branch")
                counts["preserved"] += 1
                continue
            # Recheck as near as possible to the deletion; REST Git ref
            # deletion has no compare-and-swap, so no forceful operation.
            if api.get(ref).get("object", {}).get("sha") != item.sha:
                audit(f"PRESERVE {item.branch}: ref moved during audit")
                counts["preserved"] += 1
                continue
            api.delete(delete_ref)
            counts["deleted"] += 1
            audit(f"DELETED {item.branch}: exact closed-unmerged head superseded")
        except Exception as exc:
            counts["errors"] += 1
            audit(f"ERROR {item.branch}: {type(exc).__name__}; no further action on branch")
    return counts


if __name__ == "__main__":
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    if not os.environ.get("GH_TOKEN"):
        raise SystemExit("GH_TOKEN is required")
    try:
        result = reconcile(repository, GitHub())
    except ValueError as exc:
        raise SystemExit(str(exc)) from exc
    print("Abandoned PR cleanup:", json.dumps(result, sort_keys=True))
    if result["errors"]:
        raise SystemExit("One or more GitHub operations failed; inspect log")
