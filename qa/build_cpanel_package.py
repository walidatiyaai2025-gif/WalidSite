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
PRIVATE_TREE_DENY = (b"# WalidSite private deployment tree: never serve over HTTP.\n"
    b"Options -Indexes\n"
    b"<IfModule mod_authz_core.c>\n"
    b"    Require all denied\n"
    b"</IfModule>\n"
    b"<IfModule !mod_authz_core.c>\n"
    b"    Deny from all\n"
    b"</IfModule>\n")
PRIVATE_GUARD_PATHS = ("application/.htaccess", "contracts/.htaccess")

DIRECTORIES = ("app", "bootstrap", "config", "database", "resources", "routes", "vendor")
REQUIRED = (
    "backend/vendor/autoload.php", "backend/composer.lock", "backend/VERSION",
    "backend/public/.htaccess", "contracts/owner-cv-baseline.json",
    "assets/portraits/portrait-light-derived.jpg",
    "assets/icons/briefcase.svg",
    "assets/technology-logos/laravel.svg",
)
EXCLUDE_PARTS = {".git", ".github", "tests", "node_modules", ".idea", ".vscode"}
EXCLUDE_NAMES = {".env", ".env.backup", ".env.production", "auth.json", "phpunit.xml"}
EXCLUDE_ENDINGS = (".sqlite", ".sqlite3", ".sql", ".p12", ".p8", ".pem", ".key", ".log")
STORAGE = ("storage/app/private", "storage/framework/cache/data",
           "storage/framework/sessions", "storage/framework/views",
           "storage/logs", "bootstrap/cache")


# Fail closed on ZIP metadata BEFORE CRC checks or any decompression.
# cPanel vendor/assets belong within these conservative shared-hosting limits.
MAX_ARCHIVE_ENTRIES = 30000
MAX_COMPRESSED_ARCHIVE_BYTES = 1024 * 1024 * 1024
MAX_EXPANDED_ARCHIVE_BYTES = 1024 * 1024 * 1024
MAX_SINGLE_ENTRY_BYTES = 128 * 1024 * 1024
MAX_MANIFEST_BYTES = 2 * 1024 * 1024
MAX_EXPANSION_RATIO = 200


def safe(file, root):
    if file.is_symlink():
        # Composer creates vendor/bin CLI shims as symlinks; the web app
        # never needs them. Exclude those rather than shipping link targets
        # that may escape the application directory after deployment.
        if file.relative_to(root).parts[:3] == ("backend", "vendor", "bin"):
            return False
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



def verify_archive(output, expected_sha=None):
    """Reject unsafe ZIP metadata before decompressing any archive member."""
    output = Path(output)
    if (output.is_symlink() or not output.is_file()
            or output.stat().st_size > MAX_COMPRESSED_ARCHIVE_BYTES):
        raise ValueError("Unsafe or oversized ZIP file")
    with zipfile.ZipFile(output) as archive:
        infos = archive.infolist()
        if len(infos) > MAX_ARCHIVE_ENTRIES:
            raise ValueError("Excessive ZIP entry count")
        names = [info.filename for info in infos]
        if len(names) != len(set(names)):
            raise ValueError("Duplicate ZIP entry")
        total_expanded = 0
        for info in infos:
            # ZIP central-directory sizes are untrusted. Bound both the total
            # potential expansion and a single allocation before testzip().
            if info.is_dir():
                raise ValueError("Unexpected ZIP directory entry")
            if info.file_size > MAX_SINGLE_ENTRY_BYTES:
                raise ValueError("Oversized ZIP entry")
            total_expanded += info.file_size
            if total_expanded > MAX_EXPANDED_ARCHIVE_BYTES:
                raise ValueError("Excessive ZIP expanded size")
            if info.file_size and info.compress_size == 0:
                raise ValueError("Invalid ZIP compressed entry size")
            if (info.file_size > 1024 * 1024
                    and info.file_size > info.compress_size * MAX_EXPANSION_RATIO):
                raise ValueError("Excessive ZIP compression ratio")
            if (info.filename == "PACKAGE-MANIFEST.json"
                    and info.file_size > MAX_MANIFEST_BYTES):
                raise ValueError("Oversized ZIP package manifest")
        for info in infos:
            name = info.filename
            parts = Path(name).parts
            if (not name or name.startswith("/") or "\\" in name
                    or ".." in parts or "." in parts
                    or (not name.startswith(("application/", "public_html/", "contracts/"))
                        and name not in ("PACKAGE-MANIFEST.json", "INSTALL-CPANEL-AR.md"))):
                raise ValueError("Unsafe ZIP entry: " + name)
            if (info.external_attr >> 16) & 0o170000 == 0o120000:
                raise ValueError("Symbolic link in ZIP")
        if archive.testzip() is not None:
            raise ValueError("Corrupt ZIP entry")
        mandatory = {
            "application/vendor/autoload.php", "application/composer.lock",
            "application/VERSION",
            "application/.env.example", "public_html/index.php",
            "public_html/.htaccess",
            "public_html/assets/portraits/portrait-light-derived.jpg",
            "public_html/assets/icons/briefcase.svg",
            "public_html/assets/technology-logos/laravel.svg",
            "contracts/owner-cv-baseline.json", "INSTALL-CPANEL-AR.md",
            *PRIVATE_GUARD_PATHS,
            "PACKAGE-MANIFEST.json",
        }
        if not mandatory.issubset(names):
            raise ValueError("Required deployment payload missing")
        # A rehashed manifest alone must not authorize a package that exposes
        # the private application/contract tree on a default Apache host.
        if any(archive.read(path) != PRIVATE_TREE_DENY for path in PRIVATE_GUARD_PATHS):
            raise ValueError("Private-directory HTTP denial missing or altered")
        if any(
            "/.env" in ("/" + n).replace("/.env.example", "")
            or n.endswith((".sql", ".sqlite", ".pem", ".key", ".p12", ".p8"))
            or "/tests/" in n or "/node_modules/" in n
            or n.startswith("public_html/application/")
            for n in names
        ):
            raise ValueError("Excluded private/development file in ZIP")
        try:
            manifest = json.loads(archive.read("PACKAGE-MANIFEST.json"))
        except (json.JSONDecodeError, KeyError) as exc:
            raise ValueError("Invalid package manifest") from exc
        if (manifest.get("schema") != "walidsite.cpanel-package.v1"
                or manifest.get("production_vendor_included") is not True
                or manifest.get("secrets_included") is not False
                or not isinstance(manifest.get("version"), str)
                or re.fullmatch(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)", manifest["version"]) is None
                or archive.read("application/VERSION") != (manifest["version"] + "\n").encode("ascii")
                or not isinstance(manifest.get("source_sha"), str)
                or not re.fullmatch("[0-9a-f]{40}", manifest["source_sha"])
                or (expected_sha is not None and manifest["source_sha"] != expected_sha)):
            raise ValueError("Package provenance or safety flags mismatch")
        inventory = manifest.get("files")
        if not isinstance(inventory, dict) or set(inventory) != set(names) - {"PACKAGE-MANIFEST.json"}:
            raise ValueError("Package manifest file inventory mismatch")
        for name in inventory:
            record = inventory[name]
            data = archive.read(name)
            if (not isinstance(record, dict)
                    or record.get("bytes") != len(data)
                    or record.get("sha256") != hashlib.sha256(data).hexdigest()):
                raise ValueError("Package content checksum mismatch: " + name)
    return manifest


