"""Fail-closed unit and browser-orchestration tests for #481 Admin audit.

Fakes validate only the audit harness. They are NEVER runtime screenshots or
visual reference evidence.
"""
import io
import json
import os
import shutil
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from qa.audit_admin_runtime import (
    VIEWPORTS, MAX_CHECKS, audit_admin, exact_checkout, fresh_output,
    inspect_page, main, metric_defects, private_state,
)
from qa.admin_visual_session import AdminSessionError
from qa.render_admin_screenshot import expected_heading


ROOT = Path(__file__).resolve().parents[2]
PROVIDER_ID = "12345678-1234-4abc-8def-1234567890ab"
SHA = "a" * 40


class FakePage:
    def __init__(self):
        self.url = ""
        self.locale = ""
        self.keyboard = SimpleNamespace(press=Mock())
        self.closed = False
        self.response_status = 200
        self.dom = {
            "overflow_px": 0, "unnamed": 0, "unsafe_links": 0,
            "controls": 5, "skip": True, "main": 1,
        }

    def goto(self, url, **_kwargs):
        self.url = url
        self.locale = url.rsplit("lang=", 1)[-1]
        return SimpleNamespace(status=self.response_status)

    def locator(self, selector):
        if selector == "html":
            return SimpleNamespace(get_attribute=lambda name:
                self.locale if name == "lang" else
                ("rtl" if self.locale == "ar" else "ltr"))
        if selector == "main#content h1":
            route = self.url.split("?")[0].replace("http://127.0.0.1:8000", "")
            return SimpleNamespace(
                count=lambda: 1,
                inner_text=lambda: expected_heading(route, self.locale),
            )
        raise AssertionError("Unexpected selector " + selector)

    def evaluate(self, script):
        return self.dom if "overflow_px" in script else True

    def close(self):
        self.closed = True


class FakeContext:
    def __init__(self):
        self.pages = []
        self.routes = []
        self.closed = False

    def route(self, pattern, callback):
        self.routes.append((pattern, callback))

    def new_page(self):
        page = FakePage()
        self.pages.append(page)
        return page

    def close(self):
        self.closed = True


def fake_browser():
    contexts = []
    browser = Mock()
    def context_factory(**_kwargs):
        context = FakeContext()
        contexts.append(context)
        return context
    browser.new_context.side_effect = context_factory
    playwright = SimpleNamespace(chromium=SimpleNamespace(launch=lambda **kwargs: browser))
    manager = Mock()
    manager.__enter__ = Mock(return_value=playwright)
    manager.__exit__ = Mock(return_value=False)
    return manager, contexts, browser


class AdminRuntimeAuditTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp(prefix="walidsite-qa-test-"))
        self.addCleanup(lambda: shutil.rmtree(self.folder, ignore_errors=True))
        os.chmod(self.folder, 0o700)
        self.state = self.folder / "state.json"
        self.state.write_text('{"cookies":[]}', encoding="utf-8")
        os.chmod(self.state, 0o600)

    def test_metric_defects_are_strict_and_never_echo_data(self):
        healthy = {
            "overflow_px": 0, "unnamed": 0, "unsafe_links": 0,
            "controls": 3, "skip": True, "main": 1,
        }
        self.assertEqual(metric_defects(healthy), [])
        broken = {
            **healthy, "overflow_px": 13, "unnamed": 2,
            "unsafe_links": 1, "skip": False, "main": 2,
        }
        self.assertEqual(metric_defects(broken), [
            "invalid_main_landmark", "missing_skip_navigation",
            "document_horizontal_overflow", "unnamed_visible_controls",
            "inert_or_script_links",
        ])
        for invalid in ({}, {**healthy, "controls": True},
                        {**healthy, "unnamed": -1},
                        {**healthy, "skip": 1},
                        {**healthy, "custom": "secret"}):
            with self.subTest(invalid=invalid):
                self.assertEqual(metric_defects(invalid), ["invalid_dom_metrics"])

    def test_page_verified_by_real_url_heading_and_keyboard_with_no_source_text(self):
        page = FakePage()
        url = "http://127.0.0.1:8000/admin/github?lang=ar"
        self.assertEqual(inspect_page(page, url, "ar", "مزامنة GitHub"), [])
        page.keyboard.press.assert_called_once_with("Tab")
        self.assertEqual(
            inspect_page(page, url, "ar", "wrong heading"),
            ["screen_identity_mismatch"],
        )
        page.response_status = 401
        self.assertEqual(
            inspect_page(page, url, "ar", "مزامنة GitHub"),
            ["http_auth_or_route_failure"],
        )

    def test_private_cookie_state_and_fresh_output_fail_closed(self):
        self.assertEqual(private_state(self.state), self.state)
        target = self.folder / "new-report.json"
        self.assertEqual(fresh_output(target), target)
        target.write_text("existing", encoding="utf-8")
        with self.assertRaises(AdminSessionError):
            fresh_output(target)
        os.chmod(self.state, 0o644)
        with self.assertRaises(AdminSessionError):
            private_state(self.state)
        os.chmod(self.state, 0o600)
        link = self.folder / "alias.json"
        link.symlink_to(self.state)
        with self.assertRaises(AdminSessionError):
            private_state(link)
        with self.assertRaises(AdminSessionError):
            fresh_output(link)

    def test_exact_checkout_rejects_modified_sources_or_unverified_sha(self):
        with self.assertRaises(AdminSessionError):
            exact_checkout(ROOT, "not-a-sha")
        with patch("qa.audit_admin_runtime.subprocess.check_output",
                   side_effect=[SHA + "\n", ""]):
            exact_checkout(ROOT, SHA)
        with patch("qa.audit_admin_runtime.subprocess.check_output",
                   side_effect=[SHA + "\n", " M fake-change\n"]):
            with self.assertRaises(AdminSessionError):
                exact_checkout(ROOT, SHA)
        with patch("qa.audit_admin_runtime.subprocess.check_output",
                   side_effect=["b" * 40 + "\n", ""]):
            with self.assertRaises(AdminSessionError):
                exact_checkout(ROOT, SHA)

    def test_actual_session_matrix_covers_52_unique_authenticated_screen_checks(self):
        manager, contexts, browser = fake_browser()
        with patch("qa.audit_admin_runtime.exact_checkout"):
            report = audit_admin(
                "http://127.0.0.1:8000", self.state, PROVIDER_ID, SHA,
                root=ROOT, playwright_manager=manager,
            )
        self.assertEqual(report["screen_checks"], MAX_CHECKS)
        self.assertEqual(report["failed_checks"], 0)
        self.assertEqual(report["visual_parity"], "not_attested")
        self.assertEqual(len(contexts), 2 * len(VIEWPORTS))
        self.assertEqual(len({
            (x["screen"], x["locale"], x["viewport"])
            for x in report["results"]
        }), 52)
        self.assertTrue(all(x["status"] == "pass" for x in report["results"]))
        self.assertTrue(all(len(ctx.pages) == 13 and ctx.closed for ctx in contexts))
        self.assertTrue(all(p.closed for ctx in contexts for p in ctx.pages))
        browser.close.assert_called_once()
        self.assertNotIn("cookie", json.dumps(report).lower())
        self.assertNotIn("state.json", json.dumps(report).lower())

    def test_loopback_only_and_canonical_provider_id_precede_browser(self):
        manager, contexts, _ = fake_browser()
        with patch("qa.audit_admin_runtime.exact_checkout"):
            with self.assertRaises(AdminSessionError):
                audit_admin("https://invalid.example", self.state, PROVIDER_ID, SHA,
                            root=ROOT, playwright_manager=manager)
            with self.assertRaises(ValueError):
                audit_admin("http://127.0.0.1:8000", self.state,
                            "not-real-provider", SHA,
                            root=ROOT, playwright_manager=manager)
        self.assertFalse(contexts)

    def test_external_requests_are_blocked_for_every_browser_context(self):
        manager, contexts, _ = fake_browser()
        with patch("qa.audit_admin_runtime.exact_checkout"):
            audit_admin("http://127.0.0.1:8000", self.state, PROVIDER_ID, SHA,
                        root=ROOT, playwright_manager=manager)
        for context in contexts:
            callback = context.routes[0][1]
            local = SimpleNamespace(request=SimpleNamespace(
                url="http://127.0.0.1:8000/admin"),
                continue_=Mock(), abort=Mock())
            external = SimpleNamespace(request=SimpleNamespace(
                url="https://example.test/steal"),
                continue_=Mock(), abort=Mock())
            callback(local)
            callback(external)
            local.continue_.assert_called_once()
            external.abort.assert_called_once()
            local.abort.assert_not_called()
            external.continue_.assert_not_called()

    def test_cli_redacts_browser_exception_and_never_writes_a_false_report(self):
        output = self.folder / "report.json"
        err, out = io.StringIO(), io.StringIO()
        with patch.dict(os.environ, {
            "WALIDSITE_CAPTURE_STORAGE_STATE": str(self.state),
            "WALIDSITE_RENDER_BASE_URL": "http://127.0.0.1:8000",
        }), patch("qa.audit_admin_runtime.audit_admin",
                  side_effect=RuntimeError("SECRET_SESSION_COOKIE")), \
             redirect_stderr(err), redirect_stdout(out):
            result = main(["--provider-id", PROVIDER_ID,
                           "--commit", SHA, "--output", str(output)])
        self.assertEqual(result, 2)
        self.assertFalse(output.exists())
        self.assertNotIn("SECRET_SESSION_COOKIE", err.getvalue()+out.getvalue())


if __name__ == "__main__":
    unittest.main()
