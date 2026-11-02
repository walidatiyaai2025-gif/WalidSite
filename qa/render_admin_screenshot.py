#!/usr/bin/env python3
"""Capture actual Laravel ADMIN-01..13 pixels from an authenticated local session.

Used as a renderer by qa/capture_visual_evidence.py or qa/visual_diagnostics.py.
This module NEVER certifies that a screenshot matches immutable reference art.
"""
import io
import os
import re
import stat
import sys
from pathlib import Path

from PIL import Image, UnidentifiedImageError

try:
    from qa.admin_visual_session import AdminSessionError, local_origin, _playwright
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from admin_visual_session import AdminSessionError, local_origin, _playwright


# These are the actual h1 values in versioned Laravel Blade views, not copied
# reference-art labels. A valid 200 response with a wrong UI is NOT a capture.
STATIC_HEADINGS = {
    "/admin": ("Administration overview", "نظرة عامة"),
    "/admin/content": ("Content review studio", "استوديو مراجعة المحتوى"),
    "/admin/ai/providers": ("AI providers", "مزودو الذكاء الاصطناعي"),
    "/admin/ai/routing": ("AI provider routing", "توجيه مزودي الذكاء الاصطناعي"),
    "/admin/ai/images": ("Media rights review", "مراجعة حقوق الصور"),
    "/admin/quality": ("Quality gates", "بوابات الجودة"),
    "/admin/github": ("GitHub Sync", "مزامنة GitHub"),
    "/admin/projects": ("Projects & CV", "المشاريع والسيرة المهنية"),
    "/admin/audience": ("Audience & consent", "الجمهور والموافقة"),
    "/admin/analytics": ("Verified analytics", "التحليلات المتحقّق منها"),
    "/admin/approvals": ("Approvals and exception review", "الموافقات والمراجعة الاستثنائية"),
    "/admin/audit": ("Audit & security", "التدقيق والأمان"),
}
PROVIDER_EDITOR_HEADINGS = ("Edit AI provider", "تحرير مزود AI")
# Real Laravel route model binding for ADMIN-04. No arbitrary path segments,
# encoded slashes, query parameters, credentials or browser redirect targets.
_PROVIDER_ROUTE = re.compile(
    r"/admin/ai/providers/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-"
    r"[0-9a-f]{4}-[0-9a-f]{12}\Z"
)
MAX_PNG_BYTES = 64 * 1024 * 1024


def expected_heading(route: str, locale: str) -> str:
    if locale not in ("en", "ar"):
        raise AdminSessionError("Invalid Admin capture locale")
    if route in STATIC_HEADINGS:
        titles = STATIC_HEADINGS[route]
    elif isinstance(route, str) and _PROVIDER_ROUTE.fullmatch(route):
        titles = PROVIDER_EDITOR_HEADINGS
    else:
        raise AdminSessionError("Admin renderer requires a canonical screen route")
    return titles[0 if locale == "en" else 1]


def _verify_page(page, url: str, locale: str, heading: str) -> None:
    if page.url != url:
        raise AdminSessionError("Protected Admin capture navigated away from target")
    html = page.locator("html")
    if (html.get_attribute("lang") != locale
            or html.get_attribute("dir") != ("rtl" if locale == "ar" else "ltr")
            or page.locator("main#content").count() != 1):
        raise AdminSessionError("Admin page is not the expected localized screen")
    title = page.locator("main#content h1")
    if title.count() != 1 or title.inner_text().strip() != heading:
        raise AdminSessionError("Admin screen heading does not match canonical route")


