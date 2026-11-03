"""Issue #481: no fixtures can be counted as real Web visual acceptance."""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from qa.discover_published_web_screens import (
    MAX_RESPONSE_BYTES, PublicationDiscoveryError, build_runtime_plan,
    fetch_local, loopback_port,
)
from qa.capture_visual_batch import validate_plan


ROOT = Path(__file__).resolve().parents[2]


def published_fixture():
    """Synthetic HTTP responses for testing only; never screenshot evidence."""
    return {
        ("/api/v1/public/projects?lang=en&page=1", "json"): {
            "data": [{"slug": "approved-project"}],
            "meta": {"current_page": 1, "last_page": 1},
        },
        ("/api/v1/public/projects/approved-project?lang=en", "json"): {
            "data": {"slug": "approved-project", "evidence_urls": [
                "https://docs.example.org/project-evidence"
            ]},
        },
        ("/projects/approved-project?lang=en", "html"): True,
        ("/api/v1/public/articles?lang=en&page=1", "json"): {
            "data": [{"slug": "approved-article"}],
            "meta": {"current_page": 1, "has_more_pages": False},
        },
        ("/api/v1/public/articles/approved-article?lang=en", "json"): {
            "data": {"slug": "approved-article", "citations": [
                "https://docs.example.org/reviewed-article"
            ]},
        },
        ("/blog/approved-article?lang=en", "html"): True,
    }


class MemoryPublicServer:
    def __init__(self, responses):
        self.responses = responses
        self.calls = []

    def __call__(self, port, path, kind):
        self.calls.append((port, path, kind))
        return self.responses[(path, kind)]


class FakeResponse:
    def __init__(self, *, status=200, content_type="application/json", content=b'{"data":{}}'):
        self.status = status
        self.content_type = content_type
        self.content = content

    def getheader(self, name):
        return self.content_type if name == "Content-Type" else None

    def read(self, size):
        return self.content[:size]


class FakeConnection:
    def __init__(self, response):
        self.response = response
        self.calls = []
        self.closed = False

    def request(self, method, path, headers=None):
        self.calls.append((method, path, headers))

    def getresponse(self):
        return self.response

    def close(self):
        self.closed = True


