#!/usr/bin/env python3
"""Build a cPanel ZIP after the trusted CI installs production Composer vendor."""
import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path

WEB_INDEX = """<?php
use Illuminate\\Foundation\\Application;
use Illuminate\\Http\\Request;
define('LARAVEL_START', microtime(true));
$root = dirname(__DIR__).'/application';
if (is_file($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $root.'/vendor/autoload.php';
/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
"""
DIRECTORIES = ("app", "bootstrap", "config", "database", "resources", "routes", "vendor")
REQUIRED = (
    "backend/vendor/autoload.php", "backend/composer.lock",
    "backend/public/.htaccess", "contracts/owner-cv-baseline.json",
    "assets/portraits/portrait-light-derived.jpg",
)
EXCLUDE_PARTS = {".git", ".github", "tests", "node_modules", ".idea", ".vscode"}
EXCLUDE_NAMES = {".env", ".env.backup", ".env.production", "auth.json", "phpunit.xml"}
EXCLUDE_ENDINGS = (".sqlite", ".sqlite3", ".sql", ".p12", ".p8", ".pem", ".key", ".log")
STORAGE = ("storage/app/private", "storage/framework/cache/data",
           "storage/framework/sessions", "storage/framework/views",
           "storage/logs", "bootstrap/cache")


def safe(file, root):
    if file.is_symlink():
        raise ValueError("Refuse symbolic link: " + str(file))
    parts = file.relative_to(root).parts
    if any(x in EXCLUDE_PARTS for x in parts):
        return False
    if file.name in EXCLUDE_NAMES or file.name.endswith(EXCLUDE_ENDINGS):
        return False
    if file.name.startswith(".env") and file.name != ".env.example":
        return False
    return file.is_file()


def add_folder(entries, folder, dest, root):
    if not folder.is_dir() or folder.is_symlink():
        raise ValueError("Missing/unsafe deployment directory: " + str(folder))
    for file in sorted(folder.rglob("*")):
        if not safe(file, root):
            continue
        target = dest + "/" + file.relative_to(folder).as_posix()
        if target in entries:
            raise ValueError("Duplicate destination: " + target)
        entries[target] = file.read_bytes()


def build(source, output, sha):
    if not re.fullmatch("[0-9a-f]{40}", sha):
        raise ValueError("Expected the exact 40-character Git SHA")
    source = source.resolve()
    for name in REQUIRED:
        if not (source / name).is_file() or (source / name).is_symlink():
            raise ValueError("Missing required runtime file: " + name)
    entries = {}
    backend = source / "backend"
    for name in DIRECTORIES:
        add_folder(entries, backend / name, "application/" + name, source)
    for name in ("artisan", "composer.json", "composer.lock", ".env.example"):
        file = backend / name
        if not safe(file, source):
            raise ValueError("Missing/unsafe application file: " + name)
        entries["application/" + name] = file.read_bytes()
    add_folder(entries, backend / "public", "public_html", source)
    entries["public_html/index.php"] = WEB_INDEX.encode()
    entries["public_html/assets/portraits/portrait-light-derived.jpg"] = (
        source / "assets/portraits/portrait-light-derived.jpg"
    ).read_bytes()
    entries["contracts/owner-cv-baseline.json"] = (
        source / "contracts/owner-cv-baseline.json"
    ).read_bytes()
    for folder in STORAGE:
        entries["application/" + folder + "/.gitignore"] = b"*\n!.gitignore\n"
    doc = source / "docs/CPANEL_PACKAGE_AR.md"
    if not doc.is_file():
        raise ValueError("Installation guide not found")
    entries["INSTALL-CPANEL-AR.md"] = doc.read_bytes()
    proof = {
        "schema": "walidsite.cpanel-package.v1",
        "source_sha": sha,
        "production_vendor_included": True,
        "secrets_included": False,
        "files": {name: {"sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data)}
                  for name, data in sorted(entries.items())},
    }
    entries["PACKAGE-MANIFEST.json"] = (
        json.dumps(proof, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    ).encode("utf-8")
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED, compresslevel=8) as zip:
        for name, data in sorted(entries.items()):
            if name.startswith("/") or ".." in Path(name).parts:
                raise ValueError("Unsafe archive path")
            item = zipfile.ZipInfo(name, (2026, 11, 1, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.external_attr = 0o644 << 16
            zip.writestr(item, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=8)
    with zipfile.ZipFile(output) as check:
        if check.testzip() is not None or set(check.namelist()) != set(entries):
            raise ValueError("Archive verification failed")
    return {"sha256": hashlib.sha256(output.read_bytes()).hexdigest(),
            "files": len(entries), "bytes": output.stat().st_size}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--sha", required=True)
    a = parser.parse_args()
    print(json.dumps(build(a.source, a.output, a.sha), sort_keys=True))
