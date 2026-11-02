"""QA-only Android screen identity and real-device screenshot adapter unit regressions.

Synthetic XML and PNG bytes below test the gate. They never constitute
screenshots of WalidSite or fulfill visual evidence.
"""
import io
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from PIL import Image

from qa.render_android_screenshot import (
    AndroidCaptureError, HEADINGS, PACKAGE, _verify_heading, render, main,
)


class AndroidScreenCaptureTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.output = self.root / "real-device.png"
        self.env = {
            "WALIDSITE_CAPTURE_ROUTE": "/home",
            "WALIDSITE_CAPTURE_LOCALE": "en",
            "WALIDSITE_MOBILE_ADB_SERIAL": "emulator-5554",
            "WALIDSITE_CAPTURE_WIDTH": "1440",
            "WALIDSITE_CAPTURE_HEIGHT": "860",
            "WALIDSITE_CAPTURE_DPR": "1",
            "WALIDSITE_CAPTURE_PATH": str(self.output),
        }
        pic = Image.new("RGBA", (1440, 860), (70, 80, 90, 255))
        payload = io.BytesIO()
        pic.save(payload, format="PNG")
        self.png = payload.getvalue()

    @staticmethod
    def hierarchy(title, bounds="[0,20][640,100]", visible=True):
        import xml.sax.saxutils
        safe = xml.sax.saxutils.escape(title, {'"': '&quot;'})
        return (f'UI dump\n<hierarchy rotation="0"><node text="{safe}" '
                f'bounds="{bounds}" visible-to-user="{str(visible).lower()}" />'
                '</hierarchy>\n').encode()

    def runner(self, *, title="Waleed Tech Companion", foreground=True,
               state=b"device\n", image=None, xml=None, exit_code=0):
        calls = []

        def run(args, **kwargs):
            calls.append(args)
            self.assertEqual(args[:3], ["adb", "-s", "emulator-5554"])
            self.assertNotIn("shell", kwargs)
            tail = args[3:]
            if tail == ["get-state"]:
                out = state
            elif tail == ["shell", "dumpsys", "window", "windows"]:
                app = PACKAGE if foreground else "com.other.app"
                out = (f"mCurrentFocus=Window{{abc u0 {app}/.MainActivity}}").encode()
            elif tail == ["shell", "uiautomator", "dump", "/dev/tty"]:
                out = xml if xml is not None else self.hierarchy(title)
            elif tail == ["exec-out", "screencap", "-p"]:
                out = self.png if image is None else image
            else:
                self.fail(f"Unexpected Android command: {tail}")
            return SimpleNamespace(stdout=out, stderr=b"PRIVATE DATA", returncode=exit_code)

        return run, calls

    def test_real_device_capture_requires_all_four_checks_before_writing_png(self):
        runner, calls = self.runner()
        path = render(self.env, runner)
        self.assertEqual(path, self.output)
        self.assertEqual(path.read_bytes(), self.png)
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        self.assertEqual([parts[3:] for parts in calls], [
            ["get-state"], ["shell", "dumpsys", "window", "windows"],
            ["shell", "uiautomator", "dump", "/dev/tty"],
            ["exec-out", "screencap", "-p"],
        ])

    def test_supported_routes_are_real_flutter_headings_not_contract_labels(self):
        self.assertEqual(len(HEADINGS), 8)
        for route, labels in HEADINGS.items():
            with self.subTest(route=route):
                self.assertEqual(len(labels), 2)
                self.assertTrue(all(labels))
                self.env["WALIDSITE_CAPTURE_ROUTE"] = route
                self.env["WALIDSITE_CAPTURE_LOCALE"] = "ar"
                runner, _ = self.runner(title=labels[1])
                self.assertEqual(render(self.env, runner), self.output)
                self.output.unlink()

    def test_rejects_unsupported_routes_and_untrusted_geometry_before_adb(self):
        cases = [
            ("WALIDSITE_CAPTURE_ROUTE", "/welcome"),
            ("WALIDSITE_CAPTURE_ROUTE", "/profile"),
            ("WALIDSITE_CAPTURE_ROUTE", "/solutions/123"),
            ("WALIDSITE_CAPTURE_ROUTE", "/solutions/{id}?secret=x"),
            ("WALIDSITE_CAPTURE_ROUTE", "/saved"),
            ("WALIDSITE_CAPTURE_ROUTE", "/home?secret=x"),
            ("WALIDSITE_CAPTURE_LOCALE", "fr"),
            ("WALIDSITE_MOBILE_ADB_SERIAL", ""),
            ("WALIDSITE_MOBILE_ADB_SERIAL", "emulator-5554;cat /secret"),
            ("WALIDSITE_MOBILE_ADB_SERIAL", "../device"),
            ("WALIDSITE_CAPTURE_WIDTH", "0"),
            ("WALIDSITE_CAPTURE_HEIGHT", "-1"),
            ("WALIDSITE_CAPTURE_DPR", "3"),
            ("WALIDSITE_CAPTURE_DPR", "nan"),
            ("WALIDSITE_CAPTURE_PATH", "relative.png"),
        ]
        for key, value in cases:
            with self.subTest(key=key, value=value):
                runner = Mock()
                with self.assertRaises((AndroidCaptureError, ValueError)):
                    render(dict(self.env, **{key: value}), runner)
                runner.assert_not_called()

    def test_foreground_app_wrong_language_and_malformed_hierarchy_never_write(self):
        cases = [
            {"foreground": False}, {"state": b"offline"},
            {"title": "Unrelated screen"},
            {"xml": self.hierarchy("Waleed Tech Companion", bounds="[0,300][500,350]")},
            {"xml": self.hierarchy("Waleed Tech Companion", visible=False)},
            {"xml": b"<hierarchy><node>"},
            {"xml": b"<hierarchy>" + b"x" * (8 * 1024 * 1024)},
            {"exit_code": 1},
        ]
        for case in cases:
            with self.subTest(case=list(case)):
                runner, _ = self.runner(**case)
                with self.assertRaises(AndroidCaptureError):
                    render(self.env, runner)
                self.assertFalse(self.output.exists())

    def test_screen_pixels_must_be_valid_png_at_unscaled_reference_size(self):
        wrong = Image.new("RGBA", (100, 200))
        stream = io.BytesIO()
        wrong.save(stream, "PNG")
        for content in (b"private-data", stream.getvalue(), b""):
            with self.subTest(bytes=len(content)):
                runner, _ = self.runner(image=content)
                with self.assertRaises(AndroidCaptureError):
                    render(self.env, runner)
                self.assertFalse(self.output.exists())

    def test_existing_output_and_symlink_are_never_overwritten(self):
        self.output.write_bytes(b"do not overwrite")
        runner = Mock()
        with self.assertRaises(AndroidCaptureError):
            render(self.env, runner)
        self.assertEqual(self.output.read_bytes(), b"do not overwrite")
        self.output.unlink()
        destination = self.root / "outside"
        destination.write_text("secret")
        self.output.symlink_to(destination)
        with self.assertRaises(AndroidCaptureError):
            render(self.env, runner)
        self.assertEqual(destination.read_text(), "secret")
        runner.assert_not_called()

    def test_title_must_be_visible_and_near_real_appbar(self):
        title = HEADINGS["/home"][0]
        _verify_heading(self.hierarchy(title), title, 860)
        for xml in (self.hierarchy(title, "[0,450][600,520]"),
                    self.hierarchy(title, "[0,20][600,90]", False),
                    self.hierarchy("Wrong title")):
            with self.assertRaises(AndroidCaptureError):
                _verify_heading(xml, title, 860)

    def test_cli_redacts_adb_diagnostics(self):
        import contextlib
        import sys
        error = io.StringIO()
        with patch("qa.render_android_screenshot.render",
                   side_effect=RuntimeError("secret ADB device information")), \
             contextlib.redirect_stderr(error):
            self.assertEqual(main(), 1)
        self.assertNotIn("secret", error.getvalue().lower())
        self.assertIn("NOT VERIFIED", error.getvalue())


if __name__ == "__main__":
    unittest.main()
