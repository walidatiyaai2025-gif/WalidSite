"""Issue #481: canonical runtime diagnostic accounting is never parity evidence."""
import io
import json
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from unittest.mock import patch

from qa.visual_capture_inventory import InventoryError, inventory, main


COMMIT = "a" * 40


class VisualCaptureInventoryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "contracts").mkdir()
        (self.root / "design").mkdir()
        self.screens = []
        self.ledger = {}
        for prefix, count in (("WEB", 14), ("ADMIN", 13), ("MOBILE", 13)):
            for i in range(1, count + 1):
                sid = f"{prefix}-{i:02d}"
                self.screens.append({"id": sid, "route": "/" + sid.lower()})
                self.ledger[sid] = {"status": "pending", "reference_image": "assets/screen-reference/" + sid + ".png"}
        (self.root / "contracts/screens.json").write_text(
            json.dumps({"screens": self.screens}), encoding="utf-8"
        )
        (self.root / "design/visual-fidelity-evidence.json").write_text(
            json.dumps({"screens": self.ledger}), encoding="utf-8"
        )
        patcher = patch("qa.visual_capture_inventory.visual_audit", return_value=([], 0, 40))
        patcher.start()
        self.addCleanup(patcher.stop)

    def _observation(self, status="mismatch"):
        record = {
            "route": "/web-01",
            "locale": "en",
            "device_pixel_ratio": 1,
            "status": status,
            "reference_size": [200, 100],
        }
        if status in ("mismatch", "exact_pixels_unattested"):
            record.update({
                "runtime_size": [200, 100],
                "runtime_capture": "WEB-01.runtime.png",
                "total_pixels": 20000,
                "mismatching_rgba_pixels": 5 if status == "mismatch" else 0,
            })
        if status == "mismatch":
            record["difference_preview"] = "WEB-01.difference-preview.png"
        if status == "wrong_dimensions":
            record["runtime_size"] = [100, 200]
        return record

    def _report(self, entry=None, **changes):
        payload = {
            "renderer_commit": COMMIT,
            "evidence_admitted": False,
            "real_runtime_required": True,
            "screens": {"WEB-01": entry or self._observation()},
        }
        payload.update(changes)
        target = self.root / ("diagnostic-%d.json" % len(list(self.root.glob("diagnostic-*.json"))))
        target.write_text(json.dumps(payload), encoding="utf-8")
        return target

    def test_without_reports_is_explicitly_pending_not_visual_success(self):
        summary, errors = inventory(self.root)
        self.assertEqual(errors, [])
        self.assertEqual(summary["canonical_screens"], 40)
        self.assertEqual(summary["matched_in_authoritative_ledger"], 0)
        self.assertEqual(summary["pending_or_unverified"], 40)
        self.assertEqual(summary["diagnosed"], 0)
        self.assertEqual(len(summary["missing_diagnostics"]), 40)
        self.assertEqual(summary["by_surface"]["web"]["total"], 14)
        self.assertEqual(summary["by_surface"]["admin"]["total"], 13)
        self.assertEqual(summary["by_surface"]["mobile"]["total"], 13)
        self.assertFalse(summary["ready_for_visual_release"])
        with redirect_stdout(io.StringIO()):
            self.assertEqual(main(["--root", str(self.root)]), 0)
            self.assertEqual(main(["--root", str(self.root), "--require-coverage"]), 1)
            self.assertEqual(main(["--root", str(self.root), "--require-complete"]), 1)

    def test_mismatch_is_reported_and_exact_pixels_remain_unattested(self):
        mismatch = self._report()
        summary, errors = inventory(self.root, [mismatch], COMMIT)
        self.assertEqual(errors, [])
        self.assertEqual(summary["diagnosed"], 1)
        self.assertEqual(summary["observations"]["WEB-01"]["mismatching_rgba_pixels"], 5)
        self.assertEqual(summary["by_surface"]["web"]["mismatching"], ["WEB-01"])
        self.assertFalse(summary["ready_for_visual_release"])

        exact = self._report(self._observation("exact_pixels_unattested"))
        summary, errors = inventory(self.root, [exact], COMMIT)
        self.assertEqual(errors, [])
        self.assertEqual(summary["observations"]["WEB-01"]["status"], "exact_pixels_unattested")
        self.assertEqual(summary["matched_in_authoritative_ledger"], 0)
        with redirect_stdout(io.StringIO()):
            self.assertEqual(main(["--root", str(self.root), "--report", str(exact),
                                   "--commit", COMMIT, "--require-complete"]), 1)

    def test_pin_and_duplicate_integrity_is_mandatory(self):
        one = self._report()
        with self.assertRaisesRegex(InventoryError, "Pinned"):
            inventory(self.root, [one])
        with self.assertRaisesRegex(InventoryError, "Unpinned"):
            inventory(self.root, [one], "b" * 40)
        with self.assertRaisesRegex(InventoryError, "duplicated"):
            inventory(self.root, [one, one], COMMIT)
        invalid = self._report(evidence_admitted=True)
        with self.assertRaisesRegex(InventoryError, "self-admitted"):
            inventory(self.root, [invalid], COMMIT)
        empty = self._report(screens={})
        with self.assertRaisesRegex(InventoryError, "empty"):
            inventory(self.root, [empty], COMMIT)
        with redirect_stderr(io.StringIO()):
            self.assertEqual(main(["--root", str(self.root), "--report", str(one)]), 2)

    def test_fabricated_or_inconsistent_pixel_receipts_are_rejected(self):
        cases = [
            {"status": "matched"},
            {"route": "https://evil.invalid/web-01"},
            {"device_pixel_ratio": True},
            {"reference_size": [0, 100]},
            {"mismatching_rgba_pixels": 0},
            {"mismatching_rgba_pixels": -1},
            {"total_pixels": 123},
            {"runtime_capture": "../../source.png"},
            {"difference_preview": "not-a-screen.png"},
            {"runtime_size": [100, 200]},
        ]
        for changes in cases:
            with self.subTest(changes=changes):
                sample = self._observation()
                sample.update(changes)
                path = self._report(sample)
                with self.assertRaises(InventoryError):
                    inventory(self.root, [path], COMMIT)

    def test_reports_cannot_override_matched_ledger_or_hide_conflicts(self):
        self.ledger["WEB-01"]["status"] = "matched"
        (self.root / "design/visual-fidelity-evidence.json").write_text(
            json.dumps({"screens": self.ledger}), encoding="utf-8"
        )
        patcher = patch("qa.visual_capture_inventory.visual_audit", return_value=([], 1, 40))
        with patcher:
            result, errors = inventory(self.root, [self._report()], COMMIT)
        self.assertEqual(result["matched_in_authoritative_ledger"], 1)
        self.assertTrue(any("contradicts matched evidence" in error for error in errors))
        self.assertFalse(result["ready_for_visual_release"])

    def test_invalid_input_fails_closed_without_side_effects(self):
        report = self._report()
        report.write_text("not JSON", encoding="utf-8")
        with self.assertRaises(InventoryError):
            inventory(self.root, [report], COMMIT)
        report.unlink()
        report.symlink_to(self.root / "contracts/screens.json")
        with self.assertRaises(InventoryError):
            inventory(self.root, [report], COMMIT)


if __name__ == "__main__":
    unittest.main()
