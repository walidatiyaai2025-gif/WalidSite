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
DOMAIN = "https://walid.portfolio-site.net"


class ReleaseSiteOriginTest(unittest.TestCase):
    def test_canonical_public_domain(self):
        self.assertEqual(canonical_public_origin(DOMAIN), DOMAIN)
        self.assertEqual(canonical_public_origin("https://portfolio.co.uk"),
                         "https://portfolio.co.uk")

    def test_no_local_reserved_ip_redirect_or_ambiguous_origin(self):
        invalid = (
            "", "http://walid.portfolio-site.net", "HTTPS://walid.portfolio-site.net",
            "https://localhost", "https://localhost.portfolio-site.net",
            "https://127.0.0.1", "https://[::1]",
            "https://10.0.0.1", "https://169.254.169.254",
            "https://walid.local", "https://test.invalid",
            "https://walid.example", "https://walid.test",
            "https://example.com", "https://example.net",
            "https://example.org", "https://walid.example.org",
            "https://walid.internal", "https://walid.home.arpa",
            "https://walid.lan", "https://walid.onion",
            "https://walid.portfolio-site.net/", "https://walid.portfolio-site.net/api",
            "https://walid.portfolio-site.net:443", "https://walid.portfolio-site.net?x=1",
            "https://walid.portfolio-site.net#fragment",
            "https://user:pass@walid.portfolio-site.net",
            "https://walid.portfolio-site.net@evil.com",
            "https://walid.portfolio-site.net\n", " https://walid.portfolio-site.net",
            "https://Walid.example.org", "https://walid.portfolio-site.net.",
            "https://example..org", "https://-abc.example.org",
            "https://portfolio-site.net:bad", "https://%77alid.portfolio-site.net",
            "https://walid.example.123", "https://.portfolio-site.net",
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
            ("approved_origin", "https://attacker.portfolio-site.net"),
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

    def test_release_wiring_keeps_signed_inspection_unpublished(self):
        root = Path(__file__).resolve().parents[2]
        release = (root / ".github/workflows/release.yml").read_text()
        ci = (root / ".github/workflows/ci.yml").read_text()
        script = (root / "qa/build_origin_bound_android_release.sh").read_text()
        gradle = (root / "mobile/android/app/build.gradle.kts").read_text()
        self.assertIn(
            "needs: [release-candidates, signed-web-inspection, signed-android-inspection]",
            release,
        )
        signed_web = release.split("  signed-web-inspection:", 1)[1].split(
            "  signed-android-inspection:", 1
        )[0]
        self.assertIn("if: github.event_name == 'workflow_dispatch'", signed_web)
        self.assertIn("Block publisher signing until GitHub main is actually protected",
                      signed_web)
        self.assertIn("WALIDSITE_PUBLISHER_SECRET_KEY_B64", signed_web)
        self.assertIn("qa/verify_signed_web_inspection.php", signed_web)
        self.assertIn("WALIDSITE_PRODUCTION_SITE_ORIGIN", release)
        self.assertIn("WALIDSITE_ANDROID_SIGNER_SHA256", release)
        self.assertIn("Block release until authorized staging", release)
        self.assertIn("exit 1", release)
        self.assertNotIn("gh release create", release)
        self.assertIn("qa.tests.test_release_site_origin", ci)
        for required in ("flutter build apk --release", "WALIDSITE_API_ORIGIN=",
                         "qa/native_android_acceptance.py", "apksigner",
                         "WALIDSITE_SIGNER_INSPECTED_APK_SHA256",
                         "hash_copy.hexdigest()"):
            self.assertIn(required, script)
        self.assertIn("require(hasReleaseSigning)", gradle)
        self.assertIn('getByName("walidsiteProduction")', gradle)

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
