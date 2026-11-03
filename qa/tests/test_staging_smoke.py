"""T025 staging-smoke synthetic transport tests; no deployment receipts."""
import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError, URLError

from qa.staging_smoke import CHECKS, NoRedirects, main, run, staging_origin


class FakeResponse:
    def __init__(self, code, content_type, data=b""):
        self.status = code
        self.code = code
        self.headers = {"Content-Type": content_type}
        self.body = io.BytesIO(data)
        self.closed = False

    def read(self, size=-1):
        return self.body.read(size)

    def close(self):
        self.closed = True


class FakeOpener:
    def __init__(self, override=None):
        self.override = override or {}
        self.requested = []
        self.responses = []

    def open(self, request, timeout):
        self.requested.append((request.full_url, request.get_method(), timeout))
        path = request.full_url.split("staging.example.com", 1)[-1]
        spec = next(check for check in CHECKS if check.path == path)
        code, kind, body = self.override.get(path, (spec.status, spec.kind, None))
        if body is None:
            body = json.dumps({"data": {} if spec.data_type is dict else []}).encode()
        response = FakeResponse(code, "application/json" if kind == "json" else "text/html", body)
        self.responses.append(response)
        if code >= 400:
            raise HTTPError(request.full_url, code, "blocked", response.headers, response)
        return response


class StagingSmokeTest(unittest.TestCase):
    def test_https_origin_is_strictly_bounded_and_normalized(self):
        self.assertEqual(staging_origin("https://STAGING.example.com/"), "https://staging.example.com")
        self.assertEqual(staging_origin("https://staging.example.com:443"), "https://staging.example.com:443")
        for url in [
            "http://staging.example.com", "https://localhost", "https://127.0.0.1",
            "https://[::1]", "https://10.0.0.1", "https://internal.corp",
            "https://staging.example.test", "https://service.local", "https://node.svc",
            "https://user:pass@staging.example.com", "https://staging.example.com:8443",
            "https://staging.example.com/admin", "https://staging.example.com?token=secret",
            "https://staging.example.com#fragment", "https://-bad.example.com",
            "https://staging.example.com.", "https://staging.example.com\\evil",
            "https://staging.example.com\nHost:evil", "https://staging.example.com@evil.test",
        ]:
            with self.subTest(url=url):
                with self.assertRaises(ValueError):
                    staging_origin(url)

    def test_all_nine_live_endpoints_are_read_only_and_expected_responses_count(self):
        fake = FakeOpener()
        receipt = run("https://staging.example.com", fake)
        self.assertEqual(len(receipt["checks"]), 9)
        self.assertTrue(receipt["http_smoke_passed"])
        self.assertFalse(receipt["production_ready"])
        self.assertFalse(receipt["deployment_release_sha_verified"])
        self.assertFalse(receipt["backup_restore_verified"])
        self.assertFalse(receipt["rollback_verified"])
        self.assertEqual([row["path"] for row in receipt["checks"]], [s.path for s in CHECKS])
        self.assertTrue(all(method == "GET" and timeout == 8 for _, method, timeout in fake.requested))
        self.assertTrue(all(response.closed for response in fake.responses))
        self.assertFalse(any("password" in json.dumps(item) for item in receipt["checks"]))

    def test_unavailable_page_and_unprotected_login_cannot_pass(self):
        for path, override in [
            ("/up", (503, "html", None)),
            ("/", (302, "html", None)),
            ("/admin/login", (404, "html", None)),
            ("/setup", (200, "html", None)),
            ("/api/v1/mobile/me", (200, "json", b'{"data": {}}')),
            ("/api/v1/admin/dashboard", (403, "json", b'{"message": "forbidden"}')),
        ]:
            with self.subTest(path=path):
                report = run("https://staging.example.com", FakeOpener({path: override}))
                self.assertFalse(report["http_smoke_passed"])
                self.assertEqual([x["path"] for x in report["checks"] if not x["passed"]], [path])

    def test_public_json_payloads_must_have_matching_data_shape(self):
        for override in [
            (200, "html", b'<html></html>'),
            (200, "json", b'invalid'),
            (200, "json", b'[]'),
            (200, "json", b'{"data": []}'),
            (200, "json", b'{"data": "' + b'x' * (1024 * 1024) + b'"}'),
        ]:
            with self.subTest(kind=override[1], length=len(override[2])):
                report = run("https://staging.example.com", FakeOpener({
                    "/api/v1/public/home": override,
                }))
                self.assertFalse(report["http_smoke_passed"])
                self.assertEqual([x["path"] for x in report["checks"] if not x["passed"]],
                                 ["/api/v1/public/home"])

    def test_bad_transport_never_reports_success_or_leaks_raw_error(self):
        class Offline:
            def open(self, request, timeout):
                raise URLError("secret=leak-this-host-auth-token")

        report = run("https://staging.example.com", Offline())
        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(len(report["checks"]), 9)
        self.assertNotIn("leak-this-host-auth-token", json.dumps(report))
        self.assertTrue(all(x["observed_status"] is None for x in report["checks"]))

    def test_redirect_handler_never_constructs_a_followup_request(self):
        handler = NoRedirects()
        self.assertIsNone(handler.redirect_request(None, None, 302, "Redirect", {},
                                                   "http://127.0.0.1:8080/admin"))

    def test_invalid_timeout_is_rejected_without_http_requests(self):
        for value in [0, 31, 1.5, True, "5"]:
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    run("https://staging.example.com", FakeOpener(), timeout=value)

    def test_cli_does_not_overwrite_existing_receipt_or_make_fake_provenance(self):
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder) / "receipt.json"
            target.write_text("DO NOT OVERWRITE", encoding="utf-8")
            with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                with patch("qa.staging_smoke.run", return_value={"http_smoke_passed": True}):
                    self.assertEqual(main(["--url", "https://staging.example.com",
                                           "--output", str(target)]), 2)
            self.assertEqual(target.read_text(encoding="utf-8"), "DO NOT OVERWRITE")
            with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                self.assertEqual(main(["--url", "http://localhost"]), 2)


if __name__ == "__main__":
    unittest.main()
