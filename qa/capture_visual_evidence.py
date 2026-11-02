#!/usr/bin/env python3
"""Run an actual UI capture command and admit only exact, reproducible pixels.

A valid ledger alone is NOT a visual acceptance. The renderer must write its
screenshot to WALIDSITE_CAPTURE_PATH. No reference PNG is passed to the command.
"""
import argparse
import json
import os
import re
import shlex
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, UnidentifiedImageError

from qa.validate_visual_fidelity import audit


class CaptureError(ValueError):
    pass


def _route_matches(contract_route: str, actual: str) -> bool:
    if not isinstance(actual, str) or not actual.startswith("/") or "?" in actual or "#" in actual:
        return False
    pattern = re.escape(contract_route)
    pattern = re.sub(r"\\\{[^{}]+\\\}", r"[^/]+", pattern)
    return re.fullmatch(pattern, actual) is not None


def capture(root: Path, screen_id: str, *, commit: str, locale: str,
            dpr: float, route: str, command: list[str], timeout: int = 240):
    root = root.resolve()
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise CaptureError("Renderer commit must be a full lowercase SHA")
    if locale not in ("ar", "en") or not 0 < dpr <= 8:
        raise CaptureError("Invalid locale or device pixel ratio")
    if not command or any("assets/screen-reference" in part for part in command):
        raise CaptureError("Supply a runtime renderer command, not an original reference")
    if any("\n" in arg or "\r" in arg for arg in command):
        raise CaptureError("Multiline capture commands are not auditable")
    errors, _, _ = audit(root)
    if errors:
        raise CaptureError("Existing evidence ledger or original references are invalid: " + "; ".join(errors))

    screens = json.loads((root / "contracts/screens.json").read_text(encoding="utf-8"))["screens"]
    screen = next((item for item in screens if item["id"] == screen_id), None)
    if screen is None or not _route_matches(screen["route"], route):
        raise CaptureError("Screenshot route does not match its canonical screen contract")

    ledger_path = root / "design/visual-fidelity-evidence.json"
    ledger = json.loads(ledger_path.read_text(encoding="utf-8"))
    if ledger["screens"][screen_id]["status"] != "pending":
        raise CaptureError("Screen already has evidence; audit or revert it before recapturing")

    capture_dir = root / "qa/visual-captures"
    capture_dir.mkdir(parents=True, exist_ok=True)
    approved = root / screen["reference_image"]
    with Image.open(approved) as reference:
        expected = reference.convert("RGBA")
        width, height = expected.size

    # Only the renderer writes the temporary output. No pre-existing screenshot
    # is trusted, and the immutable approved image is never an input to it.
    with tempfile.TemporaryDirectory(prefix="capture-", dir=capture_dir) as workdir:
        incoming = Path(workdir) / "runtime.png"
        env = dict(os.environ)
        env.update({
            "WALIDSITE_CAPTURE_PATH": str(incoming),
            "WALIDSITE_CAPTURE_WIDTH": str(width),
            "WALIDSITE_CAPTURE_HEIGHT": str(height),
            "WALIDSITE_CAPTURE_LOCALE": locale,
            "WALIDSITE_CAPTURE_DPR": str(dpr),
            "WALIDSITE_CAPTURE_ROUTE": route,
        })
        try:
            result = subprocess.run(
                command, cwd=root, env=env, timeout=timeout,
                check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise CaptureError("Runtime capture did not finish successfully") from exc
        if result.returncode != 0 or not incoming.is_file() or incoming.is_symlink():
            raise CaptureError("Renderer failed or did not produce a regular PNG")
        try:
            with Image.open(incoming) as image:
                if image.format != "PNG":
                    raise CaptureError("Runtime capture must be PNG")
                actual = image.convert("RGBA")
        except (OSError, UnidentifiedImageError) as exc:
            raise CaptureError("Runtime capture could not be decoded") from exc
        if actual.size != (width, height):
            raise CaptureError(f"Wrong screenshot dimensions: {actual.size} != {(width, height)}")
        differences = sum(a != b for a, b in zip(expected.getdata(), actual.getdata()))
        if differences:
            raise CaptureError(f"{screen_id}: {differences} RGBA pixels differ; remains pending")

        # A byte-for-byte copy of the source artwork cannot be runtime evidence.
        # This is a guardrail, not independent attestation of renderer identity.
        if incoming.read_bytes() == approved.read_bytes():
            raise CaptureError("Capture bytes are identical to the immutable source artwork")

        relative = f"qa/visual-captures/{screen_id}.png"
        destination = root / relative
        if destination.exists() or destination.is_symlink():
            raise CaptureError("Refusing to overwrite an existing screenshot")
        destination.write_bytes(incoming.read_bytes())

    old_ledger = ledger_path.read_bytes()
    reproducible = [
        "python3", "qa/capture_visual_evidence.py", screen_id,
        "--commit", commit, "--locale", locale, "--dpr", str(dpr),
        "--route", route, "--", *command,
    ]
    ledger["screens"][screen_id] = {
        "reference_image": screen["reference_image"],
        "status": "matched",
        "capture": relative,
        "renderer_commit": commit,
        "capture_command": shlex.join(reproducible),
        "route": route,
        "locale": locale,
        "device_pixel_ratio": dpr,
    }
    try:
        ledger_path.write_text(json.dumps(ledger, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        errors, _, _ = audit(root)
        if errors:
            raise CaptureError("Post-capture evidence audit failed: " + "; ".join(errors))
    except BaseException:
        ledger_path.write_bytes(old_ledger)
        destination.unlink(missing_ok=True)
        raise
    return relative


def main(argv=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    if "--" not in argv:
        print("Capture command must follow --", file=sys.stderr)
        return 2
    split = argv.index("--")
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("screen_id")
    parser.add_argument("--commit", required=True)
    parser.add_argument("--locale", required=True, choices=("ar", "en"))
    parser.add_argument("--dpr", required=True, type=float)
    parser.add_argument("--route", required=True)
    args = parser.parse_args(argv[:split])
    try:
        result = capture(
            Path(__file__).resolve().parents[1], args.screen_id,
            commit=args.commit, locale=args.locale, dpr=args.dpr,
            route=args.route, command=argv[split + 1:],
        )
    except CaptureError as exc:
        print("NOT VERIFIED: " + str(exc), file=sys.stderr)
        return 1
    print("VERIFIED zero RGBA differences: " + result)
    return 0


if __name__ == "__main__":
    sys.exit(main())
