"""Unit tests for secure cPanel installation ZIP generation."""
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

from qa.build_cpanel_package import build


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

    def test_symlink_fails_closed(self):
        shortcut = self.root / "backend/app/shortcut.php"
        shortcut.symlink_to(self.root / "backend/.env")
        with self.assertRaisesRegex(ValueError, "symbolic link"):
            build(self.root, self.output, "c" * 40)


if __name__ == "__main__":
    unittest.main()
