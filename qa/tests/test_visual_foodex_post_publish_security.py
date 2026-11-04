"""Adversarial tests for real FOODEX ZIP post-Publish HTTP acceptance.

Fake clients only test the safety oracle. The shipping ZIP + actual MariaDB
are exercised by qa.cpanel_foodex_15_install_probe on main CI.
"""
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from qa.cpanel_foodex_15_install_probe import post_publish_security, authenticated_session_csrf
from qa.cpanel_full_install_probe import SetupFailure


class FakeClient:
    origin = "http://127.0.0.1:8088"

    def __init__(self, *, guest: bool, overrides=None, on_call=None):
        self.guest = guest
        self.overrides = overrides or {}
        self.on_call = on_call
        self.paths = []

    def call(self, path, form=None):
        self.paths.append((path, form is not None))
        if self.on_call is not None:
            self.on_call(path, form)
        if path in self.overrides:
            return self.overrides[path]
        if self.guest:
            if path.startswith("/admin/updates"):
                return (302, "/admin/login", b"")
            if path in ("/.env", "/application/.env",
                        "/contracts/owner-cv-baseline.json"):
                return (404, "", b"")
            if path == "/":
                return (200, "", b"public")
        else:
            if path == "/admin/updates":
                # The real page has three forms, all bound to one session CSRF.
                token = b"a" * 40
                html = (b'<input name="_token" value="' + token + b'">'
                        + b'<input name="_token" value="' + token + b'">')
                return (200, "", html)
            if path in ("/install/step/15", "/setup/finish"):
                return (404, "", b"")
            if path == "/admin":
                return (200, "", b"owner")
        raise AssertionError("Unhandled mock HTTP request: " + path)


