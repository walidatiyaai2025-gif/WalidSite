#!/usr/bin/env python3
"""Build a safe, source-contract Web visual plan without inventing public content.

Static pages are capturable even with an empty canonical owner CV. Dynamic
WEB-05 and WEB-08 require real, verified PUBLIC slugs. WEB-06 remains
explicitly excluded until its missing Laravel route is merged via T007 #8.
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


WEB_IDS = tuple("WEB-%02d" % number for number in range(1, 15))
DYNAMIC_SLUGS = {"WEB-05": "project_slug", "WEB-08": "article_slug"}
SAFE_SLUG = re.compile(r"[a-zA-Z0-9][a-zA-Z0-9-]{0,199}\Z")
RENDERER = ["python3", "qa/render_web_screenshot.py"]


class WebPlanError(ValueError):
    pass


def build_web_plan(root: Path, *, project_slug: str | None = None,
                   article_slug: str | None = None,
                   include_ai_engineering: bool = False, locale: str = "en",
                   dpr: float = 1.0, timeout: int = 120,
                   require_all: bool = False) -> dict:
    root = root.resolve()
    if type(include_ai_engineering) is not bool:
        raise WebPlanError("AI Engineering inclusion must be explicit")
    if locale not in ("en", "ar"):
        raise WebPlanError("Web capture locale must be ar or en")
    if type(dpr) not in (int, float) or not 0 < dpr <= 8:
        raise WebPlanError("DPR must be numeric within 0..8")
    if type(timeout) is not int or not 1 <= timeout <= 3600:
        raise WebPlanError("Timeout must be whole seconds 1..3600")
    for label, slug in (("project", project_slug), ("article", article_slug)):
        if slug is not None and (
            not isinstance(slug, str) or not SAFE_SLUG.fullmatch(slug)
        ):
            raise WebPlanError("Invalid public %s slug" % label)
    if require_all and (not project_slug or not article_slug or not include_ai_engineering):
        raise WebPlanError("All 14 screens require verified published slugs and WEB-06 availability")

    try:
        screens = json.loads(
            (root / "contracts/screens.json").read_text(encoding="utf-8")
        )["screens"]
    except (OSError, ValueError, KeyError, TypeError) as exc:
        raise WebPlanError("Canonical screen contract is unreadable") from exc
    by_id = {screen.get("id"): screen for screen in screens
             if isinstance(screen, dict)}
    if any(screen_id not in by_id for screen_id in WEB_IDS):
        raise WebPlanError("Canonical contract does not contain 14 Web screens")

    supplied = {"WEB-05": project_slug, "WEB-08": article_slug}
    captures = []
    for screen_id in WEB_IDS:
        # WEB-06 is not yet on main; it is owned by T007 draft PR #499.
        # Never fabricate a 200 response or silently call an absent route captured.
        if screen_id == "WEB-06" and not include_ai_engineering:
            continue
        screen = by_id[screen_id]
        route = screen.get("route")
        if screen.get("surface") != "web" or not isinstance(route, str):
            raise WebPlanError("Screen %s has an invalid Web contract" % screen_id)
        if screen_id in DYNAMIC_SLUGS:
            if route.count("{slug}") != 1:
                raise WebPlanError("%s dynamic route contract has changed" % screen_id)
            if supplied[screen_id] is None:
                continue
            route = route.replace("{slug}", supplied[screen_id])
        elif "{" in route or "}" in route:
            raise WebPlanError("Unexpected dynamic Web route: %s" % screen_id)
        if not route.startswith("/") or route.startswith("//"):
            raise WebPlanError("Unsafe canonical Web route: %s" % screen_id)
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
    # require_all here is WEB-01..14 only, never the full 40-screen app gate.
    if require_all and len(captures) != len(WEB_IDS):
        raise WebPlanError("All 14 Web screens must be present")
    return plan


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project-slug")
    parser.add_argument("--include-ai-engineering", action="store_true")
    parser.add_argument("--article-slug")
    parser.add_argument("--locale", choices=("ar", "en"), default="en")
    parser.add_argument("--dpr", type=float, default=1.0)
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--require-all", action="store_true")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args(argv)
    try:
        plan = build_web_plan(
            Path(__file__).resolve().parents[1],
            project_slug=args.project_slug,
            article_slug=args.article_slug,
            include_ai_engineering=args.include_ai_engineering,
            locale=args.locale, dpr=args.dpr,
            timeout=args.timeout, require_all=args.require_all,
        )
        payload = json.dumps(plan, ensure_ascii=False, indent=2) + "\n"
        if args.output is None:
            sys.stdout.write(payload)
        else:
            if (not args.output.is_absolute() or args.output.exists()
                    or args.output.is_symlink()):
                raise WebPlanError("Output must be a fresh absolute path")
            args.output.write_text(payload, encoding="utf-8")
    except (OSError, ValueError) as exc:
        print("WEB VISUAL PLAN REJECTED: " + str(exc), file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
