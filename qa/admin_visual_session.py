#!/usr/bin/env python3
"""Create a short-lived, genuine Laravel Admin browser session for visual QA.

The state is sensitive: keep it outside the repository, never upload it, and
remove its private directory after capturing real UI screenshots.
"""
import json
import os
import re
import shutil
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlsplit


SCREENS = ("github", "projects", "audience", "analytics")


class AdminSessionError(ValueError):
    pass


def local_origin(origin: str) -> str:
    """Reject hosted targets, redirects through URL syntax, and credentials."""
    if not isinstance(origin, str) or not origin or origin != origin.strip():
        raise AdminSessionError("Expected an explicit loopback origin")
    parsed = urlsplit(origin)
    if (parsed.scheme not in ("http", "https")
            or parsed.hostname not in ("localhost", "127.0.0.1", "::1")
            or parsed.username is not None or parsed.password is not None
            or parsed.path not in ("", "/") or parsed.query or parsed.fragment
            or any(ord(char) < 33 or ord(char) == 127 for char in origin)):
        raise AdminSessionError("Admin capture login permits loopback origins only")
    try:
        port = parsed.port
    except ValueError as exc:
        raise AdminSessionError("Invalid loopback origin port") from exc
    if not parsed.netloc or (port is not None and not 1 <= port <= 65535):
        raise AdminSessionError("Invalid loopback origin")
    return origin.rstrip("/")


def _playwright():
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        raise AdminSessionError("Install Playwright Chromium on the QA machine") from exc
    return sync_playwright()


def provision_session(origin: str, email: str, password: str, *, locale: str = "en",
                      playwright_manager=None) -> Path:
    """Authenticate via actual Laravel CSRF form; verify four protected screens.

    No seeded/fabricated credentials, bypass cookie or screenshot is produced.
    The caller must delete the returned session directory after visual QA.
    """
    base = local_origin(origin)
    if locale not in ("ar", "en"):
        raise AdminSessionError("Unsupported locale")
    if (not isinstance(email, str) or not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", email)
            or not isinstance(password, str) or not password):
        raise AdminSessionError("Actual Admin credentials are required")

    directory = Path(tempfile.mkdtemp(prefix="walidsite-admin-visual-")).resolve()
    os.chmod(directory, 0o700)
    state = directory / "storage-state.json"
    try:
        manager = playwright_manager if playwright_manager is not None else _playwright()
        with manager as playwright:
            browser = playwright.chromium.launch(headless=True)
            try:
                context = browser.new_context(
                    viewport={"width": 1440, "height": 900},
                    locale="ar-KW" if locale == "ar" else "en-US",
                    reduced_motion="reduce",
                )
                try:
                    page = context.new_page()
                    login_url = base + "/admin/login"
                    response = page.goto(login_url, wait_until="domcontentloaded", timeout=30000)
                    if response is None or response.status != 200 or page.url != login_url:
                        raise AdminSessionError("Admin login page is unavailable")
                    # The real browser submits Laravel's own hidden CSRF token.
                    page.locator('input[name="email"]').fill(email)
                    page.locator('input[name="password"]').fill(password)
                    page.locator('button[type="submit"]').click()
                    page.wait_for_url(base + "/admin", timeout=15000)
                    if page.url != base + "/admin":
                        raise AdminSessionError("Admin login was not confirmed")

                    for screen in SCREENS:
                        target = base + "/admin/" + screen + "?lang=" + locale
                        response = page.goto(target, wait_until="domcontentloaded", timeout=30000)
                        if response is None or response.status != 200 or page.url != target:
                            raise AdminSessionError("Protected Admin screen is inaccessible")
                        html = page.locator("html")
                        if (html.get_attribute("lang") != locale
                                or html.get_attribute("dir") != ("rtl" if locale == "ar" else "ltr")
                                or page.locator("main#content").count() != 1):
                            raise AdminSessionError("Protected Admin screen has unexpected content")

                    # Playwright writes a real authenticated cookie jar. Prevent
                    # a world-readable temporary file even between operations.
                    fd = os.open(state, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                    os.close(fd)
                    context.storage_state(path=str(state))
                    os.chmod(state, 0o600)
                    payload = json.loads(state.read_text(encoding="utf-8"))
                    if (not isinstance(payload, dict)
                            or not isinstance(payload.get("cookies"), list)
                            or not payload["cookies"]):
                        raise AdminSessionError("No authenticated browser cookies were captured")
                finally:
                    context.close()
            finally:
                browser.close()
        return state
    except BaseException:
        shutil.rmtree(directory, ignore_errors=True)
        raise


def main() -> int:
    # Neither credentials nor raw exception text are ever printed.
    try:
        state = provision_session(
            os.environ.get("WALIDSITE_QA_ADMIN_ORIGIN", "http://127.0.0.1:8000"),
            os.environ.get("WALIDSITE_QA_ADMIN_EMAIL", ""),
            os.environ.get("WALIDSITE_QA_ADMIN_PASSWORD", ""),
            locale=os.environ.get("WALIDSITE_QA_ADMIN_LOCALE", "en"),
        )
    except Exception:
        # Playwright raises its own Error/TimeoutError classes; never allow
        # their diagnostic text or browser context to leak login credentials.
        print("ADMIN SESSION NOT VERIFIED: login, authorization or local runtime failed", file=sys.stderr)
        return 1
    print("WALIDSITE_CAPTURE_STORAGE_STATE=" + str(state))
    print("DELETE SESSION DIRECTORY AFTER QA: " + str(state.parent))
    return 0


if __name__ == "__main__":
    sys.exit(main())
