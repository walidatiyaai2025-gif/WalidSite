#!/usr/bin/env python3
"""Capture the five T007 Laravel screens and emit strict visual diagnostics.

This tool never edits the ADR-010 evidence ledger and never converts a mismatch
into acceptance. It exists so GitHub Actions can produce real browser pixels
for inspection before final zero-difference admission.
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlsplit

from PIL import Image, ImageChops, ImageEnhance

SCREEN_IDS = ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12")
# Owner-frozen WEB-01/03 and the approved WEB-02 no longer render the
# original T007 hero. Keep selector-specific real-browser proof instead of
# reverting the approved templates or silently skipping these pages.
HERO_SELECTORS = {
    "WEB-01": ".w26-landing h1",
    "WEB-02": ".wa-about .about-hero h1",
    "WEB-03": ".expertise-screen .expertise-hero h1",
    "WEB-06": ".t007-reference-stage h1",
    "WEB-12": ".t007-reference-stage h1",
}
NAVIGATION_LINK_SELECTORS = {
    "WEB-01": ".w26-desktop .w26-laptop, .w26-desktop .w26-phone, "
              ".w26-desktop .w26-tablet, .w26-ribbon a, .w26-github a",
    "WEB-02": ".t007-nav-links a, .about-actions a, .about-jump-links a",
    "WEB-03": ".t007-nav-links a, .expertise-hero-actions a, "
              ".expertise-tab-nav a, .expertise-feature a",
    "WEB-06": ".t007-nav-links a, .t007-reference-card a, "
              ".t007-reference-action.secondary",
    "WEB-12": ".t007-nav-links a, .t007-hire-card a, "
              ".t007-reference-action.secondary",
}
RESPONSIVE_VIEWPORTS = ((390, 844), (768, 1024), (1024, 768), (1280, 800))
RESPONSIVE_LOCALES = ("ar", "en")

# WEB-06 currently renders the same visible copy/geometry as its reference, so
# it is the cleanest probe for Chromium/font rasterization. These candidates
# are diagnostics only: the best mismatch count is never promoted to evidence.
RASTER_CANDIDATES = {
    "baseline_disable_lcd": ["--disable-lcd-text"],
    "no_hint": ["--disable-lcd-text", "--font-render-hinting=none"],
    "current_slight_hint": ["--disable-lcd-text", "--font-render-hinting=slight"],
    "medium_hint": ["--disable-lcd-text", "--font-render-hinting=medium"],
    "full_hint": ["--disable-lcd-text", "--font-render-hinting=full"],
    "default": [],
}

# WEB-06 has identical runtime/reference visible copy, so style experiments can
# isolate typography geometry without fabricating personal claims. These rules
# are injected only into diagnostic browser pages and never accepted as proof.
STYLE_CANDIDATES = {
    "current": "",
    "subtitle_geometry": (
        ".t007-reference-subtitle{top:1px!important;letter-spacing:0!important}"
    ),
    "card_link_weight": (
        ".t007-card-link{font-weight:700!important;letter-spacing:1.3px!important}"
    ),
    "card_title_geometry": (
        ".t007-reference-card h2{position:relative!important;top:2px!important;"
        "letter-spacing:1.4px!important}"
    ),
    "h1_down_2": (
        ".t007-reference-hero h1{position:relative!important;top:2px!important}"
    ),
    "subtitle_link_title": (
        ".t007-reference-subtitle{top:1px!important;letter-spacing:0!important}"
        ".t007-card-link{font-weight:700!important;letter-spacing:1.3px!important}"
        ".t007-reference-card h2{position:relative!important;top:2px!important;"
        "letter-spacing:1.4px!important}"
    ),
    "all_geometry": (
        ".t007-reference-hero h1{position:relative!important;top:2px!important}"
        ".t007-reference-subtitle{top:1px!important;letter-spacing:0!important}"
        ".t007-card-link{font-weight:700!important;letter-spacing:1.3px!important}"
        ".t007-reference-card h2{position:relative!important;top:2px!important;"
        "letter-spacing:1.4px!important}"
    ),
}


def select_best_raster_candidate(rows: dict):
    measured = [
        (name, row["mismatching_rgba_pixels"])
        for name, row in rows.items()
        if row.get("status") in ("exact", "mismatch")
        and isinstance(row.get("mismatching_rgba_pixels"), int)
    ]
    return min(measured, key=lambda item: (item[1], item[0]))[0] if measured else None


def rasterization_sweep(base_url: str, reference_path: Path, out: Path) -> dict:
    """Measure browser text-rasterization candidates against immutable WEB-06.

    This never edits the fidelity ledger and never treats a lower mismatch as
    acceptance. It only gives the next implementation step reproducible data.
    """
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("Raster sweep needs an approved HTTPS or local origin")

    with Image.open(reference_path) as source:
        reference = source.convert("RGBA")
        width, height = reference.size

    rows = {}
    with sync_playwright() as playwright:
        for name, browser_args in RASTER_CANDIDATES.items():
            runtime_path = out / f"WEB-06.raster-{name}.png"
            browser = playwright.chromium.launch(headless=True, args=browser_args)
            try:
                context = browser.new_context(
                    viewport={"width": width, "height": height},
                    device_scale_factor=1,
                    locale="en-US",
                    reduced_motion="reduce",
                    color_scheme="light",
                )
                try:
                    page = context.new_page()
                    url = base_url.rstrip("/") + "/ai-engineering?lang=en"
                    response = page.goto(
                        url, wait_until="domcontentloaded", timeout=30000
                    )
                    if response is None or response.status != 200:
                        raise RuntimeError("raster probe route did not return HTTP 200")
                    page.evaluate("() => document.fonts.ready")
                    if page.url.rstrip("/") != url.rstrip("/"):
                        raise RuntimeError("raster probe redirected")
                    html = page.locator("html")
                    if html.get_attribute("lang") != "en" or html.get_attribute("dir") != "ltr":
                        raise RuntimeError("raster probe locale mismatch")
                    page.screenshot(
                        path=str(runtime_path), full_page=False,
                        animations="disabled", caret="hide", type="png",
                    )
                finally:
                    context.close()
            except Exception as exc:
                rows[name] = {"status": "render_failed", "error": type(exc).__name__}
                continue
            finally:
                browser.close()

            with Image.open(runtime_path) as source:
                runtime = source.convert("RGBA")
            if runtime.size != reference.size:
                rows[name] = {
                    "status": "wrong_dimensions",
                    "runtime_size": list(runtime.size),
                    "reference_size": list(reference.size),
                }
                continue
            mismatches = sum(
                actual != expected
                for actual, expected in zip(runtime.getdata(), reference.getdata())
            )
            rows[name] = {
                "status": "exact" if mismatches == 0 else "mismatch",
                "mismatching_rgba_pixels": mismatches,
                "total_pixels": width * height,
                "mismatch_ratio": mismatches / (width * height),
                "browser_args": browser_args,
            }

    best = select_best_raster_candidate(rows)
    return {"candidates": rows, "best": best}


def style_geometry_sweep(base_url: str, reference_path: Path, out: Path) -> dict:
    """Measure candidate WEB-06 CSS geometry without modifying application UI."""
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("Style sweep needs an approved HTTPS or local origin")
    with Image.open(reference_path) as source:
        reference = source.convert("RGBA")
        width, height = reference.size

    rows = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            args=["--disable-lcd-text", "--font-render-hinting=slight"],
        )
        try:
            for name, css in STYLE_CANDIDATES.items():
                runtime_path = out / f"WEB-06.style-{name}.png"
                context = browser.new_context(
                    viewport={"width": width, "height": height},
                    device_scale_factor=1,
                    locale="en-US",
                    reduced_motion="reduce",
                    color_scheme="light",
                )
                try:
                    page = context.new_page()
                    url = base_url.rstrip("/") + "/ai-engineering?lang=en"
                    response = page.goto(
                        url, wait_until="domcontentloaded", timeout=30000
                    )
                    if response is None or response.status != 200:
                        raise RuntimeError("style probe route did not return HTTP 200")
                    page.evaluate("() => document.fonts.ready")
                    if css:
                        page.add_style_tag(content=css)
                    page.screenshot(
                        path=str(runtime_path), full_page=False,
                        animations="disabled", caret="hide", type="png",
                    )
                except Exception as exc:
                    rows[name] = {"status": "render_failed", "error": type(exc).__name__}
                    continue
                finally:
                    context.close()

                with Image.open(runtime_path) as source:
                    runtime = source.convert("RGBA")
                if runtime.size != reference.size:
                    rows[name] = {"status": "wrong_dimensions"}
                    continue
                mismatches = sum(
                    actual != expected
                    for actual, expected in zip(runtime.getdata(), reference.getdata())
                )
                rows[name] = {
                    "status": "exact" if mismatches == 0 else "mismatch",
                    "mismatching_rgba_pixels": mismatches,
                    "total_pixels": width * height,
                    "mismatch_ratio": mismatches / (width * height),
                    "css": css,
                }
        finally:
            browser.close()

    best = select_best_raster_candidate(rows)
    return {"candidates": rows, "best": best}


def capture_arabic_runtime(base_url: str, screens: dict, root: Path, out: Path) -> dict:
    """Capture genuine Arabic RTL browser pixels at each original viewport.

    English source PNGs are NOT Arabic design references: keep separate
    screenshots and never count them as zero-RGBA parity evidence.
    """
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("RTL capture requires approved HTTPS or loopback")
    results = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for screen_id in SCREEN_IDS:
                ref = root / screens[screen_id]["reference_image"]
                with Image.open(ref) as img:
                    width, height = img.size
                destination = out / f"{screen_id}.ar.runtime.png"
                context = browser.new_context(
                    viewport={"width": width, "height": height},
                    device_scale_factor=1,
                    locale="ar-KW",
                    reduced_motion="reduce",
                    color_scheme="light",
                )
                try:
                    page = context.new_page()
                    response = page.goto(
                        base_url.rstrip("/") + screens[screen_id]["route"] + "?lang=ar",
                        wait_until="domcontentloaded", timeout=30000,
                    )
                    if response is None or response.status != 200:
                        raise RuntimeError("Arabic route did not return 200")
                    page.evaluate("() => document.fonts.ready")
                    if (page.locator("html").get_attribute("lang") != "ar"
                        or page.locator("html").get_attribute("dir") != "rtl"
                        or page.locator("main#main-content").count() != 1
                        or page.locator(HERO_SELECTORS[screen_id] + ":visible").count() != 1):
                        raise RuntimeError("RTL identity or landmarks failed")
                    page.screenshot(
                        path=str(destination), full_page=False,
                        animations="disabled", caret="hide", type="png",
                    )
                    with Image.open(destination) as screen:
                        actual_size = list(screen.size)
                    results[screen_id] = {
                        "status": "captured" if actual_size == [width, height] else "wrong_dimensions",
                        "locale": "ar", "direction": "rtl", "size": actual_size,
                        "reference_viewport": [width, height],
                        "pixel_parity": "not_comparable_to_english_reference",
                    }
                except Exception as exc:
                    results[screen_id] = {
                        "status": "capture_failed", "error": type(exc).__name__
                    }
                finally:
                    context.close()
        finally:
            browser.close()
    return results


def arabic_capture_complete(rows: dict) -> bool:
    return (set(rows) == set(SCREEN_IDS)
            and all(isinstance(row, dict) and row.get("status") == "captured"
                    and row.get("locale") == "ar" and row.get("direction") == "rtl"
                    and row.get("size") == row.get("reference_viewport")
                    and row.get("pixel_parity") == "not_comparable_to_english_reference"
                    for row in rows.values()))


def functional_browser_audit(base_url: str, screens: dict) -> dict:
    """Exercise five genuine Laravel visitor journeys in both languages.

    This checks real form submissions/anchor focus and selected recruiter CV
    context, not just HTML strings or a reference image. Every journey
    traverses actual public routes using Playwright Chromium.
    """
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("E2E journey needs approved HTTPS or local origin")

    checks = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for lang in RESPONSIVE_LOCALES:
                context = browser.new_context(
                    viewport={"width": 1440, "height": 860},
                    device_scale_factor=1,
                    locale="ar-KW" if lang == "ar" else "en-US",
                    reduced_motion="reduce",
                )
                try:
                    for screen_id in SCREEN_IDS:
                        key = f"{screen_id}:{lang}"
                        page = context.new_page()
                        try:
                            route = screens[screen_id]["route"]
                            response = page.goto(
                                base_url.rstrip("/") + route + "?lang=" + lang,
                                wait_until="domcontentloaded", timeout=30000,
                            )
                            if response is None or response.status != 200:
                                raise RuntimeError("Initial public journey route inaccessible")
                            if screen_id == "WEB-01":
                                page.locator(".w26-desktop .w26-laptop").click()
                                destination = urlsplit(page.url)
                                if destination.path != "/projects":
                                    raise RuntimeError("Home projects CTA missing")
                            elif screen_id == "WEB-02":
                                page.locator(
                                    ".about-jump-links a[href='#about-certifications']"
                                ).click()
                                destination = urlsplit(page.url)
                                if destination.fragment != "about-certifications" or (
                                    page.locator("#about-certifications").count() != 1
                                ):
                                    raise RuntimeError("About credential anchor missing")
                            elif screen_id == "WEB-03":
                                page.locator("#expertise-category").select_option("database")
                                page.locator("form.expertise-filter button[type='submit']").click()
                                destination = urlsplit(page.url)
                                if (destination.path != "/expertise"
                                    or parse_qs(destination.query).get("category") != ["database"]
                                    or page.locator("#expertise-category").input_value() != "database"):
                                    raise RuntimeError("Expertise filter did not persist")
                                page.locator("form.expertise-filter a").click()
                                destination = urlsplit(page.url)
                                if "category" in parse_qs(destination.query):
                                    raise RuntimeError("Expertise clear filter did not work")
                            elif screen_id == "WEB-06":
                                page.locator(
                                    ".t007-reference-card a[href$='#ai-evidence']"
                                ).first.click()
                                destination = urlsplit(page.url)
                                if destination.fragment != "ai-evidence" or (
                                    page.locator("#ai-evidence").count() != 1
                                ):
                                    raise RuntimeError("AI evidence anchor missing")
                            else:
                                page.locator("#career-track").select_option("delivery")
                                page.locator("form.recruiter-focus button[type='submit']").click()
                                destination = urlsplit(page.url)
                                if (destination.path != "/hire"
                                    or parse_qs(destination.query).get("track") != ["delivery"]
                                    or page.locator("#career-track").input_value() != "delivery"):
                                    raise RuntimeError("Recruiter focus did not persist")
                                page.locator(
                                    ".recruiter-card-links a[href*='/resume']"
                                ).first.click()
                                destination = urlsplit(page.url)
                                if (destination.path != "/resume"
                                    or parse_qs(destination.query).get("track") != ["delivery"]):
                                    raise RuntimeError("Focused CV route lost selected track")
                            if parse_qs(destination.query).get("lang") != [lang]:
                                raise RuntimeError("Visitor journey lost language")
                            if page.locator("main#main-content").count() != 1:
                                raise RuntimeError("Destination missing main landmark")
                            checks[key] = {"passed": True, "locale": lang,
                                           "journey": screen_id}
                        except Exception as exc:
                            checks[key] = {"passed": False, "error": type(exc).__name__,
                                           "reason": str(exc)[:150]}
                        finally:
                            page.close()
                finally:
                    context.close()
        finally:
            browser.close()
    return checks


def functional_audit_complete(rows: dict) -> bool:
    expected = {f"{screen}:{lang}" for screen in SCREEN_IDS
                for lang in RESPONSIVE_LOCALES}
    return (set(rows) == expected
            and all(isinstance(v, dict) and v.get("passed") is True
                    and v.get("locale") in RESPONSIVE_LOCALES
                    and v.get("journey") in SCREEN_IDS
                    for v in rows.values()))


def navigation_browser_audit(base_url: str, screens: dict) -> dict:
    """Follow real T007 hero actions; verify all visible internal links.

    A 200 from a browser navigation is not pixel parity. The links must stay
    same-origin, preserve the selected language, avoid login redirects and
    resolve without inventing projects, CV entries or AI provider data.
    """
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("T007 link audit needs an approved HTTPS or loopback origin")

    origin = urlsplit(base_url)
    results = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for lang in RESPONSIVE_LOCALES:
                context = browser.new_context(
                    viewport={"width": 1440, "height": 860},
                    device_scale_factor=1,
                    locale="ar-KW" if lang == "ar" else "en-US",
                    reduced_motion="reduce",
                )
                try:
                    for screen_id in SCREEN_IDS:
                        key = f"{screen_id}:{lang}"
                        page = context.new_page()
                        try:
                            route = screens[screen_id]["route"]
                            url = base_url.rstrip("/") + route + "?lang=" + lang
                            response = page.goto(url, wait_until="domcontentloaded", timeout=30000)
                            if response is None or response.status != 200:
                                raise RuntimeError("Source screen not accessible")
                            if page.locator("html").get_attribute("lang") != lang:
                                raise RuntimeError("Source screen locale mismatch")

                            links = page.locator(
                                NAVIGATION_LINK_SELECTORS[screen_id]
                            ).evaluate_all("(nodes) => nodes.map(n => n.getAttribute('href'))")
                            if len(links) < 9 or any(not href for href in links):
                                raise RuntimeError("Visible navigation link missing destination")
                            for href in links:
                                destination = urlsplit(urljoin(url, href))
                                if (destination.scheme, destination.netloc) != (origin.scheme, origin.netloc):
                                    raise RuntimeError("T007 navigation escaped its public origin")
                                if parse_qs(destination.query).get("lang") != [lang]:
                                    raise RuntimeError("T007 destination lost the locale")
                                fetched = context.request.get(
                                    urljoin(url, href), timeout=20000, max_redirects=0
                                )
                                if fetched.status != 200:
                                    raise RuntimeError("Destination returned non-200")

                            # Operate BOTH actual approved hero actions in a
                            # browser. The owner-frozen landing/expertise journeys
                            # are not the obsolete T007 reference CTA markup.
                            if screen_id == "WEB-01":
                                page.locator(".w26-desktop .w26-laptop").click()
                                arrived = urlsplit(page.url)
                                if arrived.path != "/projects":
                                    raise RuntimeError("Landing projects action failed")
                                page.goto(url, wait_until="domcontentloaded")
                                page.locator(".w26-desktop .w26-phone").click()
                                arrived = urlsplit(page.url)
                                if arrived.path != "/lab":
                                    raise RuntimeError("Landing knowledge action failed")
                            elif screen_id == "WEB-03":
                                actions = page.locator(".expertise-hero-actions a")
                                if actions.count() != 2:
                                    raise RuntimeError("Expertise hero actions missing")
                                actions.first.click()
                                arrived = urlsplit(page.url)
                                if arrived.path != "/projects":
                                    raise RuntimeError("Expertise evidence action failed")
                                page.goto(url, wait_until="domcontentloaded")
                                page.locator(".expertise-hero-actions a").nth(1).click()
                                arrived = urlsplit(page.url)
                                if (arrived.path != "/expertise"
                                        or arrived.fragment != "expertise-catalog"
                                        or page.locator("details#expertise-catalog summary").count() != 1):
                                    raise RuntimeError("Expertise verified catalog action failed")
                            else:
                                page.locator(".t007-reference-action.primary").click()
                                if urlsplit(page.url).fragment != "t007-primary-content":
                                    raise RuntimeError("Explore action did not reach main content")
                                target = page.locator("#t007-primary-content")
                                if target.count() != 1 or target.get_attribute("tabindex") != "-1":
                                    raise RuntimeError("Explore anchor cannot receive keyboard focus")
                                page.goto(url, wait_until="domcontentloaded")
                                page.locator(".t007-reference-action.secondary").click()
                                arrived = urlsplit(page.url)
                                if screen_id == "WEB-02":
                                    if (arrived.path != "/about"
                                            or parse_qs(arrived.query).get("lang") != [lang]
                                            or arrived.fragment != "about-evidence"):
                                        raise RuntimeError("About evidence CTA missed source section")
                                    proof = page.locator("section#about-evidence")
                                    if (proof.count() != 1
                                            or proof.get_attribute("tabindex") != "-1"
                                            or proof.locator("h2#about-evidence-heading").count() != 1):
                                        raise RuntimeError("About source evidence target not accessible")
                                elif arrived.path != "/projects":
                                    raise RuntimeError("Evidence CTA did not reach projects")
                            if parse_qs(arrived.query).get("lang") != [lang]:
                                raise RuntimeError("Hero journey lost language")
                            if page.locator("main#main-content").count() != 1:
                                raise RuntimeError("Evidence destination missing landmark")
                            results[key] = {"passed": True, "checked_links": len(links),
                                            "hero_actions": 2}
                        except Exception as exc:
                            results[key] = {"passed": False, "error": type(exc).__name__,
                                            "reason": str(exc)[:140]}
                        finally:
                            page.close()
                finally:
                    context.close()
        finally:
            browser.close()
    return results


def navigation_audit_complete(rows: dict) -> bool:
    expected = {f"{screen_id}:{lang}" for screen_id in SCREEN_IDS
                for lang in RESPONSIVE_LOCALES}
    return (set(rows) == expected
            and all(isinstance(row, dict) and row.get("passed") is True
                    and isinstance(row.get("checked_links"), int)
                    and row["checked_links"] >= 9 and row.get("hero_actions") == 2
                    for row in rows.values()))


def responsive_browser_audit(base_url: str, screens: dict) -> dict:
    """Real responsive/keyboard checks; separate from zero-pixel visual proof."""
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("Responsive audit needs an approved HTTPS or local origin")
    results = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for width, height in RESPONSIVE_VIEWPORTS:
                for lang in RESPONSIVE_LOCALES:
                    context = browser.new_context(
                        viewport={"width": width, "height": height},
                        device_scale_factor=1,
                        locale="ar-KW" if lang == "ar" else "en-US",
                        reduced_motion="reduce",
                    )
                    try:
                        for screen_id in SCREEN_IDS:
                            page = context.new_page()
                            key = f"{screen_id}:{lang}:{width}"
                            try:
                                route = screens[screen_id]["route"]
                                url = base_url.rstrip("/") + route + (
                                    "?lang=" + lang
                                )
                                response = page.goto(
                                    url, wait_until="domcontentloaded", timeout=30000
                                )
                                if response is None or response.status != 200:
                                    raise RuntimeError("Non-200 responsive route")
                                page.evaluate("() => document.fonts.ready")
                                # Bind actual frozen/approved route identities to
                                # the real viewport, not an obsolete universal
                                # T007 hero selector. Hidden alternate headings
                                # do not count as a rendered screenshot identity.
                                checks = page.evaluate("""(heroSelector) => {
                                    const main = document.querySelector('#main-content');
                                    const heroes = [...document.querySelectorAll(heroSelector)]
                                        .filter(node => {
                                            const box = node.getBoundingClientRect();
                                            return box.height > 0 && box.width > 0 &&
                                                getComputedStyle(node).visibility !== 'hidden';
                                        });
                                    const landing = Boolean(document.querySelector('.w26-landing'));
                                    const target = document.querySelector(
                                        landing ? '#main-content' : '#t007-primary-content'
                                    );
                                    const nav = document.querySelector(
                                        landing
                                            ? (innerWidth <= 900 ? '.w26-mobile-nav' : '.w26-ribbon')
                                            : '.t007-nav-links'
                                    );
                                    return {
                                        locale_ok: document.documentElement.lang ===
                                            (location.search.includes('lang=ar') ? 'ar' : 'en'),
                                        direction_ok: document.documentElement.dir ===
                                            (location.search.includes('lang=ar') ? 'rtl' : 'ltr'),
                                        no_horizontal_page_overflow:
                                            document.documentElement.scrollWidth <=
                                            window.innerWidth + 1,
                                        main_and_hero: Boolean(main && heroes.length === 1 &&
                                            main.contains(heroes[0])),
                                        jump_target_focusable: Boolean(target &&
                                            target.tabIndex === -1),
                                        nav_present: Boolean(nav &&
                                            nav.querySelectorAll('a').length >= (landing ? 6 : 8)),
                                        // Genuine published evidence cards must wrap
                                        // instead of overflowing their owning card.
                                        card_text_contained: [...document.querySelectorAll(
                                            '.t007-about-row strong, .t007-hire-card h2, ' +
                                            '.t007-hire-card p, .t007-hire-card a'
                                        )].every((node) => {
                                            const owner = node.closest(
                                                '.t007-about-row, .t007-hire-card'
                                            );
                                            if (!owner) return false;
                                            const boundary = owner.getBoundingClientRect();
                                            const box = node.getBoundingClientRect();
                                            return node.scrollWidth <= node.clientWidth + 2 &&
                                                box.left >= boundary.left - 2 &&
                                                box.right <= boundary.right + 2 &&
                                                box.width > 0;
                                        }),
                                    };
                                }""", HERO_SELECTORS[screen_id])
                                # Check focus in the actual visible navigation,
                                # including the mobile home grid and compact rail.
                                checks["all_navigation_links_focus_reachable"] = page.evaluate("""() => {
                                    const landing = Boolean(document.querySelector('.w26-landing'));
                                    const nav = document.querySelector(
                                        landing
                                            ? (innerWidth <= 900 ? '.w26-mobile-nav' : '.w26-ribbon')
                                            : '.t007-nav-links'
                                    );
                                    if (!nav) return false;
                                    const links = [...nav.querySelectorAll('a')];
                                    if (links.length < (landing ? 6 : 8)) return false;
                                    const styles = getComputedStyle(nav);
                                    if (nav.scrollWidth > nav.clientWidth + 1 &&
                                        !['auto', 'scroll'].includes(styles.overflowX)) {
                                        return false;
                                    }
                                    return links.every((link) => {
                                        link.focus();
                                        const box = link.getBoundingClientRect();
                                        const parent = nav.getBoundingClientRect();
                                        return document.activeElement === link &&
                                            box.width > 0 && box.height > 0 &&
                                            box.left >= parent.left - 2 &&
                                            box.right <= parent.right + 2 &&
                                            box.left >= -2 &&
                                            box.right <= innerWidth + 2;
                                    });
                                }""")
                                # The approved home uses the global skip link.
                                # Other T007 routes use the original supplemental
                                # focus-revealed navigation links.
                                page.keyboard.press("Tab")
                                focus_selector = (".skip-link" if screen_id == "WEB-01"
                                                  else ".t007-sr-only")
                                page.locator(focus_selector).first.focus()
                                checks["supplemental_keyboard_link_visible"] = page.evaluate(
                                    """(selector) => {
                                        const link = document.activeElement;
                                        if (!link || !link.matches(selector)) return false;
                                        const css = getComputedStyle(link);
                                        const box = link.getBoundingClientRect();
                                        const acceptablePosition = selector === '.skip-link'
                                            ? css.position === 'absolute'
                                            : css.position === 'fixed';
                                        return acceptablePosition && box.width >= 44 &&
                                            box.height >= 40 && box.top >= 0 &&
                                            box.left >= 0 && box.right <= innerWidth &&
                                            box.bottom <= innerHeight;
                                    }""", focus_selector
                                )
                                results[key] = {
                                    "passed": all(checks.values()), "checks": checks
                                }
                            except Exception as exc:
                                results[key] = {
                                    "passed": False, "error": type(exc).__name__
                                }
                            finally:
                                page.close()
                    finally:
                        context.close()
        finally:
            browser.close()
    return results



def responsive_audit_complete(rows: dict) -> bool:
    """Require the exact supported screen/locale/viewport matrix, not a count.

    A skipped or replaced browser case must fail closed even if the same total
    number of test rows was accidentally reported.
    """
    expected = {
        f"{screen_id}:{lang}:{width}"
        for width, _ in RESPONSIVE_VIEWPORTS
        for lang in RESPONSIVE_LOCALES
        for screen_id in SCREEN_IDS
    }
    return set(rows) == expected and all(
        isinstance(row, dict) and row.get("passed") is True
        for row in rows.values()
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--locale", default="en", choices=("en", "ar"))
    parser.add_argument("--dpr", default=1.0, type=float)
    args = parser.parse_args()

    if len(args.commit) != 40 or any(c not in "0123456789abcdef" for c in args.commit):
        raise SystemExit("A full lowercase renderer commit SHA is required.")

    root = Path(__file__).resolve().parents[1]
    checked_out = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=root,
        check=True,
        text=True,
        capture_output=True,
    ).stdout.strip()
    if checked_out != args.commit:
        raise SystemExit("Renderer commit must equal the checked-out Git HEAD.")

    contracts = json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))
    screens = {item["id"]: item for item in contracts["screens"]}
    out = root / "qa/visual-diagnostics/T007"
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)

    report = {
        "renderer_commit": args.commit,
        "locale": args.locale,
        "device_pixel_ratio": args.dpr,
        "screens": {},
    }

    for screen_id in SCREEN_IDS:
        screen = screens[screen_id]
        reference_path = root / screen["reference_image"]
        runtime_path = out / f"{screen_id}.runtime.png"
        reference_copy = out / f"{screen_id}.reference.png"
        diff_path = out / f"{screen_id}.diff.png"

        with Image.open(reference_path) as source:
            reference = source.convert("RGBA")
            width, height = reference.size
            reference.save(reference_copy)

        env = dict(os.environ)
        env.update({
            "WALIDSITE_CAPTURE_PATH": str(runtime_path),
            "WALIDSITE_CAPTURE_WIDTH": str(width),
            "WALIDSITE_CAPTURE_HEIGHT": str(height),
            "WALIDSITE_CAPTURE_LOCALE": args.locale,
            "WALIDSITE_CAPTURE_DPR": str(args.dpr),
            "WALIDSITE_CAPTURE_ROUTE": screen["route"],
        })
        result = subprocess.run(
            [sys.executable, "qa/render_web_screenshot.py"],
            cwd=root,
            env=env,
            check=False,
        )
        if result.returncode != 0 or not runtime_path.is_file():
            report["screens"][screen_id] = {
                "route": screen["route"],
                "status": "render_failed",
                "reference_size": [width, height],
                "renderer_exit": result.returncode,
            }
            continue

        with Image.open(runtime_path) as source:
            runtime = source.convert("RGBA")

        if runtime.size != reference.size:
            report["screens"][screen_id] = {
                "route": screen["route"],
                "status": "wrong_dimensions",
                "reference_size": list(reference.size),
                "runtime_size": list(runtime.size),
            }
            continue

        mismatches = sum(
            actual != expected
            for actual, expected in zip(runtime.getdata(), reference.getdata())
        )
        difference = ImageChops.difference(runtime, reference).convert("RGB")
        mismatch_bbox = difference.getbbox()
        # Amplify diagnostic differences only. This file is never admissible
        # evidence and is not referenced by the visual fidelity ledger.
        ImageEnhance.Contrast(difference).enhance(4.0).save(diff_path)
        total = width * height
        report["screens"][screen_id] = {
            "route": screen["route"],
            "status": "exact" if mismatches == 0 else "mismatch",
            "reference_size": [width, height],
            "mismatching_rgba_pixels": mismatches,
            "mismatch_bbox": list(mismatch_bbox) if mismatch_bbox else None,
            "total_pixels": total,
            "mismatch_ratio": mismatches / total,
        }

    # Real Arabic captures are UX/provenance evidence only. The immutable
    # English PNGs remain the sole exact visual comparison targets.
    try:
        report["arabic_screens"] = capture_arabic_runtime(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", ""), screens, root, out
        )
    except Exception as exc:
        report["arabic_screens"] = {"setup": {
            "status": "capture_failed", "error": type(exc).__name__
        }}

    try:
        report["rasterization_sweep"] = rasterization_sweep(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", "").rstrip("/"),
            root / screens["WEB-06"]["reference_image"],
            out,
        )
    except Exception as exc:
        report["rasterization_sweep"] = {"error": type(exc).__name__}

    try:
        report["style_geometry_sweep"] = style_geometry_sweep(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", "").rstrip("/"),
            root / screens["WEB-06"]["reference_image"],
            out,
        )
    except Exception as exc:
        report["style_geometry_sweep"] = {"error": type(exc).__name__}

    try:
        from playwright.sync_api import sync_playwright
        base_url = os.environ.get("WALIDSITE_RENDER_BASE_URL", "").rstrip("/")
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                headless=True,
                args=["--disable-lcd-text"],
            )
            try:
                context = browser.new_context(
                    viewport={"width": 1440, "height": 860},
                    device_scale_factor=1,
                    locale="en-US",
                    reduced_motion="reduce",
                    color_scheme="light",
                )
                try:
                    page = context.new_page()
                    response = page.goto(
                        base_url + "/expertise",
                        wait_until="domcontentloaded",
                        timeout=30000,
                    )
                    if response is None or response.status != 200:
                        raise RuntimeError("font probe page did not return HTTP 200")
                    page.evaluate("() => document.fonts.ready")
                    report["font_probe"] = page.evaluate("""() => {
                        const pick = (selector) => {
                            const node = document.querySelector(selector);
                            if (!node) return null;
                            const css = getComputedStyle(node);
                            return {
                                family: css.fontFamily,
                                size: css.fontSize,
                                weight: css.fontWeight,
                                line_height: css.lineHeight,
                                letter_spacing: css.letterSpacing,
                            };
                        };
                        return {
                            inter_400: document.fonts.check('400 16px Inter'),
                            inter_800: document.fonts.check('800 16px Inter'),
                            body: pick('body'),
                            brand: pick('.t007-brand strong'),
                            nav: pick('.t007-nav-links a'),
                            h1: pick('.t007-reference-hero h1'),
                            subtitle: pick('.t007-reference-subtitle'),
                            action: pick('.t007-reference-action'),
                            card_title: pick('.t007-reference-card h2'),
                            card_body: pick('.t007-reference-card p'),
                            card_link: pick('.t007-card-link'),
                        };
                    }""")
                finally:
                    context.close()
            finally:
                browser.close()
    except Exception as exc:
        report["font_probe"] = {"error": type(exc).__name__}

    # Functional E2E assertions cover the five complete web visitor paths.
    try:
        report["functional_audit"] = functional_browser_audit(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", ""), screens
        )
    except Exception as exc:
        report["functional_audit"] = {"setup": {
            "passed": False, "error": type(exc).__name__
        }}

    # Interactive E2E proof complements (but cannot replace) screenshot parity.
    try:
        report["navigation_audit"] = navigation_browser_audit(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", ""), screens
        )
    except Exception as exc:
        report["navigation_audit"] = {"setup": {
            "passed": False, "error": type(exc).__name__
        }}

    # Browser evidence is for responsive/keyboard behavior, not pixel-parity
    # approval. Fail if an AR/EN mobile or tablet route is inaccessible.
    try:
        report["responsive_audit"] = responsive_browser_audit(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", ""), screens
        )
    except Exception as exc:
        report["responsive_audit"] = {"setup": {
            "passed": False, "error": type(exc).__name__
        }}

    report["summary"] = {
        "expected_screens": len(SCREEN_IDS),
        "responsive_checks": len(report["responsive_audit"]),
        "responsive_ok": responsive_audit_complete(report["responsive_audit"]),
        "navigation_checks": len(report["navigation_audit"]),
        "navigation_ok": navigation_audit_complete(report["navigation_audit"]),
        "functional_checks": len(report["functional_audit"]),
        "functional_ok": functional_audit_complete(report["functional_audit"]),
        "arabic_captures": sum(row.get("status") == "captured"
                               for row in report["arabic_screens"].values()),
        "arabic_capture_ok": arabic_capture_complete(report["arabic_screens"]),
        "captured_screens": sum(row["status"] in ("exact", "mismatch")
                                for row in report["screens"].values()),
        "exact_screens": sum(row["status"] == "exact"
                             for row in report["screens"].values()),
        "mismatched_screens": sum(row["status"] == "mismatch"
                                  for row in report["screens"].values()),
        "font_probe_ok": (isinstance(report.get("font_probe"), dict)
                          and "error" not in report["font_probe"]
                          and report["font_probe"].get("inter_400") is True
                          and report["font_probe"].get("inter_800") is True),
    }
    report["summary"]["pixel_parity_complete"] = (
        report["summary"]["exact_screens"] == len(SCREEN_IDS)
        and report["summary"]["font_probe_ok"]
    )
    report_path = out / "report.json"
    report_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(report_path.read_text(encoding="utf-8"))
    # A diagnostic run is only healthy if all five real captures were made and
    # the font probe executed. A pixel mismatch is a measured failure of visual
    # parity, not an infrastructure failure; it remains visible in the report.
    return 1 if (set(report["screens"]) != set(SCREEN_IDS)
                 or any(row["status"] not in ("exact", "mismatch")
                        for row in report["screens"].values())
                 or not report["summary"]["font_probe_ok"]
                 or not report["summary"]["responsive_ok"]
                 or not report["summary"]["navigation_ok"]
                 or not report["summary"]["functional_ok"]
                 or not report["summary"]["arabic_capture_ok"]) else 0


if __name__ == "__main__":
    raise SystemExit(main())