class RealPublishedWebPlanTest(unittest.TestCase):
    def test_only_exact_numeric_loopback_http_origin_is_allowed(self):
        self.assertEqual(loopback_port("http://127.0.0.1:8000"), 8000)
        self.assertEqual(loopback_port("http://127.0.0.1/"), 80)
        for origin in (
            "", "http://localhost:8000", "http://0.0.0.0:8000",
            "http://10.0.0.5", "https://127.0.0.1:8000",
            "https://example.org", "http://127.0.0.1.evil.test:8000",
            "http://user:pass@127.0.0.1", "http://127.0.0.1:0",
            "http://127.0.0.1:65536", "http://127.0.0.1:bad",
            "http://127.0.0.1:8000/admin", "http://127.0.0.1:8000?token=123",
            "http://127.0.0.1:8000#fragment", " http://127.0.0.1:8000",
            "http://127.0.0.1:8000 ", "http://127.0.0.1:08000",
        ):
            with self.subTest(origin=origin), self.assertRaises(PublicationDiscoveryError):
                loopback_port(origin)

    def test_real_public_api_and_html_pages_generate_all_14_canonical_routes(self):
        transport = MemoryPublicServer(published_fixture())
        plan = build_runtime_plan(ROOT, "http://127.0.0.1:8000", fetch=transport)
        routes = {row["screen_id"]: row["route"] for row in validate_plan(ROOT, plan)}
        self.assertEqual(len(routes), 14)
        self.assertEqual(routes["WEB-05"], "/projects/approved-project")
        self.assertEqual(routes["WEB-08"], "/blog/approved-article")
        self.assertEqual(routes["WEB-06"], "/ai-engineering")
        self.assertEqual(len(transport.calls), 6)
        self.assertTrue(all(port == 8000 for port, _, _ in transport.calls))
        self.assertTrue(all(path.startswith((
            "/api/v1/public/", "/projects/", "/blog/"
        )) for _, path, _ in transport.calls))
        self.assertTrue(all(row["renderer_command"] == [
            "python3", "qa/render_web_screenshot.py"
        ] for row in plan["captures"]))
        self.assertNotIn("fake", json.dumps(plan).lower())

    def test_unavailable_public_data_and_revoked_proof_fail_closed(self):
        scenarios = []
        no_project = published_fixture()
        no_project[("/api/v1/public/projects?lang=en&page=1", "json")]["data"] = []
        scenarios.append(no_project)
        revoked = published_fixture()
        revoked[("/api/v1/public/projects/approved-project?lang=en", "json")][
            "data"]["evidence_urls"] = []
        scenarios.append(revoked)
        invalid_article = published_fixture()
        invalid_article[("/api/v1/public/articles/approved-article?lang=en", "json")][
            "data"]["citations"] = ["http://127.0.0.1/internal"]
        scenarios.append(invalid_article)
        mismatched_slug = published_fixture()
        mismatched_slug[("/api/v1/public/projects/approved-project?lang=en", "json")][
            "data"]["slug"] = "different-project"
        scenarios.append(mismatched_slug)
        invalid_list = published_fixture()
        invalid_list[("/api/v1/public/articles?lang=en&page=1", "json")]["data"] = {}
        scenarios.append(invalid_list)
        for data in scenarios:
            with self.subTest(case=len(scenarios)), self.assertRaises(PublicationDiscoveryError):
                build_runtime_plan(ROOT, "http://127.0.0.1:8000",
                                   fetch=MemoryPublicServer(data))

    def test_unsafe_legacy_slug_is_skipped_and_next_current_public_page_used(self):
        data = published_fixture()
        data[("/api/v1/public/projects?lang=en&page=1", "json")] = {
            "data": [{"slug": "unsafe_slug"}], "meta": {"last_page": 2},
        }
        data[("/api/v1/public/projects?lang=en&page=2", "json")] = {
            "data": [{"slug": "approved-project"}], "meta": {"last_page": 2},
        }
        plan = build_runtime_plan(ROOT, "http://127.0.0.1:8000",
                                  fetch=MemoryPublicServer(data))
        self.assertIn("/projects/approved-project", [
            c["route"] for c in plan["captures"]
        ])

    def test_unsupported_locale_and_no_staging_network_requests(self):
        server = MemoryPublicServer(published_fixture())
        with self.assertRaises(PublicationDiscoveryError):
            build_runtime_plan(ROOT, "http://127.0.0.1:8000",
                               locale="fr", fetch=server)
        self.assertEqual(server.calls, [])
        with self.assertRaises(PublicationDiscoveryError):
            build_runtime_plan(ROOT, "https://staging.example.test", fetch=server)
        self.assertEqual(server.calls, [])

    def test_http_transport_is_get_only_without_cookies_proxy_or_redirects(self):
        for response in (
            FakeResponse(status=302),
            FakeResponse(status=503),
            FakeResponse(content_type="text/html"),
            FakeResponse(content=b"{bad json"),
            FakeResponse(content=b"x" * (MAX_RESPONSE_BYTES + 1)),
        ):
            conn = FakeConnection(response)
            with patch("qa.discover_published_web_screens.http.client.HTTPConnection",
                       return_value=conn) as ctor:
                with self.assertRaises(PublicationDiscoveryError):
                    fetch_local(8000, "/api/v1/public/projects?lang=en&page=1", "json")
                ctor.assert_called_once_with("127.0.0.1", 8000, timeout=5)
            self.assertTrue(conn.closed)
            self.assertEqual(conn.calls[0][0], "GET")
            self.assertNotIn("Authorization", conn.calls[0][2])
            self.assertNotIn("Cookie", conn.calls[0][2])

        conn = FakeConnection(FakeResponse(
            content_type="text/html; charset=UTF-8",
            content=b"<!doctype html><html lang='en'><body>Real page</body></html>",
        ))
        with patch("qa.discover_published_web_screens.http.client.HTTPConnection",
                   return_value=conn):
            self.assertTrue(fetch_local(8000, "/projects/approved-project?lang=en", "html"))
        self.assertTrue(conn.closed)

    def test_cli_never_overwrites_an_existing_plan(self):
        from qa.discover_published_web_screens import main
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "existing.json"
            destination.write_text("untouched")
            with patch("qa.discover_published_web_screens.build_runtime_plan",
                       return_value={"version": 1, "captures": []}):
                self.assertEqual(main([
                    "--origin", "http://127.0.0.1:8000",
                    "--output", str(destination),
                ]), 2)
            self.assertEqual(destination.read_text(), "untouched")


if __name__ == "__main__":
    unittest.main()
