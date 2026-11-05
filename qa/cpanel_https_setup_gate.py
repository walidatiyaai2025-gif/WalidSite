#!/usr/bin/env python3
"""W10: fail-closed, real Apache HTTPS first-run HTTP acceptance.

Requires an independently extracted *disposable* cPanel package under a
private account home and a localhost HTTPS Apache/PHP server with a trusted
locally supplied CA. This tests setup possession/CSRF/session and public/private
HTTP boundaries, not database installation, browser rendering or deployment.
It never prints the possession token, owner key, session cookies or HTML.
"""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import ssl
import stat
import sys
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import (HTTPSHandler, HTTPCookieProcessor, HTTPRedirectHandler,
                            ProxyHandler, Request, build_opener)

MAX_BODY = 128 * 1024
PRIVATE_PATHS = (
    "/.env",
    "/application/.env",
    "/contracts/owner-cv-baseline.json",
    "/vendor/autoload.php",
    "/storage/logs/laravel.log",
    "/site-first-run.json",
    "/storage/app/site-first-run.json",
)
TOKEN_RE = re.compile(r"[a-f0-9]{64}\Z")
CSRF_RE = re.compile(r"[A-Za-z0-9]{40,128}\Z")


class AcceptanceError(ValueError):
    """Non-sensitive failure; never include secret bytes or server response."""


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class TokenInput(HTMLParser):
    def __init__(self):
        super().__init__()
        self.inputs = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() == "input":
            self.inputs.append(dict(attrs))


def local_https_origin(value: str) -> str:
    """No external-network requests or unauthenticated TLS fallbacks."""
    if not isinstance(value, str) or len(value) > 128 or re.search(r"[\x00-\x20\x7f\\]", value):
        raise AcceptanceError("Invalid local HTTPS origin")
    try:
        p = urlsplit(value)
        port = p.port
    except ValueError as exc:
        raise AcceptanceError("Invalid local HTTPS port") from exc
    if (p.scheme != "https" or p.hostname != "localhost" or port is None
            or not 1024 <= port <= 65535 or p.username or p.password
            or p.query or p.fragment or p.path not in ("", "/")
            or p.netloc != f"localhost:{port}"):
        raise AcceptanceError("Only an explicit https://localhost:PORT origin is accepted")
    return f"https://localhost:{port}"


def private_token(account_home: Path) -> str:
    """Read token only from exact private bootstrap location with safe mode."""
    if account_home.is_symlink():
        raise AcceptanceError("Account-home symlink refused")
    account_home = account_home.resolve(strict=True)
    public_root = account_home / "public_html"
    private_root = account_home / "application"
    # A symlinked public root can expose the private application tree even
    # when a shallow root-level probe returns 404. Fail before any HTTP calls.
    if (public_root.is_symlink() or private_root.is_symlink()
            or not public_root.is_dir() or not private_root.is_dir()
            or public_root.resolve(strict=True) == private_root.resolve(strict=True)):
        raise AcceptanceError("Expected distinct real application and public_html directories")
    path = account_home / "application/storage/app/site-first-run.json"
    if any(item.is_symlink() for item in [path, *path.parents] if item != Path("/")):
        raise AcceptanceError("Private bootstrap symlink refused")
    # A hard link exposes the same private possession token under another
    # pathname (including an arbitrary name in public_html), which fixed
    # web-path probes cannot enumerate. The token must have one regular inode,
    # held open with O_NOFOLLOW while reading. Compare opened versus named
    # identity before and after the read to reject a concurrent pathname swap.
    if not path.is_file():
        raise AcceptanceError("Missing or oversized private bootstrap")
    try:
        before = path.lstat()
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or not 0 < before.st_size <= 4096):
            raise AcceptanceError("Unsafe private bootstrap file identity")
        if stat.S_IMODE(before.st_mode) & 0o077:
            raise AcceptanceError("Private bootstrap is accessible by group or others")
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        flags |= getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NONBLOCK", 0)
        fd = os.open(path, flags)
        try:
            identity = lambda st: (st.st_dev, st.st_ino, st.st_mode, st.st_nlink,
                                   st.st_size, st.st_mtime_ns, st.st_ctime_ns)
            if identity(os.fstat(fd)) != identity(before):
                raise AcceptanceError("Private bootstrap changed before reading")
            chunks = []
            length = 0
            while length < before.st_size:
                part = os.read(fd, before.st_size - length)
                if not part:
                    raise AcceptanceError("Truncated private bootstrap")
                chunks.append(part)
                length += len(part)
            if (os.read(fd, 1)
                    or identity(os.fstat(fd)) != identity(before)
                    or identity(path.lstat()) != identity(before)):
                raise AcceptanceError("Private bootstrap changed while reading")
        finally:
            os.close(fd)
        payload = json.loads(b"".join(chunks).decode("utf-8"))
        value = payload["token"]
    except (ValueError, UnicodeError, KeyError, TypeError, OSError) as exc:
        raise AcceptanceError("Invalid private bootstrap contents") from exc
    if not isinstance(value, str) or not TOKEN_RE.fullmatch(value):
        raise AcceptanceError("Invalid private possession token")
    return value


