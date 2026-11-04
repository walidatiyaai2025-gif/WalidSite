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

from qa.build_cpanel_package import verify_archive
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
        redirect = urlsplit(location)
        return status in (401, 403, 419) or (
            status == 302 and redirect.path == "/admin/login"
            and (not redirect.netloc
                 or redirect.netloc == urlsplit(client.origin).netloc)
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

def run(package: Path, sha: str, host: str, port: int, database: str,
        user: str, password: str) -> dict:
    if (host != "127.0.0.1" or port != 3307
            or database != "walidsite_ci_foodex" or user != "walidsite_ci"
            or not password):
        raise SetupFailure("disposable_database_only")

    verify_archive(package, sha)
    with tempfile.TemporaryDirectory(prefix="walidsite-ci-foodex-") as directory:
        home = Path(directory)
        with zipfile.ZipFile(package) as archive:
            archive.extractall(home)  # validate ZIP inventory *before* extraction
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
            advance(4, {
                "site_url": client.origin,
                "db_host": host,
                "db_port": str(port),
                "db_database": database,
                "db_username": user,
                "db_password": password,
            })
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
