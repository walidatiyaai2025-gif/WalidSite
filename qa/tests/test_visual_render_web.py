"""No-browser unit checks for deterministic real-page capture configuration."""
import unittest

from qa.render_web_screenshot import RenderConfigError, target_url, viewport


class RealWebCaptureConfigTest(unittest.TestCase):
    def test_https_and_loopback_http_targets(self):
        self.assertEqual(target_url("https://preview.example.test/", "/about"),
                         "https://preview.example.test/about")
        self.assertEqual(target_url("http://127.0.0.1:8000", "/admin/dashboard"),
                         "http://127.0.0.1:8000/admin/dashboard")

    def test_unsafe_remote_http_and_embedded_credentials_are_refused(self):
        for base in (
            "http://example.test", "http://10.0.0.5:8000",
            "https://user:password@example.test",
            "https://example.test/a", "https://example.test?token=secret",
            "file:///tmp/site", "",
        ):
            with self.subTest(base=base), self.assertRaises(RenderConfigError):
                target_url(base, "/")

    def test_route_cannot_redirect_to_another_host_or_traverse(self):
        for route in (
            "//evil.example.test", "/../secrets", "/./admin",
            "/about?token=x", "/about#fragment", "/foo\\bar", "about",
        ):
            with self.subTest(route=route), self.assertRaises(RenderConfigError):
                target_url("https://preview.example.test", route)

    def test_physical_reference_dimensions_are_never_scaled_silently(self):
        self.assertEqual(viewport(1170, 2532, 3), {"width": 390, "height": 844})
        self.assertEqual(viewport(1440, 900, 1), {"width": 1440, "height": 900})
        for values in ((1171, 2532, 3), (0, 900, 1), (1440, -1, 1),
                       (100, 100, 0), (100, 100, 9)):
            with self.subTest(values=values), self.assertRaises(RenderConfigError):
                viewport(*values)


if __name__ == "__main__":
    unittest.main()
