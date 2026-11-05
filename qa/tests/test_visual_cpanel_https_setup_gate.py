"""W10 real-HTTP gate unit regressions; live Apache is tested separately."""
import io
import json
import os
import tempfile
import unittest
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import patch
from urllib.request import ProxyHandler

from qa.cpanel_https_setup_gate import (
    AcceptanceError, Client, Response, accept, csrf_from_html,
    local_https_origin, main, private_token,
)

CSRF = "A" * 40
TOKEN = "f" * 64
UNLOCK = '<form><input name="_token" value="' + CSRF + '"><input name="token"></form>'
DATABASE = '<form><input name="db_database"></form>'
FOODEX_WELCOME = ('<form action="https://localhost:18966/install/step/1">'
                  '<input name="_token" value="' + CSRF + '"><input name="token"></form>')
FOODEX_REQUIREMENTS = ('<form action="https://localhost:18966/install/step/2">'
                       '<input name="_token" value="' + CSRF + '"></form>')


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

    def test_private_token_rejects_public_hardlink_without_http_or_token_leak(self):
        self.assertEqual(private_token(self.home), TOKEN)
        public_alias = self.home / "public_html" / "innocent-name.txt"
        try:
            os.link(self.private, public_alias)
        except (OSError, NotImplementedError):
            self.skipTest("Hard links unsupported by this test filesystem")
        try:
            with self.assertRaises(AcceptanceError) as denied:
                private_token(self.home)
            self.assertNotIn(TOKEN, str(denied.exception))
        finally:
            public_alias.unlink()
        self.assertEqual(private_token(self.home), TOKEN)

    def test_private_token_pathname_swap_during_read_fails_closed(self):
        # The attacker can write a replacement with the exact same token,
        # size and permissions. Pinning the file alone is insufficient: its
        # original *named inode* must still be present after the read.
        real_read = os.read
        moved = self.home / "former-private-token.json"
        swapped = False

        def swap_after_first_read(fd, amount):
            nonlocal swapped
            payload = real_read(fd, amount)
            if payload and not swapped:
                swapped = True
                self.private.rename(moved)
                self.private.write_bytes(moved.read_bytes())
                self.private.chmod(0o600)
            return payload

        try:
            with patch("qa.cpanel_https_setup_gate.os.read",
                       side_effect=swap_after_first_read):
                with self.assertRaises(AcceptanceError):
                    private_token(self.home)
            self.assertTrue(swapped)
        finally:
            if moved.exists():
                self.private.unlink()
                moved.rename(self.private)
        self.assertEqual(private_token(self.home), TOKEN)

    def test_loopback_installer_never_inherits_environment_proxy(self):
        ca = self.home / "local-only-ca.pem"
        ca.write_text("synthetic test context; no network opened")
        environment = {
            "HTTPS_PROXY": "http://attacker-proxy.example:8080",
            "HTTP_PROXY": "http://attacker-proxy.example:8080",
            "ALL_PROXY": "http://attacker-proxy.example:8080",
            "NO_PROXY": "",
            "https_proxy": "http://attacker-proxy.example:8080",
            "all_proxy": "http://attacker-proxy.example:8080",
            "no_proxy": "",
        }
        with patch.dict(os.environ, environment), patch(
                "qa.cpanel_https_setup_gate.ssl.create_default_context") as context:
            client = Client("https://localhost:18966", ca)
        context.assert_called_once_with(cafile=str(ca))
        handlers = [handler for handler in client.opener.handlers
                    if isinstance(handler, ProxyHandler)]
        self.assertEqual(len(handlers), 1)
        self.assertEqual(handlers[0].proxies, {})
        self.assertEqual(client.origin, "https://localhost:18966")
        self.assertEqual(private_token(self.home), TOKEN)

    def test_public_html_symlink_to_private_tree_is_rejected_before_http(self):
        public = self.home / "public_html"
        public.rmdir()
        public.symlink_to(self.home / "application", target_is_directory=True)
        # A symlink can make an application directory look like a valid
        # public_html directory; never allow the HTTP acceptance to begin.
        with patch("qa.cpanel_https_setup_gate.Client") as client:
            with self.assertRaisesRegex(AcceptanceError, "distinct real"):
                accept("https://localhost:18966", self.home, Path("ca.pem"))
            client.assert_not_called()

    def test_csrf_must_be_exactly_one_valid_hidden_input(self):
        self.assertEqual(csrf_from_html(UNLOCK), CSRF)
        for bad in ('', '<input name="x" value="' + CSRF + '">',
                    UNLOCK + UNLOCK, '<input name="_token" value="<secret>">'):
            with self.subTest(bad=bad[:20]):
                with self.assertRaises(AcceptanceError):
                    csrf_from_html(bad)

    def fake_client(self, *, failed_step=None, secret_leak=False, exposed_token_path=False,
                    foodex_failed_step=None, foodex_secret_leak=False):
        instances = 0

        class Cookie:
            name = "walidsite-session"
            secure = True
            _rest = {"HttpOnly": None}
        class Fake:
            origin = "https://localhost:18966"
            cookies = [Cookie()]
            def __init__(self, origin, ca_file):
                nonlocal instances
                instances += 1
                self.mode = instances
                self.n = 0

            def request(self, path, form=None):
                legacy_responses = [
                    ("/", 302, "/install", ""),
                    ("/admin/login", 302, "/install", ""),
                    *[(p, 404, "", "") for p in (
                        "/.env", "/application/.env",
                        "/contracts/owner-cv-baseline.json",
                        "/vendor/autoload.php",
                        "/storage/logs/laravel.log",
                        "/site-first-run.json",
                        "/storage/app/site-first-run.json",
                    )],
                    ("/up", 200, "", "healthy"),
                    ("/install", 200, "", UNLOCK),
                    ("/setup", 200, "", UNLOCK),
                    ("/setup/unlock", 419, "", "csrf denied"),
                    ("/setup/database", 403, "", "unauthorized"),
                    ("/setup/unlock", 302, "/setup", "bad token"),
                    ("/setup", 200, "", UNLOCK),
                    ("/setup/unlock", 302, "/setup", "valid token"),
                    ("/setup", 200, "", DATABASE),
                    ("/setup?lang=ar", 200, "", '<html dir="rtl">' + DATABASE),
                ]
                foodex_responses = [
                    ("/install", 200, "", FOODEX_WELCOME),
                    ("/install/step/2", 403, "", "unauthorized"),
                    ("/install/step/1", 302, "/install", "bad token"),
                    ("/install", 200, "", FOODEX_WELCOME),
                    ("/install/step/1", 302, "/install", "valid token"),
                    ("/install", 200, "", FOODEX_REQUIREMENTS),
                    ("/install/step/3", 409, "", "wrong stage"),
                    ("/install", 200, "", FOODEX_REQUIREMENTS),
                ]
                responses = legacy_responses if self.mode == 1 else foodex_responses
                expected, status, location, body = responses[self.n]
                self.n += 1
                assert path == expected
                if self.mode == 1:
                    if failed_step == self.n:
                        status = 200 if status != 200 else 403
                    if secret_leak and path == "/install":
                        body += TOKEN
                    if exposed_token_path and path == "/storage/app/site-first-run.json":
                        status = 200
                        body = TOKEN
                else:
                    if path == "/install/step/1":
                        assert form is not None
                        assert form.get("token") == ("invalid" if self.n == 3 else TOKEN)
                    if foodex_failed_step == self.n:
                        status = 200 if status != 200 else 403
                    if foodex_secret_leak and path == "/install":
                        body += TOKEN
                return Response(status, self.origin + location if location else "", body)
        return Fake

    def test_mock_contract_success_remains_explicitly_non_production(self):
        with patch("qa.cpanel_https_setup_gate.Client", self.fake_client()):
            result = accept("https://localhost:18966", self.home, Path("ca.pem"))
        self.assertGreaterEqual(result["http_checks_passed"], 29)
        self.assertIn("foodex_cannot_skip_requirements", result["checks"])
        self.assertIn("foodex_valid_possession_advances", result["checks"])
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

    def test_foodex_hostile_redirect_or_unexpected_progression_fails_closed(self):
        for case in (
            {"foodex_failed_step": 1},
            {"foodex_failed_step": 2},
            {"foodex_failed_step": 3},
            {"foodex_failed_step": 5},
            {"foodex_failed_step": 6},
            {"foodex_failed_step": 7},
            {"foodex_failed_step": 8},
            {"foodex_secret_leak": True},
        ):
            with self.subTest(case=case):
                with patch("qa.cpanel_https_setup_gate.Client", self.fake_client(**case)):
                    with self.assertRaises(AcceptanceError):
                        accept("https://localhost:18966", self.home, Path("ca.pem"))

    def test_actual_private_token_http_path_cannot_be_public(self):
        with patch("qa.cpanel_https_setup_gate.Client",
                   self.fake_client(exposed_token_path=True)):
            with self.assertRaisesRegex(AcceptanceError, "private_http_denied"):
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