def render(env=None, playwright_manager=None) -> Path:
    env = os.environ if env is None else env
    base = local_origin(env.get("WALIDSITE_RENDER_BASE_URL", ""))
    route = env.get("WALIDSITE_CAPTURE_ROUTE")
    locale = env.get("WALIDSITE_CAPTURE_LOCALE")
    heading = expected_heading(route, locale)
    try:
        dpr = float(env.get("WALIDSITE_CAPTURE_DPR", ""))
        physical_width = int(env.get("WALIDSITE_CAPTURE_WIDTH", ""))
        physical_height = int(env.get("WALIDSITE_CAPTURE_HEIGHT", ""))
    except (ValueError, TypeError) as exc:
        raise AdminSessionError("Invalid Admin screenshot dimensions") from exc
    if (not 0.5 <= dpr <= 8
            or physical_width <= 0 or physical_height <= 0
            or physical_width / dpr != int(physical_width / dpr)
            or physical_height / dpr != int(physical_height / dpr)):
        raise AdminSessionError("Admin screenshot dimensions must divide exactly by DPR")

    state_text = env.get("WALIDSITE_CAPTURE_STORAGE_STATE")
    output_text = env.get("WALIDSITE_CAPTURE_PATH")
    if not state_text or not output_text:
        raise AdminSessionError("Private authenticated state and fresh capture destination required")
    state = Path(state_text)
    output = Path(output_text)
    if (not state.is_absolute() or state.is_symlink() or not state.is_file()
            or state.parent.is_symlink() or not output.is_absolute()
            or output.exists() or output.is_symlink() or not output.parent.is_dir()
            or output.parent.is_symlink()):
        raise AdminSessionError("Unsafe Admin session or screenshot path")
    # The helper creates mode-0600 state under a mode-0700 private directory.
    # Refuse shared files and foreign-user sessions rather than leaking auth.
    if (stat.S_IMODE(state.stat().st_mode) != 0o600
            or stat.S_IMODE(state.parent.stat().st_mode) != 0o700
            or state.stat().st_uid != os.getuid()):
        raise AdminSessionError("Admin session file must be privately owned")

    url = base + route + "?lang=" + locale
    manager = playwright_manager if playwright_manager is not None else _playwright()
    with manager as playwright:
        browser = playwright.chromium.launch(
            headless=True, args=["--disable-lcd-text"],
        )
        try:
            context = browser.new_context(
                storage_state=str(state),
                viewport={"width": int(physical_width / dpr),
                          "height": int(physical_height / dpr)},
                device_scale_factor=dpr,
                locale="ar-KW" if locale == "ar" else "en-US",
                reduced_motion="reduce",
                color_scheme="light",
            )
            try:
                page = context.new_page()
                response = page.goto(url, wait_until="domcontentloaded", timeout=30000)
                if response is None or response.status != 200 or page.url != url:
                    raise AdminSessionError("Protected Admin capture was rejected")
                page.evaluate("() => document.fonts.ready")
                _verify_page(page, url, locale, heading)
                # Capture as bytes, not directly to a public/predictable path.
                # The renderer creates the result exclusively only *after*
                # validating the screenshot and verifying no late navigation.
                png = page.screenshot(
                    full_page=False, animations="disabled",
                    caret="hide", type="png",
                )
                if not isinstance(png, bytes) or not png or len(png) > MAX_PNG_BYTES:
                    raise AdminSessionError("Admin browser did not return a valid PNG")
                try:
                    with Image.open(io.BytesIO(png)) as actual:
                        if actual.format != "PNG" or actual.size != (
                                physical_width, physical_height):
                            raise AdminSessionError("Admin screenshot has incorrect pixel geometry")
                        actual.verify()
                except (OSError, UnidentifiedImageError, Image.DecompressionBombError) as exc:
                    raise AdminSessionError("Admin screenshot PNG is not decodable") from exc
                _verify_page(page, url, locale, heading)
                try:
                    descriptor = os.open(output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
                except OSError as exc:
                    raise AdminSessionError("Cannot exclusively create a fresh Admin capture") from exc
                try:
                    with os.fdopen(descriptor, "wb") as handle:
                        handle.write(png)
                except BaseException:
                    output.unlink(missing_ok=True)
                    raise
            finally:
                context.close()
        finally:
            browser.close()
    return output


def main() -> int:
    try:
        render()
    except Exception:
        # Playwright failures may embed URLs or browser state. Never print them.
        print("ADMIN SCREEN NOT VERIFIED: local authorization or rendering failed", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
