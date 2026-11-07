"""#1120: APK/Web origin identity is strict and never an HTTP health attestation."""
import contextlib
import io
import json
from pathlib import Path
import tempfile
import unittest

from qa.release_site_origin import (
    ReleaseOriginError, build_receipt, canonical_public_origin, main,
)

SHA = "a" * 40
DOMAIN = "https://walid.example.org"


class ReleaseSiteOriginTest(unittest.TestCase):
    def test_canonical_public_domain(self):
        self.assertEqual(canonical_public_origin(DOMAIN), DOMAIN)
        self.assertEqual(canonical_public_origin("https://portfolio.co.uk"),
                         "https://portfolio.co.uk")

    def test_no_local_reserved_ip_redirect_or_ambiguous_origin(self):
        invalid = (
            "", "http://walid.example.org", "HTTPS://walid.example.org",
            "https://localhost", "https://localhost.example.org",
            "https://127.0.0.1", "https://[::1]",
            "https://10.0.0.1", "https://169.254.169.254",
            "https://walid.local", "https://test.invalid",
            "https://walid.example", "https://walid.test",
            "https://walid.internal", "https://walid.home.arpa",
            "https://walid.lan", "https://walid.onion",
            "https://walid.example.org/", "https://walid.example.org/api",
            "https://walid.example.org:443", "https://walid.example.org?x=1",
            "https://walid.example.org#fragment",
            "https://user:pass@walid.example.org",
            "https://walid.example.org@evil.com",
            "https://walid.example.org\n", " https://walid.example.org",
            "https://Walid.example.org", "https://walid.example.org.",
            "https://example..org", "https://-abc.example.org",
            "https://example.org:bad", "https://%77alid.example.org",
            "https://walid.example.123", "https://\.example.org",
            "https://wä lid.example.org",
            "https://" + ("a" * 64) + ".example.org",
        )
        for origin in invalid:
            with self.subTest(origin=repr(origin)):
                with self.assertRaises(ReleaseOriginError):
                    canonical_public_origin(origin)

    def test_release_agreement_requires_pinned_sha_and_exact_semver(self):
        good = dict(origin=DOMAIN, approved_origin=DOMAIN,
                    source_sha=SHA, version="0.1.2", site_version="0.1.2\n")
        receipt = build_receipt(**good)
        self.assertTrue(receipt["approved_origin_matches"])
        self.assertEqual(receipt["source_sha"], SHA)
        self.assertEqual(receipt["web_version"], "0.1.2")
        self.assertEqual(receipt["build_argument_origin"], DOMAIN)
        for claim in ("production_ready", "live_https_verified",
                      "web_deployment_sha_verified", "apk_runtime_origin_verified"):
            self.assertIs(receipt[claim], False)

        wrong = (
            ("approved_origin", "https://attacker.example.org"),
            ("source_sha", SHA[:-1]),
            ("source_sha", "f" * 40 + "\n"),
            ("version", "0.1.3"),
            ("version", "0.1.2-rc.1"),
            ("version", "01.1.2"),
            ("site_version", "0.1.3"),
        )
        for key, value in wrong:
            with self.subTest(key=key, value=value):
                with self.assertRaises(ReleaseOriginError):
                    build_receipt(**(good | {key: value}))

    def test_cli_success_and_redacted_failures(self):
        with tempfile.TemporaryDirectory() as temp:
            version = Path(temp) / "VERSION"
            version.write_text("0.1.2\n", encoding="ascii")
            args = [
                "--origin", DOMAIN, "--approved-origin", DOMAIN,
                "--source-sha", SHA, "--version", "0.1.2",
                "--site-version-file", str(version),
            ]
            stdout, stderr = io.StringIO(), io.StringIO()
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                self.assertEqual(main(args), 0)
            self.assertEqual(json.loads(stdout.getvalue())["source_sha"], SHA)
            self.assertEqual(stderr.getvalue(), "")
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(stderr):
                self.assertEqual(main(args[:1] + ["https://host.test"] + args[2:]), 2)
            self.assertNotIn("host.test", stderr.getvalue())
            version.write_text("0.1.3\n", encoding="ascii")
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(stderr):
                self.assertEqual(main(args), 2)

if __name__ == "__main__":
    unittest.main()
