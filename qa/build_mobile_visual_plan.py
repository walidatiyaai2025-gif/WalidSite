#!/usr/bin/env python3
"""Generate trusted real-device capture plans for MOBILE-01..13.

No emulator, session, credentials, verified solution or downloaded runbook is
invented here. Dynamic identifiers are explicit operator-provided values.
"""
import argparse
import json
import re
import sys
from pathlib import Path

try:
    from qa.capture_visual_batch import validate_plan
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from capture_visual_batch import validate_plan


MOBILE_IDS = tuple(f"MOBILE-{number:02d}" for number in range(1, 14))
DYNAMIC_IDS = {"MOBILE-04": "solution_id", "MOBILE-06": "runbook_id"}
SAFE_ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9_-]{0,127}\Z")
RENDERER = ["python3", "qa/render_android_screenshot.py"]


class MobilePlanError(ValueError):
    pass


def build_mobile_plan(root: Path, *, solution_id: str | None = None,
                      runbook_id: str | None = None, locale: str = "en",
                      dpr: float = 1.0, timeout: int = 120,
                      require_all: bool = False) -> dict:
    root = root.resolve()
    if locale not in ("ar", "en"):
        raise MobilePlanError("Mobile locale must be ar or en")
    if type(dpr) not in (int, float) or not 0.5 <= dpr <= 8:
        raise MobilePlanError("Mobile DPR must be a number within 0.5..8")
    if type(timeout) is not int or not 1 <= timeout <= 3600:
        raise MobilePlanError("Mobile timeout must be a whole number 1..3600")
    supplied = {"MOBILE-04": solution_id, "MOBILE-06": runbook_id}
    for screen_id, value in supplied.items():
        if value is not None and (not isinstance(value, str)
                                  or SAFE_ID.fullmatch(value) is None):
            raise MobilePlanError(f"{screen_id}: unsafe real resource identifier")
    if require_all and any(value is None for value in supplied.values()):
        raise MobilePlanError(
            "All 13 screens require real solution and offline runbook identifiers"
        )
    try:
        data = json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))
        screens = data["screens"]
    except (OSError, ValueError, KeyError, TypeError) as exc:
        raise MobilePlanError("Canonical screen contract is unreadable") from exc
    indexed = {screen.get("id"): screen for screen in screens if isinstance(screen, dict)}
    if any(screen_id not in indexed for screen_id in MOBILE_IDS):
        raise MobilePlanError("Canonical contract lacks one or more mobile screens")

    captures = []
    for screen_id in MOBILE_IDS:
        screen = indexed[screen_id]
        route = screen.get("route")
        if screen.get("surface") != "mobile" or not isinstance(route, str):
            raise MobilePlanError(f"{screen_id}: invalid mobile contract")
        if screen_id in DYNAMIC_IDS:
            if route.count("{id}") != 1:
                raise MobilePlanError(f"{screen_id}: unexpected dynamic route")
            if supplied[screen_id] is None:
                continue
            route = route.replace("{id}", supplied[screen_id])
        elif "{" in route or "}" in route:
            raise MobilePlanError(f"{screen_id}: unknown dynamic route")
        if not route.startswith("/") or route.startswith("//"):
            raise MobilePlanError(f"{screen_id}: unsafe mobile route")
        captures.append({
            "screen_id": screen_id,
            "route": route,
            "locale": locale,
            "dpr": dpr,
            "renderer_command": list(RENDERER),
            "timeout": timeout,
        })
    plan = {"version": 1, "captures": captures}
    validate_plan(root, plan)
    if require_all and len(captures) != len(MOBILE_IDS):
        raise MobilePlanError("All 13 mobile routes must be included")
    return plan


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--solution-id")
    parser.add_argument("--runbook-id")
    parser.add_argument("--locale", choices=("en", "ar"), default="en")
    parser.add_argument("--dpr", type=float, default=1.0)
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--require-all", action="store_true")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args(argv)
    try:
        plan = build_mobile_plan(
            Path(__file__).resolve().parents[1],
            solution_id=args.solution_id, runbook_id=args.runbook_id,
            locale=args.locale, dpr=args.dpr, timeout=args.timeout,
            require_all=args.require_all,
        )
        content = json.dumps(plan, ensure_ascii=False, indent=2) + "\n"
        if args.output is None:
            sys.stdout.write(content)
        else:
            output = args.output
            if (not output.is_absolute() or output.exists() or output.is_symlink()
                    or not output.parent.is_dir() or output.parent.is_symlink()):
                raise MobilePlanError("Output requires a fresh absolute file in a real directory")
            with output.open("x", encoding="utf-8") as handle:
                handle.write(content)
    except (OSError, ValueError) as exc:
        print("MOBILE VISUAL PLAN REJECTED: " + str(exc), file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
