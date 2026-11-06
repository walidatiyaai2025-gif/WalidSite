#!/usr/bin/env python3
"""Read-only, real anonymous Laravel Web accessibility and responsive diagnostics.

This never certifies a pixel match, publishes source data, or modifies the
immutable design ledger. It checks twelve static canonical Web routes at
desktop, tablet and mobile sizes. The two dynamic routes require legitimate
published evidence and separate discovery.
"""
from __future__ import annotations

import argparse
import json
import os
import re
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

# Only currently published, evidence-gated public API records may authorize
# a WEB-05 or WEB-08 browser capture. Never invent test slugs, publish an
# unreviewed fixture, or put private URLs/record titles into evidence JSON.
PUBLIC_DETAIL_ENDPOINTS = (
    ("WEB-05", "/api/v1/public/projects?lang=en", "/api/v1/public/projects/"),
    ("WEB-08", "/api/v1/public/articles?lang=en", "/api/v1/public/articles/"),
)
PUBLIC_SLUG = re.compile(r"[A-Za-z0-9][A-Za-z0-9-]{0,199}\Z")
MAX_DISCOVERY_BYTES = 256 * 1024


# A narrow, structural root-cause probe for the sole remaining owner-frozen
# WEB-03 mobile overflow. Never copy text, href, IDs, form values or arbitrary
# CSS class strings from the DOM into artifacts. Only known source class
# prefixes are allowed; this diagnostic cannot certify visual equality.
OVERFLOW_STRUCTURE = r"""() => {
  const width = document.documentElement.clientWidth;
  const maxNodes = 4000;
  const nodes = document.body ? document.body.querySelectorAll('*') : [];
  const candidates = [];
  function safelyClipped(element) {
    for (let parent = element.parentElement; parent && parent !== document.body;
         parent = parent.parentElement) {
      const style = getComputedStyle(parent);
      if (/^(auto|scroll|hidden|clip)$/.test(style.overflowX)) {
        const bounds = parent.getBoundingClientRect();
        if (bounds.left >= -1 && bounds.right <= width + 1) return true;
      }
    }
    return false;
  }
  for (let i = 0; i < Math.min(nodes.length, maxNodes); i++) {
    const element = nodes[i];
    const style = getComputedStyle(element);
    if (style.display === 'none' || style.visibility === 'hidden' ||
        !element.isConnected || element.closest('[hidden],[inert]')) continue;
    const rect = element.getBoundingClientRect();
    if (!rect.width || !rect.height || safelyClipped(element)) continue;
    const overhang = Math.max(0, Math.ceil(rect.right - width),
                              Math.ceil(-rect.left));
    if (overhang <= 1) continue;
    const component = Array.from(element.classList || []).find(
      c => /^(?:expertise-|t007-|site-|wa-)[a-zA-Z0-9_-]{1,48}$/.test(c)
    ) || '';
    candidates.push({
      tag: element.tagName.toLowerCase(),
      component,
      overhang_px: Math.min(100000, overhang)
    });
  }
  candidates.sort((a, b) => b.overhang_px - a.overhang_px);
  return {
    viewport_px: width,
    scanned_nodes: Math.min(nodes.length, maxNodes),
    truncated: nodes.length > maxNodes,
    candidates: candidates.slice(0, 8)
  };
}"""
_ALLOWED_COMPONENT = re.compile(
    r"^(?:expertise-|t007-|site-|wa-)[A-Za-z0-9_-]{1,48}$"
)
_ALLOWED_TAG = re.compile(r"^[a-z][a-z0-9-]{0,23}$")


def safe_overflow_structure(raw: object) -> dict:
    """Strictly bounded, source-class-only Chromium overflow diagnosis."""
    absent = {"status": "unavailable", "candidates": []}
    if not isinstance(raw, dict) or set(raw) != {
        "viewport_px", "scanned_nodes", "truncated", "candidates"
    }:
        return absent
    width, scanned = raw["viewport_px"], raw["scanned_nodes"]
    if (type(width) is not int or not 100 <= width <= 10000 or
            type(scanned) is not int or not 0 <= scanned <= 4000 or
            type(raw["truncated"]) is not bool or
            not isinstance(raw["candidates"], list) or
            len(raw["candidates"]) > 8):
        return absent
    safe = []
    for item in raw["candidates"]:
        if not isinstance(item, dict) or set(item) != {
            "tag", "component", "overhang_px"
        }:
            return absent
        tag, component, px = item["tag"], item["component"], item["overhang_px"]
        if (not isinstance(tag, str) or not _ALLOWED_TAG.fullmatch(tag) or
                not isinstance(component, str) or
                (component != "" and not _ALLOWED_COMPONENT.fullmatch(component)) or
                type(px) is not int or not 2 <= px <= 100000):
            return absent
        safe.append({"tag": tag, "component": component, "overhang_px": px})
    return {
        "status": "structural_hint_only",
        "viewport_px": width,
        "scanned_nodes": scanned,
        "truncated": raw["truncated"],
        "candidates": safe,
    }


