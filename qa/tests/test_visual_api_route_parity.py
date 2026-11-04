"""T024 regressions for OpenAPI/Laravel static source parity only."""
import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

from qa.api_contract_route_parity import ROOT, audit, main, operation_key, php_routes


class ApiContractRouteParityTest(unittest.TestCase):
    def fixture(self, spec, routes):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        (root / "api").mkdir()
        (root / "backend/routes").mkdir(parents=True)
        (root / "api/openapi-contract.yaml").write_text(spec, encoding="utf-8")
        (root / "backend/routes/api.php").write_text(routes, encoding="utf-8")
        return root

    def test_current_canonical_contract_has_all_46_source_registrations(self):
        report, errors = audit(ROOT)
        self.assertEqual(errors, [])
        self.assertEqual(report["contract_operations"], 46)
        self.assertEqual(report["matched_operations"], 46)
        self.assertEqual(report["missing_operations"], [])
        self.assertEqual(report["duplicate_registrations"], [])
        self.assertFalse(report["api_runtime_verified"])
        # Noncontracted additions and placeholder spelling differences are
        # reported, not silently counted as measured HTTP/API evidence.
        self.assertTrue(report["uncontracted_registrations"])
        self.assertTrue(any("{provider}" in a for a in report["parameter_aliases"]))

    def test_missing_and_changed_method_fail(self):
        root = self.fixture(
            "paths:\n  /public/home:\n    get:\n  /admin/projects/{id}:\n    post:\n",
            "<?php\nRoute::get('/public/home', Handler::class);\n"
            "Route::get('/admin/projects/{id}', Handler::class);\n",
        )
        report, errors = audit(root)
        self.assertEqual(report["matched_operations"], 1)
        self.assertEqual(report["missing_operations"], ["POST /admin/projects/{id}"])
        self.assertTrue(errors)
        with redirect_stdout(io.StringIO()) as output:
            self.assertEqual(main(["--root", str(root)]), 1)
        self.assertIn("POST /admin/projects/{id}", json.loads(output.getvalue())["missing_operations"])

    def test_placeholder_spelling_is_not_false_missing_operation(self):
        root = self.fixture(
            "paths:\n  /admin/ai/routes/{id}:\n    put:\n",
            "<?php\nRoute::put('/admin/ai/routes/{provider}', Handler::class);\n",
        )
        report, errors = audit(root)
        self.assertEqual(errors, [])
        self.assertEqual(report["matched_operations"], 1)
        self.assertEqual(report["parameter_aliases"], [
            "PUT /admin/ai/routes/{id} => PUT /admin/ai/routes/{provider}"
        ])
        self.assertEqual(
            operation_key("/routes/{id}/detail", "get"),
            operation_key("/routes/{provider}/detail", "get"),
        )

    def test_duplicate_registration_fails_and_extra_route_is_only_reported(self):
        root = self.fixture(
            "paths:\n  /public/home:\n    get:\n",
            "<?php\nRoute::get('/public/home', A::class);\n"
            "Route::get('/public/home', B::class);\n"
            "Route::post('/private/other', C::class);\n",
        )
        report, errors = audit(root)
        self.assertTrue(errors)
        self.assertEqual(len(report["duplicate_registrations"]), 2)
        self.assertEqual(report["uncontracted_registrations"], ["POST /private/other"])

    def test_commented_routes_cannot_satisfy_a_contract(self):
        root = self.fixture(
            "paths:\n  /public/home:\n    get:\n  /private/ghost:\n    delete:\n",
            "<?php\n// Route::delete('/private/ghost', Bad::class);\n"
            "/* Route::delete('/private/ghost', Bad::class); */\n"
            "Route::get('/public/home', Good::class);\n",
        )
        report, errors = audit(root)
        self.assertEqual(report["missing_operations"], ["DELETE /private/ghost"])
        self.assertTrue(errors)

    def test_unreadable_routes_and_malformed_template_fail_closed(self):
        root = self.fixture(
            "paths:\n  /public/home:\n    get:\n",
            "<?php\nRoute::get('/public/home', Good::class);\n",
        )
        (root / "backend/routes/api.php").unlink()
        self.assertTrue(audit(root)[1])
        with self.assertRaises(ValueError):
            operation_key("/evil//path", "get")
        with self.assertRaises(ValueError):
            php_routes("<?php\n// Route::get('/commented', Handler::class);\n")


if __name__ == "__main__":
    unittest.main()
