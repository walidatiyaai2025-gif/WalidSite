"""W10 executable PHP acceptance of the real installed release identity reader.

This is deliberately outside the W1/W2 controller and Laravel feature-test
paths. It loads the actual controller class with a minimal base-class stub and
uses disposable VERSION files; no network or external database is involved.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "backend/app/Http/Controllers/AdminUpdateController.php"
READER_SOURCE = ROOT / "backend/app/Domain/Deployment/TrustedInstalledVersion.php"
PIN_SOURCE = ROOT / "backend/app/Domain/Deployment/TrustedInstalledReleasePin.php"
PHP = shutil.which("php")
RUNNER = r"""
namespace App\Http\Controllers { class Controller {} }
namespace {
    function base_path($path = '') {
        return getenv('WALIDSITE_IDENTITY_ROOT') . ($path === '' ? '' : '/' . $path);
    }
    function public_path($path = '') {
        return getenv('WALIDSITE_IDENTITY_PUBLIC_ROOT') . ($path === '' ? '' : '/' . $path);
    }
    function config($name) {
        return $name === 'site.trusted_installed_version'
            ? getenv('WALIDSITE_IDENTITY_PIN') : null;
    }
    // Mirror the source-level harness: explicitly load both real files,
    // without requiring a Laravel bootstrap or Composer vendor autoload.
    require getenv('WALIDSITE_IDENTITY_READER_SOURCE');
    require getenv('WALIDSITE_IDENTITY_PIN_SOURCE');
    require getenv('WALIDSITE_IDENTITY_SOURCE');
    $reader = new \ReflectionMethod(
        \App\Http\Controllers\AdminUpdateController::class,
        'pinnedInstalledVersion'
    );
    $result = $reader->invoke(
        new \App\Http\Controllers\AdminUpdateController()
    );
    echo json_encode(['trusted_version' => $result], JSON_THROW_ON_ERROR);
}
"""


@unittest.skipUnless(PHP and SOURCE.is_file() and READER_SOURCE.is_file() and PIN_SOURCE.is_file(),
                     "PHP, controller and installed-version reader required")
class InstalledVersionFailClosedTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="walidsite-version-pin-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.version = self.root / "VERSION"
        self.public = self.root / "public"
        self.public.mkdir()

    def evaluate(self, pinned="0.1.2", runtime=None, public=None):
        env = os.environ.copy()
        env.update({
            "WALIDSITE_IDENTITY_ROOT": str(runtime or self.root),
            "WALIDSITE_IDENTITY_PUBLIC_ROOT": str(public or self.public),
            "WALIDSITE_IDENTITY_PIN_SOURCE": str(PIN_SOURCE),
            "WALIDSITE_IDENTITY_PIN": pinned,
            "WALIDSITE_IDENTITY_SOURCE": str(SOURCE),
            "WALIDSITE_IDENTITY_READER_SOURCE": str(READER_SOURCE),
        })
        run = subprocess.run(
            [PHP, "-d", "display_errors=stderr", "-r", RUNNER],
            text=True, capture_output=True, env=env, timeout=10,
        )
        self.assertEqual(0, run.returncode, run.stderr)
        self.assertEqual("", run.stderr)
        return json.loads(run.stdout)["trusted_version"]

    def test_exact_disk_and_configured_identity_allowed(self):
        self.version.write_text("0.1.2\n", encoding="ascii")
        self.assertEqual("0.1.2", self.evaluate())

    def test_missing_disk_identity_denied(self):
        self.assertIsNone(self.evaluate())

    def test_changed_disk_identity_denied(self):
        self.version.write_text("0.1.3\n", encoding="ascii")
        self.assertIsNone(self.evaluate())

    def test_noncanonical_or_malformed_semver_denied(self):
        for value in ("v0.1.2", "01.1.2", "0.1.2-beta", "0.1.2\nbad", "0.1"):
            with self.subTest(value=value):
                self.version.write_text(value, encoding="ascii")
                self.assertIsNone(self.evaluate())

    def test_oversized_disk_identity_denied(self):
        self.version.write_bytes(b"0.1.2" + b" " * 100)
        self.assertIsNone(self.evaluate())

    def test_hardlinked_disk_identity_denied_and_recovered(self):
        self.version.write_text("0.1.2\n", encoding="ascii")
        self.assertEqual("0.1.2", self.evaluate())
        alias = self.root / "version-alias"
        try:
            os.link(self.version, alias)
        except (OSError, NotImplementedError):
            self.skipTest("Hard links unavailable")
        self.assertIsNone(self.evaluate())
        alias.unlink()
        self.assertEqual("0.1.2", self.evaluate())

    def test_group_or_world_writable_disk_identity_denied(self):
        self.version.write_text("0.1.2\n", encoding="ascii")
        self.version.chmod(0o666)
        self.assertIsNone(self.evaluate())
        self.version.chmod(0o644)
        self.assertEqual("0.1.2", self.evaluate())

    def test_verified_pointer_lineage_allows_second_signed_update_without_env_rewrite(self):
        home = self.root / "host"
        first = home / "application"
        public = home / "public_html"
        active = home / ".walidsite-releases" / ("v0.1.3-" + "a" * 40) / "application"
        first.mkdir(parents=True)
        public.mkdir()
        active.mkdir(parents=True)
        (first / "VERSION").write_text("0.1.2\\n", encoding="ascii")
        (active / "VERSION").write_text("0.1.3\\n", encoding="ascii")
        pointer = home / ".walidsite-active-release.json"
        pointer.write_text(json.dumps({
            "schema": 1, "version": "0.1.3",
            "root": ".walidsite-releases/v0.1.3-" + "a" * 40,
        }), encoding="ascii")
        pointer.chmod(0o600)
        self.assertEqual("0.1.3", self.evaluate(runtime=active, public=public))
        self.assertIsNone(self.evaluate(runtime=first, public=public))
        pointer.write_text('{"schema":1,"version":"0.1.3","root":"../../unsafe"}', encoding="ascii")
        self.assertIsNone(self.evaluate(runtime=active, public=public))

    def test_symlink_disk_identity_denied(self):
        destination = self.root / "external-version"
        destination.write_text("0.1.2\n", encoding="ascii")
        try:
            self.version.symlink_to(destination)
        except (OSError, NotImplementedError):
            self.skipTest("Symlink creation unavailable")
        self.assertIsNone(self.evaluate())


if __name__ == "__main__":
    unittest.main()