def _public_json(response) -> dict:
    """Fail closed on malformed, unbounded or non-JSON public API responses."""
    if (response.status != 200 or
            response.headers.get("content-type", "").split(";", 1)[0]
                .strip().lower() != "application/json"):
        raise AdminSessionError("Public detail discovery HTTP/JSON check failed")
    raw = response.body()
    if not isinstance(raw, bytes) or len(raw) > MAX_DISCOVERY_BYTES:
        raise AdminSessionError("Public detail discovery exceeded response budget")
    try:
        payload = json.loads(raw.decode("utf-8", errors="strict"))
    except (UnicodeError, ValueError):
        raise AdminSessionError("Public detail discovery JSON was invalid") from None
    if not isinstance(payload, dict):
        raise AdminSessionError("Public detail discovery JSON shape was invalid")
    return payload


def _verified_detail_identity(payload: dict, slug: str, screen: str) -> bool:
    """A 200 alone is not publication proof: pin identity and reviewed sources."""
    data = payload.get("data")
    if not isinstance(data, dict) or data.get("slug") != slug:
        return False
    evidence_key = "evidence_urls" if screen == "WEB-05" else "citations"
    sources = data.get(evidence_key)
    return (isinstance(sources, list) and bool(sources) and
            all(isinstance(url, str) and 0 < len(url) <= 2048 and
                url.startswith("https://") for url in sources))


def discover_published_details(browser, base: str) -> dict[str, str]:
    """Discover only anonymous, evidence-backed published detail identities.

    Both list and detail must satisfy strict bounded JSON contracts. The
    matching detail's canonical slug and reviewed source list must agree with
    the listing, not merely return HTTP 200. Only the local test origin is
    queried; never serialize titles, evidence URLs, slugs or response bodies.
    """
    found: dict[str, str] = {}
    context = browser.new_context(
        viewport={"width": 390, "height": 844},
        locale="en-US", reduced_motion="reduce", color_scheme="light",
    )
    try:
        for screen, listing, detail in PUBLIC_DETAIL_ENDPOINTS:
            response = context.request.get(base + listing, timeout=10000,
                                           max_redirects=0)
            payload = _public_json(response)
            data = payload.get("data")
            if not isinstance(data, list) or len(data) > 12:
                raise AdminSessionError("Public detail listing shape is invalid")
            if not data:
                continue
            first = data[0]
            slug = first.get("slug") if isinstance(first, dict) else None
            if not isinstance(slug, str) or PUBLIC_SLUG.fullmatch(slug) is None:
                raise AdminSessionError("Published detail slug was unsafe")
            verified = context.request.get(base + detail + slug + "?lang=en",
                                           timeout=10000, max_redirects=0)
            detail_payload = _public_json(verified)
            if not _verified_detail_identity(detail_payload, slug, screen):
                raise AdminSessionError("Published detail identity or proof was invalid")
            found[screen] = slug
    except AdminSessionError:
        raise
    except Exception:
        # A raw browser/network/response exception can contain source
        # payloads or query URLs. Do not echo or serialize it anywhere.
        raise AdminSessionError("Anonymous public detail discovery failed") from None
    finally:
        context.close()
    return found


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

    # Source and static-route assertions precede all browser/network access.
    for locale in ("en", "ar"):
        if len(build_web_plan(root, locale=locale)["captures"]) != WEB_STATIC_COUNT:
            raise AdminSessionError("Expected exactly 12 source-backed static Web routes")
    rows = []
    discovered: dict[str, str] = {}
    manager = playwright_manager if playwright_manager is not None else _playwright()
    with manager as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            discovered = discover_published_details(browser, base)
            plans = {
                locale: build_web_plan(
                    root, locale=locale,
                    project_slug=discovered.get("WEB-05"),
                    article_slug=discovered.get("WEB-08"),
                )
                for locale in ("en", "ar")
            }
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
                                row = {
                                    "screen": entry["screen_id"],
                                    "locale": locale,
                                    "viewport": viewport,
                                    "width": width,
                                    "height": height,
                                    "status": "pass" if not defects else "defect",
                                    "defects": defects,
                                }
                                if "document_horizontal_overflow" in defects:
                                    # Execute only for an actual overflow defect,
                                    # never on a private Admin/browser session.
                                    try:
                                        raw = page.evaluate(OVERFLOW_STRUCTURE)
                                    except Exception:
                                        raw = None
                                    row["overflow_structure"] = safe_overflow_structure(raw)
                                rows.append(row)
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
    extra = len(discovered) * 2 * len(VIEWPORTS)
    if (len(rows) != MAX_CHECKS + extra or
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
        "dynamic_web_pending": [
            name for name in ("WEB-05", "WEB-08") if name not in discovered
        ],
        "dynamic_web_verified": sorted(discovered),
        "static_checks": MAX_CHECKS,
        "dynamic_checks": extra,
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
