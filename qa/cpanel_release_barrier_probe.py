#!/usr/bin/env python3
"""Real PHP HTTP regression for the stable cPanel release maintenance barrier.

Runs only against an extracted verified Setup ZIP on loopback; never contacts
a production site. The false release pointer deliberately targets an absent
code tree so a crashed update cannot silently serve unverified PHP.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def status(opener, port: int, probe: str | None = None) -> tuple[int, bytes]:
    headers = {"Host": "127.0.0.1"}
    if probe:
        headers["X-Walidsite-Release-Probe"] = probe
    request = urllib.request.Request(
        f"http://127.0.0.1:{port}/install", headers=headers,
    )
    try:
        response = opener.open(request, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(60_000)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cpanel-zip", required=True, type=Path)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="walidsite-lease-http-") as tmp:
        home = Path(tmp)
        with zipfile.ZipFile(args.cpanel_zip) as archive:
            if archive.testzip() is not None:
                raise RuntimeError("Release ZIP failed CRC")
            archive.extractall(home)
        original = home / "application"
        public = home / "public_html"
        if (original / ".env").exists():
            raise RuntimeError("Refusing to test against an installed environment")
        if not (public / "index.php").is_file():
            raise RuntimeError("Missing stable front controller")

        env = os.environ.copy()
        env.update({
            "APP_ENV": "testing",
            "APP_KEY": "",
            "APP_DEBUG": "true",
            "SITE_SETUP_ENABLED": "false",
            "SESSION_DRIVER": "file",
            "CACHE_STORE": "file",
        })
        token = os.urandom(32).hex()
        marker_path = home / ".walidsite-update-maintenance.json"
        marker = {
            "schema": 1,
            "expires_at": int(time.time()) + 120,
            "previous_root": "legacy",
            "previous_version": "0.1.2",
            "probe_sha256": hashlib.sha256(token.encode("ascii")).hexdigest(),
        }
        marker_path.write_text(json.dumps(marker), encoding="ascii")
        marker_path.chmod(0o600)

        with socket.socket() as s:
            s.bind(("127.0.0.1", 0))
            port = s.getsockname()[1]
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(public), str(public / "index.php")],
            cwd=original, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        try:
            ready = False
            for _ in range(40):
                if server.poll() is not None:
                    raise RuntimeError("PHP web server exited")
                try:
                    code, body = status(opener, port)
                    ready = True
                    break
                except urllib.error.URLError:
                    time.sleep(0.1)
            if not ready or code != 503 or b"maintenance" not in body.lower():
                raise RuntimeError(f"Maintenance barrier failed closed: {code}")
            code, body = status(opener, port, "0" * 64)
            if code != 503 or token.encode() in body:
                raise RuntimeError("Untrusted probe passed update barrier")
            code, body = status(opener, port, token)
            if code != 200 or b"WalidSite Installation" not in body:
                raise RuntimeError(f"Verified health probe cannot reach the app: {code}")

            # Simulate a crash AFTER an atomic pointer switch but BEFORE final
            # independent health verification. The lease has expired. The
            # private pointer now refers to an unavailable new release.
            pointer = {
                "schema": 1, "version": "0.1.3",
                "root": ".walidsite-releases/v0.1.3-" + "a" * 40,
            }
            (home / ".walidsite-active-release.json").write_text(
                json.dumps(pointer), encoding="ascii"
            )
            marker["expires_at"] = int(time.time()) - 1
            marker_path.write_text(json.dumps(marker), encoding="ascii")
            code, body = status(opener, port)
            if code != 200 or b"WalidSite Installation" not in body:
                raise RuntimeError(f"Expired update did not serve known previous code: {code}")
            marker_path.unlink()
            code, body = status(opener, port)
            if code != 503 or b"release" not in body.lower():
                raise RuntimeError("Unverified pointer was accepted after clearing safety barrier")
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
    print("PASS: real HTTP maintenance denies visitors, authenticates a health probe and serves previous code after a crash")


if __name__ == "__main__":
    main()
