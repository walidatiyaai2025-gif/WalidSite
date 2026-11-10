"""#481: synthetic unit fixtures validate the *gate*, not native runtime evidence."""
import io
import json
import tempfile
import unittest
from pathlib import Path

from PIL import Image

from qa.android_emulator_welcome_diagnostics import (
    WelcomeDiagnosticError, collect,
)
from qa.render_android_screenshot import AndroidCaptureError

SHA = "a" * 40


class WelcomeEmulatorDiagnosticTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        reference = self.root / "assets/screen-reference/mobile_welcome.png"
        reference.parent.mkdir(parents=True)
        Image.new("RGBA", (390, 844), (15, 20, 25, 255)).save(reference)
        self.output = self.root / "dist"
        self.output.mkdir()
        self.commands = []
        self.sleeps = []

    def command(self, serial, *parts):
        self.commands.append((serial, parts))
        if parts[:3] == ("shell", "am", "start"):
            return ("Starting: Intent\nStatus: ok\n"
                    "Activity: com.walidatiya.walidsite/.MainActivity\n")
        if parts == ("shell", "pidof", "com.walidatiya.walidsite"):
            return "1234"
        return ""

    def capture(self, *, env, color=(255, 0, 0, 255), fail=False):
        self.assertEqual(env["WALIDSITE_CAPTURE_ROUTE"], "/welcome")
        self.assertEqual(env["WALIDSITE_CAPTURE_LOCALE"], "en")
        self.assertEqual(env["WALIDSITE_CAPTURE_DPR"], "1")
        self.assertEqual(env["WALIDSITE_CAPTURE_WIDTH"], "390")
        self.assertEqual(env["WALIDSITE_CAPTURE_HEIGHT"], "844")
        self.assertEqual(env["WALIDSITE_MOBILE_ADB_SERIAL"], "emulator-5554")
        path = Path(env["WALIDSITE_CAPTURE_PATH"])
        if fail:
            path.write_bytes(b"not a valid screenshot")
        else:
            Image.new("RGBA", (390, 844), color).save(path)
        return path

    def run_capture(self, **overrides):
        return collect(
            self.root, self.output, source_sha=SHA, checked_sha=SHA,
            serial="emulator-5554", command=self.command,
            capture=self.capture, sleeper=self.sleeps.append,
            **overrides,
        )

    def test_true_pixel_mismatch_report_is_sha_bound_but_never_admitted(self):
        receipt = self.run_capture()
        self.assertEqual(receipt["source_sha"], SHA)
        self.assertEqual(receipt["screen_id"], "MOBILE-01")
        self.assertEqual(receipt["device_kind"], "disposable_android_emulator")
        self.assertEqual(receipt["mismatching_rgba_pixels"], 390 * 844)
        self.assertEqual(receipt["total_pixels"], 390 * 844)
        self.assertEqual(receipt["status"], "mismatch")
        self.assertIs(receipt["evidence_admitted"], False)
        self.assertIs(receipt["visual_fidelity_verified"], False)
        self.assertIs(receipt["production_ready"], False)
        self.assertEqual(receipt["native_resolution"], [390, 844])
        self.assertEqual(self.sleeps, [2.0])
        self.assertEqual(json.loads(
            (self.output / "welcome-diagnostics.json").read_text()), receipt)
        self.assertTrue((self.output / "welcome-runtime.png").is_file())
        self.assertTrue((self.output / "welcome-difference.png").is_file())
        self.assertEqual(
            self.commands[0][1], ("shell", "wm", "size", "390x844"))
        self.assertEqual(
            self.commands[1][1], ("shell", "wm", "density", "160"))
        self.assertEqual(
            self.commands[2][1],
            ("shell", "am", "force-stop", "com.walidatiya.walidsite"))

    def test_equal_synthetic_pixels_still_do_not_establish_attestation(self):
        def same_image(*, env):
            return self.capture(env=env, color=(15, 20, 25, 255))
        receipt = collect(
            self.root, self.output, source_sha=SHA, checked_sha=SHA,
            serial="emulator-5554", command=self.command,
            capture=same_image, sleeper=lambda _: None,
        )
        self.assertEqual(receipt["mismatching_rgba_pixels"], 0)
        self.assertEqual(receipt["status"], "exact_pixels_unattested")
        self.assertFalse(receipt["evidence_admitted"])
        self.assertFalse(receipt["visual_fidelity_verified"])
        self.assertFalse((self.output / "welcome-difference.png").exists())

    def test_wrong_checkout_or_bad_serial_rejected_before_adb_or_capture(self):
        for bad in (
            {"source_sha": "b" * 40},
            {"source_sha": "invalid"},
            {"checked_sha": "b" * 40},
            {"serial": "emulator-5554; env"},
        ):
            with self.subTest(bad=bad):
                args = {"source_sha": SHA, "checked_sha": SHA,
                        "serial": "emulator-5554"}
                args.update(bad)
                with self.assertRaises(WelcomeDiagnosticError):
                    collect(self.root, self.output, command=self.command,
                            capture=self.capture, sleeper=lambda _: None, **args)
        self.assertEqual(self.commands, [])
        self.assertEqual(list(self.output.iterdir()), [])

    def test_existing_report_or_screenshot_never_overwritten(self):
        for name in ("welcome-runtime.png", "welcome-diagnostics.json",
                     "welcome-difference.png"):
            with self.subTest(name=name):
                path = self.output / name
                path.write_text("existing", encoding="utf-8")
                with self.assertRaises(WelcomeDiagnosticError):
                    self.run_capture()
                self.assertEqual(path.read_text(), "existing")
                path.unlink()
        self.assertEqual(self.commands, [])

    def test_broken_or_misidentified_runtime_never_leaves_fake_evidence(self):
        def invalid_png(*, env):
            return self.capture(env=env, fail=True)
        for fn in (invalid_png,
                   lambda *, env: (_ for _ in ()).throw(
                       WelcomeDiagnosticError("Wrong native screen identity"))):
            with self.subTest(fn=str(fn)):
                with self.assertRaises((OSError, ValueError)):
                    collect(self.root, self.output, source_sha=SHA,
                            checked_sha=SHA, serial="emulator-5554",
                            command=self.command, capture=fn, sleeper=lambda _: None)
                self.assertEqual(list(self.output.iterdir()), [])

    def test_cold_emulator_foreground_retries_until_real_native_capture(self):
        attempted = []
        def eventually_ready(*, env):
            attempted.append(True)
            if len(attempted) < 3:
                raise AndroidCaptureError(
                    "WalidSite Flutter is not the foreground application")
            return self.capture(env=env)
        receipt = collect(
            self.root, self.output, source_sha=SHA, checked_sha=SHA,
            serial="emulator-5554", command=self.command,
            capture=eventually_ready, sleeper=self.sleeps.append)
        self.assertEqual(len(attempted), 3)
        # Real app activation is performed again only after a proved
        # foreground-not-ready failure; no screenshot assertion is bypassed.
        starts = [args for _, args in self.commands
                  if args[:3] == ("shell", "am", "start")]
        self.assertEqual(len(starts), 3)
        self.assertEqual(self.sleeps, [2.0, 2.0, 2.0])
        self.assertEqual(receipt["status"], "mismatch")
        self.assertFalse(receipt["evidence_admitted"])

    def test_persistent_background_screen_fails_closed_without_false_png(self):
        attempts = []
        def remains_background(*, env):
            attempts.append(True)
            raise AndroidCaptureError(
                "WalidSite Flutter is not the foreground application")
        with self.assertRaisesRegex(WelcomeDiagnosticError,
                                    "focus_field_absent"):
            collect(self.root, self.output, source_sha=SHA, checked_sha=SHA,
                    serial="emulator-5554", command=self.command,
                    capture=remains_background, sleeper=self.sleeps.append)
        self.assertEqual(len(attempts), 5)
        starts = [args for _, args in self.commands
                  if args[:3] == ("shell", "am", "start")]
        self.assertEqual(len(starts), 5)
        self.assertEqual(self.sleeps, [2.0] * 5)
        self.assertEqual(list(self.output.iterdir()), [])

    def test_foreground_failure_reports_only_safe_category(self):
        attempts = []
        def still_background(*, env):
            attempts.append(True)
            raise AndroidCaptureError(
                "WalidSite Flutter is not the foreground application")
        def system_overlay(serial, *parts):
            if parts == ("shell", "dumpsys", "window", "windows"):
                return "mCurrentFocus=Window{123 u0 com.android.systemui/.Overlay}"
            return self.command(serial, *parts)
        with self.assertRaisesRegex(WelcomeDiagnosticError,
                                    "system_ui_foreground"):
            collect(self.root, self.output, source_sha=SHA, checked_sha=SHA,
                    serial="emulator-5554", command=system_overlay,
                    capture=still_background, sleeper=lambda _: None)
        self.assertEqual(len(attempts), 5)
        self.assertEqual(list(self.output.iterdir()), [])

    def test_unrelated_capture_failure_is_never_retried(self):
        attempts = []
        def wrong_app_identity(*, env):
            attempts.append(True)
            raise AndroidCaptureError("Unexpected device screenshot dimensions")
        with self.assertRaises(AndroidCaptureError):
            collect(self.root, self.output, source_sha=SHA, checked_sha=SHA,
                    serial="emulator-5554", command=self.command,
                    capture=wrong_app_identity, sleeper=self.sleeps.append)
        self.assertEqual(len(attempts), 1)
        self.assertEqual(self.sleeps, [2.0])
        self.assertEqual(list(self.output.iterdir()), [])

    def test_restart_failure_is_not_reported_as_welcome_capture(self):
        def bad_start(serial, *parts):
            if parts[:3] == ("shell", "am", "start"):
                return "Status: failed"
            return self.command(serial, *parts)
        with self.assertRaisesRegex(WelcomeDiagnosticError, "relaunch"):
            collect(self.root, self.output, source_sha=SHA, checked_sha=SHA,
                    serial="emulator-5554", command=bad_start,
                    capture=self.capture, sleeper=lambda _: None)
        self.assertEqual(list(self.output.iterdir()), [])


if __name__ == "__main__":
    unittest.main()