def csrf_from_html(body: str) -> str:
    parser = TokenInput()
    parser.feed(body)
    matches = [x.get("value") for x in parser.inputs if x.get("name") == "_token"]
    if len(matches) != 1 or not isinstance(matches[0], str) or not CSRF_RE.fullmatch(matches[0]):
        raise AcceptanceError("Missing or invalid CSRF input")
    return matches[0]


@dataclass
class Response:
    status: int
    location: str
    body: str


class Client:
    def __init__(self, origin: str, ca_file: Path, timeout: int = 8):
        if not ca_file.is_file() or ca_file.is_symlink():
            raise AcceptanceError("Explicit regular CA certificate required")
        if type(timeout) is not int or not 1 <= timeout <= 20:
            raise AcceptanceError("Invalid HTTP timeout")
        self.origin = local_https_origin(origin)
        self.cookies = http.cookiejar.CookieJar()
        try:
            context = ssl.create_default_context(cafile=str(ca_file))
        except (OSError, ssl.SSLError) as exc:
            raise AcceptanceError("Invalid local CA certificate") from exc
        # This acceptance harness sends the private possession token to
        # explicit localhost HTTPS only. Never inherit HTTPS_PROXY,
        # ALL_PROXY or CGI proxy variables from the CI/operator machine:
        # urllib's default ProxyHandler could tunnel token-bearing POSTs
        # through an unrelated proxy even for an approved localhost origin.
        self.opener = build_opener(ProxyHandler({}), HTTPSHandler(context=context),
                                   HTTPCookieProcessor(self.cookies), NoRedirect())
        self.timeout = timeout

    def request(self, path: str, form: dict[str, str] | None = None) -> Response:
        if not path.startswith("/") or "//" in path or "\\" in path or ".." in path:
            raise AcceptanceError("Unsafe probe path")
        data = urlencode(form).encode("utf-8") if form is not None else None
        method = "POST" if form is not None else "GET"
        request = Request(self.origin + path, data=data, method=method,
                          headers={"Accept": "text/html"})
        try:
            try:
                response = self.opener.open(request, timeout=self.timeout)
            except HTTPError as error:
                response = error
            with response:
                body = response.read(MAX_BODY + 1)
                if len(body) > MAX_BODY:
                    raise AcceptanceError("Oversized HTTP response")
                location = response.headers.get("Location", "")
                if location and not location.startswith(self.origin + "/"):
                    raise AcceptanceError("External or cross-origin redirect refused")
                return Response(int(response.code), location, body.decode("utf-8", "strict"))
        except (URLError, OSError, UnicodeError, ssl.SSLError) as exc:
            raise AcceptanceError("HTTPS transport/certificate failure") from exc


