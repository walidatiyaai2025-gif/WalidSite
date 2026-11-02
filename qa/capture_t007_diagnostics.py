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

from PIL import Image, ImageChops, ImageEnhance

SCREEN_IDS = ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12")

# WEB-06 currently renders the same visible copy/geometry as its reference, so
# it is the cleanest probe for Chromium/font rasterization. These candidates
# are diagnostics only: the best mismatch count is never promoted to evidence.
RASTER_CANDIDATES = {
    "current": ["--disable-lcd-text"],
    "no_hint": ["--disable-lcd-text", "--font-render-hinting=none"],
    "slight_hint": ["--disable-lcd-text", "--font-render-hinting=slight"],
    "default": [],
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


def responsive_browser_audit(base_url: str, screens: dict) -> dict:
    """Real responsive/keyboard checks; separate from zero-pixel visual proof."""
    from playwright.sync_api import sync_playwright

    if not base_url.startswith(("https://", "http://127.0.0.1:", "http://localhost:")):
        raise ValueError("Responsive audit needs an approved HTTPS or local origin")
    results = {}
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for width, height in ((390, 844), (768, 1024)):
                for lang in ("ar", "en"):
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
                                checks = page.evaluate("""() => {
                                    const main = document.querySelector('#main-content');
                                    const hero = document.querySelector('.t007-reference-stage h1');
                                    const target = document.querySelector('#t007-primary-content');
                                    const nav = document.querySelector('.t007-nav-links');
                                    return {
                                        locale_ok: document.documentElement.lang ===
                                            (location.search.includes('lang=ar') ? 'ar' : 'en'),
                                        direction_ok: document.documentElement.dir ===
                                            (location.search.includes('lang=ar') ? 'rtl' : 'ltr'),
                                        no_horizontal_page_overflow:
                                            document.documentElement.scrollWidth <=
                                            window.innerWidth + 1,
                                        main_and_hero: Boolean(main && hero &&
                                            hero.getBoundingClientRect().height > 0),
                                        jump_target_focusable: Boolean(target &&
                                            target.tabIndex === -1),
                                        nav_present: Boolean(nav && nav.querySelectorAll('a').length >= 8),
                                    };
                                }""")
                                # Enter keyboard modality and focus the hidden
                                # secondary navigation; its visible focus state
                                # must not remain clipped from sight.
                                page.keyboard.press("Tab")
                                page.locator(".t007-sr-only").first.focus()
                                checks["supplemental_keyboard_link_visible"] = page.evaluate(
                                    """() => {
                                        const link = document.activeElement;
                                        const css = getComputedStyle(link);
                                        const box = link.getBoundingClientRect();
                                        return link.classList.contains('t007-sr-only') &&
                                            css.position === 'fixed' && box.width >= 44 &&
                                            box.height >= 40 && box.top >= 0 &&
                                            box.left >= 0 && box.right <= innerWidth &&
                                            box.bottom <= innerHeight;
                                    }"""
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
        # Amplify diagnostic differences only. This file is never admissible
        # evidence and is not referenced by the visual fidelity ledger.
        ImageEnhance.Contrast(difference).enhance(4.0).save(diff_path)
        total = width * height
        report["screens"][screen_id] = {
            "route": screen["route"],
            "status": "exact" if mismatches == 0 else "mismatch",
            "reference_size": [width, height],
            "mismatching_rgba_pixels": mismatches,
            "total_pixels": total,
            "mismatch_ratio": mismatches / total,
        }

    try:
        report["rasterization_sweep"] = rasterization_sweep(
            os.environ.get("WALIDSITE_RENDER_BASE_URL", "").rstrip("/"),
            root / screens["WEB-06"]["reference_image"],
            out,
        )
    except Exception as exc:
        report["rasterization_sweep"] = {"error": type(exc).__name__}

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
        "responsive_ok": (len(report["responsive_audit"]) == 20 and
            all(row["passed"] for row in report["responsive_audit"].values())),
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
                 or not report["summary"]["responsive_ok"]) else 0


if __name__ == "__main__":
    raise SystemExit(main())
