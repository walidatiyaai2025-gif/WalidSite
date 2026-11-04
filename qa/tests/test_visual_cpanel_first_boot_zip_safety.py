"""W10 cPanel first-boot test: reject untrusted ZIP before any file extraction."""
from pathlib import Path
import os
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
import zipfile

from qa.cpanel_first_run_http_probe import extract_verified_archive


class CpanelFirstBootArchiveSafetyTest(unittest.TestCase):
    def setUp(self):
        self.work = TemporaryDirectory(prefix="walidsite-zip-safety-")
        self.addCleanup(self.work.cleanup)
        self.root = Path(self.work.name)
        self.package = self.root / "candidate.zip"
        self.destination = self.root / "extracted"
        self.destination.mkdir()

    def build(self, names):
        with zipfile.ZipFile(self.package, "w", zipfile.ZIP_DEFLATED) as archive:
            for name in names:
                archive.writestr(name, b"unsafe-test-payload")

    def test_missing_manifest_is_rejected_before_extraction(self):
        self.build(["application/vendor/autoload.php", "public_html/index.php"])
        with patch("qa.cpanel_first_run_http_probe.zipfile.ZipFile.extractall") as extract:
            with self.assertRaises(ValueError):
                extract_verified_archive(self.package, self.destination)
            extract.assert_not_called()
        self.assertEqual(list(self.destination.iterdir()), [])

    def test_unsafe_paths_and_duplicate_entries_are_rejected_before_extraction(self):
        for names in (
            ["../escape.txt"],
            ["application/../public_html/index.php"],
            ["application//vendor/autoload.php"],
            ["application/vendor/autoload.php", "application/vendor/autoload.php"],
        ):
            with self.subTest(names=names):
                self.build(names)
                with patch("qa.cpanel_first_run_http_probe.zipfile.ZipFile.extractall") as extract:
                    with self.assertRaises(ValueError):
                        extract_verified_archive(self.package, self.destination)
                    extract.assert_not_called()
                self.assertEqual(list(self.destination.iterdir()), [])

    def test_archive_symlink_is_rejected_without_reading_payload(self):
        self.build(["application/vendor/autoload.php"])
        link = self.root / "linked.zip"
        link.symlink_to(self.package)
        with patch("qa.cpanel_first_run_http_probe.zipfile.ZipFile.extractall") as extract:
            with self.assertRaises(ValueError):
                extract_verified_archive(link, self.destination)
            extract.assert_not_called()

    def test_provenance_verification_precedes_extraction(self):
        self.build(["application/vendor/autoload.php"])
        calls = []
        def verify(package):
            self.assertNotEqual(package, self.package)
            self.assertEqual(Path(package).read_bytes(), self.package.read_bytes())
            calls.append("verified")
        def extract(archive, target, *args, **kwargs):
            self.assertEqual(Path(target), self.destination)
            calls.append("extracted")
        with patch("qa.cpanel_first_run_http_probe.verify_archive", side_effect=verify), \
             patch("qa.cpanel_first_run_http_probe.zipfile.ZipFile.extractall", new=extract):
            extract_verified_archive(self.package, self.destination)
        self.assertEqual(calls, ["verified", "extracted"])

    def test_original_zip_swap_after_verification_cannot_change_extracted_bytes(self):
        self.build(["application/pinned.txt"])
        replacement = self.root / "replacement.zip"
        with zipfile.ZipFile(replacement, "w", zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("application/pinned.txt", b"attacker-replacement")
        verified = []
        def verify(snapshot):
            with zipfile.ZipFile(snapshot) as archive:
                verified.append(archive.read("application/pinned.txt"))
            os.replace(replacement, self.package)
        with patch("qa.cpanel_first_run_http_probe.verify_archive", side_effect=verify):
            extract_verified_archive(self.package, self.destination)
        self.assertEqual(verified, [b"unsafe-test-payload"])
        self.assertEqual((self.destination / "application/pinned.txt").read_bytes(),
                         b"unsafe-test-payload")
        with zipfile.ZipFile(self.package) as archive:
            self.assertEqual(archive.read("application/pinned.txt"), b"attacker-replacement")

    def test_non_regular_fifo_is_rejected_without_extraction(self):
        if not hasattr(os, "mkfifo"):
            self.skipTest("FIFO support required")
        fifo = self.root / "fifo.zip"
        os.mkfifo(fifo)
        with patch("qa.cpanel_first_run_http_probe.zipfile.ZipFile.extractall") as extract:
            with self.assertRaises(ValueError):
                extract_verified_archive(fifo, self.destination)
            extract.assert_not_called()

    def test_failed_verification_never_starts_extraction(self):
        self.build(["application/vendor/autoload.php"])
        with patch("qa.cpanel_first_run_http_probe.verify_archive",
                   side_effect=ValueError("Untrusted artifact")), \
             patch("qa.cpanel_first_run_http_probe.zipfile.ZipFile.extractall") as extract:
            with self.assertRaisesRegex(ValueError, "Untrusted artifact"):
                extract_verified_archive(self.package, self.destination)
            extract.assert_not_called()


if __name__ == "__main__":
    unittest.main()
