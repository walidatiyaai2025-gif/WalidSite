"""#481 diagnostic tests use synthetic PNGs, NEVER accepted screen evidence."""
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image
from qa.capture_visual_batch import CapturePlanError
from qa.visual_diagnostics import diagnose, _compare_rgba


class VisualDiagnosticsTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        for folder in ("qa", "assets/screen-reference", "contracts", "design"):
            (self.root / folder).mkdir(parents=True)
        screens = [
            {"id": f"WEB-{i:02d}",
             "route": "/" if i == 1 else "/about",
             "reference_image": f"assets/screen-reference/ref-{i:02d}.png"}
            for i in range(1, 41)
        ]
        (self.root / "contracts/screens.json").write_text(
            json.dumps({"screens": screens}), encoding="utf-8"
        )
        self.source = self.root / "assets/screen-reference/ref-01.png"
        Image.new("RGBA", (2, 2), (0, 0, 0, 255)).save(self.source)
        self.ledger = self.root / "design/visual-fidelity-evidence.json"
        self.ledger.write_text('{"screens": {}}\n', encoding="utf-8")
        self.sha = "a" * 40
        self.plan = {
            "version": 1,
            "captures": [{
                "screen_id": "WEB-01",
                "route": "/",
                "locale": "ar",
                "dpr": 1,
                "timeout": 120,
                "renderer_command": ["python3", "qa/render_web_screenshot.py"],
            }],
        }

    def run_diagnostics(self, runner, plan=None, dry_run=False, group=None):
        with patch("qa.visual_diagnostics.verified_head", return_value=True), patch(
            "qa.visual_diagnostics.audit", return_value=([], 0, 40)
        ):
            return diagnose(self.root, self.plan if plan is None else plan,
                            self.sha, runner=runner, dry_run=dry_run, group=group)

    def test_dry_run_validates_without_writing_files_or_running_renderer(self):
        def no_run(*args, **kwargs):
            self.fail("Dry-run must not invoke a renderer")
        first = self.ledger.read_bytes()
        report = self.run_diagnostics(no_run, dry_run=True)
        self.assertEqual(report["screens"]["WEB-01"]["status"], "planned_only")
        self.assertFalse((self.root / "qa/visual-diagnostics").exists())
        self.assertEqual(self.ledger.read_bytes(), first)

    def test_real_renderer_mismatch_yields_exact_rgba_count_and_private_diagnostic(self):
        def runner(command, *, cwd, env, timeout, check, stdout, stderr):
            self.assertEqual(command, ["python3", "qa/render_web_screenshot.py"])
            self.assertEqual(env["WALIDSITE_CAPTURE_WIDTH"], "2")
            self.assertEqual(env["WALIDSITE_CAPTURE_HEIGHT"], "2")
            self.assertEqual(env["WALIDSITE_CAPTURE_LOCALE"], "ar")
            self.assertEqual(env["WALIDSITE_CAPTURE_ROUTE"], "/")
            self.assertEqual(timeout, 120)
            self.assertEqual(stdout, subprocess.DEVNULL)
            self.assertEqual(stderr, subprocess.DEVNULL)
            image = Image.new("RGBA", (2, 2), (0, 0, 0, 255))
            image.putpixel((0, 1), (0, 0, 0, 254))
            image.save(env["WALIDSITE_CAPTURE_PATH"], compress_level=1)
            return subprocess.CompletedProcess(command, 0)

        source_bytes = self.source.read_bytes()
        ledger_bytes = self.ledger.read_bytes()
        report = self.run_diagnostics(runner)
        record = report["screens"]["WEB-01"]
        self.assertEqual(record["status"], "mismatch")
        self.assertEqual(record["mismatching_rgba_pixels"], 1)
        self.assertEqual(record["total_pixels"], 4)
        self.assertFalse(report["evidence_admitted"])
        out = self.root / "qa/visual-diagnostics" / self.sha
        self.assertTrue((out / "WEB-01.runtime.png").is_file())
        self.assertEqual((out / "WEB-01.difference-preview.png").suffix, ".png")
        self.assertTrue((out / "report.json").is_file())
        with Image.open(out / "WEB-01.difference-preview.png") as preview:
            self.assertEqual(preview.getpixel((0, 1)), 255)
            self.assertEqual(preview.getpixel((1, 1)), 0)
        self.assertEqual(self.source.read_bytes(), source_bytes)
        self.assertEqual(self.ledger.read_bytes(), ledger_bytes)
        self.assertNotIn("Bearer", (out / "report.json").read_text())

    def test_exact_pixels_are_diagnostics_not_accepted_evidence(self):
        def renderer(command, *, env, **kwargs):
            image = Image.new("RGBA", (2, 2), (0, 0, 0, 255))
            image.save(env["WALIDSITE_CAPTURE_PATH"], compress_level=1)
            return subprocess.CompletedProcess(command, 0)
        report = self.run_diagnostics(renderer)
        entry = report["screens"]["WEB-01"]
        # If Pillow emits identical bytes despite differing compression settings,
        # copying the canonical PNG is rejected rather than accepted.
        self.assertIn(entry["status"], ("exact_pixels_unattested", "source_copy_rejected"))
        self.assertFalse(report["evidence_admitted"])
        self.assertEqual(self.ledger.read_text(), '{"screens": {}}\n')

    def test_wrong_dimensions_and_failed_render_do_not_admit_images(self):
        def wrong_size(command, *, env, **kwargs):
            Image.new("RGB", (3, 2), "white").save(env["WALIDSITE_CAPTURE_PATH"])
            return subprocess.CompletedProcess(command, 0)
        report = self.run_diagnostics(wrong_size)
        self.assertEqual(report["screens"]["WEB-01"]["status"], "wrong_dimensions")
        self.assertFalse((self.root / "qa/visual-diagnostics" / self.sha / "WEB-01.runtime.png").exists())

    def test_invalid_plan_and_wrong_head_preflight_without_running_or_writing(self):
        def forbidden(*args, **kwargs):
            self.fail("Renderer must not run for invalid plan")
        invalid = {"version": 1, "captures": [
            {**self.plan["captures"][0], "route": "/off-contract"}
        ]}
        with self.assertRaises(CapturePlanError):
            self.run_diagnostics(forbidden, plan=invalid)
        with patch("qa.visual_diagnostics.verified_head", return_value=False):
            with self.assertRaisesRegex(CapturePlanError, "checked-out"):
                diagnose(self.root, self.plan, self.sha, runner=forbidden)
        self.assertFalse((self.root / "qa/visual-diagnostics").exists())

    def test_diagnostics_refuse_existing_destination_and_symlinked_root(self):
        target = self.root / "qa/visual-diagnostics" / self.sha
        target.mkdir(parents=True)
        with self.assertRaisesRegex(CapturePlanError, "existing"):
            self.run_diagnostics(lambda *args, **kwargs: None)
        target.rmdir()
        (self.root / "qa/visual-diagnostics").rmdir()
        other = self.root / "outside"
        other.mkdir()
        (self.root / "qa/visual-diagnostics").symlink_to(other, target_is_directory=True)
        with self.assertRaisesRegex(CapturePlanError, "unsafe"):
            self.run_diagnostics(lambda *args, **kwargs: None)

    def test_renderer_mutating_source_rejects_successful_screenshot_before_comparison(self):
        def rendered(command, *, env, **kwargs):
            Image.new("RGBA", (2, 2), (3, 4, 5, 255)).save(
                env["WALIDSITE_CAPTURE_PATH"]
            )
            return subprocess.CompletedProcess(command, 0)

        original = self.ledger.read_bytes()
        with patch("qa.visual_diagnostics.verified_head",
                   side_effect=[True, False]) as checkout, patch(
                "qa.visual_diagnostics.audit", return_value=([], 0, 40)):
            with self.assertRaisesRegex(CapturePlanError, "modified checked-out Git source"):
                diagnose(self.root, self.plan, self.sha, runner=rendered)

        self.assertEqual(checkout.call_count, 2)
        self.assertEqual(self.ledger.read_bytes(), original)
        output = self.root / "qa/visual-diagnostics" / self.sha
        self.assertFalse((output / "WEB-01.runtime.png").exists())
        self.assertFalse((output / "report.json").exists())

    def test_timed_out_renderer_mutating_source_also_aborts_batch(self):
        def timeout(command, *, timeout, **kwargs):
            raise subprocess.TimeoutExpired(command, timeout)

        with patch("qa.visual_diagnostics.verified_head",
                   side_effect=[True, False]), patch(
                "qa.visual_diagnostics.audit", return_value=([], 0, 40)):
            with self.assertRaisesRegex(CapturePlanError, "modified checked-out Git source"):
                diagnose(self.root, self.plan, self.sha, runner=timeout)

        self.assertFalse((self.root / "qa/visual-diagnostics" /
                          self.sha / "report.json").exists())

    def test_timed_out_renderer_with_clean_source_remains_unverified_not_fatal(self):
        def timeout(command, *, timeout, **kwargs):
            raise subprocess.TimeoutExpired(command, timeout)

        report = self.run_diagnostics(timeout)
        self.assertEqual(report["screens"]["WEB-01"]["status"], "renderer_failed")
        self.assertFalse(report["evidence_admitted"])

    def test_grouped_web_diagnostics_preserve_existing_admin_artifacts(self):
        # Grouped reports coexist; neither existing artifact nor reference/ledger
        # is overwritten. Synthetic fixture remains unit-test-only evidence.
        admin = self.root / "qa/visual-diagnostics" / self.sha
        admin.mkdir(parents=True)
        existing = admin / "report.json"
        existing.write_text('{"admin":true}\n', encoding="utf-8")

        def renderer(command, *, env, **kwargs):
            image = Image.new("RGBA", (2, 2), (42, 0, 0, 255))
            image.save(env["WALIDSITE_CAPTURE_PATH"])
            return subprocess.CompletedProcess(command, 0)

        report = self.run_diagnostics(renderer, group="web")
        path = self.root / "qa/visual-diagnostics" / (self.sha + "-web")
        self.assertEqual(report["screens"]["WEB-01"]["status"], "mismatch")
        self.assertTrue((path / "WEB-01.runtime.png").exists())
        self.assertTrue((path / "report.json").exists())
        self.assertEqual(existing.read_text(), '{"admin":true}\n')
        self.assertEqual(self.ledger.read_text(), '{"screens": {}}\n')

    def test_invalid_group_rejected_without_path_creation(self):
        with self.assertRaisesRegex(CapturePlanError, "Unknown diagnostic"):
            self.run_diagnostics(lambda *args, **kwargs: self.fail("called"),
                                 group="../escape")
        self.assertFalse((self.root / "qa/visual-diagnostics").exists())

    def test_binary_rgba_diagnostic_does_not_miss_alpha_channel(self):
        with tempfile.TemporaryDirectory() as tmp:
            first = Image.new("RGBA", (1, 1), (1, 2, 3, 255))
            second = Image.new("RGBA", (1, 1), (1, 2, 3, 254))
            count, total = _compare_rgba(first, second, Path(tmp) / "diff.png")
            self.assertEqual((count, total), (1, 1))


    def test_multiple_real_renderer_artifacts_stay_outside_checkout_during_attestation(self):
        # A live clean-checkout guard would reject the old implementation's
        # own untracked temporary PNG and previous-screen comparison outputs.
        Image.new("RGBA", (2, 2), (0, 0, 0, 255)).save(
            self.root / "assets/screen-reference/ref-02.png"
        )
        second = {
            **self.plan["captures"][0],
            "screen_id": "WEB-02",
            "route": "/about",
        }
        plan = {"version": 1, "captures": [self.plan["captures"][0], second]}
        checked = []

        def clean_source(root, commit):
            self.assertEqual(root, self.root.resolve())
            self.assertEqual(commit, self.sha)
            generated = root / "qa/visual-diagnostics"
            if generated.exists():
                self.assertEqual(
                    [p for p in generated.rglob("*") if p.is_file()],
                    [],
                    "Intermediate diagnostic outputs must not dirty Git status",
                )
            checked.append(commit)
            return True

        def renderer(command, *, env, **kwargs):
            destination = Path(env["WALIDSITE_CAPTURE_PATH"])
            self.assertFalse(
                destination.resolve().is_relative_to(self.root.resolve()),
                "Temporary screenshot must be rendered outside the Git checkout",
            )
            Image.new("RGBA", (2, 2), (12, 34, 56, 255)).save(destination)
            return subprocess.CompletedProcess(command, 0)

        with patch("qa.visual_diagnostics.verified_head", side_effect=clean_source), patch(
            "qa.visual_diagnostics.audit", return_value=([], 0, 40)
        ):
            report = diagnose(self.root, plan, self.sha, runner=renderer)

        self.assertGreaterEqual(len(checked), 4)
        self.assertEqual(
            {key: value["status"] for key, value in report["screens"].items()},
            {"WEB-01": "mismatch", "WEB-02": "mismatch"},
        )
        target = self.root / "qa/visual-diagnostics" / self.sha
        self.assertTrue((target / "WEB-01.runtime.png").is_file())
        self.assertTrue((target / "WEB-02.runtime.png").is_file())
        self.assertTrue((target / "WEB-01.difference-preview.png").is_file())
        self.assertTrue((target / "WEB-02.difference-preview.png").is_file())
        self.assertTrue((target / "report.json").is_file())
        self.assertEqual(self.ledger.read_text(), '{"screens": {}}\\n')


if __name__ == "__main__":
    unittest.main()
