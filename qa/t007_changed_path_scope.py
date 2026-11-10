"""Decide mandatory T007 browser diagnostics from the actual PR file diff.

A release branch containing '-web-' in its *name* is not automatically a web UI
change. Conversely UI changes on any PR must run T007 regardless of branch name.
"""
from __future__ import annotations

import pathlib
import sys

VISUAL_PREFIXES = (
    "backend/resources/views/",
    "backend/resources/css/",
    "backend/resources/js/",
    "backend/tests/Feature/Web/",
    "assets/screen-reference/",
)
VISUAL_FILES = frozenset({
    "backend/routes/web.php",
    "qa/capture_t007_diagnostics.py",
    "qa/render_web_screenshot.py",
    "design/owner-approved-web-design-freeze.json",
    "design/visual-fidelity-evidence.json",
    "contracts/screens.json",
})


def requires_t007(paths: list[str]) -> bool:
    for item in paths:
        if not item or item.startswith("/") or "\\" in item or "\x00" in item:
            raise ValueError("Git diff returned an unsafe source path")
        if item in VISUAL_FILES or item.startswith(VISUAL_PREFIXES):
            return True
    return False


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Expected PR changed-path list")
    candidate = pathlib.Path(sys.argv[1])
    if not candidate.is_file() or candidate.is_symlink() or candidate.stat().st_size > 1000000:
        raise SystemExit("PR changed-path list missing or too large")
    changed = candidate.read_text(encoding="utf-8").splitlines()
    required = requires_t007(changed)
    print("web_ui_changed=" + ("true" if required else "false"))


if __name__ == "__main__":
    main()