def build(source, output, sha):
    if not re.fullmatch("[0-9a-f]{40}", sha):
        raise ValueError("Expected the exact 40-character Git SHA")
    source = source.resolve()
    for name in REQUIRED:
        if not (source / name).is_file() or (source / name).is_symlink():
            raise ValueError("Missing required runtime file: " + name)
    backend = source / "backend"
    version_file = backend / "VERSION"
    if version_file.is_symlink() or not version_file.is_file():
        raise ValueError("Missing safe SemVer VERSION file")
    version = version_file.read_text(encoding="ascii").strip()
    if re.fullmatch(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)", version) is None:
        raise ValueError("Invalid SemVer VERSION file")
    entries = {}
    for name in DIRECTORIES:
        add_folder(entries, backend / name, "application/" + name, source)
    for name in ("artisan", "composer.json", "composer.lock", ".env.example", "VERSION"):
        file = backend / name
        if not safe(file, source):
            raise ValueError("Missing/unsafe application file: " + name)
        entries["application/" + name] = file.read_bytes()
    add_folder(entries, backend / "public", "public_html", source)
    entries["public_html/index.php"] = WEB_INDEX.encode()
    entries["public_html/assets/portraits/portrait-light-derived.jpg"] = (
        source / "assets/portraits/portrait-light-derived.jpg"
    ).read_bytes()
    # First-install About media fallbacks: copy local SVG icons and licensed
    # technology marks into the HTTP document root. Private image drafts stay
    # exclusively in application/storage and are NEVER packaged here.
    for folder in ("icons", "technology-logos"):
        add_folder(entries, source / "assets" / folder,
                   "public_html/assets/" + folder, source)
    entries["contracts/owner-cv-baseline.json"] = (
        source / "contracts/owner-cv-baseline.json"
    ).read_bytes()
    # Defense in depth if the operator mistakenly extracts within public_html.
    # Private content MUST still reside outside DocumentRoot; .htaccess is
    # only interpreted when the hosting Apache/LiteSpeed honors overrides.
    for path in PRIVATE_GUARD_PATHS:
        entries[path] = PRIVATE_TREE_DENY
    for folder in STORAGE:
        entries["application/" + folder + "/.gitignore"] = b"*\n!.gitignore\n"
    doc = source / "docs/CPANEL_PACKAGE_AR.md"
    if not doc.is_file():
        raise ValueError("Installation guide not found")
    entries["INSTALL-CPANEL-AR.md"] = doc.read_bytes()
    proof = {
        "schema": "walidsite.cpanel-package.v1",
        "source_sha": sha,
        "version": version,
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
    verify_archive(output, sha)
    checksum = hashlib.sha256(output.read_bytes()).hexdigest()
    # Publish the sidecar alongside the ZIP for straightforward offline checks.
    output.with_name(output.name + ".sha256").write_text(
        f"{checksum}  {output.name}\n", encoding="ascii"
    )
    return {"sha256": checksum,
            "files": len(entries), "bytes": output.stat().st_size}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--sha", required=True)
    a = parser.parse_args()
    print(json.dumps(build(a.source, a.output, a.sha), sort_keys=True))
