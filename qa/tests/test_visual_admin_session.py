"""Real Admin visual-session handshake security and failure-mode regressions.

Mock Chromium verifies orchestration, not pixel parity or live authentication.
"""
import io
import json
import shutil
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from qa.admin_visual_session import (
    AdminSessionError, SCREENS, local_origin, main, provision_session,
)


class FakePage:
    def __init__(self, origin, locale="en", fail=None):
        self.origin = origin
        self.locale = locale
        self.fail = fail
        self.url = ""
        self.routes = []
        self.email = Mock()
        self.password = Mock()
        self.click = Mock(side_effect=self._login)
        self.html = Mock()
        self.html.get_attribute.side_effect = lambda key: {
            "lang": locale if fail != "wrong_direction" else ("en" if locale == "ar" else "ar"),
            "dir": "rtl" if locale == "ar" else "ltr",
        }[key]
        self.main = Mock()
        self.main.count.return_value = 0 if fail == "missing_main" else 1

    def _login(self):
        self.url = self.origin + ("/admin/login" if self.fail == "invalid_login" else "/admin")

    def goto(self, url, **_kwargs):
        self.routes.append(url)
        self.url = url
        if self.fail == "screen_redirect" and "/admin/projects?" in url:
            self.url = self.origin + "/admin/login"
        if self.fail == "bad_status" and "/admin/audience?" in url:
            return SimpleNamespace(status=403)
        return SimpleNamespace(status=200)

    def locator(self, key):
        return {
            'input[name="email"]': SimpleNamespace(fill=self.email),
            'input[name="password"]': SimpleNamespace(fill=self.password),
            'button[type="submit"]': SimpleNamespace(click=self.click),
            "html": self.html,
            "main#content": self.main,
        }[key]

    def wait_for_url(self, expected, **_kwargs):
        if self.url != expected:
            raise AdminSessionError("Login did not redirect to Admin")


def fake_browser(origin, locale="en", fail=None, cookies=None):
    page = FakePage(origin, locale, fail)
    context = Mock()
    context.new_page.return_value = page
    context.storage_state.side_effect = lambda path: Path(path).write_text(
        json.dumps({"cookies": [{"name": "laravel_session", "value": "SENSITIVE_COOKIE"}]
                    if cookies is None else cookies, "origins": []}),
        encoding="utf-8",
    )
    browser = Mock()
    browser.new_context.return_value = context
    playwright = SimpleNamespace(chromium=Mock())
    playwright.chromium.launch.return_value = browser
    manager = Mock()
    manager.__enter__ = Mock(return_value=playwright)
    manager.__exit__ = Mock(return_value=False)
    return manager, page, context, browser


