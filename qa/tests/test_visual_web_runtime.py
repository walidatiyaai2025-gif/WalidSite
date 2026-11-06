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
    FATAL_CODES, MAX_CHECKS, MAX_DISCOVERY_BYTES, OVERFLOW_STRUCTURE,
    WEB_DOM_METRICS,
    audit_web, discover_published_details, inspect_page, main,
    safe_overflow_structure,
)
from qa.admin_visual_session import AdminSessionError

ROOT = Path(__file__).resolve().parents[2]
SHA = "a" * 40


class FakePage:
    emit_web03_overflow = False

    def __init__(self, viewport_width=1440):
        self.viewport_width = viewport_width
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
        if self.emit_web03_overflow and self.viewport_width == 390 and "/expertise?" in url:
            self.dom["overflow_px"] = 24
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
        if "overflow_px" in code:
            return self.dom
        if "querySelectorAll('*')" in code:
            return {
                "viewport_px": self.viewport_width,
                "scanned_nodes": 120,
                "truncated": False,
                "candidates": [{
                    "tag": "div",
                    "component": "expertise-tab-nav",
                    "overhang_px": 24,
                }],
            }
        return True

    def close(self):
        self.closed = True


class FakeApiResponse:
    def __init__(self, payload, status=200, *, content_type="application/json",
                 raw=None):
        self.status = status
        self.headers = {"content-type": content_type}
        self.payload = payload
        self.raw = raw

    def body(self):
        return self.raw if self.raw is not None else json.dumps(self.payload).encode("utf-8")


class FakeApiRequest:
    def __init__(self, published, detail_status, detail_response=None):
        self.published = published
        self.detail_status = detail_status
        self.detail_response = detail_response
        self.urls = []

    def get(self, url, *, timeout, max_redirects):
        self.urls.append(url)
        if timeout != 10000 or max_redirects != 0:
            raise AssertionError("Unsafe public API discovery request")
        if not url.startswith("http://127.0.0.1:8000/api/v1/public/"):
            raise AssertionError("Unsafe discovery origin")
        if "/public/projects?lang=" in url:
            return FakeApiResponse({"data": self.published.get("WEB-05", [])})
        if "/public/articles?lang=" in url:
            return FakeApiResponse({"data": self.published.get("WEB-08", [])})
        if "/public/projects/" in url or "/public/articles/" in url:
            if self.detail_response is not None:
                return self.detail_response
            is_project = "/public/projects/" in url
            slug = url.split("/public/projects/" if is_project else "/public/articles/", 1)[1].split("?", 1)[0]
            proof = "evidence_urls" if is_project else "citations"
            return FakeApiResponse({"data": {
                "slug": slug,
                proof: ["https://github.com/owner/approved-repository"],
            }}, self.detail_status)
        raise AssertionError("Unexpected discovery URL")


class FakeContext:
    def __init__(self, viewport_width=1440, published=None, detail_status=200,
                 detail_response=None):
        self.viewport_width = viewport_width
        self.pages = []
        self.routes = []
        self.closed = False
        self.request = FakeApiRequest(published or {}, detail_status, detail_response)

    def route(self, pattern, callback):
        self.routes.append((pattern, callback))

    def new_page(self):
        page = FakePage(self.viewport_width)
        self.pages.append(page)
        return page

    def close(self):
        self.closed = True


