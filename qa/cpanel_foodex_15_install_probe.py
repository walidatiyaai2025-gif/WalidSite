#!/usr/bin/env python3
"""Actual Setup ZIP -> 15-stage browser HTTP flow -> isolated MariaDB -> owner Publish.

This is an isolated, localhost-only CI acceptance. It does not certify an
external cPanel host, Apache HTTPS, browser pixels, or production readiness.
Never print credentials, possession token, cookies, APP_KEY, HTTP bodies or logs.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import secrets
import socket
import stat
import subprocess
import tempfile
import time
import zipfile
from pathlib import Path
from urllib.parse import urlsplit

from qa.cpanel_first_run_http_probe import extract_verified_archive
from qa.cpanel_full_install_probe import Client, SetupFailure, csrf, Inputs


def authenticated_session_csrf(body: bytes) -> str:
    """Extract one consistent current-session token from all owner forms."""
    parser = Inputs()
    try:
        parser.feed(body.decode("utf-8", "strict"))
    except UnicodeError as error:
        raise SetupFailure("post_publish_owner_csrf_invalid_html") from None
    tokens = [item.get("value") for item in parser.items
              if item.get("name") == "_token"]
    if (not tokens or any(not isinstance(token, str) for token in tokens)
            or len(set(tokens)) != 1
            or re.fullmatch(r"[A-Za-z0-9]{40,128}", tokens[0]) is None):
        raise SetupFailure("post_publish_owner_csrf_missing_or_ambiguous")
    return tokens[0]


def post_publish_security(client: Client, guest: Client, application: Path,
                          public: Path) -> list[str]:
    """Probe the *installed* HTTP server after owner Publish, without secrets.

    This is a real HTTP test in the cPanel/MariaDB CI job. In APP_ENV=testing
    Laravel may exempt POSTs from CSRF, so it does NOT certify production CSRF.
    """
    if (client.origin != guest.origin
            or urlsplit(client.origin).hostname != "127.0.0.1"):
        raise SetupFailure("post_publish_loopback_only")

    protected = {
        "environment": application / ".env",
        "install_lock": application / "storage/app/site-installed.lock",
        "version": application / "VERSION",
        "public_controller": public / "index.php",
    }
    if any(not path.is_file() or path.is_symlink()
           for path in protected.values()):
        raise SetupFailure("post_publish_immutable_files_missing")
    before = {name: hashlib.sha256(path.read_bytes()).hexdigest()
              for name, path in protected.items()}
    checks: list[str] = []

    def check(name: str, condition: bool) -> None:
        if not condition:
            raise SetupFailure("post_publish_" + name)
        checks.append("post_publish_" + name)

    def denied(status: int, location: str) -> bool:
        if status in (401, 403, 419):
            return True
        if status != 302:
            return False
        redirect, origin = urlsplit(location), urlsplit(client.origin)
        # Accept only canonical local login redirects: reject cross-scheme,
        # protocol-relative, query/fragment and credential-bearing locations.
        return (
            redirect.path == "/admin/login"
            and not redirect.query and not redirect.fragment
            and (
                (not redirect.scheme and not redirect.netloc)
                or (redirect.scheme == origin.scheme
                    and redirect.netloc == origin.netloc)
            )
        )

    status, location, _ = guest.call("/admin/updates")
    check("anonymous_update_view_denied", denied(status, location))
    for route, name in (
        ("/admin/updates/check", "check"),
        ("/admin/updates/verify-upload", "upload"),
        ("/admin/updates/apply", "apply"),
    ):
        status, location, _ = guest.call(route, {"_token": "x" * 40})
        check("anonymous_update_" + name + "_denied",
              denied(status, location))

    # A forged CSRF token yields HTTP 419 *before* the installer lock can
    # execute. Read the owner's actual session token from the three Admin
    # Update forms so this assertion tests the installed-state gate itself.
    owner_status, _, owner_html = client.call("/admin/updates")
    check("owner_update_form_reachable", owner_status == 200)
    session_token = authenticated_session_csrf(owner_html)
    for route, name in (
        ("/install/step/15", "foodex_reinstall"),
        ("/setup/finish", "legacy_reinstall"),
    ):
        status, _, _ = client.call(route, {"_token": session_token})
        check(name + "_post_blocked", status == 404)

    # A Laravel fallback route must not accidentally serve private files.
    for route, name in (
        ("/.env", "env"),
        ("/application/.env", "private_app"),
        ("/contracts/owner-cv-baseline.json", "owner_contract"),
    ):
        status, _, _ = guest.call(route)
        check("no_public_" + name, status in (403, 404))

    after = {name: hashlib.sha256(path.read_bytes()).hexdigest()
             for name, path in protected.items()}
    for name in protected:
        check("preserve_" + name, before[name] == after[name])
    check("owner_session_still_live", client.call("/admin")[0] == 200)
    check("public_site_still_live", guest.call("/")[0] == 200)
    return checks

def post_publish_missing_lock_security(owner: Client, guest: Client,
                                       application: Path, public: Path) -> list[str]:
    """Real ZIP/MariaDB anti-reinstall proof with the *private* lock withheld.

    Only touch a disposable, isolated `walidsite-ci-foodex-*` account created
    by run(); NEVER use this destructive test against live/staging files. The
    approved owner account in MariaDB must independently forbid reinstall.
    The original lock is atomically restored even when an HTTP check fails.
    """
    if (owner.origin != guest.origin
            or urlsplit(owner.origin).hostname != "127.0.0.1"
            or application.is_symlink() or public.is_symlink()
            or application.name != "application" or public.name != "public_html"
            or application.parent != public.parent
            or not application.parent.name.startswith("walidsite-ci-foodex-")
            or not application.is_dir() or not public.is_dir()):
        raise SetupFailure("post_publish_missing_lock_disposable_loopback_only")

    lock = application / "storage/app/site-installed.lock"
    held = lock.with_name(".site-installed.lock.w10-withheld")
    env_file = application / ".env"
    version = application / "VERSION"
    controller = public / "index.php"
    if (not lock.is_file() or lock.is_symlink()
            or held.exists() or held.is_symlink()
            or any(not p.is_file() or p.is_symlink()
                   for p in (env_file, version, controller))):
        raise SetupFailure("post_publish_missing_lock_private_files_invalid")

    protected = [lock, env_file, version, controller]
    digests = {path: hashlib.sha256(path.read_bytes()).hexdigest()
               for path in protected}
    bootstrap = application / "storage/app/site-first-run.json"
    if bootstrap.exists() or bootstrap.is_symlink():
        raise SetupFailure("post_publish_missing_lock_token_not_revoked")

    checks: list[str] = []

    def check(name: str, passed: bool) -> None:
        if not passed:
            raise SetupFailure("post_publish_missing_lock_" + name)
        checks.append("post_publish_missing_lock_" + name)

    os.replace(lock, held)
    try:
        check("lock_actually_withheld", not lock.exists() and held.is_file())
        check("anonymous_install_remains_closed", guest.call("/install")[0] == 404)
        check("anonymous_setup_remains_closed", guest.call("/setup")[0] == 404)
        check("owner_install_remains_closed", owner.call("/install")[0] == 404)
        check("owner_setup_remains_closed", owner.call("/setup")[0] == 404)
        owner_html = owner.call("/admin/updates")
        check("owner_update_session_still_live", owner_html[0] == 200)
        csrf_token = authenticated_session_csrf(owner_html[2])
        check("owner_install_post_denied",
              owner.call("/install/step/1", {
                  "_token": csrf_token, "token": "invalid",
              })[0] == 404)
        check("owner_setup_post_denied",
              owner.call("/setup/finish", {"_token": csrf_token})[0] == 404)
        check("no_private_possession_token_regenerated",
              not bootstrap.exists() and not bootstrap.is_symlink())
        check("public_site_still_live", guest.call("/")[0] == 200)
        check("owner_dashboard_still_live", owner.call("/admin")[0] == 200)
        check("private_configuration_preserved",
              all(hashlib.sha256(path.read_bytes()).hexdigest() == digests[path]
                  for path in (env_file, version, controller)))
        check("no_unexpected_install_lock_recreated",
              not lock.exists() and held.is_file() and not held.is_symlink())
    finally:
        # Atomic same-directory restore preserves the original file inode,
        # permissions and bytes. Never leave a disposable test half-unlocked.
        if held.is_symlink() or not held.is_file():
            raise SetupFailure("post_publish_missing_lock_original_lock_lost")
        os.replace(held, lock)

    check("original_install_lock_restored",
          lock.is_file() and not lock.is_symlink() and not held.exists()
          and hashlib.sha256(lock.read_bytes()).hexdigest() == digests[lock])
    return checks



def invalid_database_retry_probe(client: Client, application: Path,
                                 public: Path, valid: dict[str, str]) -> list[str]:
    """Prove wrong MariaDB credentials cannot damage fresh Setup ZIP state.

    Must run only inside the disposable FOODEX CI extraction *before* step 4.
    The caller then retries correct credentials in the same session and
    completes all fifteen stages on the identical release ZIP.
    """
    origin = urlsplit(client.origin)
    if (origin.scheme != "http" or origin.hostname != "127.0.0.1"
            or origin.username or origin.password
            or application.is_symlink() or public.is_symlink()
            or application.name != "application" or public.name != "public_html"
            or application.parent != public.parent
            or not application.parent.name.startswith("walidsite-ci-foodex-")
            or not application.is_dir() or not public.is_dir()):
        raise SetupFailure("invalid_database_disposable_loopback_only")

    bootstrap = application / "storage/app/site-first-run.json"
    config = application / ".env"
    version = application / "VERSION"
    front = public / "index.php"
    if (config.exists() or config.is_symlink()
            or any(not path.is_file() or path.is_symlink()
                   for path in (bootstrap, version, front))):
        raise SetupFailure("invalid_database_pristine_private_files_required")
    if set(valid) != {"site_url", "db_host", "db_port", "db_database",
                      "db_username", "db_password"} or not valid["db_password"]:
        raise SetupFailure("invalid_database_valid_fixture_incomplete")

    digest = {p: hashlib.sha256(p.read_bytes()).hexdigest()
              for p in (bootstrap, version, front)}
    checks: list[str] = []

    def check(name: str, passed: bool) -> None:
        if not passed:
            raise SetupFailure("invalid_database_" + name)
        checks.append("invalid_database_" + name)

    status, _, html = client.call("/install")
    check("step_4_current", status == 200
          and b"/install/step/4" in html)
    wrong = "QaInvalid-" + secrets.token_urlsafe(32)
    check("invalid_password_distinct", wrong != valid["db_password"])
    attempt = dict(valid)
    attempt["db_password"] = wrong
    status, location, body = client.call(
        "/install/step/4", {"_token": csrf(html), **attempt})
    target = urlsplit(location)
    check("rejected_without_advancing",
          status == 302 and target.path == "/install"
          and not target.query and not target.fragment
          and (not target.netloc or
               (target.scheme == origin.scheme and target.netloc == origin.netloc)))
    check("wrong_password_not_in_redirect",
          wrong.encode() not in body and wrong not in location)
    status, _, html = client.call("/install")
    check("same_step_recoverable", status == 200
          and b"/install/step/4" in html)
    check("wrong_password_not_in_page", wrong.encode() not in html)
    check("no_private_env_written", not config.exists() and not config.is_symlink())
    check("possession_and_code_preserved",
          all(p.is_file() and not p.is_symlink()
              and hashlib.sha256(p.read_bytes()).hexdigest() == digest[p]
              for p in digest))
    return checks


def run(package: Path, sha: str, host: str, port: int, database: str,
        user: str, password: str) -> dict:
    if (host != "127.0.0.1" or port != 3307
            or database != "walidsite_ci_foodex" or user != "walidsite_ci"
            or not password):
        raise SetupFailure("disposable_database_only")

    with tempfile.TemporaryDirectory(prefix="walidsite-ci-foodex-") as directory:
        home = Path(directory)
        # The verified source/manifest and installed bytes must be the SAME
        # immutable private snapshot, even if the source pathname is swapped.
        extract_verified_archive(package, home, expected_sha=sha)
        application = home / "application"
        public = home / "public_html"
        if ((application / ".env").exists()
                or not (application / "vendor/autoload.php").is_file()
                or not (public / "index.php").is_file()):
            raise SetupFailure("clean_zip_payload")

        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            web_port = sock.getsockname()[1]
        env = os.environ.copy()
        for key in (
            "APP_KEY", "APP_NAME", "APP_URL", "SESSION_COOKIE",
            "SITE_SETUP_ENABLED", "SITE_SETUP_TOKEN", "SITE_SETUP_DATABASE_READY",
            "DB_HOST", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "DB_PORT",
            "DB_CONNECTION",
        ):
            env.pop(key, None)
        env.update({
            "APP_ENV": "testing",
            "APP_DEBUG": "false",
            "SESSION_DRIVER": "file",
            "CACHE_STORE": "file",
            "QUEUE_CONNECTION": "sync",
            "MAIL_MAILER": "log",
        })
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{web_port}", "-t", str(public),
             str(public / "index.php")],
            cwd=application, env=env, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        client = Client(web_port)
        checks: list[str] = []

        def check(name: str, condition: bool) -> None:
            if not condition:
                raise SetupFailure(name)
            checks.append(name)

        def page(stage: int) -> bytes:
            status, _, body = client.call("/install")
            check(f"stage_{stage}_rendered", status == 200
                  and f"/install/step/{stage}".encode() in body
                  and b'data-testid="foodex-install-form"' in body)
            return body

        def advance(stage: int, values: dict[str, str] | None = None) -> None:
            html = page(stage)
            form = {"_token": csrf(html), **(values or {})}
            status, location, _ = client.call(f"/install/step/{stage}", form)
            parsed = urlsplit(location)
            target = "/admin/login" if stage == 15 else "/install"
            check(f"stage_{stage}_post", status == 302
                  and parsed.path == target
                  and (not parsed.netloc
                       or parsed.netloc == urlsplit(client.origin).netloc))
            if stage < 15:
                page(stage + 1)

        try:
            for _ in range(60):
                if server.poll() is not None:
                    raise SetupFailure("php_server_exited")
                try:
                    code, location, _ = client.call("/", timeout=3)
                    break
                except SetupFailure as error:
                    if not str(error).startswith("http_transport_"):
                        raise
                    time.sleep(0.1)
            else:
                raise SetupFailure("php_server_unavailable")

            check("domain_opens_foodex_installer",
                  code == 302 and urlsplit(location).path == "/install")
            first = page(1)
            bootstrap = application / "storage/app/site-first-run.json"
            check("private_first_run_file",
                  bootstrap.is_file() and not bootstrap.is_symlink()
                  and stat.S_IMODE(bootstrap.stat().st_mode) & 0o077 == 0)
            token = json.loads(bootstrap.read_text(encoding="utf-8")).get("token")
            check("private_possession_token",
                  isinstance(token, str)
                  and re.fullmatch(r"[0-9a-f]{64}", token) is not None
                  and token.encode() not in first)

            no_token_code, _, _ = client.call(
                "/install/step/2", {"_token": csrf(first)})
            check("skip_blocked_before_possession", no_token_code == 403)
            advance(1, {"token": token})
            advance(2)
            advance(3)
            valid_db = {
                "site_url": client.origin,
                "db_host": host,
                "db_port": str(port),
                "db_database": database,
                "db_username": user,
                "db_password": password,
            }
            checks.extend(invalid_database_retry_probe(
                client, application, public, valid_db))
            advance(4, valid_db)
            check("private_env_written", (application / ".env").is_file())
            advance(5)
            # Changing APP_NAME may not rename the authenticated session cookie.
            advance(6, {"app_name": "WalidSite cPanel QA", "app_locale": "en"})
            owner = json.loads(
                (home / "contracts/owner-cv-baseline.json").read_text(encoding="utf-8")
            )["identity"]["contact"]["email"]
            owner_password = "Qa-" + secrets.token_urlsafe(26) + "!9Az"
            advance(7, {
                "admin_email": owner,
                "admin_password": owner_password,
                "admin_password_confirmation": owner_password,
            })
            advance(8, {"disk": "local"})
            advance(9, {"cache_store": "file", "queue_connection": "sync"})
            advance(10, {
                "mail_mailer": "log",
                "mail_from_address": "noreply@example.com",
                "mail_from_name": "WalidSite QA",
            })
            advance(11)
            advance(12)
            advance(13)
            advance(14)
            advance(15)

            lock = application / "storage/app/site-installed.lock"
            check("permanent_install_lock", lock.is_file() and not lock.is_symlink())
            check("bootstrap_token_revoked", not bootstrap.exists())
            check("install_wizard_closed", client.call("/install")[0] == 404)
            check("legacy_setup_closed", client.call("/setup")[0] == 404)

            status, _, html = client.call("/admin/login")
            check("owner_login_available",
                  status == 200 and b'name="email"' in html)
            status, location, _ = client.call("/admin/login", {
                "_token": csrf(html),
                "email": owner,
                "password": owner_password,
            })
            check("verified_owner_authenticated",
                  status == 302
                  and urlsplit(location).path in ("/admin", "/admin/dashboard"))
            check("admin_dashboard_live", client.call("/admin")[0] == 200)
            check("admin_updates_live", client.call("/admin/updates")[0] == 200)
            check("public_site_live", client.call("/")[0] == 200)
            private_env = application / ".env"
            check("private_settings_permissions",
                  private_env.is_file() and not private_env.is_symlink()
                  and stat.S_IMODE(private_env.stat().st_mode) & 0o077 == 0)
            checks.extend(post_publish_security(
                client, Client(web_port), application, public))
            checks.extend(post_publish_missing_lock_security(
                client, Client(web_port), application, public))
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)

    return {
        "source_sha": sha,
        "foodex_15_stage_zip_mariadb_pass": True,
        "checks": checks,
        "real_browser_tested": False,
        "apache_https_tested": False,
        "production_ready": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cpanel-zip", required=True, type=Path)
    parser.add_argument("--sha", required=True)
    args = parser.parse_args()
    try:
        result = run(
            args.cpanel_zip, args.sha,
            os.getenv("CI_FOODEX_DB_HOST", ""),
            int(os.getenv("CI_FOODEX_DB_PORT", "0")),
            os.getenv("CI_FOODEX_DB_NAME", ""),
            os.getenv("CI_FOODEX_DB_USER", ""),
            os.getenv("CI_FOODEX_DB_PASSWORD", ""),
        )
    except (SetupFailure, ValueError, OSError, KeyError,
            zipfile.BadZipFile, UnicodeError) as error:
        print(json.dumps({
            "foodex_15_stage_zip_mariadb_pass": False,
            "reason": str(error) if isinstance(error, SetupFailure)
                      else type(error).__name__,
        }))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
