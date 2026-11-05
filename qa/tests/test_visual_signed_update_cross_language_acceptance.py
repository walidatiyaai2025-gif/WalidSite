"""W10 cross-language release trust acceptance: actual PHP signer and Laravel verifier.

Fixtures use disposable ZIP bytes, localhost filesystem and short-lived sodium keys.
Neither a signed production release nor an A->B deployment is created.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
SIGNER = ROOT / "qa/sign_trusted_update_release.php"
VERIFIER = ROOT / "backend/app/Domain/Deployment/TrustedReleaseMetadata.php"
VERSION = "0.1.1"
SOURCE_SHA = "a" * 40
ORIGIN = "https://releases.example.com"

VERIFY_PHP = r"""<?php
declare(strict_types=1);
require $argv[1];
$catalog = file_get_contents($argv[2]);
try {
    $trusted = (new App\Domain\Deployment\TrustedReleaseMetadata())->verify(
        $catalog, $argv[3], $argv[4], $argv[5]
    );
    (new App\Domain\Deployment\TrustedReleaseMetadata())->verifyStagedArchive(
        $trusted, $argv[6], $argv[7]
    );
    echo json_encode(["accepted" => true, "version" => $trusted["version"]], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, "rejected\n");
    exit(1);
}
"""


@unittest.skipUnless(shutil.which("php"), "PHP CLI is required for signing acceptance")
class SignedPublisherCrossLanguageAcceptance(unittest.TestCase):
    def setUp(self):
        if subprocess.run(["php", "-r", 'exit(extension_loaded("zip") && extension_loaded("sodium") ? 0 : 1);'],
                          capture_output=True).returncode:
            self.skipTest("PHP ZipArchive and sodium are required for actual signer acceptance")
        self.temp = tempfile.TemporaryDirectory(prefix="walidsite-w10-signed-")
        self.addCleanup(self.temp.cleanup)
        self.private = Path(self.temp.name)
        self.candidate = self.private / f"WalidSite-update-v{VERSION}.zip"
        with zipfile.ZipFile(self.candidate, "w", zipfile.ZIP_DEFLATED) as package:
            for name, body in {
                "application/app/ReleaseIdentity.php": b"<?php // isolated QA fixture",
                "application/bootstrap/app.php": b"<?php return [];",
                "application/vendor/autoload.php": b"<?php return [];",
                "application/composer.json": b'{"require":{"php":"^8.3"}}',
                "application/composer.lock": b'{"packages":[]}',
                "application/VERSION": b"0.1.1\n",
                "public_html/index.php": b"<?php echo 'stable';",
                "public_html/.htaccess": b"RewriteEngine On",
            }.items():
                package.writestr(name, body)
        self.metadata = {
            "product": "walidsite",
            "version": VERSION,
            "source_sha": SOURCE_SHA,
            "artifact_sha256": hashlib.sha256(self.candidate.read_bytes()).hexdigest(),
            "artifact_size": self.candidate.stat().st_size,
            "min_php": "8.3.0",
            "artifact_file": self.candidate.name,
        }
        self.verifier_script = self.private / "verify.php"
        self.verifier_script.write_text(VERIFY_PHP, encoding="utf-8")
        keygen = subprocess.run(
            ["php", "-r",
             '$p=sodium_crypto_sign_keypair();'
             'echo json_encode(["secret"=>base64_encode(sodium_crypto_sign_secretkey($p)),'
             '"public"=>base64_encode(sodium_crypto_sign_publickey($p))]);'],
            capture_output=True, check=True, timeout=15,
        )
        pair = json.loads(keygen.stdout)
        self.public_key = pair["public"]
        self.secret_key = pair["secret"]

    def publish(self):
        environment = dict(os.environ, WALIDSITE_PUBLISHER_SECRET_KEY_B64=self.secret_key)
        output = subprocess.run(
            ["php", str(SIGNER), str(self.private)],
            input=json.dumps(self.metadata).encode(), capture_output=True,
            env=environment, timeout=20,
        )
        self.assertEqual(output.returncode, 0, "Ephemeral publisher fixture rejected")
        self.assertNotIn(self.secret_key.encode(), output.stdout + output.stderr)
        self.assertTrue((self.private / "latest.json").is_file())

    def verify(self, *, public=None, origin=ORIGIN, installed="0.1.0"):
        return subprocess.run(
            ["php", str(self.verifier_script), str(VERIFIER),
             str(self.private / "latest.json"), public or self.public_key,
             origin, installed, str(self.private), str(self.candidate)],
            capture_output=True, timeout=20,
        )

    def test_ephemeral_publisher_catalog_is_accepted_by_real_laravel_verifier(self):
        self.publish()
        result = self.verify()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout),
                         {"accepted": True, "version": VERSION})
        self.assertFalse((self.private / ".env").exists())
        self.assertFalse((self.private / "application").exists())

    def test_wrong_publisher_or_tampered_catalog_is_rejected(self):
        self.publish()
        stranger = subprocess.run(
            ["php", "-r", 'echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));'],
            capture_output=True, check=True, timeout=15,
        ).stdout.decode()
        self.assertNotEqual(self.verify(public=stranger).returncode, 0)
        catalog = self.private / "latest.json"
        envelope = json.loads(catalog.read_text(encoding="utf-8"))
        payload = base64.b64decode(envelope["payload"], validate=True)
        changed = json.loads(payload)
        changed["version"] = "0.1.2"
        envelope["payload"] = base64.b64encode(
            json.dumps(changed, separators=(",", ":")).encode()
        ).decode()
        catalog.write_text(json.dumps(envelope), encoding="utf-8")
        self.assertNotEqual(self.verify().returncode, 0)

    def test_tampered_staged_archive_or_symlink_is_rejected(self):
        self.publish()
        self.assertEqual(self.verify().returncode, 0)
        self.candidate.write_bytes(self.candidate.read_bytes() + b"tamper")
        self.assertNotEqual(self.verify().returncode, 0)
        # Restore the original bytes but replace the trusted file with a link.
        self.candidate.unlink()
        outside = self.private / "outside.zip"
        with zipfile.ZipFile(outside, "w", zipfile.ZIP_DEFLATED) as z:
            z.writestr("application/app/ReleaseIdentity.php", b"<?php // isolated QA fixture")
        self.candidate.symlink_to(outside)
        self.assertNotEqual(self.verify().returncode, 0)

    def test_non_newer_version_and_unpinned_origin_are_rejected(self):
        self.publish()
        for installed in (VERSION, "0.1.2"):
            with self.subTest(installed=installed):
                self.assertNotEqual(self.verify(installed=installed).returncode, 0)
        for origin in ("http://releases.example.com", "https://evil.test/path",
                       "https://releases.example.com:8443",
                       "https://user@releases.example.com"):
            with self.subTest(origin=origin):
                self.assertNotEqual(self.verify(origin=origin).returncode, 0)

    def test_publisher_does_not_replace_existing_catalog(self):
        self.publish()
        original = (self.private / "latest.json").read_bytes()
        environment = dict(os.environ, WALIDSITE_PUBLISHER_SECRET_KEY_B64=self.secret_key)
        replay = subprocess.run(
            ["php", str(SIGNER), str(self.private)],
            input=json.dumps(self.metadata).encode(), capture_output=True,
            env=environment, timeout=20,
        )
        self.assertNotEqual(replay.returncode, 0)
        self.assertEqual((self.private / "latest.json").read_bytes(), original)


if __name__ == "__main__":
    unittest.main()