class AdminVisualSessionTest(unittest.TestCase):
    def test_only_explicit_loopback_origins_are_accepted(self):
        for origin in ("http://127.0.0.1:8000", "https://localhost:8443/",
                       "http://[::1]:8000"):
            with self.subTest(origin=origin):
                self.assertEqual(local_origin(origin), origin.rstrip("/"))
        for origin in ("http://example.org", "http://10.0.0.5:8000",
                       "https://admin:secret@localhost:8000",
                       "http://localhost:8000/path", "http://localhost:8000?x=1",
                       "http://localhost:8000/#secret", "file:///etc/passwd",
                       "http://localhost:bad", "http://localhost:99999",
                       "//localhost", "", "http://localhost:8000\n"):
            with self.subTest(origin=origin), self.assertRaises(AdminSessionError):
                local_origin(origin)

    def test_real_form_login_and_each_protected_screen_precedes_state_write(self):
        origin = "http://127.0.0.1:8000"
        manager, page, context, browser = fake_browser(origin, locale="ar")
        state = provision_session(
            origin, "owner@example.test", "SECRET_PASSWORD", locale="ar",
            playwright_manager=manager,
        )
        self.addCleanup(lambda: shutil.rmtree(state.parent, ignore_errors=True))
        self.assertTrue(state.is_file())
        self.assertEqual(state.stat().st_mode & 0o777, 0o600)
        self.assertEqual(state.parent.stat().st_mode & 0o777, 0o700)
        self.assertEqual(
            page.routes, [origin + "/admin/login"] +
            [origin + "/admin/" + screen + "?lang=ar" for screen in SCREENS],
        )
        page.email.assert_called_once_with("owner@example.test")
        page.password.assert_called_once_with("SECRET_PASSWORD")
        self.assertEqual(context.storage_state.call_count, 1)
        self.assertEqual(browser.close.call_count, 1)
        self.assertEqual(context.close.call_count, 1)

    def test_failure_before_complete_protected_validation_never_leaves_state(self):
        origin = "http://127.0.0.1:8000"
        for failure in ("invalid_login", "screen_redirect", "bad_status",
                        "wrong_direction", "missing_main"):
            with self.subTest(failure=failure):
                manager, _, context, browser = fake_browser(origin, fail=failure)
                with patch("qa.admin_visual_session.tempfile.mkdtemp",
                           wraps=tempfile.mkdtemp):
                    with self.assertRaises(AdminSessionError):
                        provision_session(origin, "owner@example.test", "SECRET",
                                          playwright_manager=manager)
                self.assertEqual(context.storage_state.call_count, 0)
                self.assertEqual(browser.close.call_count, 1)

    def test_rejects_missing_authenticated_cookies_and_removes_temporary_output(self):
        origin = "http://127.0.0.1:8000"
        manager, _, _, _ = fake_browser(origin, cookies=[])
        roots = []
        real_mkdtemp = tempfile.mkdtemp

        def remembered(*args, **kwargs):
            folder = real_mkdtemp(*args, **kwargs)
            roots.append(Path(folder))
            return folder

        with patch("qa.admin_visual_session.tempfile.mkdtemp", side_effect=remembered):
            with self.assertRaisesRegex(AdminSessionError, "cookies"):
                provision_session(origin, "owner@example.test", "SECRET",
                                  playwright_manager=manager)
        self.assertEqual(len(roots), 1)
        self.assertFalse(roots[0].exists())

    def test_bad_inputs_never_launch_browser(self):
        for email, password, locale in (
            ("", "SECRET", "en"), ("person@example.test", "", "en"),
            ("person@example.test", "SECRET", "fr"),
            ("bad-address", "SECRET", "en"),
        ):
            with self.subTest(email=email, locale=locale):
                with self.assertRaises(AdminSessionError):
                    provision_session("http://127.0.0.1:8000", email, password,
                                      locale=locale, playwright_manager=Mock())

    def test_cli_redacts_unexpected_browser_errors(self):
        out, err = io.StringIO(), io.StringIO()
        with patch.dict("os.environ", {
            "WALIDSITE_QA_ADMIN_ORIGIN": "http://127.0.0.1:8000",
            "WALIDSITE_QA_ADMIN_EMAIL": "owner@example.test",
            "WALIDSITE_QA_ADMIN_PASSWORD": "TOP_SECRET",
        }), patch("qa.admin_visual_session._playwright",
                  side_effect=RuntimeError("TOP_SECRET browser diagnostic")), \
                redirect_stdout(out), redirect_stderr(err):
            self.assertEqual(main(), 1)
        combined = out.getvalue() + err.getvalue()
        self.assertNotIn("TOP_SECRET", combined)
        self.assertNotIn("owner@example.test", combined)
        self.assertIn("NOT VERIFIED", combined)

    def test_cli_failure_does_not_echo_credentials(self):
        out, err = io.StringIO(), io.StringIO()
        with patch.dict("os.environ", {
            "WALIDSITE_QA_ADMIN_ORIGIN": "http://example.org",
            "WALIDSITE_QA_ADMIN_EMAIL": "secret@example.test",
            "WALIDSITE_QA_ADMIN_PASSWORD": "TOP_SECRET",
        }), redirect_stdout(out), redirect_stderr(err):
            self.assertEqual(main(), 1)
        self.assertNotIn("TOP_SECRET", out.getvalue() + err.getvalue())
        self.assertNotIn("secret@example.test", out.getvalue() + err.getvalue())
        self.assertIn("NOT VERIFIED", err.getvalue())


if __name__ == "__main__":
    unittest.main()
