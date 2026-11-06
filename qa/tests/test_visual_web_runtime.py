"""Synthetic harness regressions for the real #481 anonymous Web audit.

Fake DOMs test rejection rules, not screenshots or real visual acceptance.
"""
import io
import json
import os
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from qa.audit_web_runtime import (
    FATAL_CODES, MAX_CHECKS, WEB_DOM_METRICS, audit_web, inspect_page, main,
)
from qa.admin_visual_session import AdminSessionError

ROOT = Path(__file__).resolve().parents[2]
SHA = "a" * 40


class FakePage:
    def __init__(self):
        self.url = ""
        self.status = 200
        self.locale = "en"
        self.h1_count = 1
        self.dom = {
            "overflow_px": 0, "unnamed": 0, "unsafe_links": 0,
            "controls": 4, "skip": True, "main": 1,
        }
        self.keyboard = SimpleNamespace(press=Mock())
        self.closed = False

    def goto(self, url, **kwargs):
        self.url = url
        self.locale = url.rsplit("lang=", 1)[-1]
        return SimpleNamespace(status=self.status)

    def locator(self, selector):
        if selector == "html":
            return SimpleNamespace(get_attribute=lambda name:
                self.locale if name == "lang" else
                ("rtl" if self.locale == "ar" else "ltr"))
        if selector == "main#main-content h1:visible":
            return SimpleNamespace(count=lambda: self.h1_count)
        raise AssertionError("Unexpected browser selector")

    def evaluate(self, code):
        return self.dom if "overflow_px" in code else True

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


def fake_playwright():
    contexts = []
    browser = Mock()

    def new_context(**kwargs):
        # The production public-audit path must never provide storage state.
        if "storage_state" in kwargs:
            raise AssertionError("Private session leaked into Web audit")
        context = FakeContext()
        contexts.append(context)
        return context

    browser.new_context.side_effect = new_context
    manager = Mock()
    manager.__enter__ = Mock(return_value=SimpleNamespace(
        chromium=SimpleNamespace(launch=lambda **kwargs: browser)
    ))
    manager.__exit__ = Mock(return_value=False)
    return manager, contexts, browser


