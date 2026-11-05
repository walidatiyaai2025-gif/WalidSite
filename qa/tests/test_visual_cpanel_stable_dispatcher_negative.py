"""W10 adversarial HTTP acceptance for the real stable cPanel dispatcher.

These negative tests use the shipped PHP entrypoint, not an emulated copy.
They intentionally have no Laravel vendor tree: each unsafe input MUST return
503 before PHP reaches autoload or attempts to execute a release.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[2]
DISPATCHER = ROOT / "backend/resources/deployment/cpanel-stable-index.php"


@unittest.skipUnless(shutil.which("php") and DISPATCHER.is_file(), "PHP and stable dispatcher required")
class StableDispatcherFailClosedHTTPTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="walidsite-stable-negative-")
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.public = self.home / "public_html"
        self.public.mkdir()
        shutil.copyfile(DISPATCHER, self.public / "index.php")
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            self.port = sock.getsockname()[1]
        self.server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{self.port}", "-t", str(self.public),
             str(self.public / "index.php")],
            cwd=self.home, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        self.addCleanup(self._stop_server)
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for attempt in range(50):
            if self.server.poll() is not None:
                self.fail("PHP dispatcher exited before serving HTTP")
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            self.fail("PHP dispatcher never listened on loopback")

    def _stop_server(self):
        if self.server.poll() is None:
            self.server.terminate()
            try:
                self.server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.server.kill()
                self.server.wait(timeout=3)

    def get(self, token=None):
        headers = {"Host": "127.0.0.1"}
        if token is not None:
            headers["X-Walidsite-Release-Probe"] = token
        request = urllib.request.Request(
            f"http://127.0.0.1:{self.port}/", headers=headers
        )
        try:
            response = self.opener.open(request, timeout=4)
        except urllib.error.HTTPError as exc:
            response = exc
        with response:
            return response.status, response.read(2048)

    def assert_denied(self, expected_phrase):
        status, body = self.get()
        self.assertEqual(503, status)
        self.assertIn(expected_phrase, body.lower())
        self.assertNotIn(b"fatal error", body.lower())
        self.assertNotIn(b"stack trace", body.lower())

    def test_malformed_release_pointer_denied_before_bootstrap(self):
        (self.home / ".walidsite-active-release.json").write_text(
            "{invalid", encoding="utf-8"
        )
        self.assert_denied(b"release pointer unavailable")

    def test_traversal_release_pointer_denied_before_bootstrap(self):
        (self.home / ".walidsite-active-release.json").write_text(
            json.dumps({"schema": 1, "version": "0.1.2",
                        "root": "../../external-release"}), encoding="utf-8"
        )
        self.assert_denied(b"release pointer unavailable")

    def test_symlink_release_pointer_denied_before_bootstrap(self):
        destination = self.home / "untrusted.json"
        destination.write_text("{}", encoding="utf-8")
        (self.home / ".walidsite-active-release.json").symlink_to(destination)
        self.assert_denied(b"release pointer unavailable")

    def test_corrupted_maintenance_denied_before_bootstrap(self):
        (self.home / ".walidsite-update-maintenance.json").write_text(
            '{"schema":1,"expires_at":"invalid"}', encoding="utf-8"
        )
        self.assert_denied(b"maintenance is unavailable")

    def test_untrusted_maintenance_probe_denied_without_token_leak(self):
        token = "private-probe-" + "a" * 32
        marker = {
            "schema": 1,
            "expires_at": int(time.time()) + 120,
            "previous_root": "legacy",
            "previous_version": "0.1.1",
            "probe_sha256": hashlib.sha256(token.encode()).hexdigest(),
        }
        maintenance = self.home / ".walidsite-update-maintenance.json"
        maintenance.write_text(json.dumps(marker), encoding="utf-8")
        maintenance.chmod(0o600)
        status, body = self.get("0" * 64)
        self.assertEqual(503, status)
        self.assertIn(b"maintenance in progress", body.lower())
        self.assertNotIn(token.encode(), body)
        self.assertNotIn(marker["probe_sha256"].encode(), body)

    def test_exposed_or_hardlinked_maintenance_marker_refuses_correct_probe(self):
        token = "f" * 64
        lease = self.home / ".walidsite-update-maintenance.json"
        lease.write_text(json.dumps({
            "schema": 1,
            "expires_at": int(time.time()) + 120,
            "previous_root": "legacy",
            "previous_version": "0.1.2",
            "probe_sha256": hashlib.sha256(token.encode()).hexdigest(),
        }), encoding="utf-8")

        # An accidentally exposed 0644 marker must not authorize an otherwise
        # correct private health token or fall through to Laravel.
        self.assert_denied(b"maintenance is unavailable")
        lease.chmod(0o600)
        second_link = self.home / "maintenance-hardlink.json"
        second_link.hardlink_to(lease)
        self.assert_denied(b"maintenance is unavailable")
        second_link.unlink()

        # Restore the legitimate single-link 0600 control-file shape. Its
        # actual presence must be honored and block normal visitors.
        status, body = self.get("0" * 64)
        self.assertEqual(503, status)
        self.assertIn(b"maintenance in progress", body.lower())
        self.assertNotIn(token.encode(), body)

    def test_private_release_pointer_mode_and_hardlink_fail_before_release_probe(self):
        pointer = self.home / ".walidsite-active-release.json"
        pointer.write_text(json.dumps({
            "schema": 1,
            "version": "0.1.2",
            "root": ".walidsite-releases/v0.1.2-" + "a" * 40,
        }), encoding="utf-8")

        # The pointer is syntactically valid, but no release directory exists.
        # Enforce filesystem identity BEFORE looking for executable code.
        self.assert_denied(b"release pointer unavailable")
        pointer.chmod(0o600)
        second_link = self.home / "pointer-hardlink.json"
        second_link.hardlink_to(pointer)
        self.assert_denied(b"release pointer unavailable")
        second_link.unlink()

        # Legitimate single-link 0600 pointer passes the inode guard and
        # fails at the *next* trust gate because code is intentionally absent.
        self.assert_denied(b"release files unavailable")

    def test_shipped_dispatcher_reads_no_control_file_by_reopening_its_path(self):
        source = DISPATCHER.read_text(encoding="utf-8")
        self.assertNotIn("file_get_contents($maintenance)", source)
        self.assertNotIn("file_get_contents($pointer)", source)
        self.assertIn("stream_get_contents($stream, $maximum + 1)", source)
        self.assertIn("$readPrivateControl($maintenance, 1024", source)
        self.assertIn("$readPrivateControl($pointer, 512", source)

    def test_hostile_previous_release_denied_before_bootstrap(self):
        marker = {
            "schema": 1,
            "expires_at": int(time.time()) - 1,
            "previous_root": "../../unsafe",
            "previous_version": "0.1.1",
            "probe_sha256": "a" * 64,
        }
        (self.home / ".walidsite-update-maintenance.json").write_text(
            json.dumps(marker), encoding="utf-8"
        )
        self.assert_denied(b"maintenance is unavailable")


if __name__ == "__main__":
    unittest.main()
