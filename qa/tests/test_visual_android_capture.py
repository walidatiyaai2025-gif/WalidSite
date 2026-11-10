"""QA-only Android screen identity and real-device screenshot adapter unit regressions.

Synthetic XML and PNG bytes below test the gate. They never constitute
screenshots of WalidSite or fulfill visual evidence.
"""
import io
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from PIL import Image

from qa.render_android_screenshot import (
    AndroidCaptureError, HEADINGS, NATIVE_SCREENS, PACKAGE,
    _verify_heading, _verify_native_identity, render, main,
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
            ["shell", "dumpsys", "window", "windows"],
            ["shell", "uiautomator", "dump", "/dev/tty"],
        ])

    def test_android35_missing_subsection_focus_uses_full_window_dump(self):
        original, _ = self.runner()
        calls = []

        def android35(args, **kwargs):
            tail = args[3:]
            calls.append(tail)
            if tail == ["shell", "dumpsys", "window", "windows"]:
                return SimpleNamespace(stdout=b"WindowManager windows: no focus field",
                                       stderr=b"private", returncode=0)
            if tail == ["shell", "dumpsys", "window"]:
                return SimpleNamespace(
                    stdout=(b"WindowManager full state\n"
                            b"  mCurrentFocus=Window{abc u0 "
                            + PACKAGE.encode() + b"/.MainActivity}"),
                    stderr=b"private", returncode=0)
            return original(args, **kwargs)

        self.assertEqual(render(self.env, android35), self.output)
        self.assertEqual(self.output.read_bytes(), self.png)
        self.assertEqual(calls.count(["shell", "dumpsys", "window"]), 2)

    def test_full_window_dump_missing_ambiguous_or_foreign_focus_fails_closed(self):
        for focus in (
            b"WindowManager full state: focus unspecified",
            b"mCurrentFocus=Window{abc u0 com.android.systemui/.Overlay}",
            (b"mCurrentFocus=Window{abc u0 " + PACKAGE.encode()
             + b"/.MainActivity}\nmCurrentFocus=Window{other u0 "
             + PACKAGE.encode() + b"/.MainActivity}"),
        ):
            with self.subTest(focus=focus[:50]):
                original, _ = self.runner()

                def android35(args, **kwargs):
                    tail = args[3:]
                    if tail == ["shell", "dumpsys", "window", "windows"]:
                        return SimpleNamespace(stdout=b"No mCurrentFocus here",
                                               stderr=b"private", returncode=0)
                    if tail == ["shell", "dumpsys", "window"]:
                        return SimpleNamespace(stdout=focus, stderr=b"private",
                                               returncode=0)
                    return original(args, **kwargs)

                with self.assertRaisesRegex(AndroidCaptureError, "foreground"):
                    render(self.env, android35)
                self.assertFalse(self.output.exists())

    def test_android35_tty_dump_uses_unique_private_device_files_and_cleans_them(self):
        original, _ = self.runner()
        paths, removed = [], []

        def android35(args, **kwargs):
            tail = args[3:]
            if tail == ["shell", "uiautomator", "dump", "/dev/tty"]:
                return SimpleNamespace(stdout=b"UI hierarchy dumped to /dev/tty",
                                       stderr=b"private", returncode=0)
            if len(tail) == 4 and tail[:3] == ["shell", "uiautomator", "dump"]:
                self.assertRegex(tail[3],
                    r"^/data/local/tmp/walidsite-visual-[0-9a-f]{24}\.xml$")
                paths.append(tail[3])
                return SimpleNamespace(stdout=b"dumped", stderr=b"private", returncode=0)
            if len(tail) == 3 and tail[:2] == ["exec-out", "cat"]:
                self.assertIn(tail[2], paths)
                return SimpleNamespace(stdout=self.hierarchy("Waleed Tech Companion"),
                                       stderr=b"private", returncode=0)
            if len(tail) == 4 and tail[:3] == ["shell", "rm", "-f"]:
                removed.append(tail[3])
                self.assertIn(tail[3], paths)
                return SimpleNamespace(stdout=b"", stderr=b"private", returncode=0)
            return original(args, **kwargs)

        self.assertEqual(render(self.env, android35), self.output)
        self.assertEqual(self.output.read_bytes(), self.png)
        self.assertEqual(len(paths), 2)
        self.assertNotEqual(paths[0], paths[1])
        self.assertEqual(removed, paths)

    def test_android35_missing_device_hierarchy_rejects_without_png(self):
        original, _ = self.runner()
        paths, removed = [], []

        def android35(args, **kwargs):
            tail = args[3:]
            if tail == ["shell", "uiautomator", "dump", "/dev/tty"]:
                return SimpleNamespace(stdout=b"UI dump unavailable",
                                       stderr=b"private", returncode=0)
            if len(tail) == 4 and tail[:3] == ["shell", "uiautomator", "dump"]:
                paths.append(tail[3])
                return SimpleNamespace(stdout=b"", stderr=b"private", returncode=0)
            if len(tail) == 3 and tail[:2] == ["exec-out", "cat"]:
                return SimpleNamespace(stdout=b"private invalid XML",
                                       stderr=b"secret", returncode=0)
            if len(tail) == 4 and tail[:3] == ["shell", "rm", "-f"]:
                removed.append(tail[3])
                return SimpleNamespace(stdout=b"", stderr=b"private", returncode=0)
            return original(args, **kwargs)

        with self.assertRaisesRegex(AndroidCaptureError, "hierarchy"):
            render(self.env, android35)
        self.assertEqual(len(paths), 1)
        self.assertEqual(removed, paths)
        self.assertFalse(self.output.exists())

    def test_android35_cleanup_failure_never_admits_screenshot(self):
        original, _ = self.runner()

        def cleanup_fails(args, **kwargs):
            tail = args[3:]
            if tail == ["shell", "uiautomator", "dump", "/dev/tty"]:
                return SimpleNamespace(stdout=b"status only", stderr=b"private", returncode=0)
            if len(tail) == 4 and tail[:3] == ["shell", "uiautomator", "dump"]:
                return SimpleNamespace(stdout=b"dumped", stderr=b"private", returncode=0)
            if len(tail) == 3 and tail[:2] == ["exec-out", "cat"]:
                return SimpleNamespace(stdout=self.hierarchy("Waleed Tech Companion"),
                                       stderr=b"private", returncode=0)
            if len(tail) == 4 and tail[:3] == ["shell", "rm", "-f"]:
                return SimpleNamespace(stdout=b"", stderr=b"private", returncode=1)
            return original(args, **kwargs)

        with self.assertRaises(AndroidCaptureError):
            render(self.env, cleanup_fails)
        self.assertFalse(self.output.exists())

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

    @staticmethod
    def native_hierarchy(identifier, title, title_bounds="[0,20][600,110]",
                         marker_bounds="[0,0][1440,860]", marker_visible=True):
        from xml.sax.saxutils import escape
        title = escape(title, {'"': '&quot;'})
        return (
            f'UI dump\n<hierarchy rotation="0"><node resource-id="{identifier}" '
            f'bounds="{marker_bounds}" visible-to-user="{str(marker_visible).lower()}"/>'
            f'<node text="{title}" bounds="{title_bounds}" '
            'visible-to-user="true"/></hierarchy>\n'
        ).encode()

    def test_exactly_thirteen_contract_routes_have_genuine_attestation(self):
        contracts = json.loads(
            (Path(__file__).resolve().parents[2] / "contracts" / "screens.json")
            .read_text(encoding="utf-8")
        )["screens"]
        routes = {row["route"] for row in contracts
                  if row["id"].startswith("MOBILE-")}
        self.assertEqual(len(routes), 13)
        self.assertEqual(routes, set(HEADINGS) | set(NATIVE_SCREENS))
        self.assertEqual(set(HEADINGS).intersection(NATIVE_SCREENS), set())

    def test_five_dynamic_screens_accept_only_actual_native_and_locale(self):
        for route, (identifier, titles, top) in NATIVE_SCREENS.items():
            for locale, title in (("en", titles[0]), ("ar", titles[1])):
                with self.subTest(route=route, locale=locale):
                    # Dynamic routes must identify an actual resource, not the
                    # unresolved canonical {id} template.
                    self.env["WALIDSITE_CAPTURE_ROUTE"] = (
                        route.replace("{id}", "verified-123")
                    )
                    self.env["WALIDSITE_CAPTURE_LOCALE"] = locale
                    runner, calls = self.runner(
                        xml=self.native_hierarchy(
                            identifier, title,
                            title_bounds="[0,20][600,110]" if top
                            else "[0,300][900,380]"),
                    )
                    self.assertEqual(render(self.env, runner), self.output)
                    self.assertEqual(self.output.read_bytes(), self.png)
                    self.output.unlink()
                    self.assertEqual(len(calls), 6)

    def test_native_marker_must_be_visible_unique_and_in_viewport(self):
        identifier, titles, top = NATIVE_SCREENS["/saved"]
        good = self.native_hierarchy(identifier, titles[0])
        _verify_native_identity(good, identifier, titles[0], 1440, 860, top)
        for xml in (
            self.native_hierarchy("walidsite.mobile.account.locked", titles[0]),
            self.native_hierarchy("walidsite.mobile.12", titles[0]),
            self.native_hierarchy(identifier, titles[1]),
            self.native_hierarchy(identifier, titles[0], marker_visible=False),
            self.native_hierarchy(identifier, titles[0], marker_bounds="[0,0][1441,860]"),
            self.native_hierarchy(identifier, titles[0], title_bounds="[0,300][500,380]"),
            good.replace(b"</hierarchy>", (
                f'<node resource-id="{identifier}" bounds="[0,0][1440,860]" '
                'visible-to-user="true"/></hierarchy>').encode()),
        ):
            with self.subTest(xml=xml[:120]):
                with self.assertRaises(AndroidCaptureError):
                    _verify_native_identity(xml, identifier, titles[0], 1440, 860, top)

    def test_native_marker_switch_during_screencap_never_creates_evidence(self):
        self.env["WALIDSITE_CAPTURE_ROUTE"] = "/profile"
        identifier, titles, _ = NATIVE_SCREENS["/profile"]
        source, calls = self.runner(xml=self.native_hierarchy(identifier, titles[0]))
        seen = [0]

        def switched(args, **kwargs):
            result = source(args, **kwargs)
            if args[3:] == ["shell", "uiautomator", "dump", "/dev/tty"]:
                seen[0] += 1
                if seen[0] == 2:
                    return SimpleNamespace(
                        stdout=self.native_hierarchy("walidsite.mobile.11", titles[0]),
                        stderr=b"hidden", returncode=0,
                    )
            return result

        with self.assertRaises(AndroidCaptureError):
            render(self.env, switched)
        self.assertEqual(seen[0], 2)
        self.assertFalse(self.output.exists())

    def test_rejects_unsupported_routes_and_untrusted_geometry_before_adb(self):
        cases = [
            ("WALIDSITE_CAPTURE_ROUTE", "/welcome?lang=ar"),
            ("WALIDSITE_CAPTURE_ROUTE", "/solutions/{id}"),
            ("WALIDSITE_CAPTURE_ROUTE", "/solutions/%2e%2e"),
            ("WALIDSITE_CAPTURE_ROUTE", "/solutions/a/other"),
            ("WALIDSITE_CAPTURE_ROUTE", "/runbooks/{id}/offline"),
            ("WALIDSITE_CAPTURE_ROUTE", "/solutions/{id}?secret=x"),
            ("WALIDSITE_CAPTURE_ROUTE", "/profile?auth=false"),
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

    def test_foreground_switch_after_screenshot_rejects_device_image(self):
        original, calls = self.runner()
        focus_checks = [0]

        def switched(args, **kwargs):
            result = original(args, **kwargs)
            if args[3:] == ["shell", "dumpsys", "window", "windows"]:
                focus_checks[0] += 1
                if focus_checks[0] == 2:
                    return SimpleNamespace(stdout=b"mCurrentFocus=Window{a u0 other.app/.MainActivity}",
                                           stderr=b"SECRET", returncode=0)
            return result

        with self.assertRaisesRegex(AndroidCaptureError, "changed"):
            render(self.env, switched)
        self.assertEqual(focus_checks[0], 2)
        self.assertFalse(self.output.exists())

    def test_screen_title_switch_after_screenshot_rejects_device_image(self):
        original, calls = self.runner()
        seen = [0]

        def switched(args, **kwargs):
            result = original(args, **kwargs)
            if args[3:] == ["shell", "uiautomator", "dump", "/dev/tty"]:
                seen[0] += 1
                if seen[0] == 2:
                    return SimpleNamespace(stdout=self.hierarchy("Different screen"),
                                           stderr=b"SECRET", returncode=0)
            return result

        with self.assertRaisesRegex(AndroidCaptureError, "title"):
            render(self.env, switched)
        self.assertEqual(seen[0], 2)
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
