"""W10 QA: run actual CpanelPhysicalHostPreflight on disposable physical layout.

Only the namespace-local PHP extension availability is shimmed because this QA
runner may lack PHP cPanel extensions. File checks, realpath, SHA-256, symlink
and disk-space decision execute in the real PHP implementation.
"""
from pathlib import Path
import json
import shutil
import subprocess
import tempfile
import unittest

PHP = shutil.which("php")
SOURCE = Path(__file__).resolve().parents[2] / "backend/app/Domain/Deployment/CpanelPhysicalHostPreflight.php"
DRIVER = r"""
namespace App\Domain\Deployment {
    function extension_loaded(string $name): bool { return true; }
}
namespace {
    require $argv[1];
    try {
        $p = new \App\Domain\Deployment\CpanelPhysicalHostPreflight(
            static fn(string $path): int => (int) $argv[6]
        );
        echo json_encode(['allowed'=>true,'result'=>$p->assertReady(
            $argv[2], $argv[3], $argv[4], (int)$argv[5])], JSON_THROW_ON_ERROR);
    } catch (\DomainException $ex) {
        echo json_encode(['allowed'=>false,'reason'=>$ex->getMessage()], JSON_THROW_ON_ERROR);
    }
}
"""


@unittest.skipUnless(PHP and SOURCE.is_file(), "real PHP and source required")
class CpanelPhysicalPreflightSecurity(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="walidsite-physical-preflight-")
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.app = self.home / "application"
        self.public = self.home / "public_html"
        (self.app / "storage").mkdir(parents=True)
        (self.public / "uploads").mkdir(parents=True)
        (self.app / ".env").write_text("APP_KEY=CI-FAKE\n")
        (self.app / "VERSION").write_text("0.1.2\n")
        (self.public / ".htaccess").write_text("RewriteEngine On\n")
        (self.public / "index.php").write_text("<?php echo 'immutable';")
        self.trusted = self.home / "trusted.php"
        self.trusted.write_bytes((self.public / "index.php").read_bytes())

    def verdict(self, version="0.1.2", zipped=1024, free=2_000_000_000):
        old_env = (self.app / ".env").read_bytes()
        old_public = (self.public / "index.php").read_bytes()
        p = subprocess.run([PHP, "-d", "display_errors=stderr", "-r", DRIVER,
                            str(SOURCE), str(self.home), str(self.trusted),
                            version, str(zipped), str(free)],
                           capture_output=True, text=True, timeout=10)
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertEqual(p.stderr, "")
        self.assertEqual((self.app / ".env").read_bytes(), old_env)
        self.assertEqual((self.public / "index.php").read_bytes(), old_public)
        return json.loads(p.stdout)

    def test_real_matching_physical_layout_is_readonly_not_an_update(self):
        v = self.verdict()
        self.assertTrue(v["allowed"], v)
        self.assertEqual(v["result"]["state"], "hosting_layout_verified")
        self.assertFalse(v["result"]["backup_verified"])
        self.assertFalse(v["result"]["code_update_performed"])

    def test_tampered_live_dispatcher_rejected(self):
        (self.public / "index.php").write_text("<?php echo 'tampered';")
        self.assertFalse(self.verdict()["allowed"])

    def test_symlink_private_env_rejected(self):
        external = self.home / "external-env"
        external.write_text("APP_KEY=CI-FAKE\n")
        (self.app / ".env").unlink()
        try:
            (self.app / ".env").symlink_to(external)
        except OSError:
            self.skipTest("symlink unavailable")
        self.assertFalse(self.verdict()["allowed"])

    def test_interrupted_maintenance_lock_preserved(self):
        marker = self.home / ".walidsite-update-maintenance.json"
        marker.write_text("interrupted")
        self.assertFalse(self.verdict()["allowed"])
        self.assertEqual(marker.read_text(), "interrupted")

    def test_malformed_pointer_rejected_without_mutation(self):
        pointer = self.home / ".walidsite-active-release.json"
        pointer.write_text('{"schema":1,"version":"0.1.2","root":"../../unsafe"}')
        self.assertFalse(self.verdict()["allowed"])
        self.assertIn("../../unsafe", pointer.read_text())

    def test_valid_pointer_is_bound_to_immutable_release_version(self):
        release = self.home / ".walidsite-releases" / ("v0.1.2-" + "a" * 40)
        (release / "application").mkdir(parents=True)
        (release / "application" / "VERSION").write_text("0.1.2\n")
        pointer = self.home / ".walidsite-active-release.json"
        pointer.write_text(json.dumps({
            "schema": 1, "version": "0.1.2",
            "root": ".walidsite-releases/" + release.name,
        }))
        pointer.chmod(0o600)
        self.assertTrue(self.verdict()["allowed"])
        (release / "application" / "VERSION").write_text("0.1.3\n")
        self.assertFalse(self.verdict()["allowed"])

    def test_pointer_private_inode_mode_hardlink_and_symlink_fail_closed(self):
        release = self.home / ".walidsite-releases" / ("v0.1.2-" + "a" * 40)
        (release / "application").mkdir(parents=True)
        (release / "application" / "VERSION").write_text("0.1.2\n")
        pointer = self.home / ".walidsite-active-release.json"
        pointer.write_text(json.dumps({
            "schema": 1, "version": "0.1.2",
            "root": ".walidsite-releases/" + release.name,
        }))
        # 0644 is not the private 0600 atomic writer contract.
        self.assertFalse(self.verdict()["allowed"])
        pointer.chmod(0o600)
        self.assertTrue(self.verdict()["allowed"])
        hardlink = self.home / "pointer-hardlink"
        hardlink.hardlink_to(pointer)
        try:
            self.assertFalse(self.verdict()["allowed"])
        finally:
            hardlink.unlink()
        self.assertTrue(self.verdict()["allowed"])
        other = self.home / "original-pointer"
        pointer.rename(other)
        pointer.symlink_to(other)
        self.assertFalse(self.verdict()["allowed"])

    def test_installed_version_inode_hardlink_and_foreign_writes_fail_closed(self):
        version = self.app / "VERSION"
        self.assertTrue(self.verdict()["allowed"])
        version.chmod(0o666)
        self.assertFalse(self.verdict()["allowed"])
        version.chmod(0o644)
        hardlink = self.home / "version-hardlink"
        hardlink.hardlink_to(version)
        try:
            self.assertFalse(self.verdict()["allowed"])
        finally:
            hardlink.unlink()
        self.assertTrue(self.verdict()["allowed"])
        other = self.home / "original-version"
        version.rename(other)
        version.symlink_to(other)
        self.assertFalse(self.verdict()["allowed"])

    def test_preflight_control_reads_are_descriptor_pinned_not_path_reopened(self):
        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("readPinnedIdentityFile($pointer, 512, true)", source)
        self.assertIn("readPinnedIdentityFile($versionFile, 32, false)", source)
        self.assertIn("stream_get_contents($stream, $maximum + 1)", source)
        self.assertNotIn("file_get_contents($pointer)", source)
        self.assertNotIn("file_get_contents($versionFile)", source)

    def test_stale_disk_version_rejected(self):
        (self.app / "VERSION").write_text("0.1.3\n")
        self.assertFalse(self.verdict()["allowed"])

    def test_recovery_space_boundary(self):
        threshold = 134_217_728 + 3 * 1024
        self.assertFalse(self.verdict(free=threshold-1)["allowed"])
        self.assertTrue(self.verdict(free=threshold)["allowed"])

    def test_untrusted_size_and_noncanonical_semver_rejected(self):
        for version in ("v0.1.2", "01.1.2", "0.1.2-rc1", "0.1"):
            with self.subTest(version=version):
                self.assertFalse(self.verdict(version=version)["allowed"])
        for size in (0, -1, 1_073_741_825):
            with self.subTest(size=size):
                self.assertFalse(self.verdict(zipped=size)["allowed"])


if __name__ == "__main__":
    unittest.main()