class WebRuntimeAuditTest(unittest.TestCase):
    def test_production_ci_cli_starts_without_package_pythonpath(self):
        result = subprocess.run(
            [sys.executable, "-B", "qa/audit_web_runtime.py", "--help"],
            cwd=ROOT, env={**os.environ, "PYTHONPATH": ""},
            text=True, capture_output=True, timeout=15, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("--commit", result.stdout)
        self.assertIn("--output", result.stdout)

    def test_public_metrics_use_public_main_and_skip_link_not_admin(self):
        self.assertIn("main#main-content", WEB_DOM_METRICS)
        self.assertIn("#main-content", WEB_DOM_METRICS)
        self.assertNotIn('main#content', WEB_DOM_METRICS)
        self.assertNotIn('a[href=\\\"#content\\\"]', WEB_DOM_METRICS)

    def test_page_rejects_wrong_route_locale_and_missing_headings_without_leak(self):
        page = FakePage()
        url = "http://127.0.0.1:8000/search?lang=ar"
        self.assertEqual(inspect_page(page, url, "ar"), [])
        page.keyboard.press.assert_called_once_with("Tab")
        page.h1_count = 2
        self.assertEqual(inspect_page(page, url, "ar"), ["multiple_visible_h1"])
        page.h1_count = 0
        self.assertEqual(inspect_page(page, url, "ar"), ["missing_visible_h1"])
        page.status = 404
        self.assertEqual(inspect_page(page, url, "ar"), ["http_or_route_failure"])
        page.status = 200
        self.assertEqual(inspect_page(page, url, "en"),
                         ["locale_or_direction_mismatch"])
        self.assertIn("http_or_route_failure", FATAL_CODES)

    def test_48_real_route_slots_stay_anonymous_and_keep_external_domains_blocked(self):
        manager, contexts, browser = fake_playwright()
        with patch("qa.audit_web_runtime.exact_checkout"), \
             patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
            report = audit_web(
                "http://127.0.0.1:8000", SHA, root=ROOT,
                playwright_manager=manager,
            )
        self.assertEqual(report["screen_checks"], MAX_CHECKS)
        self.assertEqual(report["failed_checks"], 0)
        self.assertEqual(report["fatal_checks"], 0)
        self.assertFalse(report["evidence_admitted"])
        self.assertEqual(report["visual_parity"], "not_attested")
        self.assertEqual(report["dynamic_web_pending"], ["WEB-05", "WEB-08"])
        self.assertEqual(len(contexts), 4)
        self.assertEqual(len({(r["screen"], r["locale"], r["viewport"])
                              for r in report["results"]}), 48)
        self.assertTrue(all(len(ctx.pages) == 12 and ctx.closed
                            for ctx in contexts))
        self.assertTrue(all(p.closed for ctx in contexts for p in ctx.pages))
        for context in contexts:
            callback = context.routes[0][1]
            permitted = SimpleNamespace(request=SimpleNamespace(
                url="http://127.0.0.1:8000/assets/site.css"),
                continue_=Mock(), abort=Mock())
            external = SimpleNamespace(request=SimpleNamespace(
                url="https://hostile.example.test/collect-cookie"),
                continue_=Mock(), abort=Mock())
            callback(permitted)
            callback(external)
            permitted.continue_.assert_called_once()
            external.abort.assert_called_once()
        browser.close.assert_called_once()
        self.assertNotIn("cookie", json.dumps(report).lower())

    def test_rejects_remote_origin_private_state_and_source_mismatch_before_browser(self):
        manager, contexts, _ = fake_playwright()
        with patch("qa.audit_web_runtime.exact_checkout"), \
             patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
            with self.assertRaises(AdminSessionError):
                audit_web("https://untrusted.example", SHA, root=ROOT,
                          playwright_manager=manager)
        with patch.dict(os.environ, {
            "WALIDSITE_CAPTURE_STORAGE_STATE": "/tmp/private-cookie.json",
        }):
            with self.assertRaises(AdminSessionError):
                audit_web("http://127.0.0.1:8000", SHA, root=ROOT,
                          playwright_manager=manager)
        with patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}), \
             patch("qa.audit_web_runtime.exact_checkout",
                   side_effect=AdminSessionError("Not clean")):
            with self.assertRaises(AdminSessionError):
                audit_web("http://127.0.0.1:8000", SHA, root=ROOT,
                          playwright_manager=manager)
        self.assertEqual(contexts, [])

    def test_cli_persists_complete_report_even_when_accessibility_defects_exist(self):
        report = {
            "schema": 1, "tested_sha": SHA,
            "scope": "anonymous_public_web_runtime_accessibility_diagnostics_only",
            "visual_parity": "not_attested", "evidence_admitted": False,
            "dynamic_web_pending": ["WEB-05", "WEB-08"],
            "screen_checks": 48, "failed_checks": 1,
            "fatal_checks": 0, "results": [
                {"screen": "WEB-07", "locale": "ar", "viewport": "mobile",
                 "status": "defect", "defects": ["multiple_visible_h1"]},
            ],
        }
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "web-a11y.json"
            with patch("qa.audit_web_runtime.audit_web", return_value=report), \
                 redirect_stdout(io.StringIO()):
                code = main(["--commit", SHA, "--output", str(output)])
            self.assertEqual(code, 1)
            self.assertTrue(output.is_file())
            self.assertEqual(json.loads(output.read_text())["screen_checks"], 48)
            self.assertEqual(json.loads(output.read_text())["failed_checks"], 1)

    def test_failed_cli_redacts_runtime_exceptions_and_writes_no_report(self):
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "web.json"
            err, out = io.StringIO(), io.StringIO()
            with patch("qa.audit_web_runtime.audit_web",
                       side_effect=RuntimeError("PRIVATE_TOKEN_MARKER")), \
                 redirect_stderr(err), redirect_stdout(out):
                code = main(["--commit", SHA, "--output", str(output)])
            self.assertEqual(code, 2)
            self.assertFalse(output.exists())
            self.assertNotIn("PRIVATE_TOKEN_MARKER", err.getvalue()+out.getvalue())


if __name__ == "__main__":
    unittest.main()
