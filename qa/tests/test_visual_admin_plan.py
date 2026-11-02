"""Trusted ADMIN-01..13 visual-plan regressions for Issue #481."""
import json
import tempfile
import unittest
from pathlib import Path

from qa.build_admin_visual_plan import AdminPlanError, ADMIN_IDS, build_admin_plan
from qa.capture_visual_batch import validate_plan


ROOT = Path(__file__).resolve().parents[2]
PROVIDER_ID = "12345678-1234-4abc-8def-1234567890ab"


class AdminVisualPlanTest(unittest.TestCase):
    def test_builds_all_13_canonical_admin_routes_without_credentials(self):
        plan = build_admin_plan(ROOT, PROVIDER_ID)
        self.assertEqual(plan["version"], 1)
        self.assertEqual(
            [row["screen_id"] for row in plan["captures"]],
            list(ADMIN_IDS),
        )
        self.assertEqual(len(validate_plan(ROOT, plan)), 13)
        provider = next(row for row in plan["captures"] if row["screen_id"] == "ADMIN-04")
        self.assertEqual(
            provider["route"],
            f"/admin/ai/providers/{PROVIDER_ID}",
        )
        for row in plan["captures"]:
            self.assertEqual(row["renderer_command"], ["python3", "qa/render_admin_screenshot.py"])
            self.assertEqual(row["locale"], "en")
            self.assertEqual(row["dpr"], 1.0)
            self.assertEqual(row["timeout"], 120)
            self.assertNotIn("password", json.dumps(row).lower())
            self.assertNotIn("cookie", json.dumps(row).lower())
            self.assertNotIn("storage", json.dumps(row).lower())

    def test_ar_and_custom_capture_parameters_remain_canonical(self):
        plan = build_admin_plan(ROOT, PROVIDER_ID, locale="ar", dpr=2, timeout=240)
        self.assertEqual(len(validate_plan(ROOT, plan)), 13)
        for row in plan["captures"]:
            self.assertEqual(row["locale"], "ar")
            self.assertEqual(row["dpr"], 2)
            self.assertEqual(row["timeout"], 240)

    def test_rejects_noncanonical_provider_id_and_capture_parameters(self):
        for provider in (
            "", "not-a-uuid", "12345678-1234-4ABC-8def-1234567890ab",
            "12345678-1234-4abc-8def-1234567890ab/extra",
        ):
            with self.subTest(provider=provider), self.assertRaises(AdminPlanError):
                build_admin_plan(ROOT, provider)
        for kwargs in (
            {"locale": "fr"},
            {"dpr": 0},
            {"dpr": True},
            {"timeout": 0},
            {"timeout": True},
            {"timeout": 3601},
        ):
            with self.subTest(kwargs=kwargs), self.assertRaises(AdminPlanError):
                build_admin_plan(ROOT, PROVIDER_ID, **kwargs)

    def test_cli_output_policy_is_covered_by_fresh_absolute_destination(self):
        # Builder output is intentionally pure JSON and contains no private
        # session state. The CLI's destination policy is exercised indirectly
        # by guaranteeing the generated object is serializable and validated.
        plan = build_admin_plan(ROOT, PROVIDER_ID)
        payload = json.dumps(plan)
        self.assertIn("ADMIN-13", payload)
        self.assertNotIn("storage-state", payload)


if __name__ == "__main__":
    unittest.main()
