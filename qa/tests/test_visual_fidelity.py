"""Regression tests: ADR-010 cannot be bypassed with absent or divergent captures."""
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from PIL import Image

from qa.validate_visual_fidelity import audit


class VisualFidelityGateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "contracts").mkdir()
        (self.root / "design").mkdir()
        (self.root / "assets/screen-reference").mkdir(parents=True)
        (self.root / "qa/visual-captures").mkdir(parents=True)
        screens = []
        entries = {}
        manifest = []
        for number in range(1, 41):
            screen_id = f"WEB-{number:02d}"
            ref = f"assets/screen-reference/screen_{number:02d}.png"
            path = self.root / ref
            Image.new("RGBA", (3, 2), (number, 40, 80, 255)).save(path)
            data = path.read_bytes()
            screens.append({"id": screen_id, "reference_image": ref})
            entries[screen_id] = {"reference_image": ref, "status": "pending"}
            manifest.append({
                "path": ref,
                "size_bytes": len(data),
                "sha256": hashlib.sha256(data).hexdigest(),
            })
        self.entries = entries
        (self.root / "contracts/screens.json").write_text(
            json.dumps({"screens": screens}), encoding="utf-8"
        )
        (self.root / "MANIFEST.json").write_text(
            json.dumps({"integrityItems": manifest}), encoding="utf-8"
        )
        self._write_ledger()

    def _write_ledger(self):
        (self.root / "design/visual-fidelity-evidence.json").write_text(
            json.dumps({
                "policy_version": 1,
                "pixel_comparison": "zero mismatching RGBA pixels at reference PNG dimensions",
                "screens": self.entries,
            }),
            encoding="utf-8",
        )

    def _mark_matched(self):
        ref = self.root / self.entries["WEB-01"]["reference_image"]
        capture = "qa/visual-captures/WEB-01.png"
        (self.root / capture).write_bytes(ref.read_bytes())
        self.entries["WEB-01"] = {
            **self.entries["WEB-01"],
            "status": "matched",
            "capture": capture,
            "renderer_commit": "a" * 40,
            "capture_command": "flutter test integration_test/visual.dart",
            "route": "/",
            "locale": "en",
            "device_pixel_ratio": 1,
        }
        self._write_ledger()
        return self.root / capture

    def test_complete_reference_map_does_not_falsely_claim_parity(self):
        errors, matched, total = audit(self.root)
        self.assertEqual(errors, [])
        self.assertEqual((matched, total), (0, 40))

    def test_only_a_real_capture_claim_with_exact_rgba_pixels_passes(self):
        self._mark_matched()
        errors, matched, total = audit(self.root)
        self.assertEqual(errors, [])
        self.assertEqual((matched, total), (1, 40))

    def test_a_single_rgb_pixel_difference_fails_even_if_alpha_is_identical(self):
        capture = self._mark_matched()
        Image.new("RGBA", (3, 2), (200, 40, 80, 255)).save(capture)
        errors, matched, _ = audit(self.root)
        self.assertEqual(matched, 0)
        self.assertTrue(any("mismatching pixels" in error for error in errors))

    def test_missing_capture_or_fake_tolerance_fails(self):
        capture = self._mark_matched()
        capture.unlink()
        errors, _, _ = audit(self.root)
        self.assertTrue(any("screenshot is missing" in error for error in errors))
        self.entries["WEB-01"]["pixel_tolerance"] = 5
        self._write_ledger()
        errors, _, _ = audit(self.root)
        self.assertTrue(any("no masks/tolerances" in error for error in errors))

    def test_original_reference_replacement_and_missing_mapping_fail(self):
        Image.new("RGBA", (3, 2), (0, 0, 0, 255)).save(
            self.root / self.entries["WEB-01"]["reference_image"]
        )
        errors, _, _ = audit(self.root)
        self.assertTrue(any("reference hash mismatch" in error for error in errors))
        del self.entries["WEB-40"]
        self._write_ledger()
        errors, _, _ = audit(self.root)
        self.assertTrue(any("exactly all 40" in error for error in errors))


if __name__ == "__main__":
    unittest.main()
