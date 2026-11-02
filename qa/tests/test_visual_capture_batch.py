"""Visual batch runner regressions: never admit a fake match or run partial plans."""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from qa.capture_visual_batch import (
    CapturePlanError, execute, validate_plan, verified_head,
)
from qa.capture_visual_evidence import CaptureError


class VisualCaptureBatchTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        (self.root / "contracts").mkdir()
        (self.root / "design").mkdir()
        self.screens = []
        self.ledger = {}
        for index in range(1, 41):
            sid = f"WEB-{index:02d}"
            self.screens.append({"id": sid, "route": "/" if index == 1 else "/projects/{slug}"})
            self.ledger[sid] = {"status": "pending"}
        (self.root / "contracts/screens.json").write_text(
            json.dumps({"screens": self.screens}), encoding="utf-8"
        )
        self._save_ledger()
        self.sha = "d" * 40

    def _save_ledger(self):
        (self.root / "design/visual-fidelity-evidence.json").write_text(
            json.dumps({"screens": self.ledger}), encoding="utf-8"
        )

    def row(self, index=1):
        return {
            "screen_id": f"WEB-{index:02d}",
            "route": "/" if index == 1 else "/projects/proof",
            "locale": "en",
            "dpr": 2,
            "renderer_command": ["python3", "qa/render_web_screenshot.py"],
            "timeout": 240,
        }

    def plan(self, *rows):
        return {"version": 1, "captures": list(rows)}

    def run_batch(self, plan, capture_action=None, **kwargs):
        if capture_action is None:
            def admitted_capture(_, screen_id, **_kwargs):
                self.ledger[screen_id]["status"] = "matched"
                self._save_ledger()
            capture_action = Mock(side_effect=admitted_capture)
        with patch("qa.capture_visual_batch.audit", return_value=([], 0, 40)), patch(
            "qa.capture_visual_batch.verified_head", return_value=True
        ):
            output = execute(
                self.root, plan, self.sha, capture_action=capture_action, **kwargs
            )
        return output, capture_action

    def test_full_contract_preflight_and_explicit_all_40_flag(self):
        full = self.plan(*(self.row(i) for i in range(1, 41)))
        self.assertEqual(len(validate_plan(self.root, full, require_all=True)), 40)
        with self.assertRaisesRegex(CapturePlanError, "cover all 40"):
            validate_plan(self.root, self.plan(self.row()), require_all=True)

    def test_duplicate_unknown_and_noncanonical_route_fail_before_execution(self):
        for rows in (
            [self.row(), self.row()],
            [{**self.row(), "screen_id": "ADMIN-50"}],
            [{**self.row(2), "route": "/admin"}],
            [{**self.row(2), "route": "/projects/proof?x=1"}],
        ):
            with self.subTest(rows=rows), self.assertRaises(CapturePlanError):
                validate_plan(self.root, self.plan(*rows))
        command = Mock()
        with patch("qa.capture_visual_batch.audit") as audit:
            with self.assertRaises(CapturePlanError):
                execute(self.root, self.plan(self.row(), self.row()),
                        self.sha, capture_action=command)
            audit.assert_not_called()
        command.assert_not_called()

    def test_invalid_schemas_locale_dpr_timeout_and_reference_copy_fail(self):
        invalid = [
            {"dpr": True}, {"dpr": 0}, {"dpr": 9},
            {"locale": "fr"}, {"timeout": True}, {"timeout": -1},
            {"timeout": 3601}, {"renderer_command": []},
            {"renderer_command": ["cp", "assets/screen-reference/web_home.png", "x"]},
            {"renderer_command": ["python3", "bad\ncommand"]},
        ]
        for change in invalid:
            with self.subTest(change=change), self.assertRaises(CapturePlanError):
                validate_plan(self.root, self.plan({**self.row(), **change}))
        with self.assertRaisesRegex(CapturePlanError, "exactly"):
            validate_plan(self.root, {"version": 1, "captures": [self.row()], "extra": True})
        with self.assertRaises(CapturePlanError):
            validate_plan(self.root, self.plan({**self.row(), "extra": "not allowed"}))

    def test_dry_run_reports_planned_without_creating_captures(self):
        outcome, renderer = self.run_batch(self.plan(self.row()), dry_run=True)
        self.assertEqual(outcome["planned_only"], ["WEB-01"])
        self.assertEqual(outcome["captured"], [])
        renderer.assert_not_called()

    def test_resume_only_skips_previously_audited_matched_screen(self):
        self.ledger["WEB-01"]["status"] = "matched"
        self._save_ledger()
        result, renderer = self.run_batch(self.plan(self.row(), self.row(2)))
        self.assertEqual(result["already_matched"], ["WEB-01"])
        self.assertEqual(result["captured"], ["WEB-02"])
        renderer.assert_called_once()
        self.assertEqual(renderer.call_args.kwargs["route"], "/projects/proof")
        self.assertEqual(renderer.call_args.kwargs["timeout"], 240)

    def test_failing_screen_remains_unaccepted_while_another_can_run(self):
        def renderer(_, screen_id, **kwargs):
            if screen_id == "WEB-01":
                raise CaptureError("actual RGB pixels differ")
            self.ledger[screen_id]["status"] = "matched"
            self._save_ledger()
        result, _ = self.run_batch(
            self.plan(self.row(), self.row(2)), capture_action=renderer
        )
        self.assertEqual(result["captured"], ["WEB-02"])
        self.assertEqual(result["failed"], [{
            "screen_id": "WEB-01", "reason": "actual RGB pixels differ"
        }])
        self.assertEqual(self.ledger["WEB-01"]["status"], "pending")

    def test_noop_renderer_cannot_fake_success_or_full_capture(self):
        # An adapter returning success without evidence was previously counted
        # as "captured". The batch must fail closed regardless of exit code.
        noop = Mock(return_value=None)
        result, _ = self.run_batch(self.plan(self.row()), capture_action=noop)
        self.assertEqual(result["captured"], [])
        self.assertEqual(result["failed"], [{
            "screen_id": "WEB-01",
            "reason": "Capture returned without matched evidence in canonical ledger",
        }])
        self.assertEqual(self.ledger["WEB-01"]["status"], "pending")
        noop.assert_called_once()

    def test_post_capture_audit_and_checkout_failure_prevent_success(self):
        def fake_capture(_, screen_id, **kwargs):
            self.ledger[screen_id]["status"] = "matched"
            self._save_ledger()
        for source_clean, audit_results, expected_reason in [
            (False, [([], 0, 40)], "Source checkout changed"),
            (True, [([], 0, 40), (["Missing screenshot"], 0, 40)], "Post-capture evidence audit failed"),
        ]:
            self.ledger["WEB-01"]["status"] = "pending"
            self._save_ledger()
            with patch("qa.capture_visual_batch.verified_head",
                       side_effect=[True, source_clean]), patch(
                       "qa.capture_visual_batch.audit",
                       side_effect=audit_results):
                result = execute(self.root, self.plan(self.row()), self.sha,
                                 capture_action=fake_capture)
            self.assertEqual(result["captured"], [])
            self.assertIn(expected_reason, result["failed"][0]["reason"])

    def test_existing_invalid_evidence_fails_closed_before_renderer(self):
        renderer = Mock()
        with patch("qa.capture_visual_batch.verified_head", return_value=True), patch(
            "qa.capture_visual_batch.audit", return_value=(["invalid source"], 0, 40)
        ):
            with self.assertRaisesRegex(CapturePlanError, "invalid source"):
                execute(self.root, self.plan(self.row()), self.sha,
                        capture_action=renderer)
        renderer.assert_not_called()

    def test_full_batch_partial_capture_is_never_complete(self):
        from qa.capture_visual_batch import main
        from unittest.mock import patch
        plan_file = self.root / "plan.json"
        plan_file.write_text(json.dumps(self.plan(*(self.row(i) for i in range(1, 41)))))
        partial = {"captured": ["WEB-01"], "already_matched": [],
                   "planned_only": [], "failed": []}
        with patch("qa.capture_visual_batch.execute", return_value=partial):
            self.assertEqual(main(["--plan", str(plan_file), "--commit", self.sha,
                                   "--require-all"]), 1)
            self.assertEqual(main(["--plan", str(plan_file), "--commit", self.sha,
                                   "--require-all", "--dry-run"]), 0)
        complete = {**partial, "captured": [f"WEB-{i:02d}" for i in range(1, 41)]}
        with patch("qa.capture_visual_batch.execute", return_value=complete):
            self.assertEqual(main(["--plan", str(plan_file), "--commit", self.sha,
                                   "--require-all"]), 0)

    def test_head_verification_delegates_to_shared_strict_checkout_guard(self):
        with patch("qa.capture_visual_batch.verified_source_checkout", return_value=True) as guard:
            self.assertTrue(verified_head(self.root, self.sha))
            guard.assert_called_once_with(self.root, self.sha)
        with patch("qa.capture_visual_batch.verified_source_checkout", return_value=False):
            self.assertFalse(verified_head(self.root, self.sha))
            self.assertFalse(verified_head(self.root, "not a sha"))


if __name__ == "__main__":
    unittest.main()
