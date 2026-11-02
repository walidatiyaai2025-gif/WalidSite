"""Unit fixture for the real-runtime screenshot admission tool.

Synthetic generated pixels in these tests are NOT claims about the product UI.
"""
import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

from PIL import Image

from qa.capture_visual_evidence import CaptureError, capture, main
from qa.validate_visual_fidelity import audit


class CaptureRunnerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "contracts").mkdir()
        (self.root / "design").mkdir()
        (self.root / "assets/screen-reference").mkdir(parents=True)
        (self.root / "qa/visual-captures").mkdir(parents=True)
        screens, entries, integrity = [], {}, []
        for number in range(1, 41):
            screen_id = f"WEB-{number:02d}"
            source = f"assets/screen-reference/ref_{number}.png"
            path = self.root / source
            Image.new("RGBA", (3, 2), (11, 22, 33, 255)).save(
                path, compress_level=0,
            )
            raw = path.read_bytes()
            screens.append({
                "id": screen_id,
                "reference_image": source,
                "route": "/" if number == 1 else "/projects/{slug}",
            })
            entries[screen_id] = {
                "reference_image": source,
                "status": "pending",
            }
            integrity.append({
                "path": source, "sha256": hashlib.sha256(raw).hexdigest(),
                "size_bytes": len(raw),
            })
        (self.root / "contracts/screens.json").write_text(
            json.dumps({"screens": screens}), encoding="utf-8",
        )
        (self.root / "MANIFEST.json").write_text(
            json.dumps({"integrityItems": integrity}), encoding="utf-8",
        )
        self.ledger = self.root / "design/visual-fidelity-evidence.json"
        self.ledger.write_text(json.dumps({
            "policy_version": 1,
            "pixel_comparison": "zero mismatching RGBA pixels at reference PNG dimensions",
            "screens": entries,
        }), encoding="utf-8")
        self.sha = "b" * 40
        # Synthetic pixel fixtures have no Git repository. Provenance is
        # tested separately against a real temporary Git checkout.
        from unittest.mock import patch
        guard = patch("qa.capture_visual_evidence.verified_source_checkout", return_value=True)
        guard.start()
        self.addCleanup(guard.stop)

    def renderer(self, rgb=(11, 22, 33, 255)):
        script = (
            "import os; from PIL import Image; "
            "Image.new('RGBA', "
            "(int(os.environ['WALIDSITE_CAPTURE_WIDTH']), "
            "int(os.environ['WALIDSITE_CAPTURE_HEIGHT'])), "
            f"{rgb!r}).save(os.environ['WALIDSITE_CAPTURE_PATH'])"
        )
        return [sys.executable, "-c", script]

    def run_capture(self, screen="WEB-01", route="/", command=None):
        return capture(
            self.root, screen, commit=self.sha, locale="ar", dpr=1,
            route=route, command=self.renderer() if command is None else command,
        )

    def record(self, screen="WEB-01"):
        return json.loads(self.ledger.read_text(encoding="utf-8"))["screens"][screen]

    def test_success_only_after_executed_runtime_command_and_zero_difference(self):
        location = self.run_capture()
        self.assertEqual(location, "qa/visual-captures/WEB-01.png")
        self.assertEqual(self.record()["status"], "matched")
        self.assertEqual(self.record()["renderer_commit"], self.sha)
        self.assertEqual(self.record()["locale"], "ar")
        self.assertEqual(self.record()["device_pixel_ratio"], 1)
        self.assertIn("qa/capture_visual_evidence.py", self.record()["capture_command"])
        self.assertNotEqual(
            (self.root / location).read_bytes(),
            (self.root / "assets/screen-reference/ref_1.png").read_bytes(),
        )
        self.assertEqual(audit(self.root), ([], 1, 40))
        with self.assertRaisesRegex(CaptureError, "already has evidence"):
            self.run_capture()

    def test_custom_timeout_is_recorded_and_replayable(self):
        capture(self.root, "WEB-01", commit=self.sha, locale="ar",
                dpr=1, route="/", command=self.renderer(), timeout=17)
        self.assertIn("--timeout 17", self.record()["capture_command"])

    def test_cli_timeout_reaches_capture_and_rejects_invalid_values(self):
        from unittest.mock import patch
        argv = ["WEB-01", "--commit", self.sha, "--locale", "ar",
                "--dpr", "2", "--route", "/", "--timeout", "17",
                "--", *self.renderer()]
        with patch("qa.capture_visual_evidence.capture", return_value="qa/visual-captures/WEB-01.png") as mocked:
            self.assertEqual(main(argv), 0)
            self.assertEqual(mocked.call_args.kwargs["timeout"], 17)
        with self.assertRaisesRegex(CaptureError, "timeout"):
            capture(self.root, "WEB-01", commit=self.sha, locale="ar",
                    dpr=2, route="/", command=self.renderer(), timeout=True)

    def test_pixel_mismatch_fails_closed_and_retains_pending(self):
        with self.assertRaisesRegex(CaptureError, "RGBA pixels differ"):
            self.run_capture(command=self.renderer((12, 22, 33, 255)))
        self.assertEqual(self.record()["status"], "pending")
        self.assertFalse((self.root / "qa/visual-captures/WEB-01.png").exists())
        self.assertEqual(audit(self.root), ([], 0, 40))

    def test_renderer_mutating_source_cannot_advance_a_capture(self):
        from unittest.mock import patch
        # Initial source checkout attestation passes, but the renderer
        # modifies tracked source before the post-capture second check.
        with patch("qa.capture_visual_evidence.verified_source_checkout",
                   side_effect=[True, False]) as verifier:
            with self.assertRaisesRegex(CaptureError, "changed Git source"):
                self.run_capture()
            self.assertEqual(verifier.call_count, 2)
        self.assertEqual(self.record()["status"], "pending")
        self.assertFalse((self.root / "qa/visual-captures/WEB-01.png").exists())

    def test_renderer_failure_cannot_advance_ledger(self):
        with self.assertRaisesRegex(CaptureError, "Renderer failed"):
            self.run_capture(command=[sys.executable, "-c", "raise SystemExit(9)"])
        self.assertEqual(self.record()["status"], "pending")

    def test_unsupported_route_rejected_including_placeholder_paths(self):
        with self.assertRaisesRegex(CaptureError, "canonical screen contract"):
            self.run_capture(route="/admin")
        self.run_capture(screen="WEB-02", route="/projects/verified")
        self.assertEqual(self.record("WEB-02")["route"], "/projects/verified")
        self.assertEqual(audit(self.root), ([], 1, 40))

    def test_unverified_commit_or_reference_copy_command_rejected(self):
        with self.assertRaisesRegex(CaptureError, "full lowercase SHA"):
            capture(
                self.root, "WEB-01", commit="short", locale="en",
                dpr=1, route="/", command=self.renderer(),
            )
        with self.assertRaisesRegex(CaptureError, "not an original reference"):
            self.run_capture(command=["cp", "assets/screen-reference/ref_1.png",
                                      "qa/visual-captures/WEB-01.png"])
        self.assertEqual(self.record()["status"], "pending")

    def test_reference_dimensions_must_be_divisible_by_dpr(self):
        with self.assertRaisesRegex(CaptureError, "divisible by DPR"):
            capture(self.root, "WEB-01", commit=self.sha, locale="ar",
                    dpr=2, route="/", command=self.renderer())
        self.assertEqual(self.record()["status"], "pending")

    def test_dpr_and_locale_invalid_fail_before_running(self):
        for locale, dpr in [("fr", 1), ("ar", 0), ("en", 100)]:
            with self.assertRaises(CaptureError):
                capture(
                    self.root, "WEB-01", commit=self.sha,
                    locale=locale, dpr=dpr, route="/",
                    command=self.renderer(),
                )
        self.assertEqual(audit(self.root), ([], 0, 40))


if __name__ == "__main__":
    unittest.main()