def accept(origin: str, account_home: Path, ca_file: Path) -> dict:
    token = private_token(account_home)
    client = Client(origin, ca_file)
    steps = []

    def check(name: str, condition: bool):
        if not condition:
            raise AcceptanceError(f"Failed acceptance step: {name}")
        steps.append(name)

    home = client.request("/")
    check("fresh_home_redirect", home.status == 302
          and home.location == client.origin + "/install")
    login = client.request("/admin/login")
    check("owner_login_requires_setup", login.status == 302
          and login.location == client.origin + "/install")
    for path in PRIVATE_PATHS:
        result = client.request(path)
        check("private_http_denied_" + path.strip("/").replace("/", "_"),
              result.status in (403, 404) and token not in result.body)
    public = client.request("/up")
    check("laravel_health_endpoint_reachable", public.status == 200)
    # First-time domain entry now uses the canonical 15-stage FOODEX flow.
    # The old guarded /setup remains directly testable for recovery only.
    install = client.request("/install")
    check("foodex_https_first_step_available", install.status == 200
          and 'name="token"' in install.body and token not in install.body)
    setup = client.request("/setup")
    check("setup_https_available", setup.status == 200
          and 'name="token"' in setup.body and token not in setup.body)
    csrf = csrf_from_html(setup.body)
    check("csrf_missing_rejected", client.request("/setup/unlock", {"token": "invalid"}).status == 419)
    check("preunlock_migrations_forbidden",
          client.request("/setup/database", {"_token": csrf}).status == 403)
    wrong = client.request("/setup/unlock", {"_token": csrf, "token": "invalid"})
    check("incorrect_possession_rejected", wrong.status == 302
          and wrong.location == client.origin + "/setup" and token not in wrong.body)
    retry = client.request("/setup")
    check("bad_token_did_not_unlock", retry.status == 200
          and 'name="token"' in retry.body and 'name="db_database"' not in retry.body)
    valid = client.request("/setup/unlock", {
        "_token": csrf_from_html(retry.body), "token": token,
    })
    check("correct_possession_session_unlock", valid.status == 302
          and valid.location == client.origin + "/setup" and token not in valid.body)
    database = client.request("/setup")
    check("database_step_accessible", database.status == 200
          and 'name="db_database"' in database.body and token not in database.body)
    arabic = client.request("/setup?lang=ar")
    check("rtl_after_unlock", arabic.status == 200
          and 'dir="rtl"' in arabic.body and token not in arabic.body)
    session = [c for c in client.cookies if c.name == "walidsite-session"]
    check("secure_httponly_session", len(session) == 1 and session[0].secure
          and any(key.lower() == "httponly" for key in session[0]._rest))

    # The canonical FOODEX 15-stage wizard must be checked independently.
    # The legacy /setup happy path above is NOT proof that /install's token
    # gate, session progression and strict ordered stages are protected.
    # Use a fresh cookie jar so the already-unlocked legacy session cannot
    # accidentally authorize this separate canonical security assertion.
    foodex = Client(origin, ca_file)
    welcome = foodex.request("/install")
    check("foodex_independent_welcome_token_gate", welcome.status == 200
          and 'name="token"' in welcome.body and token not in welcome.body)
    initial_csrf = csrf_from_html(welcome.body)
    bypass = foodex.request("/install/step/2", {"_token": initial_csrf})
    check("foodex_preunlock_requirements_forbidden", bypass.status == 403)
    invalid = foodex.request("/install/step/1", {
        "_token": initial_csrf, "token": "invalid",
    })
    check("foodex_wrong_possession_denied", invalid.status == 302
          and invalid.location == foodex.origin + "/install"
          and token not in invalid.body)
    retry = foodex.request("/install")
    check("foodex_bad_token_did_not_advance", retry.status == 200
          and 'name="token"' in retry.body and token not in retry.body)
    authorized = foodex.request("/install/step/1", {
        "_token": csrf_from_html(retry.body), "token": token,
    })
    check("foodex_valid_possession_advances", authorized.status == 302
          and authorized.location == foodex.origin + "/install"
          and token not in authorized.body)
    requirements = foodex.request("/install")
    next_action = 'action="' + foodex.origin + '/install/step/2"'
    check("foodex_requirements_stage_rendered", requirements.status == 200
          and next_action in requirements.body
          and 'name="token"' not in requirements.body
          and token not in requirements.body)
    skip = foodex.request("/install/step/3", {
        "_token": csrf_from_html(requirements.body),
    })
    check("foodex_cannot_skip_requirements", skip.status == 409)
    unchanged = foodex.request("/install")
    check("foodex_skip_keeps_requirements", unchanged.status == 200
          and next_action in unchanged.body and token not in unchanged.body)
    foodex_session = [c for c in foodex.cookies if c.name == "walidsite-session"]
    check("foodex_session_secure_httponly", len(foodex_session) == 1
          and foodex_session[0].secure
          and any(key.lower() == "httponly" for key in foodex_session[0]._rest))

    return {
        "schema": "walidsite.local-https-first-run-acceptance.v1",
        "tested_origin": client.origin,
        "http_checks_passed": len(steps),
        "checks": steps,
        "first_run_http_possession_verified": True,
        "real_apache_required": True,
        "private_token_emitted": False,
        "database_install_verified": False,
        "owner_activation_verified": False,
        "browser_interaction_verified": False,
        "production_staging_verified": False,
        "admin_full_code_update_verified": False,
        "backup_restore_verified": False,
        "production_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--account-home", type=Path, required=True)
    parser.add_argument("--ca-file", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        report = accept(args.origin, args.account_home, args.ca_file)
    except (AcceptanceError, OSError) as exc:
        # No secrets, raw HTML, URLs with tokens or private filesystem paths.
        print(json.dumps({"first_run_http_possession_verified": False,
                          "production_ready": False,
                          "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(report, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
