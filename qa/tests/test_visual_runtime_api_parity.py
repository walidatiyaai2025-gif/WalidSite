"""T024 real Artisan registration gate fixtures (NOT HTTP/deployment evidence)."""
import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

from qa.runtime_api_route_parity import ROOT, audit, check_routes, main
from qa.t024_readiness import openapi_operations


SMALL = (
    "paths:\n"
    "  /public/home:\n"
    "    get:\n"
    "  /admin/items/{id}:\n"
    "    patch:\n"
)
VALID = [
    {"uri": "api/v1/public/home", "method": "GET|HEAD", "domain": None},
    {"uri": "api/v1/admin/items/{resource}", "method": "PATCH", "domain": None},
    {"uri": "api/v1/public/uncontracted", "method": "POST", "domain": None},
    {"uri": "admin/login", "method": "GET|HEAD", "domain": None},
]


class RuntimeArtisanRouteParityTest(unittest.TestCase):
    def test_exact_booted_shape_and_implicit_head_are_admitted_without_http_claim(self):
        report, errors = check_routes(VALID, SMALL)
        self.assertEqual(errors, [])
        self.assertEqual(report["matched_operations"], 2)
        self.assertEqual(report["contract_operations"], 2)
        self.assertEqual(report["registered_api_route_shapes"], 3)
        self.assertTrue(report["artisan_runtime_registration_verified"])
        self.assertEqual(report["missing_operations"], [])
        self.assertEqual(report["duplicate_registrations"], [])
        self.assertEqual(report["uncontracted_registrations"],
                         ["POST /public/uncontracted"])
        self.assertFalse(report["http_requests_verified"])
        self.assertFalse(report["authorization_verified"])
        self.assertFalse(report["dto_payloads_verified"])
        self.assertFalse(report["production_ready"])

    def test_changed_or_missing_method_must_fail_even_if_uri_exists(self):
        for mutation in (
            [VALID[1], VALID[2]],
            [{"uri": "api/v1/public/home", "method": "POST"}, VALID[1]],
        ):
            with self.subTest(mutation=mutation):
                report, errors = check_routes(mutation, SMALL)
                self.assertTrue(errors)
                self.assertFalse(report["artisan_runtime_registration_verified"])
                self.assertIn("GET /public/home", report["missing_operations"])

    def test_dynamic_parameter_alias_duplicates_are_shadowed(self):
        duplicate = [
            *VALID,
            {"uri": "api/v1/admin/items/{slug}", "method": "PATCH"},
        ]
        report, errors = check_routes(duplicate, SMALL)
        self.assertTrue(errors)
        self.assertEqual(report["matched_operations"], 2)
        self.assertEqual(len(report["duplicate_registrations"]), 2)
        self.assertFalse(report["artisan_runtime_registration_verified"])

    def test_domain_restricted_api_does_not_claim_public_registration(self):
        rows = [
            {**VALID[0], "domain": "internal.example.com"},
            VALID[1],
        ]
        report, errors = check_routes(rows, SMALL)
        self.assertTrue(errors)
        self.assertEqual(report["domain_restricted_contract_routes"],
                         ["GET /public/home"])
        self.assertEqual(report["missing_operations"], ["GET /public/home"])

    def test_invalid_artisan_schema_verbs_and_url_fail_closed(self):
        for routes in (
            [], None, {}, [{"uri": "api/v1/public/home"}],
            [{"uri": "api/v1/public/home", "method": "GET|GET"}],
            [{"uri": "api/v1/public/home", "method": "GET|EXECUTE"}],
            [{"uri": "api/v1/public/home?debug=1", "method": "GET"}],
            [{"uri": "api/v1/public//home", "method": "GET"}],
            [{"uri": "api/v1/public/home", "method": 500}],
        ):
            with self.subTest(routes=routes):
                report, errors = check_routes(routes, SMALL)
                self.assertTrue(errors)
                self.assertFalse(report["artisan_runtime_registration_verified"])

    def test_canonical_contract_has_42_paths_46_methods_and_synthetic_parser_parity(self):
        content = (ROOT / "api/openapi-contract.yaml").read_text(encoding="utf-8")
        paths, operations = openapi_operations(content)
        self.assertEqual((len(paths), len(operations)), (42, 46))
        # Pure parser fixture: only the CI step can provide REAL Artisan JSON.
        records = []
        for name in sorted(operations):
            uri, verb = name.rsplit("#", 1)
            records.append({
                "uri": "api/v1" + uri,
                "method": "GET|HEAD" if verb == "get" else verb.upper(),
                "domain": None,
            })
        report, errors = check_routes(records, content)
        self.assertEqual(errors, [])
        self.assertEqual(report["matched_operations"], 46)
        self.assertFalse(report["http_requests_verified"])
        self.assertFalse(report["production_ready"])

    def test_cli_is_fail_closed_on_missing_bad_or_linked_route_proof(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "api").mkdir()
            (root / "api/openapi-contract.yaml").write_text(SMALL, encoding="utf-8")
            artifact = root / "actual-artisan.json"
            artifact.write_text(json.dumps(VALID), encoding="utf-8")
            report, errors = audit(root, artifact)
            self.assertEqual(errors, [])
            self.assertEqual(report["matched_operations"], 2)
            with redirect_stdout(io.StringIO()) as output:
                self.assertEqual(main(["--root", str(root), "--artisan-json",
                                       str(artifact)]), 0)
            self.assertFalse(json.loads(output.getvalue())["http_requests_verified"])

            artifact.write_bytes(b"not-json secret=DO-NOT-PRINT")
            with redirect_stdout(io.StringIO()) as output:
                self.assertEqual(main(["--root", str(root), "--artisan-json",
                                       str(artifact)]), 1)
            self.assertNotIn("DO-NOT-PRINT", output.getvalue())
            self.assertFalse(json.loads(output.getvalue())
                             ["artisan_runtime_registration_verified"])

            artifact.write_bytes(b"x" * (4 * 1024 * 1024 + 1))
            self.assertTrue(audit(root, artifact)[1])
            artifact.unlink()
            artifact.symlink_to(root / "api/openapi-contract.yaml")
            self.assertTrue(audit(root, artifact)[1])


if __name__ == "__main__":
    unittest.main()
