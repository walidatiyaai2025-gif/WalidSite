#!/usr/bin/env python3
"""T025: read-only TLS-verified smoke checks against authorized staging.

An all-green HTTP report does NOT prove release provenance, backups, rollback,
visual acceptance or owner approval. No credentials or writes are supported.
Response bodies are never included in the diagnostic output.
"""
from __future__ import annotations

import argparse
import datetime as dt
import http.client
import ipaddress
import json
import re
import socket
import threading
import time
import sys
from dataclasses import dataclass
from http.client import HTTPException
from pathlib import Path
from typing import Protocol
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, HTTPSHandler, ProxyHandler, Request, build_opener

MAX_JSON_BYTES = 1024 * 1024
UNSAFE_SUFFIXES = {
    "localhost", "local", "localdomain", "internal", "intranet",
    "lan", "home", "arpa", "svc", "corp", "private", "onion",
    "invalid", "test", "example",
}


@dataclass(frozen=True)
class Check:
    path: str
    status: int
    kind: str
    data_type: type | None = None
    redirect_to: str | None = None


# GET-only probes: no provisioning, mutations, secrets or fake test accounts.
CHECKS = (
    Check("/up", 200, "html"),
    Check("/", 200, "html"),
    Check("/admin/login", 200, "html"),
    Check("/setup", 404, "any"),
    Check("/install", 404, "any"),
    # Without any owner session, the Updates control plane must deny access.
    # A redirect is allowed ONLY to the same-origin owner login.
    Check("/admin/updates", 302, "redirect", redirect_to="/admin/login"),
    # A public host that returns HTTP 200 for private Laravel files is unsafe,
    # regardless of healthy API routes. 403 and 404 are the ONLY denials
    # admitted; redirects (including login), 5xx or a successful body fail.
    # Never consume or serialize a denied resource's response bytes.
    Check("/.env", 404, "private_denied"),
    # cPanel/manual backup suffixes are common accidental web exposures.
    # Never read or report their contents even if they respond with HTTP 200.
    Check("/.env.bak", 404, "private_denied"),
    Check("/.env.backup", 404, "private_denied"),
    Check("/application/database/database.sqlite", 404, "private_denied"),
    Check("/application/bootstrap/cache/config.php", 404, "private_denied"),
    Check("/application/.env", 404, "private_denied"),
    Check("/.git/config", 404, "private_denied"),
    Check("/vendor/autoload.php", 404, "private_denied"),
    Check("/storage/logs/laravel.log", 404, "private_denied"),
    Check("/storage/app/site-first-run.json", 404, "private_denied"),
    Check("/site-first-run.json", 404, "private_denied"),
    # Release-pointer and maintenance metadata must remain under the private
    # cPanel account home. Leakage exposes rollback state and release layout;
    # directory listings of previous code/backup snapshots are also forbidden.
    Check("/.walidsite-active-release.json", 404, "private_denied"),
    Check("/.walidsite-update-maintenance.json", 404, "private_denied"),
    Check("/.walidsite-writer-drain.lock", 404, "private_denied"),
    Check("/.walidsite-releases/", 404, "private_denied"),
    Check("/.walidsite-backups/", 404, "private_denied"),
    Check("/contracts/owner-cv-baseline.json", 404, "private_denied"),
    Check("/api/v1/public/app-bootstrap", 200, "json", dict),
    Check("/api/v1/public/home", 200, "json", dict),
    Check("/api/v1/public/projects", 200, "json", list),
    Check("/api/v1/mobile/me", 401, "json"),
    Check("/api/v1/admin/dashboard", 401, "json"),
)


