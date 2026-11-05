#!/usr/bin/env python3
"""Regression: an unpacked cPanel install must boot without APP_KEY even if PHP inherits testing env.

Run only against disposable state. The probe never displays private key, token,
request cookies or exception bodies. No external network requests are made.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

# Support both `python qa/...py` in CI and `python -m qa...` in QA tests.
if __package__:
    from qa.build_cpanel_package import MAX_COMPRESSED_ARCHIVE_BYTES, verify_archive
else:
    from build_cpanel_package import MAX_COMPRESSED_ARCHIVE_BYTES, verify_archive


def status(opener, port: int, host: str) -> tuple[int, bytes]:
    request = urllib.request.Request(
        f"http://127.0.0.1:{port}/setup", headers={"Host": host}
    )
    try:
        response = opener.open(request, timeout=2)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(100_000)


def extract_verified_archive(package: Path, target: Path) -> None:
    """Verify and extract one pinned private ZIP snapshot, never a reopened input."""
    if not all(hasattr(os, name) for name in ("O_NOFOLLOW", "O_NONBLOCK")):
        raise ValueError("Secure no-follow ZIP access is unavailable")
    try:
        fd = os.open(package, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError as exc:
        raise ValueError("Unsafe cPanel ZIP input") from exc
    with os.fdopen(fd, "rb") as source:
        before = os.fstat(source.fileno())
        if (not stat.S_ISREG(before.st_mode)
                or before.st_size <= 0
                or before.st_size > MAX_COMPRESSED_ARCHIVE_BYTES):
            raise ValueError("Unsafe or oversized cPanel ZIP input")
        with tempfile.TemporaryDirectory(prefix="walidsite-verified-cpanel-") as directory:
            snapshot = Path(directory) / "pinned.zip"
            with snapshot.open("xb") as output:
                copied = 0
                while chunk := source.read(1024 * 1024):
                    copied += len(chunk)
                    if copied > MAX_COMPRESSED_ARCHIVE_BYTES:
                        raise ValueError("cPanel ZIP changed or exceeded safety budget")
                    output.write(chunk)
            after = os.fstat(source.fileno())
            if (copied != before.st_size
                    or (before.st_dev, before.st_ino, before.st_size,
                        before.st_mtime_ns, before.st_ctime_ns)
                    != (after.st_dev, after.st_ino, after.st_size,
                        after.st_mtime_ns, after.st_ctime_ns)):
                raise ValueError("cPanel ZIP changed during snapshot")
            verify_archive(snapshot)
            with zipfile.ZipFile(snapshot) as archive:
                archive.extractall(target)


def probe(app_root: Path, public_root: Path) -> None:
    if not (app_root / "vendor/autoload.php").is_file():
        raise RuntimeError("Missing production/test Composer vendor")
    if (app_root / ".env").exists() or (app_root / "bootstrap/cache/config.php").exists():
        raise RuntimeError("Disposable initial install must have no saved environment/cache")

    boot = app_root / "storage/app/site-first-run.json"
    if boot.exists() or boot.is_symlink():
        raise RuntimeError("Refusing to modify existing private bootstrap state")
    if not shutil.which("php"):
        raise RuntimeError("PHP CLI missing")

    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing",
        "APP_DEBUG": "true",
        "APP_KEY": "",
        "SITE_SETUP_ENABLED": "false",
        "SITE_SETUP_TOKEN": "",
        "SESSION_DRIVER": "file",
        "CACHE_STORE": "file",
    })
    # Local ephemeral port only: no outgoing requests and no published secrets.
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]

    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(public_root), str(public_root / "index.php")],
        cwd=app_root, env=env, stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        local_code = None
        for _ in range(40):
            if server.poll() is not None:
                raise RuntimeError("Local PHP HTTP server exited")
            try:
                local_code, body = status(opener, port, "127.0.0.1")
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        if local_code != 200:
            raise RuntimeError(f"Initial HTTP setup not available: status {local_code}")
        if b'name="token"' not in body or b"MissingAppKeyException" in body:
            raise RuntimeError("Initial setup did not render the possession form")
        if not boot.is_file() or boot.is_symlink():
            raise RuntimeError("Missing private bootstrap after HTTP request")
        if stat.S_IMODE(boot.stat().st_mode) & 0o077:
            raise RuntimeError("Private bootstrap permissions too broad")
        state = json.loads(boot.read_text(encoding="utf-8"))
        token = state.get("token")
        if not isinstance(token, str) or not re.fullmatch(r"[0-9a-f]{64}", token):
            raise RuntimeError("Invalid first-run possession token")
        if token.encode("ascii") in body:
            raise RuntimeError("Private token exposed in the initial form")
        external_code, external_body = status(opener, port, "walidatiya.com")
        if external_code != 403 or b"MissingAppKeyException" in external_body:
            raise RuntimeError("External host accepted insecure setup or showed a stack trace")
        if token.encode("ascii") in external_body:
            raise RuntimeError("Private token exposed in HTTP error")
        if json.loads(boot.read_text(encoding="utf-8")) != state:
            raise RuntimeError("Bootstrap token was rotated unexpectedly")
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)
        # Only remove the state created by this disposable test.
        if boot.is_file() and not boot.is_symlink():
            boot.unlink()
    print("PASS: first web boot generates private state, no APP_KEY 500, external HTTP denied")


def main() -> None:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--app-root", type=Path, help="Uninstalled Laravel root (CI checkout)")
    modes.add_argument("--cpanel-zip", type=Path, help="Built cPanel one-upload archive")
    args = parser.parse_args()
    if args.app_root:
        root = args.app_root.resolve()
        probe(root, root / "public")
    else:
        with tempfile.TemporaryDirectory(prefix="walidsite-bootstrap-probe-") as tmp:
            extract_verified_archive(args.cpanel_zip, Path(tmp))
            home = Path(tmp)
            probe(home / "application", home / "public_html")


if __name__ == "__main__":
    main()
