"""T025 staging-smoke synthetic transport tests; no deployment receipts."""
import io
import json
import os
import socket
import ssl
import tempfile
import threading
import time
import unittest
from contextlib import redirect_stdout, redirect_stderr
from http.client import HTTPException
from pathlib import Path
from unittest.mock import Mock, patch
from urllib.error import HTTPError, URLError
from urllib.request import ProxyHandler, Request, getproxies

from qa.staging_smoke import (CHECKS, NoRedirects, PinnedPublicHTTPSConnection,
                              PublicDNSPinnedHTTPSHandler, main, run, staging_origin)


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
        self.assertEqual(len(receipt["checks"]), 28)
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
        self.assertEqual(len(private), 17)
        self.assertEqual(len({check.path for check in private}), 17)
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

    def test_private_backup_and_cached_config_probes_are_never_read(self):
        sensitive_paths = {
            "/.env.bak", "/.env.backup",
            "/application/database/database.sqlite",
            "/application/bootstrap/cache/config.php",
            "/.walidsite-active-release.json",
            "/.walidsite-update-maintenance.json",
            "/.walidsite-writer-drain.lock",
            "/.walidsite-releases/",
            "/.walidsite-backups/",
        }
        self.assertTrue(sensitive_paths.issubset({spec.path for spec in CHECKS}))
        for path in sorted(sensitive_paths):
            with self.subTest(path=path):
                secret = b"APP_KEY=NEVER_READ_OR_EMIT_THIS_VALUE"
                fake = FakeOpener({path: (200, "private_denied", secret)})
                report = permitted_run("https://staging.example.com", fake)
                self.assertFalse(report["http_smoke_passed"])
                failed = [item for item in report["checks"] if not item["passed"]]
                self.assertEqual([(item["path"], item["reason"]) for item in failed],
                                 [(path, "private_resource_not_denied")])
                self.assertNotIn(secret.decode(), json.dumps(report))
                self.assertTrue(all(response.closed for response in fake.responses))
                exposed = next(response for request, response in
                               zip(fake.requested, fake.responses)
                               if request[0].endswith(path))
                self.assertEqual(exposed.body.tell(), 0)

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

    def test_response_mime_essence_must_match_without_substring_bypass(self):
        class MimeOpener(FakeOpener):
            def __init__(self, content_type, path):
                super().__init__()
                self.content_type = content_type
                self.path = path

            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith(self.path):
                    response.headers["Content-Type"] = self.content_type
                return response

        for path, valid, invalid in (
            ("/up", ("text/html", "Text/HTML; charset=utf-8"),
             ("text/html-extra", "x-text/html", "text/plain;type=text/html")),
            ("/api/v1/public/home",
             ("application/json", "Application/JSON; charset=utf-8"),
             ("application/json-extra", "text/application/json",
              "text/plain; type=application/json")),
        ):
            for mime in valid:
                with self.subTest(path=path, mime=mime):
                    fake = MimeOpener(mime, path)
                    self.assertTrue(permitted_run(
                        "https://staging.example.com", fake)["http_smoke_passed"])
                    self.assertTrue(all(x.closed for x in fake.responses))
            for mime in invalid:
                with self.subTest(path=path, mime=mime):
                    fake = MimeOpener(mime, path)
                    receipt = permitted_run("https://staging.example.com", fake)
                    self.assertFalse(receipt["http_smoke_passed"])
                    self.assertEqual(
                        [(r["path"], r["reason"]) for r in receipt["checks"]
                         if not r["passed"]],
                        [(path, "non_html_response" if path == "/up"
                          else "non_json_response")],
                    )
                    self.assertTrue(all(x.closed for x in fake.responses))

    def test_ambiguous_update_login_redirect_location_headers_fail_closed(self):
        from email.message import Message

        class RedirectHeadersOpener(FakeOpener):
            def __init__(self, locations):
                super().__init__()
                self.locations = locations

            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith("/admin/updates"):
                    headers = Message()
                    for location in self.locations:
                        headers["Location"] = location
                    response.headers = headers
                return response

        valid = [
            ["/admin/login"],
            ["https://staging.example.com/admin/login"],
        ]
        invalid = [
            [],
            ["/admin/login", "/admin/login"],
            ["/admin/login", "https://evil.example.org/login"],
            ["https://evil.example.org/login", "/admin/login"],
            ["/admin/login, https://evil.example.org/login"],
            ["https://staging.example.com/admin/login?next=evil"],
        ]
        for locations in valid:
            with self.subTest(valid=locations):
                fake = RedirectHeadersOpener(locations)
                receipt = permitted_run("https://staging.example.com", fake)
                self.assertTrue(receipt["http_smoke_passed"])
                self.assertTrue(all(resp.closed for resp in fake.responses))
        for locations in invalid:
            with self.subTest(invalid=locations):
                fake = RedirectHeadersOpener(locations)
                receipt = permitted_run("https://staging.example.com", fake)
                self.assertFalse(receipt["http_smoke_passed"])
                self.assertEqual(
                    [(row["path"], row["reason"]) for row in receipt["checks"]
                     if not row["passed"]],
                    [("/admin/updates", "unsafe_or_unexpected_redirect")],
                )
                self.assertTrue(all(resp.closed for resp in fake.responses))

    def test_repeated_or_ambiguous_mime_headers_fail_without_reading_body(self):
        from email.message import Message

        class HeaderOpener(FakeOpener):
            def __init__(self, values, path):
                super().__init__()
                self.values = values
                self.path = path

            def open(self, request, timeout):
                response = super().open(request, timeout)
                if request.full_url.endswith(self.path):
                    headers = Message()
                    for value in self.values:
                        headers["Content-Type"] = value
                    response.headers = headers
                return response

        for path, valid, invalid in (
            ("/up", ["text/html; charset=UTF-8"],
             [["text/html", "text/html"],
              ["text/html", "application/json"],
              ["text/html, application/json"],
              ["text/html; charset=UTF-8; CHARSET=ASCII"],
              ["text/html; charset="],
              ["text/html; charset=UTF-8\\nX-Injected: yes"]]),
            ("/api/v1/public/home", ["application/json; charset=\"utf-8\""],
             [["application/json", "application/json"],
              ["application/json", "text/html"],
              ["application/json, text/html"],
              ["application/json; charset=UTF-8; charset=ASCII"],
              ["application/json; charset="],
              ["application/json; charset=UTF-8\\rX-Injected: yes"]]),
        ):
            fake = HeaderOpener(valid, path)
            self.assertTrue(permitted_run(
                "https://staging.example.com", fake)["http_smoke_passed"])
            self.assertTrue(all(response.closed for response in fake.responses))
            for values in invalid:
                with self.subTest(path=path, values=values):
                    fake = HeaderOpener(values, path)
                    receipt = permitted_run("https://staging.example.com", fake)
                    self.assertFalse(receipt["http_smoke_passed"])
                    self.assertEqual(
                        [(item["path"], item["reason"]) for item in
                         receipt["checks"] if not item["passed"]],
                        [(path, "non_html_response" if path == "/up"
                          else "non_json_response")],
                    )
                    self.assertTrue(all(response.closed for response in fake.responses))

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

    def test_ambiguous_or_nonfinite_public_json_fails_closed(self):
        # urllib smoke must use strict JSON semantics: last-key-wins and
        # non-standard NaN/Infinity can otherwise yield a false PASS.
        cases = {
            "duplicate_top_level": b'{"data":[],"data":{"ok":true}}',
            "escaped_duplicate": b'{"\\u0064ata":[],"data":{"ok":true}}',
            "duplicate_nested": b'{"data":{"status":0,"status":1}}',
            "nonfinite_nan": b'{"data":{"ratio":NaN}}',
            "nonfinite_infinity": b'{"data":{"ratio":Infinity}}',
            "nonfinite_negative": b'{"data":{"ratio":-Infinity}}',
        }
        for name, payload in cases.items():
            with self.subTest(name=name):
                fake = FakeOpener({
                    "/api/v1/public/home": (200, "json", payload),
                })
                report = permitted_run("https://staging.example.com", fake)
                self.assertFalse(report["http_smoke_passed"])
                self.assertEqual(
                    [(x["path"], x["reason"]) for x in report["checks"]
                     if not x["passed"]],
                    [("/api/v1/public/home", "invalid_json_response")],
                )
                self.assertEqual(len(report["checks"]), len(CHECKS))
                self.assertFalse(report["production_ready"])
                self.assertTrue(all(x.closed for x in fake.responses))
                self.assertNotIn(payload.decode("utf-8"), json.dumps(report))

        # Duplicate-free nested JSON remains a valid staging response.
        clean = FakeOpener({
            "/api/v1/public/home": (
                200, "json", b'{"data":{"status":"healthy","ratio":0.5}}',
            ),
        })
        self.assertTrue(permitted_run(
            "https://staging.example.com", clean,
        )["http_smoke_passed"])

    def test_deeply_nested_public_json_fails_closed_without_losing_receipt(self):
        # Valid-sized JSON can still exceed the Python decoder recursion
        # budget. The read-only staging audit must return a normal failed
        # check instead of an uncaught worker exception or traceback.
        # A 12,000-deep payload also exceeds runners with raised limits.
        secret = "PRIVATE_MARKER_NEVER_REPORT"
        hostile = (b"[" * 12000 + json.dumps(secret).encode("utf-8")
                   + b"]" * 12000)
        self.assertLess(len(hostile), 1024 * 1024)
        fake = FakeOpener({
            "/api/v1/public/home": (200, "json", hostile),
        })
        report = permitted_run("https://staging.example.com", fake)
        self.assertFalse(report["http_smoke_passed"])
        failures = [row for row in report["checks"] if not row["passed"]]
        self.assertEqual(
            [(row["path"], row["reason"]) for row in failures],
            [("/api/v1/public/home", "invalid_json_response")],
        )
        self.assertEqual(len(report["checks"]), len(CHECKS))
        self.assertFalse(report["production_ready"])
        self.assertNotIn(secret, json.dumps(report))
        self.assertTrue(all(response.closed for response in fake.responses))
        # A rejected hostile response must not poison later valid probes.
        self.assertTrue(permitted_run(
            "https://staging.example.com", FakeOpener(),
        )["http_smoke_passed"])

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

    def test_unexpected_worker_error_is_redacted_and_other_probes_continue(self):
        secret = "TOP_SECRET_TOKEN_MUST_NOT_ESCAPE"

        class ExplodingOpener(FakeOpener):
            def open(self, request, timeout):
                if request.full_url.endswith("/api/v1/public/home"):
                    raise RuntimeError(secret)
                return super().open(request, timeout)

        fake = ExplodingOpener()
        report = permitted_run("https://staging.example.com", fake)
        failures = [item for item in report["checks"] if not item["passed"]]
        self.assertEqual(
            [(x["path"], x["reason"]) for x in failures],
            [("/api/v1/public/home", "probe_worker_error")],
        )
        self.assertIsNone(failures[0]["observed_status"])
        self.assertFalse(report["http_smoke_passed"])
        self.assertFalse(report["production_ready"])
        self.assertEqual(len(report["checks"]), len(CHECKS))
        self.assertEqual(fake.requested[-1][0],
                         "https://staging.example.com/api/v1/admin/dashboard")
        self.assertNotIn(secret, json.dumps(report))
        self.assertTrue(all(response.closed for response in fake.responses))

    def test_response_close_failure_fails_probe_without_leaking_or_aborting(self):
        secret = "CLOSE_ERROR_SECRET_MUST_NOT_ESCAPE"

        class CloseExplodingResponse(FakeResponse):
            def close(self):
                self.closed = True
                raise RuntimeError(secret)

        class CloseExplodingOpener(FakeOpener):
            def open(self, request, timeout):
                if not request.full_url.endswith("/api/v1/public/home"):
                    return super().open(request, timeout)
                self.requested.append(
                    (request.full_url, request.get_method(), timeout))
                response = CloseExplodingResponse(
                    200, "application/json", b'{"data":{}}')
                self.responses.append(response)
                return response

        fake = CloseExplodingOpener()
        report = permitted_run("https://staging.example.com", fake)
        failures = [item for item in report["checks"] if not item["passed"]]
        self.assertEqual(
            [(x["path"], x["reason"]) for x in failures],
            [("/api/v1/public/home", "response_close_failed")],
        )
        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(len(report["checks"]), len(CHECKS))
        self.assertNotIn(secret, json.dumps(report))
        self.assertTrue(all(response.closed for response in fake.responses))
        self.assertTrue(permitted_run(
            "https://staging.example.com", FakeOpener()
        )["http_smoke_passed"])

    def test_bad_transport_never_reports_success_or_leaks_raw_error(self):
        class Offline:
            def open(self, request, timeout):
                raise URLError("secret=leak-this-host-auth-token")

        report = permitted_run("https://staging.example.com", Offline())
        self.assertFalse(report["http_smoke_passed"])
        self.assertEqual(len(report["checks"]), 28)
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

    def test_default_https_staging_transport_ignores_hostile_environment_proxy(self):
        # urllib build_opener() silently installs ProxyHandler() from the host
        # environment unless a ProxyHandler({}) is explicitly supplied.
        # A synthetic HTTPS_PROXY must never change who a staging audit reaches.
        proxy_environment = {
            "HTTPS_PROXY": "http://proxy.attacker.invalid:9999",
            "https_proxy": "http://proxy.attacker.invalid:9999",
            "ALL_PROXY": "http://proxy.attacker.invalid:9999",
            "all_proxy": "http://proxy.attacker.invalid:9999",
            "NO_PROXY": "",
            "no_proxy": "",
        }
        fake = FakeOpener()
        with patch.dict(os.environ, proxy_environment):
            self.assertEqual(getproxies().get("https"),
                             proxy_environment["https_proxy"])
            with patch("qa.staging_smoke.build_opener", return_value=fake) as builder:
                receipt = permitted_run("https://staging.example.com")

        builder.assert_called_once()
        handlers = builder.call_args.args
        no_proxy = [item for item in handlers if isinstance(item, ProxyHandler)]
        self.assertEqual(len(no_proxy), 1,
                         "A default urllib proxy handler would inherit HTTPS_PROXY")
        self.assertEqual(no_proxy[0].proxies, {})
        self.assertTrue(any(isinstance(item, NoRedirects) for item in handlers))
        self.assertEqual(
            [item.expected_host for item in handlers
             if isinstance(item, PublicDNSPinnedHTTPSHandler)],
            ["staging.example.com"],
        )
        self.assertTrue(receipt["http_smoke_passed"])
        self.assertFalse(receipt["network_egress_isolation_verified"])
        self.assertTrue(all(method == "GET" for _, method, _ in fake.requested))
        self.assertEqual(len(fake.requested), len(CHECKS))

    def test_public_dns_https_connection_is_pinned_and_validates_original_tls_host(self):
        handler = PublicDNSPinnedHTTPSHandler("staging.example.com")
        observed = {}

        def inspect_open(constructor, request, **kwargs):
            observed["url"] = request.full_url
            observed["connection"] = constructor("staging.example.com", timeout=8)
            observed["options"] = kwargs
            return "fixture-only-no-network"

        handler.do_open = inspect_open
        records = [
            (socket.AF_INET6, socket.SOCK_STREAM, socket.IPPROTO_TCP, "",
             ("2606:4700:4700::1111", 443, 0, 0)),
            (socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, "",
             ("1.1.1.1", 443)),
        ]
        with patch("qa.staging_smoke.socket.getaddrinfo", return_value=records) as resolver:
            self.assertEqual(handler.https_open(Request(
                "https://staging.example.com/api/v1/public/home"
            )), "fixture-only-no-network")
        resolver.assert_called_once_with(
            "staging.example.com", 443, type=socket.SOCK_STREAM,
            proto=socket.IPPROTO_TCP,
        )
        self.assertEqual(observed["connection"]._pinned_ip, "1.1.1.1")
        self.assertEqual(observed["connection"].host, "staging.example.com")
        self.assertIsInstance(observed["options"]["context"], ssl.SSLContext)
        self.assertTrue(observed["options"]["context"].check_hostname)
        self.assertEqual(observed["options"]["context"].verify_mode, ssl.CERT_REQUIRED)
        self.assertEqual(observed["url"],
                         "https://staging.example.com/api/v1/public/home")

        # Only the socket's destination is numeric. SNI / certificate
        # validation MUST continue to use the explicitly approved domain.
        raw_socket = Mock()
        wrapped = Mock()
        tls_context = Mock()
        tls_context.wrap_socket.return_value = wrapped
        connection = PinnedPublicHTTPSConnection(
            "staging.example.com", pinned_ip="1.1.1.1",
            timeout=5, context=tls_context,
        )
        with patch("qa.staging_smoke.socket.create_connection",
                   return_value=raw_socket) as create_socket:
            connection.connect()
        create_socket.assert_called_once_with(("1.1.1.1", 443), 5, None)
        tls_context.wrap_socket.assert_called_once_with(
            raw_socket, server_hostname="staging.example.com",
        )
        self.assertIs(connection.sock, wrapped)

    def test_dns_private_mixed_special_or_invalid_addresses_fail_before_socket(self):
        def answer(address):
            family = socket.AF_INET6 if ":" in address else socket.AF_INET
            endpoint = ((address, 443, 0, 0) if family == socket.AF_INET6
                        else (address, 443))
            return (family, socket.SOCK_STREAM, socket.IPPROTO_TCP,
                    "", endpoint)

        for addresses in (
            ["127.0.0.1"], ["10.0.0.5"], ["192.168.1.1"],
            ["169.254.169.254"], ["100.64.0.1"], ["203.0.113.3"],
            ["::1"], ["fc00::1"], ["fe80::1"],
            ["::ffff:127.0.0.1"],
            ["1.1.1.1", "127.0.0.1"], ["1.1.1.1", "fc00::1"],
            [], ["broken-ip"],
        ):
            with self.subTest(addresses=addresses):
                handler = PublicDNSPinnedHTTPSHandler("staging.example.com")
                handler.do_open = Mock(side_effect=AssertionError(
                    "No outbound HTTPS may follow untrusted DNS"
                ))
                with patch("qa.staging_smoke.socket.getaddrinfo",
                           return_value=[answer(x) for x in addresses]):
                    with self.assertRaises(URLError):
                        handler.https_open(Request("https://staging.example.com/up"))
                handler.do_open.assert_not_called()

    def test_pinned_https_rejects_rebinding_and_proxy_tunnel(self):
        handler = PublicDNSPinnedHTTPSHandler("staging.example.com")
        handler.do_open = Mock(side_effect=AssertionError(
            "Off-origin requests must fail before DNS"
        ))
        for url in ("https://other.example.com/up",
                    "http://staging.example.com/up",
                    "https://user:pass@staging.example.com/up",
                    "https://staging.example.com:444/up"):
            with self.subTest(url=url), patch(
                    "qa.staging_smoke.socket.getaddrinfo") as resolver:
                with self.assertRaises(URLError):
                    handler.https_open(Request(url))
                resolver.assert_not_called()
        with patch("qa.staging_smoke.socket.getaddrinfo",
                   side_effect=socket.gaierror("fixture-only secret")):
            with self.assertRaises(URLError) as error:
                handler.https_open(Request("https://staging.example.com/up"))
        self.assertNotIn("fixture-only secret", str(error.exception))
        handler.do_open.assert_not_called()

        connection = PinnedPublicHTTPSConnection(
            "staging.example.com", pinned_ip="1.1.1.1",
        )
        connection.set_tunnel("another.example.com")
        with patch("qa.staging_smoke.socket.create_connection") as socket_call:
            with self.assertRaises(OSError):
                connection.connect()
            socket_call.assert_not_called()

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
