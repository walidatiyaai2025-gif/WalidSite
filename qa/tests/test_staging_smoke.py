"""T025 staging-smoke synthetic transport tests; no deployment receipts."""
import io
import json
import tempfile
import threading
import time
import unittest
from contextlib import redirect_stdout, redirect_stderr
from http.client import HTTPException
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

    def read1(self, size=-1):
        # Mirrors HTTPResponse.read1: return available bytes, not a fixed
        # exact-length read that can wait indefinitely on slow peers.
        return self.body.read1(size)

    def close(self):
        self.closed = True


class FakeOpener:
    def __init__(self, override=None, redirect_location=None):
        self.override = override or {}
        self.redirect_location = redirect_location or {}
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
        if spec.redirect_to is not None:
            response.headers["Location"] = self.redirect_location.get(
                path, "https://staging.example.com" + spec.redirect_to
            )
        self.responses.append(response)
        if code >= 400:
            raise HTTPError(request.full_url, code, "blocked", response.headers, response)
        return response


def permitted_run(origin, *args, **kwargs):
    return run(origin, *args, approved_host="staging.example.com", **kwargs)


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

    def test_public_and_private_live_endpoints_are_read_only_and_expected_count(self):
        fake = FakeOpener()
        receipt = permitted_run("https://staging.example.com", fake)
        self.assertEqual(len(receipt["checks"]), 19)
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
            ("/install", (200, "html", None)),
            ("/admin/updates", (200, "html", None)),
            ("/api/v1/mobile/me", (200, "json", b'{"data": {}}')),
            ("/api/v1/admin/dashboard", (403, "json", b'{"message": "forbidden"}')),
        ]:
            with self.subTest(path=path):
                report = permitted_run("https://staging.example.com", FakeOpener({path: override}))
                self.assertFalse(report["http_smoke_passed"])
                self.assertEqual([x["path"] for x in report["checks"] if not x["passed"]], [path])

    def test_owner_updates_redirect_must_be_exact_same_origin_login(self):
        """Guest Admin must not pass via a 302 to a remote or wrong target."""
        for expected in ("/admin/login", "https://staging.example.com/admin/login"):
            with self.subTest(valid=expected):
                fake = FakeOpener(redirect_location={"/admin/updates": expected})
                report = permitted_run("https://staging.example.com", fake)
                self.assertTrue(report["http_smoke_passed"])
                self.assertEqual(fake.requested[-1][0],
                                 "https://staging.example.com/api/v1/admin/dashboard")

        for unsafe in (
            "", "//evil.example.com/admin/login",
            "https://evil.example.com/admin/login",
            "http://staging.example.com/admin/login",
            "https://staging.example.com/admin/login?next=%2Fadmin%2Fupdates",
            "/admin/login/../updates", "/admin/login?next=/admin/updates",
        ):
            with self.subTest(unsafe=unsafe):
                report = permitted_run(
                    "https://staging.example.com",
                    FakeOpener(redirect_location={"/admin/updates": unsafe}),
                )
                failures = [x for x in report["checks"] if not x["passed"]]
                self.assertEqual(len(failures), 1)
                self.assertEqual(failures[0]["path"], "/admin/updates")
                self.assertEqual(failures[0]["reason"], "unsafe_or_unexpected_redirect")
                self.assertFalse(report["http_smoke_passed"])

    def test_private_laravel_paths_deny_without_reading_any_private_response_body(self):
        private = [check for check in CHECKS if check.kind == "private_denied"]
        self.assertEqual(len(private), 8)
        self.assertEqual(len({check.path for check in private}), 8)
        self.assertEqual(len({check.path for check in CHECKS}), len(CHECKS))

        for spec in private:
            for accepted_status in (403, 404):
                with self.subTest(path=spec.path, status=accepted_status):
                    class PrivateUnreadableOpener(FakeOpener):
                        def open(self, request, timeout):
                            response = super().open(request, timeout)
                            return response

                    fake = PrivateUnreadableOpener({
                        spec.path: (accepted_status, "private_denied",
                                    b"APP_KEY=DO_NOT_READ_THIS_PRIVATE_VALUE"),
                    })
                    report = permitted_run("https://staging.example.com", fake)
                    self.assertTrue(report["http_smoke_passed"])
                    self.assertNotIn("DO_NOT_READ_THIS_PRIVATE_VALUE", json.dumps(report))
                    self.assertTrue(all(response.closed for response in fake.responses))
                    # The underlying fake response still contains the private
                    # fixture bytes, but no private response content was read.
                    private_response = next(
                        response for request, response in zip(fake.requested, fake.responses)
                        if request[0].split("staging.example.com", 1)[-1] == spec.path
                    )
                    self.assertEqual(private_response.body.tell(), 0)

    def test_any_exposed_private_file_redirect_or_server_error_blocks_staging(self):
        private = [check for check in CHECKS if check.kind == "private_denied"]
        for spec in private:
            for status in (200, 204, 301, 302, 307, 500):
                with self.subTest(path=spec.path, status=status):
                    fake = FakeOpener({
                        spec.path: (status, "private_denied",
                                    b"APP_KEY=NEVER_EMIT_PRIVATE_CONTENT"),
                    })
                    report = permitted_run("https://staging.example.com", fake)
                    failed = [row for row in report["checks"] if not row["passed"]]
                    self.assertFalse(report["http_smoke_passed"])
                    self.assertEqual(len(failed), 1)
                    self.assertEqual(failed[0]["path"], spec.path)
                    self.assertEqual(failed[0]["observed_status"], status)
                    self.assertEqual(failed[0]["reason"], "private_resource_not_denied")
                    self.assertNotIn("NEVER_EMIT_PRIVATE_CONTENT", json.dumps(report))
                    self.assertTrue(all(response.closed for response in fake.responses))
                    private_response = next(
                        response for request, response in zip(fake.requested, fake.responses)
                        if request[0].split("staging.example.com", 1)[-1] == spec.path
                    )
                    self.assertEqual(private_response.body.tell(), 0)

    def test_public_json_payloads_must_have_matching_data_shape(self):
        for override in [
            (200, "html", b'<html></html>'),
            (200, "json", b'invalid'),
            (200, "json", b'[]'),
            (200, "json", b'{"data": []}'),
            (200, "json", b'{"data": "' + b'x' * (1024 * 1024) + b'"}'),
        ]:
            with self.subTest(kind=override[1], length=len(override[2])):
                report = permitted_run("https://staging.example.com", FakeOpener({
                    "/api/v1/public/home": override,
                }))
                self.assertFalse(report["http_smoke_passed"])
                self.assertEqual([x["path"] for x in report["checks"] if not x["passed"]],
                                 ["/api/v1/public/home"])

    def test_json_body_drip_is_stopped_by_wall_clock_budget(self):
        """One byte at a time cannot keep the release smoke hung forever."""
        clock = [0.0]

        class DrippingOpener(FakeOpener):
            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith("/api/v1/public/home"):
                    original = response.read1

                    def slow_drip(size):
                        clock[0] += 0.6
                        return original(1)

                    response.read1 = slow_drip
                return response

        opener = DrippingOpener({
            "/api/v1/public/home": (
                200, "json", b'{"data":{"private":"do-not-log-this-token"}}',
            ),
        })
        with patch("qa.staging_smoke.time.monotonic", side_effect=lambda: clock[0]):
            report = permitted_run("https://staging.example.com", opener, timeout=2)

        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(
            [(x["path"], x["reason"]) for x in report["checks"] if not x["passed"]],
            [("/api/v1/public/home", "json_body_deadline_exceeded")],
        )
        self.assertLess(clock[0], 3)
        self.assertTrue(all(x.closed for x in opener.responses))
        self.assertNotIn("do-not-log-this-token", json.dumps(report))

    def test_blocked_https_headers_or_body_cannot_exceed_whole_request_budget(self):
        """Socket/header/body stalls fail within deadline, without raw values."""
        for mode in ("headers", "body"):
            entered = threading.Event()
            release = threading.Event()

            class BlockingOpener(FakeOpener):
                def open(self, request, timeout):
                    blocked = request.full_url.endswith("/api/v1/public/home")
                    if blocked and mode == "headers":
                        entered.set()
                        release.wait(5)
                    response = super().open(request, timeout)
                    if blocked and mode == "body":
                        original = response.read1

                        def blocked_read(size):
                            entered.set()
                            release.wait(5)
                            return original(size)

                        response.read1 = blocked_read
                    return response

            opener = BlockingOpener({
                "/api/v1/public/home": (
                    200, "json", b'{"data":{"secret":"do-not-print"}}',
                ),
            })
            try:
                start = time.perf_counter()
                report = permitted_run("https://staging.example.com", opener, timeout=1)
                elapsed = time.perf_counter() - start
                self.assertTrue(entered.is_set(), mode)
                self.assertLess(elapsed, 1.9, mode)
                failures = [item for item in report["checks"] if not item["passed"]]
                self.assertEqual(
                    [(item["path"], item["reason"]) for item in failures],
                    [("/api/v1/public/home", "request_deadline_exceeded")],
                    mode,
                )
                self.assertIsNone(failures[0]["observed_status"])
                self.assertFalse(report["http_smoke_passed"])
                self.assertNotIn("do-not-print", json.dumps(report))
            finally:
                # Unblock the test worker for cleanup; no production GET has
                # ever been permitted to send data or perform a write.
                release.set()

    def test_json_body_inherits_budget_spent_before_http_headers(self):
        """A late header must not buy a second full JSON read deadline."""
        clock = [0.0]

        class SlowHeadersOpener(FakeOpener):
            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith("/api/v1/public/home"):
                    clock[0] += 0.85
                    original = response.read1

                    def first_chunk(size):
                        clock[0] += 0.25
                        return original(size)

                    response.read1 = first_chunk
                return response

        opener = SlowHeadersOpener()
        with patch("qa.staging_smoke.time.monotonic", side_effect=lambda: clock[0]):
            report = permitted_run("https://staging.example.com", opener, timeout=1)
        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(
            [(item["path"], item["reason"]) for item in report["checks"] if not item["passed"]],
            [("/api/v1/public/home", "json_body_deadline_exceeded")],
        )
        self.assertTrue(all(response.closed for response in opener.responses))

    def test_single_slow_read_fails_even_when_full_valid_json_is_returned(self):
        clock = [0.0]

        class SlowReadOpener(FakeOpener):
            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith("/api/v1/public/home"):
                    original = response.read1

                    def late_complete(size):
                        clock[0] += 2
                        return original(size)

                    response.read1 = late_complete
                return response

        opener = SlowReadOpener()
        with patch("qa.staging_smoke.time.monotonic", side_effect=lambda: clock[0]):
            report = permitted_run("https://staging.example.com", opener, timeout=1)
        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(
            next(x for x in report["checks"] if x["path"] == "/api/v1/public/home")["reason"],
            "json_body_deadline_exceeded",
        )
        self.assertTrue(all(x.closed for x in opener.responses))

    def test_custom_response_without_read1_uses_one_byte_reads_only(self):
        requested = []

        class ExactReadOnly(FakeOpener):
            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith("/api/v1/public/home"):
                    original = response.read

                    def exact_read(n):
                        requested.append(n)
                        if n != 1:
                            raise AssertionError("Unbounded exact-length read")
                        return original(n)

                    response.read = exact_read
                    response.read1 = None
                return response

        opener = ExactReadOnly()
        receipt = permitted_run("https://staging.example.com", opener)
        self.assertTrue(receipt["http_smoke_passed"])
        self.assertTrue(requested)
        self.assertEqual(set(requested), {1})
        self.assertTrue(all(x.closed for x in opener.responses))

    def test_malformed_stream_and_http_exception_fail_closed_without_leaking(self):
        for mode in ("invalid_chunk", "http_exception"):
            class BrokenOpener(FakeOpener):
                def open(self, request, timeout):
                    response = super().open(request, timeout)
                    if request.full_url.endswith("/api/v1/public/home"):
                        if mode == "invalid_chunk":
                            response.read1 = lambda n: "password=sensitive"
                        else:
                            def reject_chunk(n):
                                raise HTTPException("password=sensitive")
                            response.read1 = reject_chunk
                    return response

            with self.subTest(mode=mode):
                opener = BrokenOpener()
                report = permitted_run("https://staging.example.com", opener)
                self.assertFalse(report["http_smoke_passed"])
                self.assertNotIn("password=sensitive", json.dumps(report))
                self.assertEqual(
                    [row["path"] for row in report["checks"] if not row["passed"]],
                    ["/api/v1/public/home"],
                )
                self.assertTrue(all(x.closed for x in opener.responses))

    def test_bad_transport_never_reports_success_or_leaks_raw_error(self):
        class Offline:
            def open(self, request, timeout):
                raise URLError("secret=leak-this-host-auth-token")

        report = permitted_run("https://staging.example.com", Offline())
        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(len(report["checks"]), 19)
        self.assertNotIn("leak-this-host-auth-token", json.dumps(report))
        self.assertTrue(all(x["observed_status"] is None for x in report["checks"]))

    def test_operator_hostname_is_required_before_any_network_access(self):
        fake = FakeOpener()
        for approved in [None, "other.example.com", "STAGING.example.com", "*"]:
            with self.subTest(approved=approved):
                with self.assertRaisesRegex(ValueError, "approved staging hostname"):
                    run("https://staging.example.com", fake, approved_host=approved)
        self.assertEqual(fake.requested, [])
        report = permitted_run("https://staging.example.com", fake)
        self.assertFalse(report["network_egress_isolation_verified"])
        self.assertEqual(report["operator_approved_host"], "staging.example.com")

    def test_redirect_handler_never_constructs_a_followup_request(self):
        handler = NoRedirects()
        self.assertIsNone(handler.redirect_request(None, None, 302, "Redirect", {},
                                                   "http://127.0.0.1:8080/admin"))

    def test_invalid_timeout_is_rejected_without_http_requests(self):
        for value in [0, 31, 1.5, True, "5"]:
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    permitted_run("https://staging.example.com", FakeOpener(), timeout=value)

    def test_cli_does_not_overwrite_existing_receipt_or_make_fake_provenance(self):
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder) / "receipt.json"
            target.write_text("DO NOT OVERWRITE", encoding="utf-8")
            with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                with patch("qa.staging_smoke.run", return_value={"http_smoke_passed": True}):
                    self.assertEqual(main(["--url", "https://staging.example.com",
                                           "--approved-host", "staging.example.com",
                                           "--output", str(target)]), 2)
            self.assertEqual(target.read_text(encoding="utf-8"), "DO NOT OVERWRITE")
            with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                self.assertEqual(main(["--url", "http://localhost", "--approved-host", "staging.example.com"]), 2)


if __name__ == "__main__":
    unittest.main()