class NoRedirects(HTTPRedirectHandler):
    """A redirect is a failure, not permission to contact its target."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class PinnedPublicHTTPSConnection(http.client.HTTPSConnection):
    """Connect to a vetted numeric IP, but authenticate the original TLS host."""

    def __init__(self, host: str, *, pinned_ip: str, **kwargs):
        super().__init__(host, **kwargs)
        self._pinned_ip = pinned_ip

    def connect(self) -> None:
        # A proxy tunnel would invalidate the DNS/public-IP binding.
        if self._tunnel_host is not None:
            raise OSError("Staging HTTP proxy tunnels are forbidden")
        raw = socket.create_connection(
            (self._pinned_ip, self.port), self.timeout, self.source_address,
        )
        try:
            # Never replace the TLS SNI/certificate hostname with the IP:
            # the same approved host must remain authenticated over HTTPS.
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except BaseException:
            raw.close()
            raise


class PublicDNSPinnedHTTPSHandler(HTTPSHandler):
    """Reject private/mixed DNS then use that exact public IP for the socket.

    A hostname-only preflight is insufficient: resolving the hostname again
    during connect permits DNS rebinding between inspection and use. We pin
    a numeric address for each request and retain HTTPS hostname validation.
    The outer per-request deadline also bounds a stalled DNS resolution.
    """

    def __init__(self, expected_host: str):
        super().__init__()
        self.expected_host = expected_host

    def https_open(self, request: Request):
        parsed = urlsplit(request.full_url)
        if (parsed.scheme != "https" or parsed.hostname != self.expected_host
                or parsed.port not in (None, 443)
                or parsed.username is not None or parsed.password is not None):
            raise URLError("Staging HTTPS request escaped the approved origin")
        try:
            addresses = socket.getaddrinfo(
                self.expected_host, 443, type=socket.SOCK_STREAM,
                proto=socket.IPPROTO_TCP,
            )
        except OSError as exc:
            raise URLError("Approved staging DNS is unavailable") from exc
        if not addresses:
            raise URLError("Approved staging DNS has no public addresses")

        ips: set[str] = set()
        for answer in addresses:
            try:
                ip = ipaddress.ip_address(answer[4][0])
            except (ValueError, IndexError, TypeError) as exc:
                raise URLError("Approved staging DNS returned an invalid IP") from exc
            # Refuse even ONE private/loopback/link-local/reserved A/AAAA
            # alongside otherwise public records. Do not silently fall back.
            if not ip.is_global:
                raise URLError("Approved staging DNS contains a non-public IP")
            ips.add(str(ip))
        selected = sorted(
            ips, key=lambda value: (ipaddress.ip_address(value).version != 4, value),
        )[0]

        def pinned_connection(host: str, **kwargs):
            if urlsplit("https://" + host).hostname != self.expected_host:
                raise URLError("Staging HTTPS connection changed the approved host")
            return PinnedPublicHTTPSConnection(host, pinned_ip=selected, **kwargs)

        # Match Python's HTTPSHandler contract across supported runners:
        # the HTTPSConnection creates a validating default SSLContext when
        # context=None. A handler-level _check_hostname is not portable.
        return self.do_open(pinned_connection, request, context=self._context)


class Opener(Protocol):
    def open(self, request: Request, timeout: int): ...


def staging_origin(value: str) -> str:
    """Check HTTPS target syntax only; never interpret this as DNS/IP isolation.

    This is an operator target syntax gate, NOT a DNS-rebinding defense. Use
    only with an explicitly authorized staging DNS name.
    """
    if not isinstance(value, str) or not 1 <= len(value) <= 253:
        raise ValueError("Staging target must be a bounded HTTPS DNS origin")
    if re.search(r"[\x00-\x20\x7f\\]", value):
        raise ValueError("Staging target includes invalid whitespace or escaping")
    try:
        parsed = urlsplit(value)
        host = parsed.hostname
        port = parsed.port
    except ValueError as exc:
        raise ValueError("Staging URL is malformed") from exc
    if (parsed.scheme != "https" or not host or parsed.username is not None
            or parsed.password is not None or parsed.query or parsed.fragment
            or parsed.path not in ("", "/") or port not in (None, 443)):
        raise ValueError("Staging target must be the root of a public HTTPS site")
    if len(host) > 253 or not re.fullmatch(r"[a-z0-9.-]+", host, re.ASCII):
        raise ValueError("Staging target must be a DNS name")
    try:
        ipaddress.ip_address(host.strip("[]"))
    except ValueError:
        pass
    else:
        raise ValueError("IP-literal targets are disallowed")
    labels = host.split(".")
    if (len(labels) < 2 or len(labels[-1]) < 2
            or any(not 1 <= len(part) <= 63 or not re.fullmatch(
                r"[a-z0-9](?:[a-z0-9-]*[a-z0-9])?", part, re.ASCII
            ) for part in labels)
            or labels[-1] in UNSAFE_SUFFIXES):
        raise ValueError("Staging target must be a public DNS name")
    return "https://" + host + (":443" if port == 443 else "")


class JsonBodyDeadlineExceeded(TimeoutError):
    """The staging response has exceeded its allowed JSON-body wall time."""


def _read_bounded_json_body(
    response: object, timeout: int, *, deadline: float | None = None,
) -> bytes:
    """Consume JSON without waiting for a fixed-size exact-byte socket read.

    HTTPResponse.read(n) can wait for all n bytes and a slow-drip peer can
    prolong that indefinitely under a per-socket inactivity timeout. read1()
    performs at most one buffered read, permitting monotonic deadline checks
    between fragments. The caller passes the original GET deadline, so
    DNS/TLS/headers cannot grant a fresh body budget. A separate outer guard
    also bounds a single stuck read or response close.
    """
    if deadline is None:
        deadline = time.monotonic() + timeout
    body = bytearray()
    read_once = getattr(response, "read1", None)
    while len(body) <= MAX_JSON_BYTES:
        if time.monotonic() >= deadline:
            raise JsonBodyDeadlineExceeded()
        remaining = MAX_JSON_BYTES + 1 - len(body)
        if callable(read_once):
            chunk = read_once(min(64 * 1024, remaining))
        else:
            # Custom/HTTPError wrappers may only support exact-length read().
            # Request one byte, not MAX_JSON_BYTES, to avoid indefinite waits.
            chunk = response.read(1)
        if time.monotonic() >= deadline:
            raise JsonBodyDeadlineExceeded()
        if not isinstance(chunk, bytes):
            raise ValueError("Non-byte staging response chunk")
        if not chunk:
            return bytes(body)
        body.extend(chunk)
    return bytes(body)


def _unique_json_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Reject ambiguous duplicate keys, including escaped-key equivalents."""
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate staging JSON key")
        result[key] = value
    return result


