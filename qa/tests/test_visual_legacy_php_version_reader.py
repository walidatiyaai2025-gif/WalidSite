"""Issue #1120: execute the real PHP release identity reader on old host fixtures.

Never rewrite a live VERSION file, bypass the reader or authorize an update.
The PHP reader must fail closed on the reported two-line 0.1.0 legacy format.
"""
from __future__ import annotations

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
READER = ROOT / "backend/app/Domain/Deployment/TrustedInstalledVersion.php"
PHP = shutil.which("php")
SCRIPT = r"""
require $argv[1];
$reader = new \App\Domain\Deployment\TrustedInstalledVersion();
$value = $reader->read($argv[2]);
echo $value === null ? "BLOCKED" : $value;
"""


@unittest.skipUnless(PHP and READER.is_file(), "Real PHP reader required")
class LegacyInstalledReleaseReaderTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="walidsite-live-010-reader-")
        self.addCleanup(self.temp.cleanup)
        self.file = Path(self.temp.name) / "VERSION"

    def read_actual_php(self):
        completed = subprocess.run(
            [PHP, "-r", SCRIPT, str(READER), str(self.file)],
            capture_output=True, text=True, timeout=10, check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        return completed.stdout

    def test_real_reader_recognizes_canonical_legacy_semver_only(self):
        # The version 0.1.0 is not itself rejected; only unsafe on-disk
        # representation / mismatched provenance blocks automatic adoption.
        self.file.write_bytes(b"0.1.0\n")
        self.assertEqual(self.read_actual_php(), "0.1.0")
        self.assertEqual(self.file.read_bytes(), b"0.1.0\n")

    def test_reported_timestamp_version_is_blocked_without_mutation(self):
        original = b"0.1.0\n2026-11-04T09:02:48+00:00\n"
        self.file.write_bytes(original)
        self.assertEqual(self.read_actual_php(), "BLOCKED")
        self.assertEqual(self.file.read_bytes(), original)

    def test_unsupported_source_versions_and_noncanonical_formats(self):
        for value in (b"v0.1.0\n", b"00.1.0\n", b"0.1.0-beta\n",
                      b"0.1.0\n2026-11-04T09:02:48+00:00\nextra\n",
                      b"0.1.0\n" + b"X" * 70):
            with self.subTest(version=value[:50]):
                self.file.write_bytes(value)
                self.assertEqual(self.read_actual_php(), "BLOCKED")

    def test_symlink_or_hardlink_never_becomes_release_authority(self):
        destination = Path(self.temp.name) / "original-version"
        destination.write_bytes(b"0.1.0\n")
        try:
            self.file.symlink_to(destination)
        except (OSError, NotImplementedError):
            self.skipTest("Symlink not supported on test platform")
        self.assertEqual(self.read_actual_php(), "BLOCKED")
        self.file.unlink()
        try:
            os.link(destination, self.file)
        except (OSError, NotImplementedError):
            self.skipTest("Hardlink not supported on test platform")
        self.assertEqual(self.read_actual_php(), "BLOCKED")


if __name__ == "__main__":
    unittest.main()
