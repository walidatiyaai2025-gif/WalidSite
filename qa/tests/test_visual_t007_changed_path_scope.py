"""T007 classification must exercise genuine UI changes, not branch-name strings."""
import unittest

from qa.t007_changed_path_scope import requires_t007


class T007ChangedScopeTests(unittest.TestCase):
    def test_release_inspection_does_not_trigger_unrelated_page_diagnostics(self):
        self.assertFalse(requires_t007([
            ".github/workflows/release.yml",
            ".github/workflows/ci.yml",
            "qa/verify_signed_web_inspection.php",
            "qa/t007_changed_path_scope.py",
            "backend/cron/release-poll.php",
            "backend/app/Domain/Deployment/TrustedReleaseArchive.php",
            "docs/CPANEL_LEGACY_010_CRON_ADOPTION.md",
        ]))

    def test_real_visual_changes_require_mandatory_browser_diagnostics(self):
        for path in [
            "backend/resources/views/welcome.blade.php",
            "backend/resources/css/app.css",
            "backend/resources/js/app.js",
            "backend/routes/web.php",
            "backend/tests/Feature/Web/WelcomeTest.php",
            "qa/capture_t007_diagnostics.py",
            "qa/render_web_screenshot.py",
            "assets/screen-reference/WEB-01.png",
            "design/owner-approved-web-design-freeze.json",
            "design/visual-fidelity-evidence.json",
            "contracts/screens.json",
        ]:
            with self.subTest(path=path):
                self.assertTrue(requires_t007([path]))

    def test_untrusted_changed_path_fails_closed(self):
        for path in ["", "/etc/passwd", "backend\\resources\\views\\x", "x\x00y"]:
            with self.subTest(path=repr(path)):
                with self.assertRaises(ValueError):
                    requires_t007([path])

    def test_mixed_release_and_visual_scope_still_runs_visual_checks(self):
        self.assertTrue(requires_t007([
            "qa/build_origin_bound_android_release.sh",
            "backend/resources/views/layouts/site.blade.php",
        ]))


if __name__ == "__main__":
    unittest.main()