def _reject_nonfinite_json(value: str) -> object:
    # The Python decoder otherwise accepts NaN and Infinity outside JSON.
    raise ValueError("Invalid non-finite staging JSON constant")


def _is_expected_media_type(headers: object, expected: str) -> bool:
    """Fail closed on ambiguous MIME headers; permit normal charset parameters.

    HTTPMessage.get() returns only one instance when a server sends multiple
    Content-Type fields. Repeated fields or comma-joined values must never
    attest a known HTML/JSON response. Do not read the body to diagnose them.
    """
    get_all = getattr(headers, "get_all", None)
    if callable(get_all):
        values = get_all("Content-Type", [])
        if not isinstance(values, (list, tuple)) or len(values) != 1:
            return False
        raw = values[0]
    else:
        raw = headers.get("Content-Type", "")
    if not isinstance(raw, str) or any(
        ord(ch) < 32 or ord(ch) == 127 for ch in raw
    ) or "," in raw:
        return False
    parts = raw.split(";")
    if parts[0].strip().lower() != expected:
        return False
    names: set[str] = set()
    for param in parts[1:]:
        match = re.fullmatch(
            r'\s*([A-Za-z][A-Za-z0-9_-]*)\s*=\s*(?:[A-Za-z0-9._-]+|"[A-Za-z0-9._-]+")\s*',
            param,
            flags=re.ASCII,
        )
        if match is None or match.group(1).lower() in names:
            return False
        names.add(match.group(1).lower())
    return True


