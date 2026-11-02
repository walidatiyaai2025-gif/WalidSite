#!/usr/bin/env python3
"""Render genuine Android Flutter pixels for canonical, independently identifiable screens.

Requires a real foreground WalidSite Android app. The operator navigates to
the destination using the running app; this does not bypass login or fabricate
screen state. Unsupported routes fail closed until a verifiable UI identity
exists. The separate visual evidence validator decides zero-pixel parity.
"""
import io
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

from PIL import Image, UnidentifiedImageError

PACKAGE = "com.example.waleed_tech_companion"
# These must be *visible AppBar titles in the actual Flutter source*, not
# off-screen home navigation cards. Require title in the top of the viewport.
HEADINGS = {
    "/home": ("Waleed Tech Companion", "رفيق وليد التقني"),
    "/tools/ai-troubleshooter": ("AI Troubleshooter", "مساعد تشخيص الأعطال"),
    "/runbooks": ("Offline runbook library", "مكتبة الأدلة المحلية"),
    "/articles": ("Technical articles", "المقالات التقنية"),
    "/learn": ("Learning paths", "مسارات التعلّم"),
    "/learn/interview": ("Interview trainer", "تدريب المقابلات"),
    "/github": ("My GitHub", "مشاريعي على GitHub"),
    "/settings": ("Preferences", "التفضيلات"),
}
# Native Flutter Semantics.identifier is exposed to Android UIAutomator as
# resource-id, not as visible labels. The target route identity must be
# present on the *currently rendered* view, never inferred from a home card.
# Dynamic solution detail is tagged only after a verified public fetch;
# Saved/Profile tags require a valid authenticated account and selected tab.
NATIVE_SCREENS = {
    "/welcome": ("walidsite.mobile.01",
                 ("Welcome to Waleed Tech Companion", "مرحبًا بك في رفيق وليد التقني"),
                 False),
    "/solutions/{id}": ("walidsite.mobile.04",
                        ("Solution detail", "تفاصيل الحل"), True),
    "/runbooks/{id}/offline": ("walidsite.mobile.06",
                              ("General guidance steps", "خطوات توضيحية عامة"), False),
    "/saved": ("walidsite.mobile.11",
               ("Saved resources", "المحفوظات"), True),
    "/profile": ("walidsite.mobile.12",
                 ("Personal profile", "الملف الشخصي"), True),
}
MAX_CAPTURE_BYTES = 64 * 1024 * 1024
MAX_ACCESSIBILITY_BYTES = 8 * 1024 * 1024


class AndroidCaptureError(ValueError):
    pass


