"""W10 real-HTTP gate unit regressions; live Apache is tested separately."""
import io
import json
import tempfile
import unittest
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import patch

from qa.cpanel_https_setup_gate import (
    AcceptanceError, Client, Response, accept, csrf_from_html,
    local_https_origin, main, private_token,
)

CSRF = "A" * 40
TOKEN = "f" * 64
UNLOCK = '<form><input name="_token" value="' + CSRF + '"><input name="token"></form>'
DATABASE = '<form><input name="db_database"></form>'


class FirstRunHttpGateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / "cpanel-home"
        (self.home / "public_html").mkdir(parents=True)
        path = self.home / "application/storage/app/site-first-run.json"
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps({"token": TOKEN, "key": "not exported"}),
                        encoding="utf-8")
        path.chmod(0o600)
        self.private = path

    def test_origin_is_loopback_only_with_valid_https_port(self):
        self.assertEqual(local_https_origin("https://localhost:18966/"),
                         "https://localhost:18966")
        for value in ("http://localhost:18966", "https://example.com:18966",
                      "https://127.0.0.1:18966", "https://localhost",
                      "https://localhost:18966/evil",
                      "https://user:pw@localhost:18966",
                      "https://localhost:18966?x=1",
                      "https://localhost:443", "https://LOCALHOST:18966",
                      "https://localhost:18966\\@evil"):
            with self.subTest(value=value):
                with self.assertRaises(AcceptanceError):
                    local_https_origin(value)

    def test_private_token_exact_location_mode_and_symlink_guard(self):
        self.assertEqual(private_token(self.home), TOKEN)
        self.private.chmod(0o644)
        with self.assertRaises(AcceptanceError):
            private_token(self.home)
        self.private.chmod(0o600)
        self.private.write_text('{"token":"short"}', encoding="utf-8")
        with self.assertRaises(AcceptanceError):
            private_token(self.home)
        self.private.write_text(json.dumps({"token": TOKEN}), encoding="utf-8")
        moved = self.home / "elsewhere.json"
        self.private.rename(moved)
        self.private.symlink_to(moved)
        with self.assertRaises(AcceptanceError):
            private_token(self.home)

    def test_csrf_must_be_exactly_one_valid_hidden_input(self):
        self.assertEqual(csrf_from_html(UNLOCK), CSRF)
        for bad in ('', '<input name="x" value="' + CSRF + '">',
                    UNLOCK + UNLOCK, '<input name="_token" value="<secret>">'):
            with self.subTest(bad=bad[:20]):
                with self.assertRaises(AcceptanceError):
                    csrf_from_html(bad)

    def fake_client(self, *, failed_step=None, secret_leak=False):
        class Cookie:
            name = "laravel-session"
            secure = True
            _rest = {"HttpOnly": None}
        class Fake:
            origin = "https://localhost:18966"
            cookies = [Cookie()]
            def __init__(self, origin, ca_file):
                self.n = 0

            def request(self, path, form=None):
                responses = [
                    ("/", 302, "/setup", ""),
                    ("/admin/login", 302, "/setup", ""),
                    *[(p, 404, "", "") for p in (
                        "/.env", "/application/.env",
                        "/contracts/owner-cv-baseline.json",
                        "/vendor/autoload.php",
                        "/storage/logs/laravel.log",
                        "/site-first-run.json",
                    )],
                    ("/up", 200, "", "healthy"),
                    ("/setup", 200, "", UNLOCK),
                    ("/setup/unlock", 419, "", "csrf denied"),
                    ("/setup/database", 403, "", "unauthorized"),
                    ("/setup/unlock", 302, "/setup", "bad token"),
                    ("/setup", 200, "", UNLOCK),
                    ("/setup/unlock", 302, "/setup", "valid token"),
                    ("/setup", 200, "", DATABASE),
                    ("/setup?lang=ar", 200, "", '<html dir="rtl">' + DATABASE),
                ]
                expected, status, location, body = responses[self.n]
                self.n += 1
                assert path == expected
                if failed_step == self.n:
                    status = 200 if status != 200 else 403
                if secret_leak and self.n == 10:
                    body += TOKEN
                return Response(status, self.origin + location if location else "", body)
        return Fake

    def test_mock_contract_success_remains_explicitly_non_production(self):
        with patch("qa.cpanel_https_setup_gate.Client", self.fake_client()):
            result = accept("https://localhost:18966", self.home, Path("ca.pem"))
        self.assertGreaterEqual(result["http_checks_passed"], 14)
        self.assertTrue(result["first_run_http_possession_verified"])
        for key in ("database_install_verified", "owner_activation_verified",
                    "browser_interaction_verified", "production_staging_verified",
                    "admin_full_code_update_verified", "backup_restore_verified",
                    "production_ready"):
            self.assertFalse(result[key], key)

    def test_wrong_http_status_and_secret_body_fail_closed(self):
        for args in ({"failed_step": 1}, {"failed_step": 14}, {"secret_leak": True}):
            with self.subTest(args=args):
                with patch("qa.cpanel_https_setup_gate.Client", self.fake_client(**args)):
                    with self.assertRaises(AcceptanceError):
                        accept("https://localhost:18966", self.home, Path("ca.pem"))

    def test_cli_error_redacts_private_files_and_token(self):
        with redirect_stderr(io.StringIO()) as error:
            exit_code = main(["--origin", "https://evil.com:18966",
                              "--account-home", str(self.home),
                              "--ca-file", str(self.private)])
        self.assertEqual(exit_code, 1)
        failure = json.loads(error.getvalue())
        self.assertFalse(failure["production_ready"])
        self.assertNotIn(TOKEN, error.getvalue())
        self.assertNotIn(str(self.home), error.getvalue())


if __name__ == "__main__":
    unittest.main()
