#!/usr/bin/env python3
"""Read-only, authenticated Admin browser accessibility and responsive audit.

An audit is diagnostic evidence, NEVER proof of screenshot pixel parity.
Do not run against production; existing private Laravel session is required.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import stat
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit

try:
    from qa.admin_visual_session import AdminSessionError, local_origin, _playwright
    from qa.build_admin_visual_plan import ADMIN_IDS, build_admin_plan
    from qa.render_admin_screenshot import expected_heading
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from admin_visual_session import AdminSessionError, local_origin, _playwright
    from build_admin_visual_plan import ADMIN_IDS, build_admin_plan
    from render_admin_screenshot import expected_heading


VIEWPORTS = (("desktop", 1440, 900), ("mobile", 390, 844))
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
MAX_CHECKS = len(ADMIN_IDS) * 2 * len(VIEWPORTS)

# Executed only inside the trusted local Chromium process. It returns counts,
# not element names, page text, cookies or values from private Admin records.
DOM_METRICS = r"""() => {
  const active = document.documentElement;
  const controls = Array.from(document.querySelectorAll(
    'a[href],button,input:not([type="hidden"]),select,textarea,[role="button"]'
  )).filter(el => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.display !== 'none' &&
      s.visibility !== 'hidden' && !el.closest('[hidden],[inert]');
  });
  function named(el) {
    if ((el.getAttribute('aria-label') || '').trim()) return true;
    const by = (el.getAttribute('aria-labelledby') || '').trim().split(/\s+/);
    if (by.some(id => id && document.getElementById(id)?.textContent?.trim())) return true;
    if ((el.innerText || el.textContent || '').trim()) return true;
    if ((el.getAttribute('title') || '').trim()) return true;
    if ((el.getAttribute('alt') || '').trim()) return true;
    if ((el.labels && Array.from(el.labels).some(l => l.textContent.trim()))) return true;
    if ((el.getAttribute('type') || '').toLowerCase() === 'submit' &&
        (el.getAttribute('value') || '').trim()) return true;
    return false;
  }
  const invalidTargets = controls.filter(el => {
    if (el.tagName.toLowerCase() !== 'a') return false;
    const href = el.getAttribute('href') || '';
    return !href || href === '#' || /^\s*javascript:/i.test(href);
  }).length;
  const width = Math.max(document.body.scrollWidth, active.scrollWidth);
  return {
    overflow_px: Math.max(0, width - active.clientWidth),
    unnamed: controls.filter(el => !named(el)).length,
    unsafe_links: invalidTargets,
    controls: controls.length,
    skip: !!document.querySelector('a[href="#content"]'),
    main: document.querySelectorAll('main#content').length
  };
}"""


def private_state(path: Path) -> Path:
    if (not path.is_absolute() or path.is_symlink() or not path.is_file()
            or path.parent.is_symlink()
            or stat.S_IMODE(path.stat().st_mode) != 0o600
            or stat.S_IMODE(path.parent.stat().st_mode) != 0o700
            or path.stat().st_uid != os.getuid()):
        raise AdminSessionError("Private authenticated Admin session required")
    return path


def fresh_output(path: Path) -> Path:
    if (not path.is_absolute() or path.exists() or path.is_symlink()
            or not path.parent.is_dir() or path.parent.is_symlink()):
        raise AdminSessionError("Report must be a fresh absolute file in a real directory")
    return path


def exact_checkout(root: Path, expected: str) -> None:
    if not isinstance(expected, str) or not SHA_RE.fullmatch(expected):
        raise AdminSessionError("A canonical exact source SHA is required")
    try:
        sha = subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=root, text=True, stderr=subprocess.DEVNULL
        ).strip()
        dirty = subprocess.check_output(
            ["git", "status", "--porcelain=v1", "--untracked-files=all"],
            cwd=root, text=True, stderr=subprocess.DEVNULL,
        )
    except (OSError, subprocess.CalledProcessError) as exc:
        raise AdminSessionError("Source checkout was not verified") from exc
    if sha != expected or dirty:
        raise AdminSessionError("Source SHA mismatch or repository is not clean")


def metric_defects(metrics: object) -> list[str]:
    """Pure, schema-checked defects. Never include source page content."""
    if not isinstance(metrics, dict) or set(metrics) != {
            "overflow_px", "unnamed", "unsafe_links", "controls", "skip", "main"
    }:
        return ["invalid_dom_metrics"]
    ints = ("overflow_px", "unnamed", "unsafe_links", "controls", "main")
    if any(type(metrics[x]) is not int or metrics[x] < 0 for x in ints):
        return ["invalid_dom_metrics"]
    if type(metrics["skip"]) is not bool:
        return ["invalid_dom_metrics"]
    defects = []
    if metrics["main"] != 1:
        defects.append("invalid_main_landmark")
    if not metrics["skip"]:
        defects.append("missing_skip_navigation")
    if metrics["overflow_px"] > 1:
        defects.append("document_horizontal_overflow")
    if metrics["unnamed"]:
        defects.append("unnamed_visible_controls")
    if metrics["unsafe_links"]:
        defects.append("inert_or_script_links")
    if not metrics["controls"]:
        defects.append("missing_interactive_controls")
    return defects


def inspect_page(page, url: str, locale: str, heading: str) -> list[str]:
    """Check actual current authenticated page; return only stable error codes."""
    try:
        response = page.goto(url, wait_until="domcontentloaded", timeout=30000)
        if response is None or response.status != 200 or page.url != url:
            return ["http_auth_or_route_failure"]
        html = page.locator("html")
        if (html.get_attribute("lang") != locale
                or html.get_attribute("dir") != ("rtl" if locale == "ar" else "ltr")):
            return ["locale_or_direction_mismatch"]
        h1 = page.locator("main#content h1")
        if h1.count() != 1 or h1.inner_text().strip() != heading:
            return ["screen_identity_mismatch"]
        metrics = page.evaluate(DOM_METRICS)
        defects = metric_defects(metrics)
        if "invalid_dom_metrics" not in defects:
            page.keyboard.press("Tab")
            keyboard_ok = page.evaluate(
                "() => document.activeElement?.matches('a[href=\"#content\"]') === true"
            )
            if keyboard_ok is not True:
                defects.append("keyboard_skip_focus_failure")
        return defects
    except Exception:
        # Browser, network and DOM errors must not log private page text.
        return ["runtime_inspection_failure"]


def audit_admin(origin: str, state: Path, provider_id: str, sha: str,
                *, root: Path | None = None, playwright_manager=None) -> dict:
    base = local_origin(origin)
    state = private_state(state)
    root = (root or Path(__file__).resolve().parents[1]).resolve()
    exact_checkout(root, sha)
    # Both language matrices are bound to the immutable 13 Admin routes.
    plans = {
        language: build_admin_plan(root, provider_id, locale=language)
        for language in ("en", "ar")
    }
    rows = []
    manager = playwright_manager if playwright_manager is not None else _playwright()
    with manager as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for language, plan in plans.items():
                for viewport, width, height in VIEWPORTS:
                    context = browser.new_context(
                        storage_state=str(state),
                        viewport={"width": width, "height": height},
                        locale="ar-KW" if language == "ar" else "en-US",
                        reduced_motion="reduce",
                        color_scheme="light",
                    )
                    try:
                        # Fail closed: never visit a remote URL or transmit a
                        # local authenticated browser state to an external host.
                        def restrict_network(route):
                            request_url = urlsplit(route.request.url)
                            if (request_url.scheme, request_url.netloc) == (
                                    urlsplit(base).scheme, urlsplit(base).netloc):
                                route.continue_()
                            else:
                                route.abort()
                        context.route("**/*", restrict_network)
                        for entry in plan["captures"]:
                            page = context.new_page()
                            try:
                                url = base + entry["route"] + "?lang=" + language
                                defects = inspect_page(
                                    page, url, language,
                                    expected_heading(entry["route"], language),
                                )
                                rows.append({
                                    "screen": entry["screen_id"],
                                    "locale": language,
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
    if len(rows) != MAX_CHECKS or {
            (row["screen"], row["locale"], row["viewport"]) for row in rows
    } != {
            (screen, lang, view[0])
            for screen in ADMIN_IDS for lang in ("en", "ar") for view in VIEWPORTS
    }:
        raise AdminSessionError("Admin runtime matrix was incomplete")
    return {
        "schema": 1,
        "tested_sha": sha,
        "scope": "authenticated_admin_runtime_accessibility_diagnostics_only",
        "visual_parity": "not_attested",
        "screen_checks": len(rows),
        "failed_checks": sum(row["status"] != "pass" for row in rows),
        "results": rows,
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--provider-id", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        destination = fresh_output(args.output)
        state = private_state(Path(os.environ.get("WALIDSITE_CAPTURE_STORAGE_STATE", "")))
        report = audit_admin(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", ""),
            state, args.provider_id, args.commit,
        )
        fd = os.open(destination, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(report, stream, ensure_ascii=False, sort_keys=True, indent=2)
            stream.write("\n")
    except Exception:
        print("ADMIN RUNTIME AUDIT REJECTED: source/session/route/runtime verification failed",
              file=sys.stderr)
        return 2
    print(f"ADMIN RUNTIME AUDIT: {report['screen_checks']} actual page checks, "
          f"{report['failed_checks']} checks with defects; visual parity NOT attested")
    return 1 if report["failed_checks"] else 0


if __name__ == "__main__":
    sys.exit(main())
