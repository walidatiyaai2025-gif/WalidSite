#!/usr/bin/env python3
"""Disposable cPanel ZIP -> real local HTTP setup -> MariaDB -> owner activation acceptance.

Runs only against the fixed CI localhost database identity and a disposable
extraction; does NOT prove Apache HTTPS, browser fidelity or production setup.
Never print tokens, credentials, cookies, application keys or HTTP bodies.
"""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import secrets
import socket
import stat
import subprocess
import tempfile
import time
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, ProxyHandler, Request, build_opener
import zipfile

from qa.build_cpanel_package import verify_archive


class SetupFailure(RuntimeError):
    """Safe, fixed-name acceptance failure."""


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class Inputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.items = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() == 'input':
            self.items.append(dict(attrs))


def csrf(body: bytes) -> str:
    parser = Inputs()
    parser.feed(body.decode('utf-8', 'strict'))
    matches = [i.get('value') for i in parser.items if i.get('name') == '_token']
    if len(matches) != 1 or not isinstance(matches[0], str) or not re.fullmatch('[A-Za-z0-9]{40,128}', matches[0]):
        raise SetupFailure('csrf_field')
    return matches[0]


class Client:
    def __init__(self, port):
        self.origin = f'http://127.0.0.1:{port}'
        self.http = build_opener(ProxyHandler({}), HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def call(self, path, form=None, timeout=120):
        if not path.startswith('/') or path.startswith('//') or '..' in path:
            raise SetupFailure('unsafe_path')
        request = Request(self.origin + path,
                          data=urlencode(form).encode('utf-8') if form is not None else None,
                          headers={'Accept': 'text/html'},
                          method='POST' if form is not None else 'GET')
        try:
            try:
                response = self.http.open(request, timeout=timeout)
            except HTTPError as error:
                response = error
            with response:
                body = response.read(256 * 1024 + 1)
                if len(body) > 256 * 1024:
                    raise SetupFailure('response_too_large')
                return response.status, response.headers.get('Location', ''), body
        except (URLError, OSError) as error:
            raise SetupFailure('http_transport_' + type(error).__name__) from None


def run(package: Path, sha: str, host: str, port: int, database: str, user: str, password: str):
    if (host != '127.0.0.1' or port != 3306 or database != 'walidsite_ci_install'
            or user != 'walidsite_ci' or not password):
        raise SetupFailure('disposable_database_only')
    verify_archive(package, sha)
    with tempfile.TemporaryDirectory(prefix='walidsite-ci-install-') as directory:
        home = Path(directory)
        with zipfile.ZipFile(package) as archive:
            archive.extractall(home)  # verify_archive rejects traversal/symlinks
        app, public = home / 'application', home / 'public_html'
        if (app / '.env').exists() or not (app / 'vendor/autoload.php').is_file():
            raise SetupFailure('clean_install_payload')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            web_port = sock.getsockname()[1]
        env = os.environ.copy()
        for key in ('APP_KEY', 'SITE_SETUP_ENABLED', 'SITE_SETUP_TOKEN', 'SITE_SETUP_DATABASE_READY',
                    'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PORT', 'DB_CONNECTION'):
            env.pop(key, None)
        env.update({'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'SESSION_DRIVER': 'file',
                    'CACHE_STORE': 'file', 'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'log'})
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{web_port}', '-t', str(public),
                                   str(public / 'index.php')], cwd=app, env=env,
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        client = Client(web_port)
        steps = []

        def require(step, condition):
            if not condition:
                raise SetupFailure(step)
            steps.append(step)

        def post_redirect(path, form, step):
            status, location, _ = client.call(path, form)
            u = urlsplit(location)
            require(step, status == 302 and u.path in ('/setup', '/admin/login', '/admin')
                    and (not u.netloc or u.netloc == urlsplit(client.origin).netloc))

        try:
            for _ in range(60):
                if server.poll() is not None:
                    raise SetupFailure('php_server_exited')
                try:
                    status, _, html = client.call('/setup', timeout=3)
                    break
                except SetupFailure as error:
                    if not str(error).startswith('http_transport_'):
                        raise
                    time.sleep(0.1)
            else:
                raise SetupFailure('php_server_unavailable')
            require('welcome', status == 200 and b'name="token"' in html)
            boot = app / 'storage/app/site-first-run.json'
            if not boot.is_file() or boot.is_symlink() or stat.S_IMODE(boot.stat().st_mode) & 0o077:
                raise SetupFailure('private_bootstrap_permissions')
            token = json.loads(boot.read_text(encoding='utf-8')).get('token')
            require('private_token', isinstance(token, str) and re.fullmatch('[0-9a-f]{64}', token) is not None
                    and token.encode() not in html)
            post_redirect('/setup/unlock', {'_token': csrf(html), 'token': token}, 'unlock')
            status, _, html = client.call('/setup')
            require('database_form', status == 200 and b'name="db_database"' in html)
            post_redirect('/setup/configure', {
                '_token': csrf(html), 'site_url': client.origin,
                'db_host': host, 'db_port': str(port), 'db_database': database,
                'db_username': user, 'db_password': password,
            }, 'database_config_saved')
            status, _, html = client.call('/setup')
            if not (status == 200 and b'/setup/database' in html
                    and b'name="db_database"' not in html):
                # Only print a fixed server-side category, never the response,
                # database password, token, session, or user-entered host.
                messages = (
                    ('db_probe', b'Database connection failed or is not empty'),
                    ('canonical_url', b'Enter a valid root HTTPS site URL'),
                    ('save_private_env', b'Could not save private settings'),
                    ('database_identifier', b'Invalid database identifier or host'),
                    ('secure_setup', b'HTTPS is required for setup'),
                )
                reason = next((key for key, marker in messages if marker in html), '')
                if not reason:
                    if b'The saved database connection is unavailable' in html:
                        reason = 'saved_connection_unavailable'
                    elif b'name="token"' in html:
                        reason = 'session_relocked'
                    elif b'name="db_database"' in html:
                        reason = 'still_database_form'
                    else:
                        reason = 'unexpected_status_' + str(status)
                # State flags only. Never log .env contents or user secrets.
                private_environment = app / '.env'
                reason += '_env_' + ('present' if private_environment.is_file() else 'absent')
                if private_environment.is_file():
                    ready_flag = 'SITE_SETUP_DATABASE_READY=true' in private_environment.read_text(encoding='utf-8')
                    reason += '_ready_' + ('true' if ready_flag else 'false')
                raise SetupFailure('connected_' + reason)
            steps.append('connected')
            post_redirect('/setup/database', {'_token': csrf(html)}, 'migrations')
            status, _, html = client.call('/setup')
            require('owner_form', status == 200 and b'name="password_confirmation"' in html)
            owner_password = 'Ci-' + secrets.token_urlsafe(25) + '!9A'
            post_redirect('/setup/finish', {
                '_token': csrf(html), 'password': owner_password,
                'password_confirmation': owner_password,
            }, 'owner_activation')
            require('install_lock', (app / 'storage/app/site-installed.lock').is_file())
            require('token_revoked', not boot.exists())
            require('installer_closed', client.call('/setup')[0] == 404)
            login_code, _, login_html = client.call('/admin/login')
            require('admin_login_form', login_code == 200 and b'name="email"' in login_html)
            owner_source = json.loads((home / 'contracts/owner-cv-baseline.json').read_text(encoding='utf-8'))
            owner_email = owner_source['identity']['contact']['email']
            post_redirect('/admin/login', {
                '_token': csrf(login_html), 'email': owner_email,
                'password': owner_password,
            }, 'owner_login_accepted')
            require('admin_dashboard', client.call('/admin')[0] == 200)
            require('admin_updates_view', client.call('/admin/updates')[0] == 200)
            require('public_home', client.call('/')[0] == 200)
            private_env = app / '.env'
            require('private_env', private_env.is_file() and not private_env.is_symlink()
                    and stat.S_IMODE(private_env.stat().st_mode) & 0o077 == 0)
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
    return {'source_sha': sha, 'fresh_zip_mariadb_setup_pass': True, 'checks': steps,
            'browser_tested': False, 'apache_https_tested': False,
            'production_ready': False}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--cpanel-zip', type=Path, required=True)
    parser.add_argument('--sha', required=True)
    args = parser.parse_args()
    try:
        result = run(args.cpanel_zip, args.sha, os.getenv('CI_INSTALL_DB_HOST', ''),
                     int(os.getenv('CI_INSTALL_DB_PORT', '0')), os.getenv('CI_INSTALL_DB_NAME', ''),
                     os.getenv('CI_INSTALL_DB_USER', ''), os.getenv('CI_INSTALL_DB_PASSWORD', ''))
    except (SetupFailure, ValueError, OSError, zipfile.BadZipFile, KeyError) as error:
        print(json.dumps({'fresh_zip_mariadb_setup_pass': False, 'reason':
                          str(error) if isinstance(error, SetupFailure) else type(error).__name__}))
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