def fake_playwright(*, published=None, detail_status=200, detail_response=None):
    contexts = []
    browser = Mock()

    def new_context(**kwargs):
        # The production public-audit path must never provide storage state.
        if "storage_state" in kwargs:
            raise AssertionError("Private session leaked into Web audit")
        context = FakeContext(kwargs.get("viewport", {}).get("width", 1440),
                              published=published, detail_status=detail_status,
                              detail_response=detail_response)
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

    def test_unrelated_t007_pixel_gate_excludes_lead_481_qa_branches(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(
            encoding="utf-8")
        self.assertIn("name: T007 real Laravel visual diagnostics", workflow)
        self.assertIn("!startsWith(github.head_ref, 'feat/481-')", workflow)
        self.assertIn("github.head_ref == 'feat/T007-runtime-parity-final'",
                      workflow)
        self.assertIn("name: Issue 481 real authenticated Admin runtime diagnostics",
                      workflow)

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

    def test_72_real_route_slots_stay_anonymous_and_keep_external_domains_blocked(self):
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
        self.assertEqual(report["dynamic_web_verified"], [])
        self.assertEqual(report["static_checks"], 72)
        self.assertEqual(report["dynamic_checks"], 0)
        self.assertEqual(len(contexts), 7)
        self.assertEqual(contexts[0].pages, [])
        self.assertTrue(contexts[0].closed)
        self.assertEqual({ctx.viewport_width for ctx in contexts[1:]},
                         {1440, 768, 390})
        self.assertEqual(len({(r["screen"], r["locale"], r["viewport"])
                              for r in report["results"]}), 72)
        self.assertEqual({r["viewport"] for r in report["results"]},
                         {"desktop", "tablet", "mobile"})
        self.assertTrue(all(len(ctx.pages) == 12 and ctx.closed
                            for ctx in contexts[1:]))
        self.assertTrue(all(p.closed for ctx in contexts[1:] for p in ctx.pages))
        for context in contexts[1:]:
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

    def test_real_published_project_adds_six_anonymous_detail_browser_checks(self):
        manager, contexts, browser = fake_playwright(
            published={"WEB-05": [{"slug": "approved-public-project"}]},
        )
        with patch("qa.audit_web_runtime.exact_checkout"), \
             patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
            report = audit_web(
                "http://127.0.0.1:8000", SHA, root=ROOT,
                playwright_manager=manager,
            )
        self.assertEqual(report["screen_checks"], 78)
        self.assertEqual(report["static_checks"], 72)
        self.assertEqual(report["dynamic_checks"], 6)
        self.assertEqual(report["dynamic_web_verified"], ["WEB-05"])
        self.assertEqual(report["dynamic_web_pending"], ["WEB-08"])
        detail = [x for x in report["results"] if x["screen"] == "WEB-05"]
        self.assertEqual(len(detail), 6)
        self.assertEqual({(x["locale"], x["viewport"]) for x in detail},
                         {(lang, view) for lang in ("en", "ar")
                          for view in ("desktop", "tablet", "mobile")})
        self.assertTrue(all(x["status"] == "pass" for x in detail))
        self.assertEqual(len(contexts), 7)
        self.assertEqual(sum(len(ctx.pages) for ctx in contexts), 78)
        self.assertNotIn("approved-public-project", json.dumps(report))
        self.assertFalse(report["evidence_admitted"])
        browser.close.assert_called_once()

    def test_both_really_published_details_require_twelve_extra_browser_checks(self):
        manager, contexts, _ = fake_playwright(published={
            "WEB-05": [{"slug": "approved-project"}],
            "WEB-08": [{"slug": "verified-article"}],
        })
        with patch("qa.audit_web_runtime.exact_checkout"), \
             patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
            report = audit_web(
                "http://127.0.0.1:8000", SHA, root=ROOT,
                playwright_manager=manager,
            )
        self.assertEqual(report["screen_checks"], 84)
        self.assertEqual(report["dynamic_checks"], 12)
        self.assertEqual(report["dynamic_web_pending"], [])
        self.assertEqual(report["dynamic_web_verified"], ["WEB-05", "WEB-08"])
        self.assertTrue(all(len(ctx.pages) == 14 for ctx in contexts[1:]))
        self.assertNotIn("verified-article", json.dumps(report))

    def test_private_or_invalid_detail_discovery_fails_closed_before_browser_capture(self):
        for published, status in [
            ({"WEB-05": [{"slug": "../private"}]}, 200),
            ({"WEB-08": [{"slug": "valid-public-article"}]}, 404),
            ({"WEB-05": [{"slug": "not/allowed"}]}, 200),
        ]:
            with self.subTest(published=published, status=status):
                manager, contexts, _ = fake_playwright(
                    published=published, detail_status=status,
                )
                with patch("qa.audit_web_runtime.exact_checkout"), \
                     patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
                    with self.assertRaises(AdminSessionError):
                        audit_web("http://127.0.0.1:8000", SHA, root=ROOT,
                                  playwright_manager=manager)
                self.assertEqual(len(contexts), 1)
                self.assertTrue(contexts[0].closed)
                self.assertEqual(contexts[0].pages, [])

    def test_detail_identity_evidence_and_json_integrity_fail_closed(self):
        approved = {"WEB-05": [{"slug": "approved-project"}]}
        good_data = {"slug": "approved-project", "evidence_urls": [
            "https://github.com/owner/approved-repository",
        ]}
        malicious = [
            FakeApiResponse({"data": {"slug": "another-project",
                                      "evidence_urls": good_data["evidence_urls"]}}),
            FakeApiResponse({"data": {"slug": "approved-project", "evidence_urls": []}}),
            FakeApiResponse({"data": {"slug": "approved-project",
                                      "citations": good_data["evidence_urls"]}}),
            FakeApiResponse({"data": {"slug": "approved-project",
                                      "evidence_urls": ["http://untrusted.invalid"]}}),
            FakeApiResponse({"data": [good_data]}),
            FakeApiResponse({"data": good_data}, content_type="text/html"),
            FakeApiResponse({"data": good_data}, raw=b"\\xff"),
            FakeApiResponse({"data": good_data}, raw=b"not-json"),
            FakeApiResponse({"data": good_data},
                            raw=b"x" * (MAX_DISCOVERY_BYTES + 1)),
            FakeApiResponse({"data": good_data}, status=404),
        ]
        for detail in malicious:
            with self.subTest(detail=detail.status, type=detail.headers["content-type"]):
                manager, contexts, browser = fake_playwright(
                    published=approved, detail_response=detail)
                with patch("qa.audit_web_runtime.exact_checkout"), \\
                     patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
                    with self.assertRaises(AdminSessionError) as rejected:
                        audit_web("http://127.0.0.1:8000", SHA, root=ROOT,
                                  playwright_manager=manager)
                self.assertEqual(len(contexts), 1)
                self.assertEqual(contexts[0].pages, [])
                self.assertTrue(contexts[0].closed)
                self.assertNotIn("approved-project", str(rejected.exception))
                browser.close.assert_called_once()

        # WEB-08 must independently prove its own pinned slug and citations,
        # not a different publication's evidence_urls.
        manager, contexts, _ = fake_playwright(
            published={"WEB-08": [{"slug": "approved-article"}]},
            detail_response=FakeApiResponse({"data": {
                "slug": "approved-article",
                "evidence_urls": ["https://github.com/owner/repo"],
            }}))
        with patch("qa.audit_web_runtime.exact_checkout"), \\
             patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
            with self.assertRaises(AdminSessionError):
                audit_web("http://127.0.0.1:8000", SHA, root=ROOT,
                          playwright_manager=manager)
        self.assertTrue(contexts[0].closed)

    def test_overflow_structure_is_bounded_and_never_records_dom_text_or_url(self):
        valid = {
            "viewport_px": 390, "scanned_nodes": 200, "truncated": False,
            "candidates": [
                {"tag": "div", "component": "expertise-tab-nav", "overhang_px": 24},
                {"tag": "svg", "component": "", "overhang_px": 3},
            ],
        }
        self.assertIn("document.body.querySelectorAll('*')", OVERFLOW_STRUCTURE)
        self.assertNotIn("innerText", OVERFLOW_STRUCTURE)
        self.assertNotIn("outerHTML", OVERFLOW_STRUCTURE)
        report = safe_overflow_structure(valid)
        self.assertEqual(report["status"], "structural_hint_only")
        self.assertEqual(report["candidates"][0]["component"], "expertise-tab-nav")
        self.assertEqual(report["candidates"][0]["overhang_px"], 24)
        self.assertNotIn("secret", json.dumps(report).lower())

        malicious = dict(valid)
        for bad in [
            {"tag": "a", "component": "secret-token-PRIVATE", "overhang_px": 30},
            {"tag": "div", "component": "expertise-tab-nav", "overhang_px": -1},
            {"tag": "script", "component": "wa-foo", "overhang_px": 22,
             "href": "https://private.example.test/secret"},
        ]:
            malicious["candidates"] = [bad]
            report = safe_overflow_structure(malicious)
            self.assertEqual(report, {"status": "unavailable", "candidates": []})
            self.assertNotIn("private.example", json.dumps(report))
        self.assertEqual(safe_overflow_structure({"arbitrary": "private"}),
                         {"status": "unavailable", "candidates": []})
        self.assertEqual(safe_overflow_structure(True),
                         {"status": "unavailable", "candidates": []})

    def test_real_audit_overflow_attribution_only_on_mobile_web03(self):
        manager, contexts, browser = fake_playwright()
        with patch.object(FakePage, "emit_web03_overflow", True), \
             patch("qa.audit_web_runtime.exact_checkout"), \
             patch.dict(os.environ, {"WALIDSITE_CAPTURE_STORAGE_STATE": ""}):
            report = audit_web(
                "http://127.0.0.1:8000", SHA, root=ROOT,
                playwright_manager=manager,
            )
        affected = [r for r in report["results"]
                    if "document_horizontal_overflow" in r["defects"]]
        self.assertEqual(len(affected), 2)
        self.assertEqual({r["screen"] for r in affected}, {"WEB-03"})
        self.assertEqual({r["locale"] for r in affected}, {"en", "ar"})
        self.assertEqual({r["viewport"] for r in affected}, {"mobile"})
        self.assertTrue(all(r["overflow_structure"]["status"]
                            == "structural_hint_only" for r in affected))
        self.assertTrue(all(r["overflow_structure"]["candidates"][0]["component"]
                            == "expertise-tab-nav" for r in affected))
        self.assertEqual(report["failed_checks"], 2)
        self.assertEqual(report["fatal_checks"], 0)
        self.assertFalse(report["evidence_admitted"])
        self.assertTrue(all("overflow_structure" not in r for r in report["results"]
                            if r not in affected))
        browser.close.assert_called_once()

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
            "screen_checks": 72, "failed_checks": 1,
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
            self.assertEqual(json.loads(output.read_text())["screen_checks"], 72)
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