def _run(serial: str, parts: list[str], runner=subprocess.run) -> bytes:
    try:
        result = runner(
            ["adb", "-s", serial, *parts],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            check=False, timeout=25,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise AndroidCaptureError("Android debug bridge did not complete") from exc
    if result.returncode != 0 or not isinstance(result.stdout, bytes):
        raise AndroidCaptureError("Android debug bridge returned an error")
    return result.stdout


def _verify_heading(xml: bytes, heading: str, height: int) -> None:
    """A visible AppBar, not a card or fabricated claimed route, is required."""
    if len(xml) > MAX_ACCESSIBILITY_BYTES:
        raise AndroidCaptureError("Accessibility evidence exceeds safe bounds")
    start = xml.find(b"<hierarchy")
    end = xml.rfind(b"</hierarchy>")
    if start < 0 or end < start:
        raise AndroidCaptureError("No Android accessibility hierarchy available")
    try:
        root = ET.fromstring(xml[start:end + len(b"</hierarchy>")])
    except ET.ParseError as exc:
        raise AndroidCaptureError("Invalid Android accessibility hierarchy") from exc
    if root.tag != "hierarchy":
        raise AndroidCaptureError("Unexpected accessibility document")
    for node in root.iter("node"):
        if heading not in (node.attrib.get("text"), node.attrib.get("content-desc")):
            continue
        bounds = node.attrib.get("bounds", "")
        match = re.fullmatch(r"\[(\d+),(\d+)\]\[(\d+),(\d+)\]", bounds)
        if (match and int(match[2]) <= height // 5
                and int(match[4]) > int(match[2])
                and int(match[3]) > int(match[1])
                and node.attrib.get("visible-to-user", "true") == "true"):
            return
    raise AndroidCaptureError("Current visible screen title does not match canonical route")



def _verify_native_identity(xml: bytes, identifier: str, heading: str,
                            width: int, height: int, top_heading: bool) -> None:
    """Require one visible native route marker AND visible localized text."""
    if len(xml) > MAX_ACCESSIBILITY_BYTES:
        raise AndroidCaptureError("Accessibility evidence exceeds safe bounds")
    start, end = xml.find(b"<hierarchy"), xml.rfind(b"</hierarchy>")
    if start < 0 or end < start:
        raise AndroidCaptureError("No Android accessibility hierarchy available")
    try:
        root = ET.fromstring(xml[start:end + len(b"</hierarchy>")])
    except ET.ParseError as exc:
        raise AndroidCaptureError("Invalid Android accessibility hierarchy") from exc
    if root.tag != "hierarchy":
        raise AndroidCaptureError("Unexpected accessibility document")

    marker_count = 0
    has_heading = False
    for node in root.iter("node"):
        bounds = node.attrib.get("bounds", "")
        box = re.fullmatch(r"\[(\d+),(\d+)\]\[(\d+),(\d+)\]", bounds)
        visible = node.attrib.get("visible-to-user") == "true"
        inside = bool(box and 0 <= int(box[1]) < int(box[3]) <= width
                      and 0 <= int(box[2]) < int(box[4]) <= height)
        if node.attrib.get("resource-id") == identifier:
            if not visible or not inside:
                raise AndroidCaptureError("Native screen marker is not visible")
            marker_count += 1
        if heading in (node.attrib.get("text"), node.attrib.get("content-desc")):
            if visible and inside and (not top_heading or int(box[2]) <= height // 5):
                has_heading = True
    if marker_count != 1:
        raise AndroidCaptureError("Canonical Flutter native screen identity is missing or ambiguous")
    if not has_heading:
        raise AndroidCaptureError("Native screen locale or visible title is not verified")


def render(env=None, runner=subprocess.run) -> Path:
    env = os.environ if env is None else env
    route = env.get("WALIDSITE_CAPTURE_ROUTE")
    if route not in HEADINGS and route not in NATIVE_SCREENS:
        raise AndroidCaptureError("This Flutter route has no independently verified identity")
    locale = env.get("WALIDSITE_CAPTURE_LOCALE")
    if locale not in ("en", "ar"):
        raise AndroidCaptureError("Capture locale is invalid")
    serial = env.get("WALIDSITE_MOBILE_ADB_SERIAL")
    if not isinstance(serial, str) or re.fullmatch(r"[A-Za-z0-9_.:-]{1,100}", serial) is None:
        raise AndroidCaptureError("Explicit safe device serial required")
    try:
        width = int(env.get("WALIDSITE_CAPTURE_WIDTH", ""))
        height = int(env.get("WALIDSITE_CAPTURE_HEIGHT", ""))
        dpr = float(env.get("WALIDSITE_CAPTURE_DPR", ""))
    except (ValueError, TypeError) as exc:
        raise AndroidCaptureError("Invalid requested screenshot geometry") from exc
    if (width <= 0 or height <= 0 or width > 10000 or height > 10000
            or not 0.5 <= dpr <= 8
            or width / dpr != int(width / dpr)
            or height / dpr != int(height / dpr)):
        raise AndroidCaptureError("Invalid reference dimensions or DPR")
    output_value = env.get("WALIDSITE_CAPTURE_PATH")
    if not isinstance(output_value, str) or not output_value:
        raise AndroidCaptureError("Capture destination required")
    output = Path(output_value)
    if (not output.is_absolute() or output.exists() or output.is_symlink()
            or not output.parent.is_dir() or output.parent.is_symlink()):
        raise AndroidCaptureError("Capture must write to a fresh trusted absolute path")

    if _run(serial, ["get-state"], runner).strip() != b"device":
        raise AndroidCaptureError("Selected Android device is not connected")
    window = _run(serial, ["shell", "dumpsys", "window", "windows"], runner)
    if len(window) > MAX_ACCESSIBILITY_BYTES:
        raise AndroidCaptureError("Invalid foreground application evidence")
    focused_lines = [line.decode("utf-8", errors="replace")
                     for line in window.splitlines() if b"mCurrentFocus=" in line]
    if (len(focused_lines) != 1
            or not re.search(r"\bmCurrentFocus=Window\{[^}]*\b" +
                             re.escape(PACKAGE) + r"/", focused_lines[0])):
        raise AndroidCaptureError("WalidSite Flutter is not the foreground application")

    hierarchy = _run(serial, ["shell", "uiautomator", "dump", "/dev/tty"], runner)
    if route in HEADINGS:
        _verify_heading(hierarchy, HEADINGS[route][0 if locale == "en" else 1], height)
    else:
        identifier, titles, top = NATIVE_SCREENS[route]
        _verify_native_identity(hierarchy, identifier,
                                titles[0 if locale == "en" else 1], width, height, top)
    pixels = _run(serial, ["exec-out", "screencap", "-p"], runner)
    if not pixels or len(pixels) > MAX_CAPTURE_BYTES:
        raise AndroidCaptureError("Invalid or oversized device screenshot")
    try:
        with Image.open(io.BytesIO(pixels)) as image:
            if image.format != "PNG" or image.size != (width, height):
                raise AndroidCaptureError("Device screenshot differs from immutable reference size")
            image.verify()
    except (OSError, UnidentifiedImageError, Image.DecompressionBombError) as exc:
        raise AndroidCaptureError("Device screenshot could not be decoded") from exc

    # Guard against an app or screen switch while the ADB screenshot command
    # was running. Both the focused window and visible localized heading must
    # remain the same after the native pixels were captured.
    window_after = _run(serial, ["shell", "dumpsys", "window", "windows"], runner)
    if len(window_after) > MAX_ACCESSIBILITY_BYTES:
        raise AndroidCaptureError("Invalid post-capture foreground evidence")
    focused_after = [line.decode("utf-8", errors="replace")
                     for line in window_after.splitlines() if b"mCurrentFocus=" in line]
    if focused_after != focused_lines:
        raise AndroidCaptureError("Foreground application changed during capture")
    hierarchy_after = _run(serial, ["shell", "uiautomator", "dump", "/dev/tty"], runner)
    if route in HEADINGS:
        _verify_heading(hierarchy_after, HEADINGS[route][0 if locale == "en" else 1], height)
    else:
        identifier, titles, top = NATIVE_SCREENS[route]
        _verify_native_identity(hierarchy_after, identifier,
                                titles[0 if locale == "en" else 1], width, height, top)

    # Exclusive creation: a pre-existing file/symlink is never overwritten.
    try:
        descriptor = os.open(output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    except OSError as exc:
        raise AndroidCaptureError("Capture target cannot be created exclusively") from exc
    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(pixels)
    except BaseException:
        output.unlink(missing_ok=True)
        raise
    return output


def main() -> int:
    try:
        render()
    except Exception:
        # ADB output may include private UI or device details: never echo it.
        print("ANDROID CAPTURE NOT VERIFIED: device, app, screen or pixels failed", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
