"""T025 synthetic config/negative checks for disposable Apache HTTPS acceptance."""
import io
import json
import os
import tempfile
import unittest
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import patch

from qa.cpanel_apache_https_ci import apache_config, main, run
from qa.cpanel_https_setup_gate import AcceptanceError


class ApacheHTTPSSetupCITest(unittest.TestCase):
    def test_vhost_binds_loopback_tls_and_only_verified_public_root(self):
        with tempfile.TemporaryDirectory() as root:
            work = Path(root)
            home = work / "account"
            (home / "public_html").mkdir(parents=True)
            (home / "public_html/index.php").write_text("<?php")
            result = apache_config(home, work, 18443)
            self.assertIn("Listen 127.0.0.1:18443", result)
            self.assertIn('DocumentRoot "' + str(home / "public_html") + '"', result)
            self.assertIn('SSLCertificateFile "' + str(work / "localhost.crt") + '"', result)
            self.assertIn("SSLEngine On", result)
            self.assertIn('Define APACHE_RUN_DIR "' + str(work) + '"', result)
            self.assertIn('Mutex "file:' + str(work) + '" default', result)
            self.assertNotIn('Mutex file:"', result)
            self.assertIn("AllowOverride All", result)
            self.assertNotIn("Listen 0.0.0.0", result)
            self.assertNotIn("DocumentRoot \"" + str(home / "application"), result)
            for bad in (0, 443, 65536):
                with self.subTest(port=bad), self.assertRaises(AcceptanceError):
                    apache_config(home, work, bad)
            linked = work / "linked-account"
            linked.symlink_to(home, target_is_directory=True)
            # A symlinked public document root is not a safe cPanel fixture.
            (home / "public_html").rename(home / "public-files")
            (home / "public_html").symlink_to(home / "public-files")
            with self.assertRaises(AcceptanceError):
                apache_config(home, work, 18443)

    def test_runner_rejects_non_ci_and_bad_sha_without_starting_any_server(self):
        with tempfile.TemporaryDirectory() as root:
            absent = Path(root) / "absent.zip"
            with patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
                with self.assertRaises(AcceptanceError):
                    run(absent, "a" * 40)
            with patch.dict(os.environ, {"GITHUB_ACTIONS": "true"}):
                with self.assertRaises(AcceptanceError):
                    run(absent, "not-a-source-commit")
            errors = io.StringIO()
            with patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
                with redirect_stderr(errors):
                    self.assertEqual(main(["--cpanel-zip", str(absent), "--sha", "a" * 40]), 1)
            payload = json.loads(errors.getvalue())
            self.assertFalse(payload["actual_apache_https_verified"])
            self.assertFalse(payload["production_ready"])
            self.assertNotIn(str(absent), errors.getvalue())


if __name__ == "__main__":
    unittest.main()
