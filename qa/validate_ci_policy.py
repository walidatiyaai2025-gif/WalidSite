#!/usr/bin/env python3
"""Guard the effective GitHub Actions configuration and required test suites."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"
errors = []

active = sorted(
    path.name
    for path in WORKFLOWS.iterdir()
    if path.is_file() and path.suffix in {".yml", ".yaml"}
) if WORKFLOWS.is_dir() else []

if active != ["ci.yml", "release.yml"]:
    errors.append(f"expected only ci.yml and release.yml in .github/workflows; found {active!r}")

ci_path = WORKFLOWS / "ci.yml"
ci = ci_path.read_text(encoding="utf-8") if ci_path.is_file() else ""
if ci.count("required-ci-gate:") != 1:
    errors.append("ci.yml must define exactly one stable required-ci-gate job")
if "pull_request:" not in ci:
    errors.append("ci.yml must run for pull requests")

for command in (
    "composer validate --strict",
    "php artisan test",
    "npm run lint",
    "npm run test -- --run",
    "npm run build",
    "flutter analyze",
    "flutter test",
):
    if command not in ci:
        errors.append(f"ci.yml is missing required quality command: {command}")

release_path = WORKFLOWS / "release.yml"
release = release_path.read_text(encoding="utf-8") if release_path.is_file() else ""
if "workflow_dispatch:" not in release:
    errors.append("release.yml must remain manually dispatched")

if errors:
    print("FAILED", errors)
    sys.exit(1)

print("PASS: exactly two active workflows; stable PR gate and required quality suites are present")