def _check_one_transaction(origin: str, spec: Check, opener: Opener, timeout: int) -> dict:
    """One read-only probe; outer deadline protects even blocking HTTPS open."""
    deadline = time.monotonic() + timeout
    request = Request(
        origin + spec.path,
        headers={"Accept": "application/json" if spec.kind == "json" else "text/html"},
        method="GET",
    )
    observed = None
    reason = ""
    response = None
    try:
        try:
            response = opener.open(request, timeout=timeout)
        except HTTPError as error:
            # Deliberate HTTP 401/404 responses are exceptions in urllib.
            response = error
        observed = int(response.status if hasattr(response, "status") else response.code)
        if time.monotonic() >= deadline:
            reason = "request_deadline_exceeded"
        elif spec.kind == "private_denied":
            # Check the HTTP status only. Reading leaked content, even for
            # diagnostics, could put APP_KEY/credentials into runner memory or
            # a later traceback. A 302 is NOT a denial and is never followed.
            if observed not in (403, 404):
                reason = "private_resource_not_denied"
        elif observed != spec.status:
            reason = "unexpected_http_status"
        elif spec.kind == "redirect":
            # A 302 to an attacker-controlled origin must never pass a guest
            # authorization check. Compare exact targets; do not follow them.
            location = response.headers.get("Location", "")
            if location not in (spec.redirect_to, origin + spec.redirect_to):
                reason = "unsafe_or_unexpected_redirect"
        elif spec.kind == "html" and not _is_expected_media_type(response.headers, "text/html"):
            reason = "non_html_response"
        elif spec.kind == "json":
            if not _is_expected_media_type(response.headers, "application/json"):
                reason = "non_json_response"
            else:
                try:
                    payload = _read_bounded_json_body(response, timeout, deadline=deadline)
                except JsonBodyDeadlineExceeded:
                    reason = "json_body_deadline_exceeded"
                else:
                    if len(payload) > MAX_JSON_BYTES:
                        reason = "oversized_json_response"
                    else:
                        try:
                            decoded = json.loads(
                                payload, object_pairs_hook=_unique_json_object,
                                parse_constant=_reject_nonfinite_json,
                            )
                        except (ValueError, UnicodeDecodeError, RecursionError):
                            # A small, deeply nested untrusted JSON body may
                            # exhaust the parser recursion budget. Record a
                            # failed probe, never crash or reveal its bytes.
                            reason = "invalid_json_response"
                        else:
                            if not isinstance(decoded, dict):
                                reason = "invalid_json_envelope"
                            elif spec.data_type is not None and not isinstance(decoded.get("data"), spec.data_type):
                                reason = "invalid_public_data_shape"
    except (URLError, OSError, TimeoutError, ValueError, HTTPException) as exc:
        # Raw error text may contain credentials or private host information.
        reason = "transport_or_tls_error_" + type(exc).__name__
    finally:
        if response is not None:
            try:
                response.close()
            except Exception:
                # A broken close must not escape the read-only audit or turn
                # an otherwise successful response into an unverified PASS.
                # Never print a transport exception message (may be sensitive).
                reason = "response_close_failed"
    return {
        "path": spec.path,
        "expected_status": spec.status,
        "observed_status": observed,
        "passed": reason == "",
        "reason": reason or None,
    }


