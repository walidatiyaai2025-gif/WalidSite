#!/usr/bin/env python3
"""Fail closed on CI evidence assembled from different commits/runs/events.

For Release workflow only. GitHub REST responses are observations, not
publisher signatures or a production/hosting acceptance receipt.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

SHA = re.compile(r"[a-f0-9]{40}\Z")
REPO = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z")
REQUIRED_JOBS = (
    "required-ci-gate",
    "build-cpanel-package",
    "build-android-qa-apk",
    "android-emulator-install-launch",
    "verify-source-pinned-release-bundle",
)


class ReleaseCIError(ValueError):
    """Release CI evidence is incomplete, stale, or inconsistent."""


def _json_file(path: Path) -> dict:
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 3_000_000:
        raise ReleaseCIError("Invalid CI evidence file")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ReleaseCIError("Invalid CI evidence response")
    return data


def _positive(value: object) -> bool:
    return type(value) is int and value > 0


def _same_repo(item: dict, repository: str) -> bool:
    return (isinstance(item.get("repository"), dict)
            and isinstance(item.get("head_repository"), dict)
            and item["repository"].get("full_name") == repository
            and item["head_repository"].get("full_name") == repository)


def select_exact_main_ci(runs: dict, sha: str, repository: str) -> int:
    """Select the newest exact-SHA *push* CI run, including non-green states.

    A later failed/running push cannot silently fall back to older green
    evidence. Run identity is then verified again with its own job collection.
    """
    if not SHA.fullmatch(sha) or not REPO.fullmatch(repository):
        raise ReleaseCIError("Invalid source identity")
    items = runs.get("workflow_runs")
    count = runs.get("total_count")
    if (not isinstance(items, list) or not type(count) is int
            or count < 1 or count > 100 or count != len(items)):
        raise ReleaseCIError("Incomplete CI run listing")
    candidates = []
    for run in items:
        if not isinstance(run, dict):
            raise ReleaseCIError("Invalid CI run item")
        if (run.get("name") == "PR CI" and run.get("head_sha") == sha
                and run.get("head_branch") == "main"
                and run.get("event") == "push"
                and run.get("path") == ".github/workflows/ci.yml"
                and _same_repo(run, repository)):
            if not _positive(run.get("id")) or not _positive(run.get("run_attempt")):
                raise ReleaseCIError("Invalid CI run identity")
            candidates.append(run)
    if not candidates:
        raise ReleaseCIError("No exact main-push CI run")
    newest = max(candidates, key=lambda run: run["id"])
    if newest.get("status") != "completed" or newest.get("conclusion") != "success":
        raise ReleaseCIError("Latest main-push CI is not successful")
    return newest["id"]


def verify_jobs(jobs_response: dict, *, sha: str, run_id: int) -> dict:
    jobs = jobs_response.get("jobs")
    count = jobs_response.get("total_count")
    if (not isinstance(jobs, list) or type(count) is not int or count < len(REQUIRED_JOBS)
            or count > 100 or count != len(jobs) or not _positive(run_id)):
        raise ReleaseCIError("Incomplete CI job listing")
    found: dict[str, dict] = {}
    for job in jobs:
        if not isinstance(job, dict) or job.get("run_id") != run_id or job.get("head_sha") != sha:
            raise ReleaseCIError("CI job belongs to another run or commit")
        name = job.get("name")
        if name in REQUIRED_JOBS:
            if name in found:
                raise ReleaseCIError("Duplicate required CI job")
            found[name] = job
    if set(found) != set(REQUIRED_JOBS):
        raise ReleaseCIError("Required CI job missing")
    if any(job.get("status") != "completed" or job.get("conclusion") != "success"
           for job in found.values()):
        raise ReleaseCIError("Required CI job not successful")
    return {
        "schema": "walidsite.exact-main-ci-release-gate.v1",
        "source_sha": sha,
        "ci_run_id": run_id,
        "required_jobs": list(REQUIRED_JOBS),
        "same_run_success_verified": True,
        "production_ready": False,
        "publisher_signature_verified": False,
        "live_deployment_verified": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runs", type=Path, required=True)
    parser.add_argument("--sha", required=True)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--jobs", type=Path)
    parser.add_argument("--run-id", type=int)
    args = parser.parse_args(argv)
    try:
        run_id = select_exact_main_ci(_json_file(args.runs), args.sha, args.repo)
        if args.jobs is None and args.run_id is None:
            print(run_id)
            return 0
        if args.jobs is None or args.run_id != run_id:
            raise ReleaseCIError("CI job/run pairing mismatch")
        result = verify_jobs(_json_file(args.jobs), sha=args.sha, run_id=run_id)
    except (ReleaseCIError, ValueError, OSError, UnicodeError, json.JSONDecodeError):
        # No raw API body, paths, tokens or other untrusted content in logs.
        print("EXACT MAIN CI RELEASE GATE: BLOCKED", file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
