#!/usr/bin/env python3
"""CI-only: run the exact source-attested cPanel Setup ZIP on real Apache HTTPS.

The existing strict local HTTPS gate checks both /setup and the independent
FOODEX /install possession/CSRF/session boundaries. This harness supplies a
temporary mod_php Apache vhost and locally trusted, disposable test certificate.
It does NOT install a database, sign a publisher release, deploy to staging,
or assert that a real hosting operator has approved cPanel compatibility.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time

from qa.cpanel_first_run_http_probe import extract_verified_archive
from qa.cpanel_https_setup_gate import AcceptanceError, accept


def apache_config(home: Path, work: Path, port: int) -> str:
    """Local-only TLS vhost serving the *unmodified* extracted public_html."""
    if (not home.is_absolute() or not work.is_absolute()
            or not 1024 <= port <= 65535 or not home.is_dir()
            or (home / "public_html").is_symlink()
            or not (home / "public_html/index.php").is_file()):
        raise AcceptanceError("Invalid disposable Apache source")
    # No interpolated host names/paths from a form, DNS or request are accepted.
    public = home / "public_html"
    return "\n".join([
        'ServerRoot "/etc/apache2"',
        'ServerName localhost',
        'Listen 127.0.0.1:' + str(port),
        'PidFile "' + str(work / "apache.pid") + '"',
        'Mutex file:"' + str(work) + '" default',
        'IncludeOptional "/etc/apache2/mods-enabled/*.load"',
        'IncludeOptional "/etc/apache2/mods-enabled/*.conf"',
        'TypesConfig /etc/mime.types',
        'DirectoryIndex index.php',
        'ErrorLog "' + str(work / "apache.log") + '"',
        'LogLevel warn',
        '<VirtualHost 127.0.0.1:' + str(port) + '>',
        '  ServerName localhost',
        '  SSLEngine On',
        '  SSLCertificateFile "' + str(work / "localhost.crt") + '"',
        '  SSLCertificateKeyFile "' + str(work / "localhost.key") + '"',
        '  DocumentRoot "' + str(public) + '"',
        '  <Directory "' + str(public) + '">',
        '    Options +FollowSymLinks',
        '    AllowOverride All',
        '    Require all granted',
        '  </Directory>',
        '</VirtualHost>',
        '',
    ])


def run(package: Path, source_sha: str) -> dict:
    if os.environ.get("GITHUB_ACTIONS") != "true":
        raise AcceptanceError("This HTTPS rehearsal requires the disposable CI runner")
    if not re.fullmatch(r"[a-f0-9]{40}", source_sha):
        raise AcceptanceError("Exact source SHA is required")
    if (not package.is_file() or package.is_symlink()
            or not shutil.which("apache2") or not shutil.which("openssl")):
        raise AcceptanceError("Apache, OpenSSL and the Setup ZIP are required")

    with tempfile.TemporaryDirectory(prefix="walidsite-apache-https-ci-") as folder:
        work = Path(folder)
        home = work / "account"
        home.mkdir(mode=0o700)
        # Verifies the complete cPanel manifest and source SHA against the
        # same pinned ZIP snapshot whose bytes will be extracted.
        extract_verified_archive(package, home, expected_sha=source_sha)
        if (home / "application/.env").exists() or not (
                home / "application/vendor/autoload.php").is_file():
            raise AcceptanceError("Disposable Setup ZIP is not a clean install")

        cert, key = work / "localhost.crt", work / "localhost.key"
        try:
            subprocess.run([
                "openssl", "req", "-x509", "-newkey", "rsa:2048",
                "-nodes", "-days", "1", "-subj", "/CN=localhost",
                "-addext", "subjectAltName=DNS:localhost",
                "-addext", "basicConstraints=critical,CA:TRUE",
                "-keyout", str(key), "-out", str(cert),
            ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
               timeout=30)
            key.chmod(0o600)
            with socket.socket() as server:
                server.bind(("127.0.0.1", 0))
                port = server.getsockname()[1]
            configuration = work / "apache.conf"
            configuration.write_text(apache_config(home, work, port), encoding="utf-8")
            subprocess.run(
                ["apache2", "-t", "-f", str(configuration)],
                check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                timeout=10,
            )
            modules = subprocess.run(
                ["apache2", "-M", "-f", str(configuration)], check=True,
                capture_output=True, text=True, timeout=10,
            ).stdout
            if any(name not in modules for name in (
                    "php_module", "ssl_module", "rewrite_module",
                    "mpm_prefork_module")):
                raise AcceptanceError("Apache TLS/PHP modules are unavailable")
        except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
            raise AcceptanceError("Disposable Apache TLS setup failed") from None

        process = subprocess.Popen(
            ["apache2", "-X", "-f", str(configuration)],
            cwd=work, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            env={**os.environ, "APP_ENV": "testing", "APP_DEBUG": "false",
                 "APP_KEY": "", "SESSION_DRIVER": "file", "CACHE_STORE": "file"},
        )
        try:
            for _ in range(80):
                if process.poll() is not None:
                    raise AcceptanceError("Disposable Apache stopped before HTTPS acceptance")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise AcceptanceError("Disposable Apache HTTPS port did not open")
            report = accept(f"https://localhost:{port}", home, cert)
            if not report["first_run_http_possession_verified"]:
                raise AcceptanceError("Real Apache first-run acceptance did not pass")
            return {
                "schema": "walidsite.ci-apache-https-setup.v1",
                "source_sha": source_sha,
                "actual_apache_https_verified": True,
                "source_setup_zip_verified": True,
                "security_checks": report["http_checks_passed"],
                "fresh_database_install_verified": False,
                "publisher_signature_verified": False,
                "production_staging_verified": False,
                "production_ready": False,
            }
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cpanel-zip", required=True, type=Path)
    parser.add_argument("--sha", required=True)
    args = parser.parse_args(argv)
    try:
        result = run(args.cpanel_zip, args.sha)
    except (AcceptanceError, ValueError, OSError, RuntimeError) as exc:
        print(json.dumps({"actual_apache_https_verified": False,
                          "production_ready": False,
                          "error": type(exc).__name__}), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
