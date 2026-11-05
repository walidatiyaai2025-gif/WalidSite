"""Adversarial oracle for real FOODEX ZIP wrong-DB / safe-retry acceptance.

These pure-Python tests run in required-ci-gate. The actual shipped ZIP,
localhost HTTP and disposable MariaDB are exercised by
qa.cpanel_foodex_15_install_probe in the main package job.
"""
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from qa.cpanel_foodex_15_install_probe import invalid_database_retry_probe
from qa.cpanel_full_install_probe import SetupFailure


VALID = {
    "site_url": "http://127.0.0.1:8181",
    "db_host": "127.0.0.1",
    "db_port": "3307",
    "db_database": "walidsite_ci_foodex",
    "db_username": "walidsite_ci",
    "db_password": "fixture-only-correct-password",
}
FORM = b'<input name="_token" value="' + b"a" * 40 + b'">/install/step/4'


class FakeHttp:
    origin = VALID["site_url"]

    def __init__(self, *, rejected=(302, "/install", b""),
                 stage=FORM, on_post=None):
        self.rejected, self.stage, self.on_post = rejected, stage, on_post
        self.attempts = []

    def call(self, path, form=None):
        if path == "/install" and form is None:
            return 200, "", self.stage
        if path == "/install/step/4" and form is not None:
            self.attempts.append(form)
            if self.on_post:
                self.on_post(form)
            if callable(self.rejected):
                return self.rejected(form)
            return self.rejected
        raise AssertionError("Unexpected fake request path")


class BadDatabaseRecoveryOracleTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="walidsite-ci-foodex-")
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.app, self.public = root / "application", root / "public_html"
        for target, data in (
            (self.app / "storage/app/site-first-run.json", b"private token fixture"),
            (self.app / "VERSION", b"0.1.1\n"),
            (self.public / "index.php", b"<?php /* fixture */"),
        ):
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)

    def run_probe(self, client=None):
        return invalid_database_retry_probe(
            client or FakeHttp(), self.app, self.public, VALID)

    def test_rejected_invalid_password_retains_same_session_and_pristine_files(self):
        client = FakeHttp()
        checks = self.run_probe(client)
        self.assertEqual(len(checks), 8)
        self.assertIn("invalid_database_same_step_recoverable", checks)
        self.assertIn("invalid_database_no_private_env_written", checks)
        self.assertEqual(len(client.attempts), 1)
        self.assertNotEqual(client.attempts[0]["db_password"], VALID["db_password"])
        self.assertEqual(client.attempts[0]["_token"], "a" * 40)

    def test_invalid_database_must_not_succeed_or_advance(self):
        for result in ((200, "", b""), (302, "/admin", b""),
                       (302, "/install?step=5", b""),
                       (302, "https://evil.example/install", b"")):
            with self.subTest(result=result):
                with self.assertRaisesRegex(SetupFailure, "rejected_without_advancing"):
                    self.run_probe(FakeHttp(rejected=result))

    def test_bad_password_must_not_be_reflected_or_logged(self):
        with self.assertRaisesRegex(SetupFailure, "wrong_password_not_in_redirect"):
            self.run_probe(FakeHttp(
                rejected=lambda form: (302, "/install", form["db_password"].encode())))

    def test_bad_password_must_not_be_in_response_page(self):
        def echoed(form):
            client.stage = FORM + form["db_password"].encode()
        client = FakeHttp(on_post=echoed)
        with self.assertRaisesRegex(SetupFailure, "wrong_password_not_in_page"):
            self.run_probe(client)

    def test_failed_attempt_must_not_write_env(self):
        def write_env(form):
            (self.app / ".env").write_bytes(b"incorrect credentials must not persist")
        with self.assertRaisesRegex(SetupFailure, "no_private_env_written"):
            self.run_probe(FakeHttp(on_post=write_env))

    def test_failed_attempt_must_not_modify_possession_or_code(self):
        for relative in ("storage/app/site-first-run.json", "VERSION"):
            with self.subTest(relative=relative):
                target = self.app / relative
                original = target.read_bytes()
                def modify(form):
                    target.write_bytes(b"tampered")
                with self.assertRaisesRegex(SetupFailure, "possession_and_code_preserved"):
                    self.run_probe(FakeHttp(on_post=modify))
                target.write_bytes(original)

    def test_missing_bootstrap_and_symlink_are_rejected(self):
        target = self.app / "storage/app/site-first-run.json"
        target.unlink()
        with self.assertRaisesRegex(SetupFailure, "pristine_private_files_required"):
            self.run_probe()
        target.symlink_to(self.app / "VERSION")
        with self.assertRaisesRegex(SetupFailure, "pristine_private_files_required"):
            self.run_probe()

    def test_existing_env_or_symlink_env_is_rejected(self):
        env = self.app / ".env"
        env.write_text("already configured")
        with self.assertRaisesRegex(SetupFailure, "pristine_private_files_required"):
            self.run_probe()
        env.unlink()
        env.symlink_to(self.app / "VERSION")
        with self.assertRaisesRegex(SetupFailure, "pristine_private_files_required"):
            self.run_probe()

    def test_non_disposable_root_and_remote_origin_fail_closed(self):
        client = FakeHttp()
        client.origin = "http://remote.example:8181"
        with self.assertRaisesRegex(SetupFailure, "disposable_loopback_only"):
            self.run_probe(client)
        client.origin = VALID["site_url"]
        wrong_app = Path(self.temp.name) / "some-other-application"
        with self.assertRaisesRegex(SetupFailure, "disposable_loopback_only"):
            invalid_database_retry_probe(client, wrong_app, self.public, VALID)

    def test_incomplete_good_database_fixture_rejected(self):
        with self.assertRaisesRegex(SetupFailure, "valid_fixture_incomplete"):
            invalid_database_retry_probe(FakeHttp(), self.app, self.public,
                                         {"db_password": "x"})

    def test_step_four_must_be_current_to_attempt_credentials(self):
        with self.assertRaisesRegex(SetupFailure, "step_4_current"):
            self.run_probe(FakeHttp(stage=b"<html>step 5</html>"))


if __name__ == "__main__":
    unittest.main()
