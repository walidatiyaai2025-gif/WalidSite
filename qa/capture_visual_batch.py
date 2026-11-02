#!/usr/bin/env python3
"""Run a validated batch of real v6 UI screenshot commands; never forge parity.

Capture plans execute local programs and must only be supplied by trusted operators.
Each screen remains PENDING unless the real renderer produces an exact RGBA match.
"""
import argparse
import json
import sys
from pathlib import Path

try:
    from qa.capture_visual_evidence import CaptureError, capture, _route_matches, verified_source_checkout
    from qa.validate_visual_fidelity import audit
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from capture_visual_evidence import CaptureError, capture, _route_matches, verified_source_checkout
    from validate_visual_fidelity import audit


class CapturePlanError(ValueError):
    pass


def validate_plan(root: Path, plan: object, require_all: bool = False) -> list[dict]:
    if (not isinstance(plan, dict) or set(plan) != {"version", "captures"}
            or type(plan["version"]) is not int or plan["version"] != 1):
        raise CapturePlanError("Plan must have exactly version=1 and captures")
    entries = plan["captures"]
    if not isinstance(entries, list) or not 1 <= len(entries) <= 40:
        raise CapturePlanError("Plan must contain 1 to 40 captures")
    screens = json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))["screens"]
    expected = {screen["id"]: screen for screen in screens}
    if len(screens) != 40 or len(expected) != 40:
        raise CapturePlanError("Canonical 40-screen contract is invalid")
    seen = set()
    for item in entries:
        if not isinstance(item, dict) or set(item) != {
            "screen_id", "route", "locale", "dpr", "renderer_command", "timeout"
        }:
            raise CapturePlanError("Every capture requires exactly screen_id, route, locale, dpr, renderer_command and timeout")
        screen_id = item["screen_id"]
        if not isinstance(screen_id, str) or screen_id not in expected or screen_id in seen:
            raise CapturePlanError("Unknown or duplicated canonical screen ID")
        seen.add(screen_id)
        if not _route_matches(expected[screen_id]["route"], item["route"]):
            raise CapturePlanError(f"{screen_id}: route violates canonical screen contract")
        if item["locale"] not in ("ar", "en"):
            raise CapturePlanError(f"{screen_id}: invalid locale")
        if type(item["dpr"]) not in (int, float) or not 0 < item["dpr"] <= 8:
            raise CapturePlanError(f"{screen_id}: invalid DPR")
        command = item["renderer_command"]
        if (not isinstance(command, list) or not command or
                any(not isinstance(arg, str) or not arg or
                    "\n" in arg or "\r" in arg or "assets/screen-reference" in arg
                    for arg in command)):
            raise CapturePlanError(f"{screen_id}: invalid renderer argv (no reference image inputs)")
        if type(item["timeout"]) is not int or not 1 <= item["timeout"] <= 3600:
            raise CapturePlanError(f"{screen_id}: timeout must be a whole number 1..3600")
    if require_all and seen != set(expected):
        raise CapturePlanError(
            "Full plan must cover all 40 screens; missing " + ", ".join(sorted(set(expected) - seen))
        )
    return entries


def verified_head(root: Path, commit: str) -> bool:
    # Keep compatibility with the batch API, but use the SAME strict
    # checkout/worktree attestation as standalone screenshot capture.
    return verified_source_checkout(root, commit)


def execute(root: Path, plan: object, commit: str, *,
            require_all: bool = False, dry_run: bool = False,
            capture_action=capture) -> dict:
    root = root.resolve()
    # Validate the entire execution plan BEFORE the first renderer invocation.
    entries = validate_plan(root, plan, require_all=require_all)
    if not verified_head(root, commit):
        raise CapturePlanError("Renderer commit must match an unmodified source checkout")
    problems, _, _ = audit(root)
    if problems:
        raise CapturePlanError("Existing evidence is invalid: " + "; ".join(problems))
    ledger = json.loads((root / "design/visual-fidelity-evidence.json").read_text(encoding="utf-8"))
    result = {"captured": [], "already_matched": [], "planned_only": [], "failed": []}
    for item in entries:
        screen_id = item["screen_id"]
        if ledger["screens"][screen_id]["status"] == "matched":
            result["already_matched"].append(screen_id)
        elif dry_run:
            result["planned_only"].append(screen_id)
        else:
            try:
                capture_action(
                    root, screen_id, commit=commit,
                    locale=item["locale"], dpr=item["dpr"], route=item["route"],
                    command=item["renderer_command"], timeout=item["timeout"],
                )
                result["captured"].append(screen_id)
            except CaptureError as exc:
                # Independent screens may continue; failures never get matched.
                result["failed"].append({"screen_id": screen_id, "reason": str(exc)})
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", required=True, type=Path)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--require-all", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    try:
        plan = json.loads(args.plan.read_text(encoding="utf-8"))
        result = execute(
            Path(__file__).resolve().parents[1], plan, args.commit,
            require_all=args.require_all, dry_run=args.dry_run,
        )
    except (OSError, ValueError) as exc:
        print("CAPTURE PLAN REJECTED: " + str(exc), file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    # A dry run is planning only; it cannot be mistaken for completed evidence.\n    if args.dry_run:\n        return 0\n    # In a full batch, a successful process must mean every canonical screen\n    # has either been captured now or was already independently attested.\n    if args.require_all and len(result["captured"]) + len(result["already_matched"]) != 40:\n        return 1\n    return 1 if result["failed"] else 0


if __name__ == "__main__":
    sys.exit(main())
