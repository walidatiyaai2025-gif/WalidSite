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
        (self.home / ".walidsite-active-release.json").write_text(json.dumps({
            "schema": 1, "version": "0.1.2",
            "root": ".walidsite-releases/" + release.name,
        }))
        self.assertTrue(self.verdict()["allowed"])
        (release / "application" / "VERSION").write_text("0.1.3\n")
        self.assertFalse(self.verdict()["allowed"])

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
