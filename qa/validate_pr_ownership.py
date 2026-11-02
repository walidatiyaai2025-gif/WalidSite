#!/usr/bin/env python3
"""Fail-closed PR admission gate: canonical Issue, unique branch and exclusive ready-PR files.

This is a PR-scoped gate. Unrelated legacy duplicates are reported in documentation,
not used to block an independent Issue. No mutation or branch deletion is performed.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
BRANCH = re.compile(r"^(?:feat|fix|chore)/(?P<key>T[0-9]{3}|[1-9][0-9]*)[-/][A-Za-z0-9][A-Za-z0-9._/-]*$")
TITLE_ISSUE = re.compile(r"#([1-9][0-9]*)\b")
TITLE_TASK = re.compile(r"\b(T[0-9]{3})\b")
SHA40 = re.compile(r"^[0-9a-f]{40}$")


def task_map():
    data = json.loads((ROOT / "tasks/github-issue-map.json").read_text(encoding="utf-8"))
    return {item["id"]: int(item["issue_number"]) for item in data["items"]}


def issue_from_branch(name, mapping):
    match = BRANCH.fullmatch(name or "")
    if not match:
        return None
    key = match.group("key")
    return mapping.get(key) if key.startswith("T") else int(key)


def issue_from_title(title, mapping):
    # Title is used to detect mismatches, never to override the branch's claim.
    values = {int(n) for n in TITLE_ISSUE.findall(title or "")}
    values.update(mapping[t] for t in TITLE_TASK.findall(title or "") if t in mapping)
    return values


def compare_proves_ancestor(payload):
    """Only an identical/ancestor branch is reconciled; divergence stays blocking."""
    return payload.get("status") in ("ahead", "identical") and payload.get("behind_by") == 0


def inspect(pr, open_prs, branches, issue_state, files_by_pr, mapping, repo, expected_sha,
            reconciled_branches=None):
    errors, warnings = [], []
    reconciled_branches = set(reconciled_branches or ())
    number = pr["number"]
    head = pr["head"]
    source = head["ref"]
    issue = issue_from_branch(source, mapping)

    if pr["base"]["ref"] != "main":
        errors.append("PR must target main")
    if (head.get("repo") or {}).get("full_name") != repo:
        errors.append("PR must originate in this repository")
    if issue is None:
        errors.append("Branch must be feat|fix|chore/<Issue-number-or-Txxx>-<slug>")
        return errors, warnings
    if issue == 1:
        errors.append("Program tracker #1 cannot be used as a catch-all work claim")
    if pr["head"]["sha"] != expected_sha:
        errors.append("PR head moved since this CI run; rerun on latest commit")
    if issue_state != "open":
        errors.append(f"Canonical Issue #{issue} must be open, not {issue_state}")
    title_issues = issue_from_title(pr.get("title", ""), mapping)
    if issue not in title_issues:
        errors.append(f"PR title must identify its canonical Issue #{issue} or corresponding Txxx")

    for other in open_prs:
        if other["number"] == number:
            continue
        other_head = other["head"]
        other_issue = issue_from_branch(other_head["ref"], mapping)
        if other_issue == issue:
            errors.append(f"Duplicate active PR #{other['number']} owns Issue #{issue}")
        if other_head["ref"] == source and (other_head.get("repo") or {}).get("full_name") == repo:
            errors.append(f"Branch {source} is also used by PR #{other['number']}")

    for branch in branches:
        name = branch["name"]
        if name != source and issue_from_branch(name, mapping) == issue:
            if name in reconciled_branches:
                warnings.append(
                    f"RECONCILED ANCESTOR branch retained for Issue #{issue}: {name}; "
                    "safe cleanup may remove it after the canonical PR merges"
                )
            else:
                errors.append(f"Competing branch for Issue #{issue}: {name}; reconcile, do not delete unmerged code")

    if not pr.get("draft", False):
        ours = set(files_by_pr.get(number, ()))
        for other in open_prs:
            if other["number"] == number or other["base"]["ref"] != "main":
                continue
            overlap = sorted(ours & set(files_by_pr.get(other["number"], ())))
            if not overlap:
                continue
            detail = f"PR #{other['number']} overlaps files: {', '.join(overlap[:12])}"
            if other.get("draft"):
                # Draft PR reserves work; serialize integration and recheck it when ready.
                warnings.append("DRAFT COLLISION (must rebase/reconcile before ready): " + detail)
            else:
                errors.append("Concurrent ready-PR file ownership collision: " + detail)
    return sorted(set(errors)), sorted(set(warnings))


class GitHub:
    def __init__(self, repo, token):
        self.repo = repo
        self.token = token
        self.url = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")

    def get(self, endpoint):
        request = Request(self.url + endpoint, headers={
            "Accept": "application/vnd.github+json",
            "Authorization": "Bearer " + self.token,
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "walidsite-pr-admission",
        })
        with urlopen(request, timeout=20) as response:
            return json.load(response)

    def pages(self, endpoint):
        entries = []
        for page in range(1, 31):
            sep = "&" if "?" in endpoint else "?"
            chunk = self.get(f"{endpoint}{sep}per_page=100&page={page}")
            if not isinstance(chunk, list):
                raise ValueError("GitHub API returned a non-list response")
            entries.extend(chunk)
            if len(chunk) < 100:
                return entries
        raise ValueError("GitHub pagination exceeded 3000 entries; refusing incomplete audit")


def main():
    if os.environ.get("GITHUB_EVENT_NAME") != "pull_request":
        print("SKIP: no pull_request event (other CI checks still required)")
        return 0
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    token = os.environ.get("GH_TOKEN", "")
    event_file = os.environ.get("GITHUB_EVENT_PATH", "")
    if not repo or not token or not event_file:
        print("FAIL: missing repository, token or event metadata", file=sys.stderr)
        return 1
    try:
        event = json.loads(Path(event_file).read_text(encoding="utf-8"))
        number = int(event["pull_request"]["number"])
        expected_sha = event["pull_request"]["head"]["sha"]
        if event.get("action") == "closed":
            return 0
        api = GitHub(repo, token)
        pr = api.get(f"/repos/{repo}/pulls/{number}")
        if pr["number"] != number:
            raise ValueError("mismatched pull request response")
        mapping = task_map()
        issue = issue_from_branch(pr["head"]["ref"], mapping)
        issue_state = api.get(f"/repos/{repo}/issues/{issue}")["state"] if issue else "unknown"
        peers = api.pages(f"/repos/{repo}/pulls?state=open")
        branches = api.pages(f"/repos/{repo}/branches")
        reconciled_branches = set()
        if issue:
            if not SHA40.fullmatch(expected_sha):
                raise ValueError("pull-request head SHA is malformed")
            source = pr["head"]["ref"]
            for branch in branches:
                name = branch["name"]
                if name == source or issue_from_branch(name, mapping) != issue:
                    continue
                branch_sha = (branch.get("commit") or {}).get("sha", "")
                if not SHA40.fullmatch(branch_sha):
                    raise ValueError(f"branch {name} returned a malformed head SHA")
                comparison = api.get(f"/repos/{repo}/compare/{branch_sha}...{expected_sha}")
                if compare_proves_ancestor(comparison):
                    reconciled_branches.add(name)
        files_by_pr = {}
        # Inspect ready PRs and drafts only when the target is ready.
        if not pr.get("draft"):
            for other in peers:
                if other["base"]["ref"] == "main":
                    files_by_pr[other["number"]] = [
                        f["filename"] for f in api.pages(f"/repos/{repo}/pulls/{other['number']}/files")
                    ]
        errors, warnings = inspect(
            pr, peers, branches, issue_state, files_by_pr, mapping, repo, expected_sha,
            reconciled_branches=reconciled_branches,
        )
        for warning in warnings:
            print("WARNING: " + warning)
        for error in errors:
            print("BLOCKED: " + error, file=sys.stderr)
        if errors:
            return 1
        print(f"PASS: PR #{number} at {expected_sha} holds exclusive Issue #{issue}; branch and ready-PR files verified")
        return 0
    except (ValueError, KeyError, OSError, HTTPError, URLError) as exc:
        print(f"BLOCKED: GitHub PR ownership audit unavailable/incomplete: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
