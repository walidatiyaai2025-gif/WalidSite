#!/usr/bin/env python3
"""Capture actual Laravel ADMIN-08..11 pixels from an authenticated local session.

Used as a renderer by qa/capture_visual_evidence.py or qa/visual_diagnostics.py.
This module NEVER certifies that a screenshot matches immutable reference art.
"""
import os
import stat
import sys
from pathlib import Path

try:
    from qa.admin_visual_session import AdminSessionError, SCREENS, local_origin, _playwright
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from admin_visual_session import AdminSessionError, SCREENS, local_origin, _playwright


def render(env=None, playwright_manager=None) -> Path:
    env = os.environ if env is None else env
    base = local_origin(env.get("WALIDSITE_RENDER_BASE_URL", ""))
    route = env.get("WALIDSITE_CAPTURE_ROUTE")
    if route not in tuple("/admin/" + screen for screen in SCREENS):
        raise AdminSessionError("Admin renderer requires one approved canonical route")
    locale = env.get("WALIDSITE_CAPTURE_LOCALE")
    if locale not in ("ar", "en"):
        raise AdminSessionError("Invalid Admin capture locale")
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
            or output.exists() or output.is_symlink() or not output.parent.is_dir()):
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
                if page.url != url:
                    raise AdminSessionError("Protected Admin capture navigated after loading")
                html = page.locator("html")
                if (html.get_attribute("lang") != locale
                        or html.get_attribute("dir") != ("rtl" if locale == "ar" else "ltr")
                        or page.locator("main#content").count() != 1):
                    raise AdminSessionError("Admin page is not the expected localized screen")
                page.screenshot(
                    path=str(output), full_page=False, animations="disabled",
                    caret="hide", type="png",
                )
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