def _check_one(origin: str, spec: Check, opener: Opener, timeout: int) -> dict:
    """Bound the *entire* HTTPS GET, including DNS, TLS and response headers.

    urllib's per-socket timeout is an inactivity limit; a slow header stream
    may otherwise keep a smoke audit alive indefinitely. One short-lived
    daemon worker handles this GET-only probe. If it fails to finish within
    the wall budget the report fails closed immediately, never using late
    response data. Its response is closed by the worker if it later returns;
    the thread itself is not falsely described as a cancelled network call.
    At most len(CHECKS) worker requests can be outstanding in one run.
    """
    completed = threading.Event()
    result: list[dict] = []
    error: list[BaseException] = []

    def readonly_probe() -> None:
        try:
            result.append(_check_one_transaction(origin, spec, opener, timeout))
        except BaseException as exc:
            error.append(exc)
        finally:
            completed.set()

    threading.Thread(
        target=readonly_probe, name="walidsite-staging-readonly-get", daemon=True,
    ).start()
    if not completed.wait(timeout):
        return {
            "path": spec.path,
            "expected_status": spec.status,
            "observed_status": None,
            "passed": False,
            "reason": "request_deadline_exceeded",
        }
    if error or not result:
        # A custom opener/HTTP response can raise outside the ordinary
        # transport exception list. Preserve the full failure receipt instead
        # of leaking error text or aborting the remaining staging probes.
        return {
            "path": spec.path,
            "expected_status": spec.status,
            "observed_status": None,
            "passed": False,
            "reason": "probe_worker_error",
        }
    return result[0]


def run(origin: str, opener: Opener | None = None, timeout: int = 8,
        approved_host: str | None = None) -> dict:
    origin = staging_origin(origin)
    expected_host = urlsplit(origin).hostname
    # Operator allowlisting is an explicit admission gate, not proof that DNS
    # currently resolves to a public address. Real execution additionally
    # requires an egress-restricted runner/network policy.
    if not isinstance(approved_host, str) or approved_host != expected_host:
        raise ValueError("Exact operator-approved staging hostname required")
    if type(timeout) is not int or not 1 <= timeout <= 30:
        raise ValueError("Timeout must be an integer from 1 to 30 seconds")
    if opener is None:
        # urllib otherwise inherits HTTPS_PROXY / ALL_PROXY from the runner.
        # An environment-controlled proxy can impersonate the destination
        # and invalidate the meaning of a read-only staging smoke receipt.
        # Explicitly disable proxy discovery: TLS verification and the strict
        # no-redirect policy still apply to every direct HTTPS request.
        # A network requiring an outbound proxy must fail closed rather than
        # silently claiming to have reached the approved staging origin.
        opener = build_opener(
            ProxyHandler({}), NoRedirects(),
            PublicDNSPinnedHTTPSHandler(expected_host),
        )
    results = [_check_one(origin, check, opener, timeout) for check in CHECKS]
    passed = all(item["passed"] for item in results)
    return {
        "version": 1,
        "target_origin": origin,
        "observed_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "read_only": True,
        "tls_verification_required": True,
        "operator_approved_host": expected_host,
        "network_egress_isolation_verified": False,
        "checks": results,
        "http_smoke_passed": passed,
        "deployment_release_sha_verified": False,
        "backup_restore_verified": False,
        "rollback_verified": False,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="HTTPS staging origin, no credentials")
    parser.add_argument("--approved-host", required=True,
                        help="Explicit operator-approved DNS hostname; not a network-isolation guarantee")
    parser.add_argument("--timeout", type=int, default=8)
    parser.add_argument("--output", type=Path, help="Write a new, non-overwriting JSON receipt")
    args = parser.parse_args(argv)
    try:
        report = run(args.url, timeout=args.timeout, approved_host=args.approved_host)
    except ValueError as exc:
        print("INVALID TARGET: " + str(exc), file=sys.stderr)
        return 2
    rendered = json.dumps(report, indent=2, sort_keys=True) + "\n"
    if args.output is not None:
        try:
            with args.output.open("x", encoding="utf-8") as dest:
                dest.write(rendered)
        except OSError as exc:
            print("REPORT WRITE FAILED: " + type(exc).__name__, file=sys.stderr)
            return 2
    print(rendered, end="")
    return 0 if report["http_smoke_passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
