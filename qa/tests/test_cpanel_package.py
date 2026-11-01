"""Unit tests for secure cPanel installation ZIP generation."""
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

from qa.build_cpanel_package import build, verify_archive


class TestCpanelPackage(unittest.TestCase):
    def setUp(self):
        self.t = tempfile.TemporaryDirectory()
        self.addCleanup(self.t.cleanup)
        self.root = Path(self.t.name) / "repo"
        self.output = Path(self.t.name) / "dist/site.zip"
        files = {
            "backend/app/Test.php": "<?php",
            "backend/bootstrap/app.php": "<?php",
            "backend/config/site.php": "<?php",
            "backend/database/migrations/first.php": "<?php",
            "backend/resources/views/site.blade.php": "hello",
            "backend/routes/web.php": "<?php",
            "backend/vendor/autoload.php": "<?php",
            "backend/vendor/psr/logger.php": "<?php",
            "backend/vendor/acme/tests/private.txt": "not for deployment",
            "backend/public/.htaccess": "RewriteEngine On",
            "backend/public/index.php": "old Laravel front controller",
            "backend/artisan": "<?php",
            "backend/composer.json": "{}",
            "backend/composer.lock": '{"packages":[]}',
            "backend/.env.example": "APP_KEY=",
            "backend/.env": "PRIVATE_SECRET=never-ship",
            "backend/database/dump.sql": "private schema",
            "backend/tests/OwnerTest.php": "<?php",
            "contracts/owner-cv-baseline.json": "{}",
            "assets/portraits/portrait-light-derived.jpg": "approved derivative",
            "docs/CPANEL_PACKAGE_AR.md": "Cpanel instructions",
        }
        for name, data in files.items():
            file = self.root / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text(data, encoding="utf-8")

    def test_layout_and_no_sensitive_file(self):
        result = build(self.root, self.output, "a" * 40)
        self.assertGreater(result["bytes"], 200)
        with zipfile.ZipFile(self.output) as zip:
            names = set(zip.namelist())
            for name in (
                "application/vendor/autoload.php", "application/composer.lock",
                "application/.env.example", "contracts/owner-cv-baseline.json",
                "public_html/index.php", "public_html/.htaccess",
                "public_html/assets/portraits/portrait-light-derived.jpg",
                "application/storage/framework/views/.gitignore",
                "INSTALL-CPANEL-AR.md", "PACKAGE-MANIFEST.json",
            ):
                self.assertIn(name, names)
            for name in (
                "application/.env", "application/database/dump.sql",
                "application/tests/OwnerTest.php", "application/vendor/acme/tests/private.txt",
            ):
                self.assertNotIn(name, names)
            public_index = zip.read("public_html/index.php").decode()
            self.assertIn("dirname(__DIR__).'/application'", public_index)
            self.assertIn("usePublicPath(__DIR__)", public_index)
            info = json.loads(zip.read("PACKAGE-MANIFEST.json"))
            self.assertEqual(info["source_sha"], "a" * 40)
            self.assertFalse(info["secrets_included"])
            self.assertEqual(len(info["files"]), len(names)-1)

    def test_deterministic_and_missing_vendor_lock(self):
        first = build(self.root, self.output, "b" * 40)
        second = build(self.root, self.output, "b" * 40)
        self.assertEqual(first, second)
        (self.root / "backend/composer.lock").unlink()
        with self.assertRaisesRegex(ValueError, "composer.lock"):
            build(self.root, self.output, "b" * 40)

    def test_checksum_sidecar_and_verified_manifest_match_every_payload(self):
        result = build(self.root, self.output, "e" * 40)
        checksum_file = self.output.with_name(self.output.name + ".sha256")
        self.assertEqual(
            checksum_file.read_text(encoding="ascii"),
            f"{result['sha256']}  {self.output.name}\\n",
        )
        proof = verify_archive(self.output, "e" * 40)
        self.assertEqual(proof["source_sha"], "e" * 40)
        self.assertEqual(proof["files"]["application/vendor/autoload.php"]["bytes"], 5)
        with self.assertRaisesRegex(ValueError, "provenance"):
            verify_archive(self.output, "f" * 40)

    def test_tampered_zip_payload_is_detected_even_with_valid_crc(self):
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output) as zip:
            files = [(name, zip.read(name)) for name in zip.namelist()]
        with zipfile.ZipFile(self.output, "w") as zip:
            for name, data in files:
                zip.writestr(name, b"malicious" if name == "application/app/Test.php" else data)
        with self.assertRaisesRegex(ValueError, "content checksum mismatch"):
            verify_archive(self.output, "e" * 40)

    def test_undeclared_public_file_and_unsafe_paths_fail_verification(self):
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output, "a") as zip:
            zip.writestr("public_html/.env", "injected secret")
        with self.assertRaisesRegex(ValueError, "Excluded private/development file"):
            verify_archive(self.output, "e" * 40)

        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output, "a") as zip:
            zip.writestr("../outside.php", "<?php")
        with self.assertRaisesRegex(ValueError, "Unsafe ZIP entry"):
            verify_archive(self.output, "e" * 40)

    def test_duplicate_entries_never_pass_integrity_validation(self):
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output, "a") as zip:
            zip.writestr("application/app/Test.php", "<?php malicious")
        with self.assertRaisesRegex(ValueError, "Duplicate ZIP entry"):
            verify_archive(self.output, "e" * 40)

    def test_composer_cli_symlinks_are_not_exposed_in_zip(self):
        executable = self.root / "backend/vendor/bin/psysh"
        executable.parent.mkdir(parents=True, exist_ok=True)
        executable.symlink_to(self.root / "backend/vendor/autoload.php")
        build(self.root, self.output, "d" * 40)
        with zipfile.ZipFile(self.output) as zip:
            self.assertNotIn("application/vendor/bin/psysh", zip.namelist())

    def test_symlink_fails_closed(self):
        shortcut = self.root / "backend/app/shortcut.php"
        shortcut.symlink_to(self.root / "backend/.env")
        with self.assertRaisesRegex(ValueError, "symbolic link"):
            build(self.root, self.output, "c" * 40)


if __name__ == "__main__":
    unittest.main()
