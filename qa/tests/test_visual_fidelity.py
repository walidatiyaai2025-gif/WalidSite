"""Regression tests: ADR-010 cannot be bypassed with absent or divergent captures."""
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from PIL import Image
from PIL.PngImagePlugin import PngInfo

from qa.validate_visual_fidelity import audit, _route_matches


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
            screens.append({"id": screen_id, "reference_image": ref, "route": "/" if number == 1 else "/blog/{slug}"})
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
        # A synthetic independently encoded image tests the RGBA comparator.
        # This fixture never claims to prove a real website/device rendering.
        metadata = PngInfo()
        metadata.add_text("unit_test", "RGBA comparator regression fixture")
        with Image.open(ref) as source:
            source.save(self.root / capture, pnginfo=metadata)
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

    def test_byte_identical_reference_is_not_accepted_as_runtime_capture(self):
        capture = self._mark_matched()
        reference = self.root / self.entries["WEB-01"]["reference_image"]
        capture.write_bytes(reference.read_bytes())

        errors, matched, _ = audit(self.root)
        self.assertEqual(matched, 0)
        self.assertTrue(any("byte-for-byte copy" in error for error in errors))

    def test_final_release_gate_rejects_pending_evidence(self):
        errors, matched, total = audit(self.root, require_complete=True)
        self.assertEqual((matched, total), (0, 40))
        self.assertTrue(any("40/40" in error and "release gate" in error for error in errors))

        self._mark_matched()
        errors, matched, _ = audit(self.root, require_complete=True)
        self.assertEqual(matched, 1)
        self.assertTrue(any("39/40" in error and "release gate" in error for error in errors))

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


    def test_evidence_bound_to_exact_screen_route_capture_path_and_provenance(self):
        self._mark_matched()
        self.assertEqual(audit(self.root)[0], [])

        cases = [
            ("capture", "qa/visual-captures/WEB-02.png", "canonical screen ID"),
            ("capture", 7, "canonical screen ID"),
            ("capture", ["qa/visual-captures/WEB-01.png"], "canonical screen ID"),
            ("route", "/about", "invalid/placeholder screenshot provenance"),
            ("route", "//outside.example.test", "invalid/placeholder screenshot provenance"),
            ("route", "/blog/../admin", "invalid/placeholder screenshot provenance"),
            ("renderer_commit", "A" * 40, "invalid/placeholder screenshot provenance"),
            ("device_pixel_ratio", True, "invalid/placeholder screenshot provenance"),
            ("device_pixel_ratio", 1.5, "invalid/placeholder screenshot provenance"),
            ("device_pixel_ratio", 9, "invalid/placeholder screenshot provenance"),
        ]
        original = dict(self.entries["WEB-01"])
        for field, invalid, expected in cases:
            with self.subTest(field=field, invalid=invalid):
                self.entries["WEB-01"] = {**original, field: invalid}
                self._write_ledger()
                errors, matched, _ = audit(self.root)
                self.assertEqual(matched, 0)
                self.assertTrue(any(expected in error for error in errors), errors)
        self.entries["WEB-01"] = original
        self._write_ledger()
        self.assertEqual(audit(self.root)[0], [])

    def test_canonical_dynamic_routes_allow_only_valid_path_segments(self):
        self.assertTrue(_route_matches("/blog/{slug}", "/blog/verified-article"))
        self.assertTrue(_route_matches("/runbooks/{id}/offline", "/runbooks/123/offline"))
        for path in (
            "/blog/", "/blog/one/two", "/blog/../admin", "/blog/./admin",
            "/blog/story?token=x", "/blog/story#fragment", "//blog/story",
            "/blog/a\bb", "/blog/a\nb", "/blog/a\\b",
        ):
            with self.subTest(path=repr(path)):
                self.assertFalse(_route_matches("/blog/{slug}", path))


if __name__ == "__main__":
    unittest.main()