class PostPublishSecurityTest(unittest.TestCase):
    @staticmethod
    def fixture(root: Path):
        app, public = root / "application", root / "public_html"
        for file, data in (
            (app / ".env", b"example QA secret-not-real"),
            (app / "storage/app/site-installed.lock", b"installed"),
            (app / "VERSION", b"0.1.0\n"),
            (public / "index.php", b"<?php echo 'site';"),
        ):
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(data)
        return app, public

    def test_all_post_publish_security_checks_pass(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            owner, guest = FakeClient(guest=False), FakeClient(guest=True)
            checks = post_publish_security(owner, guest, app, public)
            self.assertEqual(len(checks), 16)
            self.assertIn("post_publish_anonymous_update_apply_denied", checks)
            self.assertIn("post_publish_preserve_environment", checks)
            self.assertIn("post_publish_owner_session_still_live", checks)

    def test_guest_cannot_receive_success_from_updates(self):
        for route in ("/admin/updates", "/admin/updates/check",
                      "/admin/updates/verify-upload", "/admin/updates/apply"):
            with self.subTest(route=route), tempfile.TemporaryDirectory() as tmp:
                app, public = self.fixture(Path(tmp))
                guest = FakeClient(guest=True, overrides={route: (200, "", b"ok")})
                with self.assertRaisesRegex(SetupFailure, "anonymous_update"):
                    post_publish_security(FakeClient(guest=False), guest, app, public)

    def test_cross_origin_login_redirect_is_not_an_authorization_denial(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            guest = FakeClient(
                guest=True,
                overrides={"/admin/updates": (302, "https://evil.invalid/admin/login", b"")},
            )
            with self.assertRaisesRegex(SetupFailure, "anonymous_update_view_denied"):
                post_publish_security(FakeClient(guest=False), guest, app, public)

    def test_same_host_different_scheme_or_ambiguous_redirect_is_rejected(self):
        # Matching hostname/port is insufficient for same-origin security.
        invalid_locations = (
            "https://127.0.0.1:8088/admin/login",
            "//127.0.0.1:8088/admin/login",
            "http://127.0.0.1:8088/admin/login?continue=elsewhere",
            "/admin/login#fragment",
            "/admin/login?next=/admin/updates",
            "http://user@127.0.0.1:8088/admin/login",
        )
        for location in invalid_locations:
            with self.subTest(location=location), tempfile.TemporaryDirectory() as tmp:
                app, public = self.fixture(Path(tmp))
                guest = FakeClient(guest=True, overrides={
                    "/admin/updates": (302, location, b""),
                })
                with self.assertRaisesRegex(
                        SetupFailure, "anonymous_update_view_denied"):
                    post_publish_security(FakeClient(guest=False), guest, app, public)

    def test_exact_same_origin_absolute_and_relative_login_are_accepted(self):
        for location in ("/admin/login", "http://127.0.0.1:8088/admin/login"):
            with self.subTest(location=location), tempfile.TemporaryDirectory() as tmp:
                app, public = self.fixture(Path(tmp))
                guest = FakeClient(guest=True, overrides={
                    "/admin/updates": (302, location, b""),
                })
                checks = post_publish_security(
                    FakeClient(guest=False), guest, app, public)
                self.assertIn("post_publish_anonymous_update_view_denied", checks)

    def test_valid_explicit_denials_are_accepted(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            guest = FakeClient(guest=True, overrides={
                "/admin/updates": (403, "", b""),
                "/admin/updates/apply": (419, "", b""),
            })
            self.assertEqual(len(post_publish_security(
                FakeClient(guest=False), guest, app, public)), 16)


    def test_real_session_csrf_is_used_for_reinstall_replay(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            values = []

            def capture(path, form):
                if path in ("/install/step/15", "/setup/finish"):
                    values.append(form.get("_token"))

            owner = FakeClient(guest=False, on_call=capture)
            checks = post_publish_security(owner, FakeClient(guest=True), app, public)
            self.assertIn("post_publish_owner_update_form_reachable", checks)
            self.assertEqual(values, ["a" * 40, "a" * 40])

    def test_missing_conflicting_or_invalid_owner_csrf_fails_closed(self):
        examples = (
            b"<html>No owner update form</html>",
            (b'<input name="_token" value="' + b"a" * 40
             + b'"><input name="_token" value="' + b"b" * 40 + b'">'),
            b'<input name="_token" value="short">',
            b"\xff\xfe",
        )
        for html in examples:
            with self.subTest(html_variant=repr(html[:16])):
                with self.assertRaisesRegex(SetupFailure, "owner_csrf"):
                    authenticated_session_csrf(html)

    def test_post_install_reentry_redirect_is_rejected(self):
        for route in ("/install/step/15", "/setup/finish"):
            with self.subTest(route=route), tempfile.TemporaryDirectory() as tmp:
                app, public = self.fixture(Path(tmp))
                owner = FakeClient(guest=False, overrides={
                    route: (302, "/install", b"")
                })
                with self.assertRaisesRegex(SetupFailure, "reinstall"):
                    post_publish_security(owner, FakeClient(guest=True), app, public)

    def test_private_file_exposure_is_rejected(self):
        for route in ("/.env", "/application/.env",
                      "/contracts/owner-cv-baseline.json"):
            with self.subTest(route=route), tempfile.TemporaryDirectory() as tmp:
                app, public = self.fixture(Path(tmp))
                guest = FakeClient(guest=True, overrides={route: (200, "", b"secret")})
                with self.assertRaisesRegex(SetupFailure, "no_public_"):
                    post_publish_security(FakeClient(guest=False), guest, app, public)

    def test_env_or_lock_mutation_during_denied_request_is_rejected(self):
        for relative in (".env", "storage/app/site-installed.lock",
                         "VERSION"):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as tmp:
                app, public = self.fixture(Path(tmp))
                target = app / relative

                def tamper(path, form):
                    if path == "/admin/updates/apply":
                        target.write_bytes(b"changed by bad request")

                guest = FakeClient(guest=True, on_call=tamper)
                with self.assertRaisesRegex(SetupFailure, "preserve_"):
                    post_publish_security(FakeClient(guest=False), guest, app, public)

    def test_active_public_front_controller_mutation_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))

            def tamper(path, form):
                if path == "/admin/updates/apply":
                    (public / "index.php").write_bytes(b"bad code swap")

            with self.assertRaisesRegex(SetupFailure, "preserve_public_controller"):
                post_publish_security(
                    FakeClient(guest=False),
                    FakeClient(guest=True, on_call=tamper), app, public)

    def test_missing_or_symlinked_immutable_files_fail_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            (app / ".env").unlink()
            with self.assertRaisesRegex(SetupFailure, "immutable_files_missing"):
                post_publish_security(
                    FakeClient(guest=False), FakeClient(guest=True), app, public)

        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            target = public / "index.php"
            target.unlink()
            target.symlink_to(app / "VERSION")
            with self.assertRaisesRegex(SetupFailure, "immutable_files_missing"):
                post_publish_security(
                    FakeClient(guest=False), FakeClient(guest=True), app, public)

    def test_public_and_owner_sessions_must_remain_operational(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            owner = FakeClient(guest=False, overrides={
                "/admin": (500, "", b"failed")
            })
            with self.assertRaisesRegex(SetupFailure, "owner_session_still_live"):
                post_publish_security(owner, FakeClient(guest=True), app, public)
            guest = FakeClient(guest=True, overrides={
                "/": (500, "", b"failed")
            })
            with self.assertRaisesRegex(SetupFailure, "public_site_still_live"):
                post_publish_security(FakeClient(guest=False), guest, app, public)

    def test_cross_origin_or_non_loopback_clients_fail_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            app, public = self.fixture(Path(tmp))
            owner, guest = FakeClient(guest=False), FakeClient(guest=True)
            guest.origin = "http://127.0.0.1:9999"
            with self.assertRaisesRegex(SetupFailure, "loopback_only"):
                post_publish_security(owner, guest, app, public)
            owner.origin, guest.origin = "http://staging.example.com", "http://staging.example.com"
            with self.assertRaisesRegex(SetupFailure, "loopback_only"):
                post_publish_security(owner, guest, app, public)


if __name__ == "__main__":
    unittest.main()
