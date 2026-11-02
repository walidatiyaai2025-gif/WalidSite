#!/usr/bin/env python3
"""Capture T012 public screens with real Laravel + Chromium pixels.

This diagnostic never edits the visual-fidelity evidence ledger and never
turns a mismatch into acceptance. WEB-13 reaches its result state through the
real search form instead of smuggling a query into the generic renderer.
"""
import argparse
import json
import shutil
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

from PIL import Image, ImageChops, ImageEnhance
from playwright.sync_api import sync_playwright

SCREEN_IDS = ("WEB-07", "WEB-08", "WEB-09", "WEB-13")
ROUTES = {
    "WEB-07": "/blog",
    "WEB-08": "/blog/visual-article-sql-failover",
    "WEB-09": "/lab",
    "WEB-13": "/search",
}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--locale", default="en", choices=("en", "ar"))
    parser.add_argument("--dpr", default=1.0, type=float)
    args = parser.parse_args()

    if len(args.commit) != 40 or any(c not in "0123456789abcdef" for c in args.commit):
        raise SystemExit("A full lowercase renderer commit SHA is required.")
    base = urlsplit(args.base_url)
    if base.scheme != "http" or base.hostname not in ("127.0.0.1", "localhost") or base.query or base.fragment:
        raise SystemExit("T012 CI capture requires a clean local loopback HTTP origin.")

    root = Path(__file__).resolve().parents[1]
    checked_out = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=root, check=True,
        text=True, capture_output=True,
    ).stdout.strip()
    if checked_out != args.commit:
        raise SystemExit("Renderer commit must equal checked-out Git HEAD.")

    screens = {
        row["id"]: row
        for row in json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))["screens"]
    }
    out = root / "qa/visual-diagnostics/T012"
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)

    report = {
        "renderer_commit": args.commit,
        "locale": args.locale,
        "device_pixel_ratio": args.dpr,
        "screens": {},
    }

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
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

                if width % args.dpr or height % args.dpr:
                    raise SystemExit("Reference dimensions must be divisible by DPR.")
                context = browser.new_context(
                    viewport={"width": int(width / args.dpr), "height": int(height / args.dpr)},
                    device_scale_factor=args.dpr,
                    locale="ar-KW" if args.locale == "ar" else "en-US",
                    reduced_motion="reduce",
                    color_scheme="light",
                )
                try:
                    page = context.new_page()
                    route = ROUTES[screen_id]
                    suffix = ("?lang=ar" if args.locale == "ar" else "")
                    response = page.goto(args.base_url.rstrip("/") + route + suffix,
                                         wait_until="domcontentloaded", timeout=30000)
                    if response is None or response.status != 200:
                        report["screens"][screen_id] = {
                            "route": route, "status": "render_failed",
                            "reference_size": [width, height],
                            "http_status": None if response is None else response.status,
                        }
                        continue
                    page.evaluate("() => document.fonts.ready")

                    if screen_id == "WEB-13":
                        query = page.locator('input[name="q"]').first
                        query.fill("visualsearch")
                        query.press("Enter")
                        page.wait_for_load_state("domcontentloaded")
                        page.evaluate("() => document.fonts.ready")
                        if "visualsearch" not in page.url:
                            report["screens"][screen_id] = {
                                "route": route, "status": "render_failed",
                                "reference_size": [width, height],
                                "reason": "search form did not reach result URL",
                            }
                            continue

                    page.locator(".t012-reference-stage").wait_for(state="visible", timeout=15000)
                    page.screenshot(path=str(runtime_path), full_page=False,
                                    animations="disabled", caret="hide", type="png")

                    if screen_id == "WEB-07":
                        report["font_probe"] = page.evaluate("""() => {
                            const pick = (selector) => {
                                const node = document.querySelector(selector);
                                if (!node) return null;
                                const css = getComputedStyle(node);
                                return {
                                    family: css.fontFamily, size: css.fontSize,
                                    weight: css.fontWeight, line_height: css.lineHeight,
                                    letter_spacing: css.letterSpacing,
                                };
                            };
                            return {
                                inter_400: document.fonts.check('400 16px Inter'),
                                inter_800: document.fonts.check('800 16px Inter'),
                                brand: pick('.t012-brand strong'),
                                nav: pick('.t012-nav-links a'),
                                h1: pick('.t012-reference-hero h1'),
                                subtitle: pick('.t012-reference-subtitle'),
                                card_title: pick('.t012-reference-card h2'),
                            };
                        }""")
                finally:
                    context.close()

                with Image.open(runtime_path) as source:
                    runtime = source.convert("RGBA")
                if runtime.size != reference.size:
                    report["screens"][screen_id] = {
                        "route": ROUTES[screen_id], "status": "wrong_dimensions",
                        "reference_size": list(reference.size), "runtime_size": list(runtime.size),
                    }
                    continue
                mismatches = sum(a != b for a, b in zip(runtime.getdata(), reference.getdata()))
                difference = ImageChops.difference(runtime, reference).convert("RGB")
                ImageEnhance.Contrast(difference).enhance(4.0).save(diff_path)
                total = width * height
                report["screens"][screen_id] = {
                    "route": ROUTES[screen_id],
                    "status": "exact" if mismatches == 0 else "mismatch",
                    "reference_size": [width, height],
                    "mismatching_rgba_pixels": mismatches,
                    "total_pixels": total,
                    "mismatch_ratio": mismatches / total,
                }
        finally:
            browser.close()

    font = report.get("font_probe", {})
    report["summary"] = {
        "expected_screens": len(SCREEN_IDS),
        "captured_screens": sum(
            row["status"] in ("exact", "mismatch") for row in report["screens"].values()
        ),
        "exact_screens": sum(row["status"] == "exact" for row in report["screens"].values()),
        "mismatched_screens": sum(row["status"] == "mismatch" for row in report["screens"].values()),
        "font_probe_ok": font.get("inter_400") is True and font.get("inter_800") is True,
    }
    report["summary"]["pixel_parity_complete"] = (
        report["summary"]["exact_screens"] == len(SCREEN_IDS)
        and report["summary"]["font_probe_ok"]
    )
    report_path = out / "report.json"
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(report_path.read_text(encoding="utf-8"))

    healthy = (
        set(report["screens"]) == set(SCREEN_IDS)
        and all(row["status"] in ("exact", "mismatch") for row in report["screens"].values())
        and report["summary"]["font_probe_ok"]
    )
    return 0 if healthy else 1


if __name__ == "__main__":
    raise SystemExit(main())
