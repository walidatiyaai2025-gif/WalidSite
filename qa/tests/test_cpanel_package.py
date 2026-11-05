"""Unit tests for secure cPanel installation ZIP generation."""
import hashlib
import os
import stat
import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

import qa.build_cpanel_package as package_module

from qa.build_cpanel_package import PRIVATE_GUARD_PATHS, PRIVATE_TREE_DENY, build, verify_archive


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
            "backend/resources/deployment/cpanel-stable-index.php": package_module.WEB_INDEX,
            "backend/routes/web.php": "<?php",
            "backend/vendor/autoload.php": "<?php",
            "backend/vendor/psr/logger.php": "<?php",
            "backend/vendor/acme/tests/private.txt": "not for deployment",
            "backend/public/.htaccess": "RewriteEngine On",
            "backend/public/index.php": "old Laravel front controller",
            "backend/artisan": "<?php",
            "backend/composer.json": "{}",
            "backend/composer.lock": '{"packages":[]}',
            "backend/VERSION": "0.1.0\n",
            "backend/.env.example": "APP_KEY=",
            "backend/.env": "PRIVATE_SECRET=never-ship",
            "backend/database/dump.sql": "private schema",
            "backend/tests/OwnerTest.php": "<?php",
            "contracts/owner-cv-baseline.json": "{}",
            "assets/portraits/portrait-light-derived.jpg": "approved derivative",
            "assets/icons/briefcase.svg": "<svg xmlns=\"http://www.w3.org/2000/svg\"/>",
            "assets/icons/database.svg": "<svg xmlns=\"http://www.w3.org/2000/svg\"/>",
            "assets/technology-logos/laravel.svg": "<svg xmlns=\"http://www.w3.org/2000/svg\"/>",
            "assets/technology-logos/LICENSE": "MIT License",
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
                "application/.env.example", "application/VERSION", "contracts/owner-cv-baseline.json",
                "public_html/index.php", "public_html/.htaccess",
                "public_html/assets/portraits/portrait-light-derived.jpg",
                "public_html/assets/icons/briefcase.svg",
                "public_html/assets/icons/database.svg",
                "public_html/assets/technology-logos/laravel.svg",
                "public_html/assets/technology-logos/LICENSE",
                "application/storage/framework/views/.gitignore",
                *PRIVATE_GUARD_PATHS,
                "INSTALL-CPANEL-AR.md", "PACKAGE-MANIFEST.json",
            ):
                self.assertIn(name, names)
            for name in (
                "application/.env", "application/database/dump.sql",
                "application/tests/OwnerTest.php", "application/vendor/acme/tests/private.txt",
            ):
                self.assertNotIn(name, names)
            public_index = zip.read("public_html/index.php").decode()
            self.assertIn("dirname(__DIR__)", public_index)
            self.assertIn(".walidsite-active-release.json", public_index)
            self.assertIn("useEnvironmentPath($original)", public_index)
            self.assertIn("useStoragePath($original.'/storage')", public_index)
            self.assertIn("usePublicPath(__DIR__)", public_index)
            info = json.loads(zip.read("PACKAGE-MANIFEST.json"))
            self.assertEqual(info["source_sha"], "a" * 40)
            self.assertEqual(info["version"], "0.1.0")
            self.assertEqual(zip.read("application/VERSION"), b"0.1.0\n")
            self.assertFalse(info["secrets_included"])
            self.assertEqual(len(info["files"]), len(names)-1)

    def test_deterministic_and_missing_vendor_lock(self):
        first = build(self.root, self.output, "b" * 40)
        second = build(self.root, self.output, "b" * 40)
        self.assertEqual(first, second)
        (self.root / "backend/composer.lock").unlink()
        with self.assertRaisesRegex(ValueError, "composer.lock"):
            build(self.root, self.output, "b" * 40)

    def test_semver_is_validated_and_pinned_to_the_package_manifest(self):
        build(self.root, self.output, "c" * 40)
        version = self.root / "backend/VERSION"
        for invalid in ("latest", "01.2.3", "1.0.0-beta", "1.0.0; rm -rf /"):
            version.write_text(invalid + "\n", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "SemVer"):
                build(self.root, self.output, "c" * 40)
        version.write_text("0.1.0\n", encoding="ascii")
        version.unlink()
        with self.assertRaisesRegex(ValueError, "VERSION"):
            build(self.root, self.output, "c" * 40)

    def test_checksum_sidecar_and_verified_manifest_match_every_payload(self):
        result = build(self.root, self.output, "e" * 40)
        checksum_file = self.output.with_name(self.output.name + ".sha256")
        self.assertEqual(
            checksum_file.read_text(encoding="ascii"),
            f"{result['sha256']}  {self.output.name}\n",
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

    def test_noncanonical_member_aliases_rejected_before_inflate(self):
        """A rehashed ZIP inventory cannot legitimize two extraction aliases."""
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output) as archive:
            original = {
                name: archive.read(name)
                for name in archive.namelist()
                if name != "PACKAGE-MANIFEST.json"
            }
            baseline = json.loads(archive.read("PACKAGE-MANIFEST.json"))

        for alias in (
            "application/app//Test.php",
            "application/app/./Test.php",
            "application//app/Test.php",
            "application/app/sub/../Test.php",
        ):
            with self.subTest(alias=alias):
                added = b'<?php echo "different extracted bytes";'
                payload = {**original, alias: added}
                inventory = {
                    **baseline["files"],
                    alias: {
                        "bytes": len(added),
                        "sha256": hashlib.sha256(added).hexdigest(),
                    },
                }
                manifest = {**baseline, "files": inventory}
                with zipfile.ZipFile(self.output, "w", zipfile.ZIP_DEFLATED) as archive:
                    for name, data in payload.items():
                        archive.writestr(name, data)
                    archive.writestr("PACKAGE-MANIFEST.json", json.dumps(manifest).encode())

                # These are individually valid, re-inventoried member bytes.
                # The verifier must reject the unsafe *name* even before CRC.
                with patch.object(zipfile.ZipFile, "testzip",
                                  side_effect=AssertionError("unsafe name decompressed")):
                    with self.assertRaisesRegex(ValueError, "Unsafe ZIP entry"):
                        verify_archive(self.output, "e" * 40)

    def test_duplicate_entries_never_pass_integrity_validation(self):
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output, "a") as zip:
            zip.writestr("application/app/Test.php", "<?php malicious")
        with self.assertRaisesRegex(ValueError, "Duplicate ZIP entry"):
            verify_archive(self.output, "e" * 40)

    def test_special_unix_zip_members_rejected_before_decompression(self):
        # A well-formed central directory may label a member as a FIFO,
        # character/block device or socket. No extractor should ever be
        # asked to materialize these entries into a cPanel document root.
        for mode in (stat.S_IFIFO, stat.S_IFCHR, stat.S_IFBLK, stat.S_IFSOCK):
            with self.subTest(mode=mode):
                build(self.root, self.output, "e" * 40)
                member = zipfile.ZipInfo("public_html/assets/unsafe-entry")
                member.create_system = 3
                member.external_attr = (mode | 0o644) << 16
                with zipfile.ZipFile(self.output, "a") as archive:
                    archive.writestr(member, b"untrusted")
                with patch.object(zipfile.ZipFile, "testzip",
                                  side_effect=AssertionError("special file inflated")):
                    with self.assertRaisesRegex(ValueError, "Non-regular Unix file"):
                        verify_archive(self.output, "e" * 40)

    def test_high_ratio_zip_bomb_rejected_before_reading_or_crc(self):
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output, "a", zipfile.ZIP_DEFLATED) as archive:
            archive.writestr(
                "application/app/zip-bomb.php", b"x" * (2 * 1024 * 1024),
                compress_type=zipfile.ZIP_DEFLATED, compresslevel=9,
            )
        with patch.object(zipfile.ZipFile, "testzip",
                          side_effect=AssertionError("decompressed before size check")):
            with self.assertRaisesRegex(ValueError, "compression ratio"):
                verify_archive(self.output, "e" * 40)

    def test_entry_count_and_expanded_size_preflight_before_decompression(self):
        build(self.root, self.output, "e" * 40)
        for setting, cap, expected in (
            ("MAX_ARCHIVE_ENTRIES", 1, "entry count"),
            ("MAX_SINGLE_ENTRY_BYTES", 1, "Oversized ZIP entry"),
            ("MAX_EXPANDED_ARCHIVE_BYTES", 20, "expanded size"),
            ("MAX_MANIFEST_BYTES", 1, "package manifest"),
            ("MAX_COMPRESSED_ARCHIVE_BYTES", 1, "oversized ZIP"),
        ):
            with self.subTest(setting=setting):
                with patch.object(package_module, setting, cap):
                    with patch.object(zipfile.ZipFile, "testzip",
                                      side_effect=AssertionError("unbounded inflate")):
                        with self.assertRaisesRegex(ValueError, expected):
                            verify_archive(self.output, "e" * 40)

    def test_directory_entry_rejected_before_any_archive_inflate(self):
        build(self.root, self.output, "e" * 40)
        with zipfile.ZipFile(self.output, "a") as archive:
            archive.writestr("application/app/unexpected/", b"")
        with patch.object(zipfile.ZipFile, "testzip",
                          side_effect=AssertionError("decompressed directory archive")):
            with self.assertRaisesRegex(ValueError, "directory entry"):
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


    def test_optional_vendor_hardlink_to_private_file_is_rejected(self):
        # Optional dependency assets previously used Path.read_bytes() after
        # is_file(), accepting hardlinked bytes from outside the checkout.
        dependency = self.root / "backend/vendor/psr/logger.php"
        dependency.unlink()
        secret = Path(self.t.name) / "private-host-secret"
        secret.write_bytes(b"PRIVATE HOST CREDENTIALS")
        os.link(secret, dependency)
        with self.assertRaisesRegex(ValueError, "Unsafe required package file identity"):
            build(self.root, self.output, "a" * 40)
        self.assertFalse(self.output.exists())

    def test_optional_vendor_symlinked_parent_is_rejected(self):
        original = self.root / "backend/vendor/psr"
        moved = self.root / "backend/vendor/psr-safe"
        original.rename(moved)
        original.symlink_to(moved, target_is_directory=True)
        try:
            with self.assertRaisesRegex(ValueError, "Unsafe required file parent|symbolic link"):
                build(self.root, self.output, "a" * 40)
        finally:
            original.unlink()
            moved.rename(original)
        self.assertFalse(self.output.exists())

    def test_optional_source_changes_after_open_are_rejected(self):
        # A swapped file pathname must never be accepted as a frozen release
        # even if a descriptor still points at the original file's bytes.
        dependency = self.root / "backend/vendor/psr/logger.php"
        before = dependency.read_bytes()
        real_fdopen = os.fdopen
        swapped = False

        def replace_after_open(fd, *args, **kwargs):
            nonlocal swapped
            stream = real_fdopen(fd, *args, **kwargs)
            if not swapped and os.fstat(stream.fileno()).st_size == len(before):
                # Only mutate when this descriptor actually names the tested
                # dependency, not another similarly sized source.
                if os.stat(dependency).st_ino == os.fstat(stream.fileno()).st_ino:
                    swapped = True
                    replacement = dependency.with_name("replacement.php")
                    replacement.write_bytes(before)
                    replacement.replace(dependency)
            return stream

        with patch.object(package_module.os, "fdopen", side_effect=replace_after_open):
            with self.assertRaisesRegex(ValueError, "Unsafe required package file identity|changed during read"):
                build(self.root, self.output, "a" * 40)
        self.assertTrue(swapped)
        self.assertFalse(self.output.exists())

    def test_explicit_required_inputs_never_follow_symlinks_outside_checkout(self):
        secret = Path(self.t.name) / "private-owner-secret"
        secret.write_bytes(b"DO NOT SHIP EXTERNAL FILE")
        for name in (
            "assets/portraits/portrait-light-derived.jpg",
            "contracts/owner-cv-baseline.json",
            "docs/CPANEL_PACKAGE_AR.md",
        ):
            with self.subTest(name=name):
                target = self.root / name
                original = target.read_bytes()
                target.unlink()
                target.symlink_to(secret)
                try:
                    with self.assertRaisesRegex(ValueError, "Missing required runtime file|Unsafe required package file"):
                        build(self.root, self.output, "a" * 40)
                finally:
                    target.unlink()
                    target.write_bytes(original)
        self.assertFalse(self.output.exists())
        self.assertFalse(self.output.with_name(self.output.name + ".sha256").exists())

    def test_explicit_required_inputs_reject_symlinked_parent_directory(self):
        original = self.root / "assets/portraits"
        moved = self.root / "assets/portraits-real"
        original.rename(moved)
        original.symlink_to(moved, target_is_directory=True)
        try:
            with self.assertRaisesRegex(ValueError, "Unsafe required file parent"):
                build(self.root, self.output, "a" * 40)
        finally:
            original.unlink()
            moved.rename(original)
        self.assertFalse(self.output.exists())

    def test_one_extract_from_cpanel_home_keeps_private_code_outside_document_root(self):
        """Exercise actual ZIP extraction at the documented account-home location."""
        build(self.root, self.output, "1" * 40)
        with tempfile.TemporaryDirectory() as temp_home:
            home = Path(temp_home) / "cpanel-user"
            home.mkdir()
            with zipfile.ZipFile(self.output) as archive:
                self.assertIsNone(archive.testzip())
                archive.extractall(home)

            public = home / "public_html"
            app = home / "application"
            contracts = home / "contracts"
            self.assertTrue((app / "vendor/autoload.php").is_file())
            self.assertTrue((app / "bootstrap/app.php").is_file())
            self.assertTrue((public / "index.php").is_file())
            self.assertTrue((contracts / "owner-cv-baseline.json").is_file())
            self.assertEqual((public / "index.php").read_text(),
                             (zipfile.ZipFile(self.output).read("public_html/index.php")).decode())
            # The front controller must resolve sibling private application and
            # never require a second extraction or user-edited relative path.
            self.assertEqual((public / "..").resolve(), home.resolve())
            self.assertEqual((public / "../application/vendor/autoload.php").resolve(),
                             (app / "vendor/autoload.php").resolve())
            self.assertNotIn("application", [item.name for item in public.iterdir()])
            self.assertNotIn("contracts", [item.name for item in public.iterdir()])
            self.assertFalse((app / ".env").exists())
            self.assertFalse((public / ".env").exists())
            self.assertFalse((home / ".env").exists())
            self.assertFalse((public / "vendor").exists())
            self.assertFalse((public / "storage").exists())
            self.assertFalse((app / "tests").exists())
            self.assertFalse((app / "database/dump.sql").exists())
            self.assertFalse((public / "backend").exists())
            for guard in PRIVATE_GUARD_PATHS:
                self.assertEqual((home / guard).read_bytes(), PRIVATE_TREE_DENY)
            self.assertTrue((app / "storage/framework/views").is_dir())
            self.assertTrue((app / "storage/app/private").is_dir())
            self.assertEqual(
                verify_archive(self.output, "1" * 40)["source_sha"], "1" * 40
            )

    def test_extracting_inside_public_html_is_explicitly_not_a_safe_one_step_install(self):
        """Pin the cPanel HOME extraction prerequisite, not an unsafe shortcut."""
        build(self.root, self.output, "2" * 40)
        with tempfile.TemporaryDirectory() as temp_home:
            home = Path(temp_home) / "account"
            document_root = home / "public_html"
            document_root.mkdir(parents=True)
            with zipfile.ZipFile(self.output) as archive:
                archive.extractall(document_root)
            # This places private Laravel code in a web-served subtree,
            # and the generated public front controller points to the
            # WRONG sibling application. It must never be claimed supported.
            self.assertTrue((document_root / "application/vendor/autoload.php").exists())
            self.assertTrue((document_root / "public_html/index.php").exists())
            self.assertFalse((document_root / "index.php").exists())
            self.assertFalse((home / "application/vendor/autoload.php").exists())
            # The wrong location is NOT a supported install. These guards
            # only mitigate some accidental public exposure on Apache.
            for guard in PRIVATE_GUARD_PATHS:
                self.assertEqual((document_root / guard).read_bytes(), PRIVATE_TREE_DENY)



    def test_private_directory_denial_survives_one_extract_and_is_manifest_pinned(self):
        build(self.root, self.output, "3" * 40)
        with zipfile.ZipFile(self.output) as archive:
            names = set(archive.namelist())
            for path in PRIVATE_GUARD_PATHS:
                self.assertIn(path, names)
                self.assertEqual(archive.read(path), PRIVATE_TREE_DENY)
                self.assertIn(b"Require all denied", archive.read(path))
                self.assertIn(b"Deny from all", archive.read(path))
                self.assertIn(path, json.loads(archive.read("PACKAGE-MANIFEST.json"))["files"])
        self.assertEqual(verify_archive(self.output, "3" * 40)["source_sha"], "3" * 40)

    def test_rehashed_but_weakened_private_apache_guard_is_rejected(self):
        build(self.root, self.output, "4" * 40)
        with zipfile.ZipFile(self.output) as archive:
            files = {name: archive.read(name) for name in archive.namelist()}
        path = "application/.htaccess"
        files[path] = b"Require all granted\n"
        manifest = json.loads(files["PACKAGE-MANIFEST.json"])
        manifest["files"][path] = {
            "sha256": hashlib.sha256(files[path]).hexdigest(),
            "bytes": len(files[path]),
        }
        files["PACKAGE-MANIFEST.json"] = (json.dumps(manifest, sort_keys=True) + "\n").encode()
        with zipfile.ZipFile(self.output, "w") as archive:
            for name, data in files.items():
                archive.writestr(name, data)
        with self.assertRaisesRegex(ValueError, "Private-directory HTTP denial"):
            verify_archive(self.output, "4" * 40)

    def test_missing_private_contracts_guard_rejected_even_with_rebuilt_manifest(self):
        build(self.root, self.output, "5" * 40)
        with zipfile.ZipFile(self.output) as archive:
            files = {name: archive.read(name) for name in archive.namelist()}
        del files["contracts/.htaccess"]
        manifest = json.loads(files["PACKAGE-MANIFEST.json"])
        del manifest["files"]["contracts/.htaccess"]
        files["PACKAGE-MANIFEST.json"] = (json.dumps(manifest, sort_keys=True) + "\n").encode()
        with zipfile.ZipFile(self.output, "w") as archive:
            for name, data in files.items():
                archive.writestr(name, data)
        with self.assertRaisesRegex(ValueError, "Required deployment payload missing"):
            verify_archive(self.output, "5" * 40)

if __name__ == "__main__":
    unittest.main()
