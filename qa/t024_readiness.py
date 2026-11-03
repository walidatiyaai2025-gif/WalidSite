#!/usr/bin/env python3
"""T024 honest integration traceability; never infer runtime proof from source code.

Normal mode audits the immutable v6 scope and reports missing acceptance evidence.
--require-ready is deliberately red until actual screen pixels, endpoint/action
proofs and independently audited internal G-01..G-20 evidence are present.
Staging and owner handoff G-12/G-20 remain explicitly external (T025/T027).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

try:
    from qa.validate_visual_fidelity import audit as visual_audit
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from validate_visual_fidelity import audit as visual_audit

ROOT = Path(__file__).resolve().parents[1]
METHODS = frozenset(("get", "post", "put", "patch", "delete", "head", "options", "trace"))
EXTERNAL = {"G-12": 26, "G-20": 28}
SHA40 = re.compile(r"[0-9a-f]{40}\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
EXPECTED_IDS = {
    *(f"WEB-{i:02d}" for i in range(1, 15)),
    *(f"ADMIN-{i:02d}" for i in range(1, 14)),
    *(f"MOBILE-{i:02d}" for i in range(1, 14)),
}
EXPECTED_GATES = {f"G-{i:02d}" for i in range(1, 21)}
# Source-sensitive files that invalidate integrated QA proof after they change.
# The evidence itself may be appended in a later commit without invalidating
# the runtime SHA. Do not silently exempt application, API or visual QA code.
SOURCE_PATHS = (
    "backend", "mobile", "api", "contracts", "database", "assets",
    "locales", "qa", ".github/workflows", "design/tokens.json",
    ":(exclude)qa/t024-evidence", ":(exclude)qa/visual-captures",
    ":(exclude)qa/visual-diagnostics",
)


def _json(root: Path, path: str):
    return json.loads((root / path).read_text(encoding="utf-8"))


def openapi_operations(source: str) -> tuple[set[str], set[str]]:
    """Extract only actual root-level OpenAPI paths and HTTP methods.

    Indentation is fixed by the v6 source contract (2-space paths, 4-space
    methods). Never count nested response/parameter keys as API operations.
    """
    paths: set[str] = set()
    operations: set[str] = set()
    in_paths = False
    current = ""
    for line in source.splitlines():
        if line == "paths:":
            if in_paths:
                raise ValueError("Duplicated root paths section")
            in_paths = True
            continue
        if in_paths and line and not line.startswith((" ", "#")):
            in_paths = False
            current = ""
        if not in_paths:
            continue
        path_match = re.fullmatch(r"  (/[^:]+):\s*", line)
        if path_match:
            current = path_match.group(1)
            if current in paths:
                raise ValueError("Duplicate OpenAPI path")
            paths.add(current)
            continue
        method_match = re.fullmatch(r"    (get|post|put|patch|delete|head|options|trace):\s*", line)
        if method_match:
            if not current:
                raise ValueError("Operation without a path")
            key = current + "#" + method_match.group(1)
            if key in operations:
                raise ValueError("Duplicate OpenAPI operation")
            operations.add(key)
    if not paths or not operations:
        raise ValueError("Missing OpenAPI paths or operations")
    return paths, operations


def proof_ok(root: Path, proof: object) -> bool:
    """A proof must be an existing, pinned, small on-repo file, not a URL.

    This verifies integrity and bookkeeping only. Human/functional review
    must separately establish that its contents came from a real runtime.
    """
    if not isinstance(proof, dict) or set(proof) != {
        "path", "sha256", "source_sha", "command"
    }:
        return False
    rel = proof["path"]
    if not isinstance(rel, str) or not rel.startswith("qa/t024-evidence/"):
        return False
    parts = Path(rel).parts
    if not parts or any(part in (".", "..") for part in parts) or Path(rel).is_absolute():
        return False
    if (not isinstance(proof["sha256"], str) or not SHA256.fullmatch(proof["sha256"])
            or not isinstance(proof["source_sha"], str)
            or not SHA40.fullmatch(proof["source_sha"])
            or not isinstance(proof["command"], str)
            or not 1 <= len(proof["command"].strip()) <= 1024):
        return False
    target = root
    for part in parts:
        target = target / part
        if target.is_symlink():
            return False
    if not target.is_file() or target.stat().st_size > 2 * 1024 * 1024:
        return False
    return hashlib.sha256(target.read_bytes()).hexdigest() == proof["sha256"]


def source_proof_ok(root: Path, source_sha: str) -> bool:
    """Confirm an evidence SHA is an ancestor with unchanged source at HEAD.

    A hash of an evidence file alone is not proof that the named source commit
    exists or that the tested product matches this checkout. Source commits
    may predate evidence-only commits; product/API/QA changes invalidate them.
    No external network calls, shell commands or credentials are used.
    """
    if not isinstance(source_sha, str) or not SHA40.fullmatch(source_sha):
        return False

    root = root.resolve()

    def git(*args: str) -> subprocess.CompletedProcess[str] | None:
        try:
            return subprocess.run(
                ["git", "-C", str(root), *args], capture_output=True,
                text=True, timeout=10, check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            return None

    identity = git("rev-parse", "--show-toplevel", "HEAD")
    if identity is None or identity.returncode != 0:
        return False
    lines = identity.stdout.splitlines()
    if (len(lines) != 2 or Path(lines[0]).resolve() != root
            or not SHA40.fullmatch(lines[1])):
        return False

    ancestor = git("merge-base", "--is-ancestor", source_sha, lines[1])
    if ancestor is None or ancestor.returncode != 0:
        return False

    # Evidence can be committed after the tested SHA. Only non-source changes
    # can happen between that revision and the release candidate.
    drift = git("diff", "--quiet", source_sha, lines[1], "--", *SOURCE_PATHS)
    if drift is None or drift.returncode != 0:
        return False
    # A checkout with uncommitted/new source code is not the tested revision.
    dirty = git("status", "--porcelain", "--untracked-files=all", "--", *SOURCE_PATHS)
    return dirty is not None and dirty.returncode == 0 and not dirty.stdout.strip()


def audit(root: Path = ROOT) -> tuple[dict, list[str]]:
    root = root.resolve()
    errors: list[str] = []
    report = {
        "scope": "T024 engineering; G-12 release and G-20 owner handoff are external",
        "automatic_runtime_certification": False,
        "engineering_ready": False,
    }
    try:
        contract = _json(root, "contracts/screens.json")
        screens = contract["screens"]
        gates = _json(root, "contracts/acceptance-gates.json")
        evidence = _json(root, "design/t024-gate-evidence.json")
        api_source = (root / "api/openapi-contract.yaml").read_text(encoding="utf-8")
        paths, operations = openapi_operations(api_source)
        visual_errors, matched, visual_total = visual_audit(root)
        visual_records = _json(root, "design/visual-fidelity-evidence.json")["screens"]
    except (OSError, ValueError, TypeError, KeyError, AttributeError) as exc:
        return report, ["Unreadable or invalid canonical QA input: " + type(exc).__name__]

    if not isinstance(screens, list) or contract.get("screenCount") != 40:
        return report, ["Canonical screen list/count is invalid"]
    ids = [x.get("id") for x in screens if isinstance(x, dict)]
    if len(screens) != 40 or set(ids) != EXPECTED_IDS or len(set(ids)) != 40:
        errors.append("40 WEB/ADMIN/MOBILE screen identities are incomplete or duplicated")
    action_keys: set[str] = set()
    for screen in screens:
        if not isinstance(screen, dict):
            errors.append("Invalid screen record")
            continue
        sid = screen.get("id")
        expected_surface = str(sid).split("-", 1)[0].lower()
        actions = screen.get("actions")
        if screen.get("surface") != expected_surface or not isinstance(actions, list) or not actions:
            errors.append(str(sid) + ": missing surface or action contract")
            continue
        if any(not isinstance(a, str) or not a.strip() or "/" in a for a in actions):
            errors.append(str(sid) + ": invalid action identifier")
            continue
        keys = {sid + "/" + action for action in actions}
        if len(keys) != len(actions):
            errors.append(str(sid) + ": duplicated action")
        action_keys.update(keys)
    if len(action_keys) != 97:
        errors.append(f"Expected 97 screen/action pairs, got {len(action_keys)}")
    if len(paths) != 42 or len(operations) != 46:
        errors.append(f"Expected 42 OpenAPI paths/46 methods, got {len(paths)}/{len(operations)}")
    gate_ids = [g.get("id") for g in gates if isinstance(g, dict)] if isinstance(gates, list) else []
    if (len(gates) != 20 or set(gate_ids) != EXPECTED_GATES or len(set(gate_ids)) != 20
            or any(not g.get("blocking") for g in gates)):
        errors.append("20 unique blocking G-01..G-20 contracts are required")

    if not isinstance(evidence, dict) or evidence.get("version") != 1:
        return report, errors + ["T024 evidence schema version is invalid"]
    action_evidence = evidence.get("actions")
    api_evidence = evidence.get("api_operations")
    gate_evidence = evidence.get("gates")
    if not all(isinstance(x, dict) for x in (action_evidence, api_evidence, gate_evidence)):
        return report, errors + ["T024 actions, api_operations and gates must be mappings"]
    if set(action_evidence) - action_keys or set(api_evidence) - operations:
        errors.append("Evidence contains uncontracted actions or API operations")
    if set(gate_evidence) != EXPECTED_GATES:
        errors.append("Evidence must enumerate all G-01..G-20 gates")

    # One checked-out revision per audit. Cache Git ancestry/source-drift
    # checks by SHA across the 97 actions, 46 API operations and 20 gates.
    checked_sources: dict[str, bool] = {}

    def checked_proof(proof: object) -> bool:
        if not proof_ok(root, proof):
            return False
        sha = proof["source_sha"]
        if sha not in checked_sources:
            checked_sources[sha] = source_proof_ok(root, sha)
        return checked_sources[sha]

    def count_proven(mapping: dict, allowed: set[str], label: str) -> int:
        count = 0
        for key, proofs in mapping.items():
            if key not in allowed:
                continue
            if (not isinstance(proofs, list) or not proofs
                    or any(not checked_proof(p) for p in proofs)):
                errors.append(f"{label} {key}: unverifiable source-pinned proof")
            else:
                count += 1
        return count

    action_verified = count_proven(action_evidence, action_keys, "Action")
    api_verified = count_proven(api_evidence, operations, "API")
    gate_verified = 0
    gate_deferred = 0
    for gid in sorted(EXPECTED_GATES):
        entry = gate_evidence.get(gid)
        if not isinstance(entry, dict) or set(entry) != {"status", "owner_issue", "evidence"}:
            errors.append(gid + ": invalid or absent gate evidence entry")
            continue
        status, owner, proofs = entry["status"], entry["owner_issue"], entry["evidence"]
        if type(owner) is not int or owner < 1:
            errors.append(gid + ": invalid owner issue")
        if gid in EXTERNAL and status == "deferred_external" and owner == EXTERNAL[gid] and proofs == []:
            gate_deferred += 1
            continue
        if status == "deferred_external":
            errors.append(gid + ": only G-12/G-20 may be deferred externally")
        elif status == "pending" and proofs == []:
            pass
        elif (status == "verified" and isinstance(proofs, list) and proofs
              and all(checked_proof(p) for p in proofs)):
            gate_verified += 1
        else:
            errors.append(gid + ": status/evidence mismatch or unverified proof")
    errors.extend(visual_errors)
    report.update({
        "screens": {"total": len(screens), "visually_matched": matched,
                    "unmatched": visual_total - matched},
        "screen_actions": {"total": len(action_keys), "with_pinned_proof": action_verified,
                           "unverified": len(action_keys) - action_verified},
        "openapi": {"paths": len(paths), "operations": len(operations),
                    "with_pinned_proof": api_verified, "unverified": len(operations) - api_verified},
        "gates": {"total": len(gate_ids), "with_pinned_proof": gate_verified,
                  "deferred_external": gate_deferred,
                  "unverified": len(EXPECTED_GATES) - gate_verified - gate_deferred},
        "pending_screen_ids": sorted(
            sid for sid in EXPECTED_IDS if not isinstance(visual_records, dict)
            or visual_records.get(sid, {}).get("status") != "matched"
        ),
        "unverified_actions": sorted(action_keys - set(action_evidence)),
        "unverified_api_operations": sorted(operations - set(api_evidence)),
        "unverified_gates": sorted(
            gid for gid in EXPECTED_GATES if isinstance(gate_evidence.get(gid), dict)
            and gate_evidence[gid].get("status") == "pending"
        ),
    })
    report["engineering_ready"] = (
        not errors and matched == 40 and action_verified == 97
        and api_verified == 46 and gate_verified == 18 and gate_deferred == 2
    )
    return report, errors


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--require-ready", action="store_true",
                        help="Fail if real 40-screen and T024 engineering proof is incomplete")
    args = parser.parse_args(argv)
    report, errors = audit(args.root)
    report["audit_errors"] = errors
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    if errors:
        return 1
    if args.require_ready and not report["engineering_ready"]:
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
