"""No-browser unit checks for deterministic real-page capture configuration."""
import sys
import tempfile
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest.mock import Mock, patch

from qa.render_web_screenshot import (
    CHROMIUM_RENDER_ARGS,
    RenderConfigError, localized_target, render, target_url, viewport,
)


class RealWebCaptureConfigTest(unittest.TestCase):
    def test_renderer_uses_measured_grayscale_hinting_for_reference_stability(self):
        self.assertEqual(CHROMIUM_RENDER_ARGS, [
            "--disable-lcd-text", "--font-render-hinting=slight",
        ])

    def test_https_and_loopback_http_targets(self):
        self.assertEqual(target_url("https://preview.example.test/", "/about"),
                         "https://preview.example.test/about")
        self.assertEqual(target_url("http://127.0.0.1:8000", "/admin/dashboard"),
                         "http://127.0.0.1:8000/admin/dashboard")

    def test_unsafe_remote_http_and_embedded_credentials_are_refused(self):
        for base in (
            "http://example.test", "http://10.0.0.5:8000",
            "https://user:password@example.test",
            "https://example.test/a", "https://example.test?token=secret",
            "file:///tmp/site", "",
        ):
            with self.subTest(base=base), self.assertRaises(RenderConfigError):
                target_url(base, "/")

    def test_route_cannot_redirect_to_another_host_or_traverse(self):
        for route in (
            "//evil.example.test", "/../secrets", "/./admin",
            "/about?token=x", "/about#fragment", "/foo\\bar", "about",
        ):
            with self.subTest(route=route), self.assertRaises(RenderConfigError):
                target_url("https://preview.example.test", route)

    def test_physical_reference_dimensions_are_never_scaled_silently(self):
        self.assertEqual(viewport(1170, 2532, 3), {"width": 390, "height": 844})
        self.assertEqual(viewport(1440, 900, 1), {"width": 1440, "height": 900})
        for values in ((1171, 2532, 3), (0, 900, 1), (1440, -1, 1),
                       (100, 100, 0), (100, 100, 9)):
            with self.subTest(values=values), self.assertRaises(RenderConfigError):
                viewport(*values)


    def test_laravel_server_locale_query_is_explicit_and_canonical(self):
        self.assertEqual(
            localized_target("http://127.0.0.1:8000", "/about", "ar"),
            "http://127.0.0.1:8000/about?lang=ar",
        )
        self.assertEqual(
            localized_target("https://preview.example.test/", "/admin/analytics", "en"),
            "https://preview.example.test/admin/analytics?lang=en",
        )
        for locale in ("fr", "", "AR", "../../secret"):
            with self.subTest(locale=locale), self.assertRaises(RenderConfigError):
                localized_target("https://preview.example.test", "/", locale)
        with self.assertRaises(RenderConfigError):
            localized_target("https://preview.example.test", "/about?token=private", "ar")

    def _render_fake(self, locale="ar", page_lang="ar", direction="rtl",
                     redirect_after_fonts=False, redirect_during_screenshot=False,
                     change_locale_during_screenshot=False):
        # This tests the real render() orchestration without installing a
        # browser. Synthetic responses are NOT WalidSite screenshot evidence.
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "runtime.png"
            route = "/about"
            expected_url = "http://127.0.0.1:8000/about?lang=" + locale
            env = {
                "WALIDSITE_RENDER_BASE_URL": "http://127.0.0.1:8000",
                "WALIDSITE_CAPTURE_ROUTE": route,
                "WALIDSITE_CAPTURE_LOCALE": locale,
                "WALIDSITE_CAPTURE_DPR": "1",
                "WALIDSITE_CAPTURE_WIDTH": "1440",
                "WALIDSITE_CAPTURE_HEIGHT": "900",
                "WALIDSITE_CAPTURE_PATH": str(output),
            }
            page = Mock()
            page.goto.return_value = SimpleNamespace(status=200)
            page.url = expected_url
            html = Mock()
            html.get_attribute.side_effect = lambda name: {
                "lang": page_lang, "dir": direction,
            }[name]
            page.locator.return_value = html
            if redirect_after_fonts:
                def navigate_after_font_load(script):
                    page.url = "http://127.0.0.1:8000/login"
                page.evaluate.side_effect = navigate_after_font_load
            if redirect_during_screenshot:
                def navigate_during_screenshot(**kwargs):
                    output.write_bytes(b"synthetic-png-placeholder")
                    page.url = "http://127.0.0.1:8000/login"
                page.screenshot.side_effect = navigate_during_screenshot
            if change_locale_during_screenshot:
                def relocalize_during_screenshot(**kwargs):
                    output.write_bytes(b"synthetic-png-placeholder")
                    html.get_attribute.side_effect = lambda name: {
                        "lang": "en" if locale == "ar" else "ar",
                        "dir": "ltr" if locale == "ar" else "rtl",
                    }[name]
                page.screenshot.side_effect = relocalize_during_screenshot
            browser_context = Mock()
            browser_context.new_page.return_value = page
            browser = Mock()
            browser.new_context.return_value = browser_context
            playwright = SimpleNamespace(chromium=Mock())
            playwright.chromium.launch.return_value = browser
            manager = Mock()
            manager.__enter__ = Mock(return_value=playwright)
            manager.__exit__ = Mock(return_value=False)
            module = ModuleType("playwright.sync_api")
            module.sync_playwright = lambda: manager
            with patch.dict(sys.modules, {
                "playwright": ModuleType("playwright"),
                "playwright.sync_api": module,
            }):
                if page_lang != locale or direction != (
                        "rtl" if locale == "ar" else "ltr"):
                    with self.assertRaisesRegex(RenderConfigError,
                                                "language/direction"):
                        render(env)
                    page.screenshot.assert_not_called()
                elif redirect_after_fonts:
                    with self.assertRaisesRegex(RenderConfigError, "navigated"):
                        render(env)
                    page.screenshot.assert_not_called()
                elif redirect_during_screenshot or change_locale_during_screenshot:
                    expected_error = ("navigated during screenshot"
                                      if redirect_during_screenshot
                                      else "changed during screenshot")
                    with self.assertRaisesRegex(RenderConfigError, expected_error):
                        render(env)
                    page.screenshot.assert_called_once()
                    self.assertFalse(output.exists(), "Wrong-page candidate must be removed")
                else:
                    render(env)
                    page.goto.assert_called_once_with(
                        expected_url, wait_until="domcontentloaded",
                        timeout=30000,
                    )
                    page.screenshot.assert_called_once()
                    self.assertEqual(
                        browser.new_context.call_args.kwargs["locale"],
                        "ar-KW" if locale == "ar" else "en-US",
                    )
            browser_context.close.assert_called_once()
            browser.close.assert_called_once()

    def test_real_renderer_binds_arabic_and_english_html_direction(self):
        self._render_fake(locale="ar", page_lang="ar", direction="rtl")
        self._render_fake(locale="en", page_lang="en", direction="ltr")

    def test_real_renderer_refuses_wrong_localized_document(self):
        self._render_fake(locale="ar", page_lang="en", direction="ltr")
        self._render_fake(locale="ar", page_lang="ar", direction="ltr")
        self._render_fake(locale="en", page_lang="en", direction="rtl")

    def test_real_renderer_rejects_delayed_login_redirect_before_screenshot(self):
        self._render_fake(redirect_after_fonts=True)

    def test_real_renderer_discards_late_redirect_after_screenshot(self):
        self._render_fake(redirect_during_screenshot=True)

    def test_real_renderer_discards_late_locale_change_after_screenshot(self):
        self._render_fake(change_locale_during_screenshot=True)

    def test_public_capture_rejects_authenticated_owner_storage(self):
        with tempfile.TemporaryDirectory() as temp:
            state = Path(temp) / "storage.json"
            state.write_text("{}", encoding="utf-8")
            env = {
                "WALIDSITE_RENDER_BASE_URL": "http://127.0.0.1:8000",
                "WALIDSITE_CAPTURE_ROUTE": "/projects",
                "WALIDSITE_CAPTURE_LOCALE": "en",
                "WALIDSITE_CAPTURE_DPR": "1",
                "WALIDSITE_CAPTURE_WIDTH": "1440",
                "WALIDSITE_CAPTURE_HEIGHT": "860",
                "WALIDSITE_CAPTURE_PATH": str(Path(temp) / "runtime.png"),
                "WALIDSITE_CAPTURE_STORAGE_STATE": str(state),
            }
            with self.assertRaisesRegex(RenderConfigError, "anonymous browser"):
                render(env)
            self.assertFalse(Path(env["WALIDSITE_CAPTURE_PATH"]).exists())


if __name__ == "__main__":
    unittest.main()
