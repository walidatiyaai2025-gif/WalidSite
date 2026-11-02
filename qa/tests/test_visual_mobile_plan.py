"""Canonical 13-screen Flutter device plan: fixture IDs are never screenshot proof."""
import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

from qa.build_mobile_visual_plan import (
    DYNAMIC_IDS, MOBILE_IDS, MobilePlanError, build_mobile_plan, main,
)
from qa.capture_visual_batch import validate_plan


ROOT = Path(__file__).resolve().parents[2]


class MobileVisualPlanTest(unittest.TestCase):
    def test_static_routes_and_explicit_dynamic_omission(self):
        plan = build_mobile_plan(ROOT)
        entries = validate_plan(ROOT, plan)
        self.assertEqual(len(entries), 11)
        self.assertEqual(
            [row["screen_id"] for row in entries],
            [screen_id for screen_id in MOBILE_IDS if screen_id not in DYNAMIC_IDS],
        )
        self.assertTrue(all(row["renderer_command"] == [
            "python3", "qa/render_android_screenshot.py"
        ] for row in entries))
        self.assertFalse(any("{id}" in row["route"] for row in entries))
        self.assertNotIn("WALIDSITE_MOBILE_ADB_SERIAL", json.dumps(plan))

    def test_full_canonical_plan_requires_both_verified_resource_ids(self):
        with self.assertRaisesRegex(MobilePlanError, "require real solution"):
            build_mobile_plan(ROOT, require_all=True)
        with self.assertRaisesRegex(MobilePlanError, "require real solution"):
            build_mobile_plan(ROOT, solution_id="real-uuid", require_all=True)
        plan = build_mobile_plan(
            ROOT, solution_id="verified_123", runbook_id="offline-2",
            locale="ar", dpr=2.0, timeout=240, require_all=True,
        )
        rows = validate_plan(ROOT, plan, require_all=False)
        self.assertEqual(len(rows), 13)
        self.assertEqual([row["screen_id"] for row in rows], list(MOBILE_IDS))
        self.assertEqual(rows[3]["route"], "/solutions/verified_123")
        self.assertEqual(rows[5]["route"], "/runbooks/offline-2/offline")
        self.assertTrue(all(row["locale"] == "ar" and row["dpr"] == 2 for row in rows))

    def test_injection_invalid_locals_and_type_confusion_fail_closed(self):
        cases = (
            {"solution_id": "../admin"}, {"solution_id": "{id}"},
            {"solution_id": "slug?secret=1"}, {"solution_id": "slug%2fother"},
            {"solution_id": "slug/other"}, {"solution_id": "é"},
            {"solution_id": ""}, {"solution_id": True},
            {"runbook_id": "/admin"}, {"runbook_id": "."},
            {"runbook_id": "a" * 129},
            {"locale": "fr"}, {"dpr": 0}, {"dpr": 9},
            {"dpr": float("nan")}, {"dpr": True},
            {"timeout": 0}, {"timeout": 3601}, {"timeout": 4.2},
        )
        for case in cases:
            with self.subTest(case=case), self.assertRaises((MobilePlanError, ValueError)):
                build_mobile_plan(ROOT, **case)

    def test_cli_writes_only_new_external_plan_and_is_json_parseable(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "captures.json"
            errors = io.StringIO()
            with contextlib.redirect_stderr(errors):
                self.assertEqual(main([
                    "--solution-id", "valid-123", "--runbook-id", "valid-456",
                    "--require-all", "--output", str(path),
                ]), 0)
                self.assertEqual(main(["--output", str(path)]), 2)
                self.assertEqual(main(["--output", "relative.json"]), 2)
            self.assertEqual(len(json.loads(path.read_text())["captures"]), 13)
            self.assertIn("REJECTED", errors.getvalue())
            self.assertEqual(main(["--require-all"]), 2)


if __name__ == "__main__":
    unittest.main()
