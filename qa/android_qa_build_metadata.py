#!/usr/bin/env python3
"""Read native Android QA identity from checked-in Flutter and Gradle sources.

No package identity or version is accepted from untrusted workflow inputs. The
caller independently pins the checkout SHA and verifies the compiled APK.
This is build *metadata*, never proof of distribution signing or deployment.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = re.compile(
    r"(?m)^[ \t]*version:[ \t]*([0-9]+(?:\.[0-9]+){2}(?:-[0-9A-Za-z.-]+)?)\+([1-9][0-9]*)[ \t]*(?:#.*)?$"
)
APPLICATION_ID = re.compile(
    r'(?m)^[ \t]*applicationId[ \t]*=[ \t]*"([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+)"[ \t]*(?://.*)?$'
)


class AndroidBuildMetadataError(ValueError):
    """Invalid, missing or ambiguous checked-in Android build identity."""


def metadata(root: Path) -> dict:
    try:
        pubspec = (root / "mobile/pubspec.yaml").read_text(encoding="utf-8")
        gradle = (root / "mobile/android/app/build.gradle.kts").read_text(encoding="utf-8")
    except (OSError, UnicodeError) as exc:
        raise AndroidBuildMetadataError("Flutter/Gradle identity source unavailable") from exc
    versions = VERSION.findall(pubspec)
    ids = APPLICATION_ID.findall(gradle)
    if len(versions) != 1 or len(ids) != 1:
        raise AndroidBuildMetadataError("Expected one literal package ID and one version")
    name, number = versions[0]
    application_id = ids[0]
    if len(application_id) > 200 or len(name) > 100 or int(number) > 2147483647:
        raise AndroidBuildMetadataError("Invalid compiled package version or identity")
    return {
        "package_id": application_id,
        "version_name": name,
        "version_code": int(number),
        "placeholder_package_id": application_id.startswith("com.example."),
        "debug_qa_only": True,
        "distribution_ready": False,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--github-output", type=Path,
                        help="Append safe native identity outputs for a GitHub Actions step")
    args = parser.parse_args(argv)
    try:
        result = metadata(args.root)
        if args.github_output:
            # Variables come only from tightly validated source regexes; no
            # untrusted newlines, expressions or shell evaluation.
            with args.github_output.open("a", encoding="utf-8") as output:
                for key in ("package_id", "version_name", "version_code"):
                    output.write(f"{key}={result[key]}\n")
    except (AndroidBuildMetadataError, OSError) as exc:
        print("ANDROID QA METADATA REJECTED: " + type(exc).__name__, file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
