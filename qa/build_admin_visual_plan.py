#!/usr/bin/env python3
"""Build a trusted ADMIN-01..13 visual-capture plan from canonical contracts.

The generated plan contains no credentials or browser storage state. It is
intended for qa/visual_diagnostics.py / qa/capture_visual_batch.py and never
changes visual evidence by itself.
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


ADMIN_IDS = tuple(f"ADMIN-{index:02d}" for index in range(1, 14))
PROVIDER_ID_RE = re.compile(
    r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\Z"
)
RENDERER = ["python3", "qa/render_admin_screenshot.py"]


class AdminPlanError(ValueError):
    pass


def build_admin_plan(root: Path, provider_id: str, *, locale: str = "en",
                     dpr: float = 1.0, timeout: int = 120) -> dict:
    root = root.resolve()
    if not isinstance(provider_id, str) or not PROVIDER_ID_RE.fullmatch(provider_id):
        raise AdminPlanError("ADMIN-04 requires a canonical lowercase provider UUID")
    if locale not in ("en", "ar"):
        raise AdminPlanError("Admin plan locale must be ar or en")
    if type(dpr) not in (int, float) or not 0 < dpr <= 8:
        raise AdminPlanError("Admin plan DPR must be numeric and within 0..8")
    if type(timeout) is not int or not 1 <= timeout <= 3600:
        raise AdminPlanError("Admin plan timeout must be a whole number 1..3600")

    try:
        screens = json.loads(
            (root / "contracts/screens.json").read_text(encoding="utf-8")
        )["screens"]
    except (OSError, ValueError, KeyError, TypeError) as exc:
        raise AdminPlanError("Canonical screen contract is unreadable") from exc

    by_id = {screen.get("id"): screen for screen in screens if isinstance(screen, dict)}
    if any(screen_id not in by_id for screen_id in ADMIN_IDS):
        raise AdminPlanError("Canonical contract does not contain all 13 Admin screens")

    captures = []
    for screen_id in ADMIN_IDS:
        screen = by_id[screen_id]
        if screen.get("surface") != "admin":
            raise AdminPlanError(f"{screen_id} is not an Admin surface")
        route = screen.get("route")
        if not isinstance(route, str) or not route.startswith("/admin"):
            raise AdminPlanError(f"{screen_id} has an invalid Admin route")
        if screen_id == "ADMIN-04":
            if route.count("{id}") != 1:
                raise AdminPlanError("ADMIN-04 must contain exactly one {id} placeholder")
            route = route.replace("{id}", provider_id)
        elif "{" in route or "}" in route:
            raise AdminPlanError(f"{screen_id} unexpectedly contains a dynamic route")

        captures.append({
            "screen_id": screen_id,
            "route": route,
            "locale": locale,
            "dpr": dpr,
            "renderer_command": list(RENDERER),
            "timeout": timeout,
        })

    plan = {"version": 1, "captures": captures}
    # Reuse the independent canonical route/schema admission before returning.
    validate_plan(root, plan)
    return plan


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--provider-id", required=True)
    parser.add_argument("--locale", choices=("en", "ar"), default="en")
    parser.add_argument("--dpr", type=float, default=1.0)
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args(argv)

    try:
        plan = build_admin_plan(
            Path(__file__).resolve().parents[1],
            args.provider_id,
            locale=args.locale,
            dpr=args.dpr,
            timeout=args.timeout,
        )
        payload = json.dumps(plan, ensure_ascii=False, indent=2) + "\n"
        if args.output is None:
            sys.stdout.write(payload)
        else:
            if not args.output.is_absolute() or args.output.exists() or args.output.is_symlink():
                raise AdminPlanError("Output path must be a fresh absolute file")
            args.output.write_text(payload, encoding="utf-8")
    except (OSError, ValueError) as exc:
        print("ADMIN VISUAL PLAN REJECTED: " + str(exc), file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
