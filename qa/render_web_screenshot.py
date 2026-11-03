#!/usr/bin/env python3
"""Capture a REAL Laravel public or admin page with Playwright Chromium.

Install optional local tooling: pip install playwright && python -m playwright install chromium
Run through qa/capture_visual_evidence.py, which supplies environment variables
and admits the PNG only after exact immutable-reference pixel comparison.
"""
import io
import os
from pathlib import Path
from urllib.parse import urlsplit

from PIL import Image, UnidentifiedImageError

# Reference PNGs use grayscale text antialiasing. Disable Chromium LCD
# subpixel text and use the measured slight FreeType hinting mode; T007
# diagnostics proved this produces fewer WEB-06 reference mismatches than
# Chromium default, no-hint, or disable-LCD alone on the pinned CI browser.
CHROMIUM_RENDER_ARGS = ["--disable-lcd-text", "--font-render-hinting=slight"]
MAX_CAPTURE_BYTES = 64 * 1024 * 1024


class RenderConfigError(ValueError):
    pass


def target_url(base: str, route: str) -> str:
    parsed = urlsplit(base.strip())
    if (
        parsed.scheme not in ("http", "https")
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
        or parsed.path not in ("", "/")
        or (parsed.scheme == "http" and parsed.hostname not in ("localhost", "127.0.0.1", "::1"))
    ):
        raise RenderConfigError("Use a clean HTTPS base origin or local loopback HTTP")
    if (
        not route.startswith("/")
        or route.startswith("//")
        or any(char in route for char in ("\\", "?", "#", "\r", "\n"))
        or any(segment in (".", "..") for segment in route.split("/"))
    ):
        raise RenderConfigError("Invalid local capture route")
    return base.rstrip("/") + route


def localized_target(base: str, route: str, locale: str) -> str:
    """Use Laravel's real server-side locale rather than browser language alone.

    The route stays exactly canonical (without caller-controlled query data);
    only the two allowed locale selectors may be appended by the renderer.
    """
    if locale not in ("ar", "en"):
        raise RenderConfigError("Unsupported screenshot locale")
    return target_url(base, route) + "?lang=" + locale


def viewport(physical_width: int, physical_height: int, dpr: float):
    if physical_width <= 0 or physical_height <= 0 or not (0.5 <= dpr <= 8):
        raise RenderConfigError("Invalid screenshot size or DPR")
    width, height = physical_width / dpr, physical_height / dpr
    if width != int(width) or height != int(height):
        raise RenderConfigError("Reference pixels must be evenly divisible by DPR")
    return {"width": int(width), "height": int(height)}


def _verified_destination(output: Path) -> None:
    """Do not let a capture escape a trusted existing workspace."""
    if (not output.is_absolute() or output.exists() or output.is_symlink()
            or not output.parent.is_dir() or output.parent.is_symlink()
            or output.parent.resolve() != output.parent):
        raise RenderConfigError(
            "Screenshot output must be a fresh absolute file in a trusted workspace"
        )


def _verified_png(pixels: object, width: int, height: int) -> bytes:
    """Validate the actual returned browser PNG, not just its filename."""
    if (not isinstance(pixels, bytes) or not pixels
            or len(pixels) > MAX_CAPTURE_BYTES):
        raise RenderConfigError("Browser did not return a bounded PNG screenshot")
    try:
        with Image.open(io.BytesIO(pixels)) as image:
            if image.format != "PNG" or image.size != (width, height):
                raise RenderConfigError("Browser screenshot format or dimensions disagree")
            image.verify()
    except (OSError, UnidentifiedImageError, Image.DecompressionBombError) as exc:
        raise RenderConfigError("Browser returned invalid PNG screenshot") from exc
    return pixels


def _write_png_exclusive(output: Path, pixels: bytes) -> None:
    """Create once with private permissions; never follow a destination symlink."""
    _verified_destination(output)
    try:
        parent_fd = os.open(
            output.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
        )
        try:
            descriptor = os.open(
                output.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                0o600, dir_fd=parent_fd,
            )
            try:
                with os.fdopen(descriptor, "wb") as handle:
                    handle.write(pixels)
            except BaseException:
                os.unlink(output.name, dir_fd=parent_fd)
                raise
        finally:
            os.close(parent_fd)
    except OSError as exc:
        raise RenderConfigError("Cannot create a private exclusive PNG candidate") from exc


