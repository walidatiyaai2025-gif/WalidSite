"""Admin authenticated screenshot renderer contract; mocks are NOT visual proof."""
import io
import json
import os
import stat
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

from PIL import Image

from qa.admin_visual_session import AdminSessionError
from qa.render_admin_screenshot import render, STATIC_HEADINGS, expected_heading, PROVIDER_EDITOR_HEADINGS


class AdminScreenshotRendererTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.private = self.root / "private"
        self.private.mkdir(mode=0o700)
        self.state = self.private / "state.json"
        self.state.write_text('{"cookies":[{"name":"laravel_session","value":"secret"}],"origins":[]}')
        os.chmod(self.state, 0o600)
        self.output = self.root / "runtime.png"
        buf = io.BytesIO()
        Image.new("RGB", (1440, 860), (244, 250, 255)).save(buf, "PNG")
        self.fake_png = buf.getvalue()
        self.env = {
            "WALIDSITE_RENDER_BASE_URL": "http://127.0.0.1:8000",
            "WALIDSITE_CAPTURE_ROUTE": "/admin/github",
            "WALIDSITE_CAPTURE_LOCALE": "en",
            "WALIDSITE_CAPTURE_DPR": "1",
            "WALIDSITE_CAPTURE_WIDTH": "1440",
            "WALIDSITE_CAPTURE_HEIGHT": "860",
            "WALIDSITE_CAPTURE_STORAGE_STATE": str(self.state),
            "WALIDSITE_CAPTURE_PATH": str(self.output),
        }

    def fake(self, *, status=200, redirected=False, language="en",
             direction="ltr", missing_main=False, missing_heading=False,
             wrong_heading=False, png=None):
        page = Mock()
        target = "http://127.0.0.1:8000" + self.env["WALIDSITE_CAPTURE_ROUTE"] + "?lang=" + self.env["WALIDSITE_CAPTURE_LOCALE"]
        page.goto.return_value = SimpleNamespace(status=status)
        page.url = ("http://127.0.0.1:8000/admin/login" if redirected else target)
        html = Mock()
        html.get_attribute.side_effect = lambda key: {"lang": language, "dir": direction}[key]
        content = Mock()
        content.count.return_value = 0 if missing_main else 1
        title = Mock()
        title.count.return_value = 0 if missing_heading else 1
        title.inner_text.return_value = (
            "Other Admin screen" if wrong_heading
            else expected_heading(self.env["WALIDSITE_CAPTURE_ROUTE"],
                                  self.env["WALIDSITE_CAPTURE_LOCALE"])
        )
        page.locator.side_effect = lambda key: {
            "html": html, "main#content": content, "main#content h1": title,
        }[key]
        page.screenshot.return_value = self.fake_png if png is None else png
        context = Mock()
        context.new_page.return_value = page
        browser = Mock()
        browser.new_context.return_value = context
        playwright = SimpleNamespace(chromium=Mock())
        playwright.chromium.launch.return_value = browser
        manager = Mock()
        manager.__enter__ = Mock(return_value=playwright)
        manager.__exit__ = Mock(return_value=False)
        return manager, page, browser, context

    def test_each_canonical_admin_route_uses_actual_authenticated_context(self):
        for route in (*STATIC_HEADINGS, "/admin/ai/providers/01234567-89ab-4cde-8fab-0123456789ab"):
            with self.subTest(route=route):
                self.env["WALIDSITE_CAPTURE_ROUTE"] = route
                manager, page, browser, context = self.fake()
                self.assertEqual(render(self.env, manager), self.output)
                self.assertEqual(page.goto.call_args.args[0],
                                 "http://127.0.0.1:8000" + route + "?lang=en")
                self.assertEqual(
                    browser.new_context.call_args.kwargs["storage_state"],
                    str(self.state),
                )
                self.assertEqual(
                    browser.new_context.call_args.kwargs["viewport"],
                    {"width": 1440, "height": 860},
                )
                page.screenshot.assert_called_once()
                self.assertEqual(self.output.read_bytes(), self.fake_png)
                self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)
                self.output.unlink()
                context.close.assert_called_once()
                browser.close.assert_called_once()

    def test_rejects_unsafe_inputs_before_starting_chromium(self):
        invalid = [
            ("WALIDSITE_RENDER_BASE_URL", "https://example.org"),
            ("WALIDSITE_CAPTURE_ROUTE", "/admin/login"),
            ("WALIDSITE_CAPTURE_ROUTE", "/admin/audit"),
            ("WALIDSITE_CAPTURE_ROUTE", "/admin/projects?token=secret"),
            ("WALIDSITE_CAPTURE_LOCALE", "fr"),
            ("WALIDSITE_CAPTURE_DPR", "0"),
            ("WALIDSITE_CAPTURE_DPR", "3"),
            ("WALIDSITE_CAPTURE_WIDTH", "0"),
            ("WALIDSITE_CAPTURE_STORAGE_STATE", ""),
            ("WALIDSITE_CAPTURE_PATH", "relative.png"),
        ]
        for key, value in invalid:
            with self.subTest(key=key, value=value):
                altered = dict(self.env, **{key: value})
                manager, _, _, _ = self.fake()
                with self.assertRaises(AdminSessionError):
                    render(altered, manager)
                manager.__enter__.assert_not_called()

    def test_rejects_shared_cookie_permissions_and_symlink(self):
        manager, _, _, _ = self.fake()
        os.chmod(self.state, 0o644)
        with self.assertRaisesRegex(AdminSessionError, "privately owned"):
            render(self.env, manager)
        os.chmod(self.state, 0o600)
        symlink = self.root / "state-link.json"
        symlink.symlink_to(self.state)
        with self.assertRaisesRegex(AdminSessionError, "Unsafe"):
            render(dict(self.env, WALIDSITE_CAPTURE_STORAGE_STATE=str(symlink)),
                   manager)
        manager.__enter__.assert_not_called()

    def test_redirect_role_failure_and_wrong_locale_cannot_write_screenshot(self):
        for kwargs in (
            {"status": 403}, {"redirected": True},
            {"language": "ar"}, {"direction": "rtl"},
            {"missing_main": True},
        ):
            with self.subTest(kwargs=kwargs):
                manager, page, browser, context = self.fake(**kwargs)
                with self.assertRaises(AdminSessionError):
                    render(self.env, manager)
                page.screenshot.assert_not_called()
                browser.close.assert_called_once()
                context.close.assert_called_once()
                self.assertFalse(self.output.exists())

    def test_arabic_viewport_direction_and_dpr(self):
        env = dict(self.env, WALIDSITE_CAPTURE_LOCALE="ar",
                   WALIDSITE_CAPTURE_DPR="2",
                   WALIDSITE_CAPTURE_WIDTH="1440",
                   WALIDSITE_CAPTURE_HEIGHT="860")
        self.env = env
        manager, page, browser, _ = self.fake(language="ar", direction="rtl")
        render(env, manager)
        kwargs = browser.new_context.call_args.kwargs
        self.assertEqual(kwargs["viewport"], {"width": 720, "height": 430})
        self.assertEqual(kwargs["device_scale_factor"], 2)
        self.assertEqual(kwargs["locale"], "ar-KW")
        page.screenshot.assert_called_once()


if __name__ == "__main__":
    unittest.main()
