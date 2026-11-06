#!/usr/bin/env python3
"""Read-only, real anonymous Laravel Web accessibility and responsive diagnostics.

This never certifies a pixel match, publishes source data, or modifies the
immutable design ledger. It checks twelve static canonical Web routes; the two
dynamic routes require legitimate published evidence and separate discovery.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path
from urllib.parse import urlsplit

# Supports both unittest package import and direct CI CLI execution:
# python3 -B qa/audit_web_runtime.py --commit ... --output ...
try:
    from qa.admin_visual_session import AdminSessionError, local_origin, _playwright
    from qa.audit_admin_runtime import (
        DOM_METRICS as ADMIN_DOM_METRICS, VIEWPORTS, exact_checkout,
        fresh_output, metric_defects,
    )
    from qa.build_web_visual_plan import build_web_plan
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from admin_visual_session import AdminSessionError, local_origin, _playwright
    from audit_admin_runtime import (
        DOM_METRICS as ADMIN_DOM_METRICS, VIEWPORTS, exact_checkout,
        fresh_output, metric_defects,
    )
    from build_web_visual_plan import build_web_plan

# Public Blade uses main#main-content / #main-content, not Admin's #content.
# Reuse the independently tested accessibility metric schema unchanged.
WEB_DOM_METRICS = ADMIN_DOM_METRICS.replace("#content", "#main-content")
WEB_STATIC_COUNT = 12
MAX_CHECKS = WEB_STATIC_COUNT * 2 * len(VIEWPORTS)
FATAL_CODES = frozenset({
    "http_or_route_failure", "locale_or_direction_mismatch",
    "runtime_inspection_failure",
})


def inspect_page(page, url: str, locale: str) -> list[str]:
    """Inspect actual rendered controls; return codes only, no page content."""
    try:
        response = page.goto(url, wait_until="domcontentloaded", timeout=30000)
        if response is None or response.status != 200 or page.url != url:
            return ["http_or_route_failure"]
        html = page.locator("html")
        if (html.get_attribute("lang") != locale
                or html.get_attribute("dir") != ("rtl" if locale == "ar" else "ltr")):
            return ["locale_or_direction_mismatch"]

        headings = page.locator("main#main-content h1:visible")
        defects = []
        if headings.count() == 0:
            defects.append("missing_visible_h1")
        elif headings.count() > 1:
            defects.append("multiple_visible_h1")

        defects.extend(metric_defects(page.evaluate(WEB_DOM_METRICS)))
        if "invalid_dom_metrics" not in defects:
            page.keyboard.press("Tab")
            keyboard_ok = page.evaluate(
                """() => document.activeElement?.matches(
                    'a[href="#main-content"]') === true"""
            )
            if keyboard_ok is not True:
                defects.append("keyboard_skip_focus_failure")
        if page.url != url or html.get_attribute("lang") != locale:
            return ["http_or_route_failure"]
        return defects
    except Exception:
        # Never echo an exception that may contain a page URL, contact or secret.
        return ["runtime_inspection_failure"]


def audit_web(origin: str, sha: str, *, root: Path | None = None,
              playwright_manager=None) -> dict:
    base = local_origin(origin)
    if os.environ.get("WALIDSITE_CAPTURE_STORAGE_STATE"):
        raise AdminSessionError("Public Web audit must not inherit private storage")
    root = (root or Path(__file__).resolve().parents[1]).resolve()
    exact_checkout(root, sha)

    plans = {locale: build_web_plan(root, locale=locale)
             for locale in ("en", "ar")}
    if any(len(plan["captures"]) != WEB_STATIC_COUNT for plan in plans.values()):
        raise AdminSessionError("Expected exactly 12 source-backed static Web routes")
    rows = []
    manager = playwright_manager if playwright_manager is not None else _playwright()
    with manager as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for locale, plan in plans.items():
                for viewport, width, height in VIEWPORTS:
                    # Fresh unauthenticated context; never reuse Admin cookies.
                    context = browser.new_context(
                        viewport={"width": width, "height": height},
                        locale="ar-KW" if locale == "ar" else "en-US",
                        reduced_motion="reduce",
                        color_scheme="light",
                    )
                    try:
                        expected = urlsplit(base)
                        def restrict_network(route):
                            destination = urlsplit(route.request.url)
                            if (destination.scheme, destination.netloc) == (
                                    expected.scheme, expected.netloc):
                                route.continue_()
                            else:
                                route.abort()
                        context.route("**/*", restrict_network)
                        for entry in plan["captures"]:
                            page = context.new_page()
                            try:
                                target = base + entry["route"] + "?lang=" + locale
                                defects = inspect_page(page, target, locale)
                                rows.append({
                                    "screen": entry["screen_id"],
                                    "locale": locale,
                                    "viewport": viewport,
                                    "width": width,
                                    "height": height,
                                    "status": "pass" if not defects else "defect",
                                    "defects": defects,
                                })
                            finally:
                                page.close()
                    finally:
                        context.close()
        finally:
            browser.close()

    expected_keys = {
        (row["screen_id"], locale, viewport[0])
        for locale, plan in plans.items()
        for row in plan["captures"]
        for viewport in VIEWPORTS
    }
    if (len(rows) != MAX_CHECKS or
            {(row["screen"], row["locale"], row["viewport"])
             for row in rows} != expected_keys):
        raise AdminSessionError("Public Web runtime audit matrix incomplete")
    exact_checkout(root, sha)
    fatal = sum(bool(set(row["defects"]) & FATAL_CODES) for row in rows)
    return {
        "schema": 1,
        "tested_sha": sha,
        "scope": "anonymous_public_web_runtime_accessibility_diagnostics_only",
        "visual_parity": "not_attested",
        "evidence_admitted": False,
        "dynamic_web_pending": ["WEB-05", "WEB-08"],
        "screen_checks": len(rows),
        "failed_checks": sum(row["status"] == "defect" for row in rows),
        "fatal_checks": fatal,
        "results": rows,
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        destination = fresh_output(args.output)
        report = audit_web(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", ""), args.commit
        )
        fd = os.open(destination, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(report, stream, ensure_ascii=False, sort_keys=True, indent=2)
            stream.write("\n")
    except Exception:
        print("WEB RUNTIME AUDIT REJECTED: source/origin/runtime verification failed",
              file=sys.stderr)
        return 2
    print("WEB RUNTIME AUDIT: "
          f"{report['screen_checks']} actual public page checks; "
          f"{report['failed_checks']} accessibility/responsive defects; "
          f"{report['fatal_checks']} fatal runtime errors; "
          "visual parity NOT attested")
    if report["fatal_checks"]:
        return 2
    return 1 if report["failed_checks"] else 0


if __name__ == "__main__":
    sys.exit(main())