def render(env=None):
    env = os.environ if env is None else env
    locale = env["WALIDSITE_CAPTURE_LOCALE"]
    url = localized_target(env.get("WALIDSITE_RENDER_BASE_URL", ""),
                           env["WALIDSITE_CAPTURE_ROUTE"], locale)
    dpr = float(env["WALIDSITE_CAPTURE_DPR"])
    size = viewport(int(env["WALIDSITE_CAPTURE_WIDTH"]),
                    int(env["WALIDSITE_CAPTURE_HEIGHT"]), dpr)
    output = Path(env["WALIDSITE_CAPTURE_PATH"])
    _verified_destination(output)

    # Admin captures use a pre-existing, locally provisioned authenticated
    # storage-state file. Never create or commit a login session here.
    storage = env.get("WALIDSITE_CAPTURE_STORAGE_STATE")
    if urlsplit(url).path.startswith("/admin") and not storage:
        raise RenderConfigError("Admin screenshots require authenticated storage state")
    if storage and not urlsplit(url).path.startswith("/admin"):
        # A public-screen capture must prove the visitor journey, not an
        # authenticated owner's preview of unpublished/private content.
        raise RenderConfigError("Public screenshots require an anonymous browser context")
    if storage and not Path(storage).is_file():
        raise RenderConfigError("Authenticated storage state file does not exist")

    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        raise RenderConfigError("Install optional playwright and Chromium locally") from exc

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            args=CHROMIUM_RENDER_ARGS,
        )
        try:
            options = {
                "viewport": size,
                "device_scale_factor": dpr,
                "locale": "ar-KW" if locale == "ar" else "en-US",
                "reduced_motion": "reduce",
                "color_scheme": "light",
            }
            if storage:
                options["storage_state"] = storage
            context = browser.new_context(**options)
            try:
                page = context.new_page()
                response = page.goto(url, wait_until="domcontentloaded", timeout=30000)
                if response is None or response.status != 200:
                    raise RenderConfigError("Page did not return HTTP 200")
                if page.url.rstrip("/") != url.rstrip("/"):
                    raise RenderConfigError("Page redirected; refusing to capture login or error UI")
                # Wait for loaded web fonts; do not pretend network-idle means
                # an authenticated page has genuine or authorized content.
                page.evaluate("() => document.fonts.ready")
                selector = env.get("WALIDSITE_CAPTURE_READY_SELECTOR")
                if selector:
                    page.locator(selector).wait_for(state="visible", timeout=15000)
                # Navigations triggered after DOMContentLoaded (e.g. a late
                # auth redirect) must not be accepted as the requested screen.
                if page.url.rstrip("/") != url.rstrip("/"):
                    raise RenderConfigError("Page navigated after load; refusing a wrong screen")
                html = page.locator("html")
                expected_dir = "rtl" if locale == "ar" else "ltr"
                if (html.get_attribute("lang") != locale
                        or html.get_attribute("dir") != expected_dir):
                    raise RenderConfigError(
                        "Rendered HTML language/direction disagree with capture locale"
                    )
                pixels = page.screenshot(
                    full_page=False, animations="disabled",
                    caret="hide", type="png",
                )
                # A deferred navigation or client-side locale change may race
                # the screenshot itself. Never retain a wrong-page candidate.
                if page.url.rstrip("/") != url.rstrip("/"):
                    raise RenderConfigError(
                        "Page navigated during screenshot; discarding captured PNG"
                    )
                if (html.get_attribute("lang") != locale
                        or html.get_attribute("dir") != expected_dir):
                    raise RenderConfigError(
                        "Page language/direction changed during screenshot"
                    )
                verified = _verified_png(
                    pixels, int(env["WALIDSITE_CAPTURE_WIDTH"]),
                    int(env["WALIDSITE_CAPTURE_HEIGHT"]),
                )
                _write_png_exclusive(output, verified)
            finally:
                context.close()
        finally:
            browser.close()


if __name__ == "__main__":
    try:
        render()
    except (RenderConfigError, KeyError, ValueError) as exc:
        raise SystemExit("WEB CAPTURE NOT VERIFIED: " + str(exc)) from exc
