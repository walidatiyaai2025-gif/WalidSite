"""W10 release contract: actual PHP publisher -> production trust/ZIP consumer.

This is a synthetic, private fixture. It is NOT a real signed release,
running cPanel installation, owner-controlled update, or deployment.
"""
from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

from qa.build_trusted_update_release import build


ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which("php")
SOURCE_SHA = "a" * 40
VERSION = "1.2.3"
ORIGIN = "https://updates.example.test"

CONSUMER = r"""
require $argv[1];
require $argv[2];
try {
    $release = (new \App\Domain\Deployment\TrustedReleaseMetadata())->verify(
        file_get_contents($argv[3]), $argv[6], $argv[8], $argv[7]
    );
    $result = ['version' => $release['version'],
               'sha256' => $release['artifact_sha256']];
    if ($argv[9] === 'inspect') {
        $result += (new \App\Domain\Deployment\TrustedReleaseArchive())->inspect(
            $release, $argv[4], $argv[5]
        );
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    fwrite(STDERR, get_class($error));
    exit(21);
}
"""


@unittest.skipUnless(PHP, "PHP CLI is not installed (no PHP acceptance evidence)")
class PublisherConsumerAcceptanceTest(unittest.TestCase):
    def setUp(self):
        extension = subprocess.run([PHP, "-r", 'exit(extension_loaded("sodium") ? 0 : 1);'],
                                   capture_output=True, check=False)
        if extension.returncode:
            self.skipTest("PHP sodium is unavailable (no signing acceptance evidence)")
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.cpanel = self.root / "WalidSite-cPanel.zip"
        with zipfile.ZipFile(self.cpanel, "w", zipfile.ZIP_DEFLATED) as z:
            for name, data in {
                "application/bootstrap/app.php": b"<?php return 1;",
                "application/vendor/autoload.php": b"<?php return 2;",
                "public_html/index.php": b"<?php echo 'ok';",
                "public_html/.htaccess": b"Options -Indexes",
                "contracts/owner-cv-baseline.json": b"{}",
                "application/.env.example": b"APP_KEY=",
                "application/storage/app/site-installed.lock": b"secret state",
                "public_html/uploads/owner.png": b"private owner content",
            }.items():
                z.writestr(name, data)
        # The input is a synthetic fixture; real-source provenance and full
        # manifest validation remain protected separately by cPanel pack tests.
        def verify_private_snapshot(snapshot, sha):
            self.assertEqual(sha, SOURCE_SHA)
            self.assertNotEqual(snapshot, self.cpanel)
            self.assertEqual(snapshot.name, "source.zip")
            self.assertTrue(snapshot.is_file())
            with zipfile.ZipFile(snapshot) as source:
                self.assertEqual(source.read("application/bootstrap/app.php"), b"<?php return 1;")
            return {"version": VERSION}

        with patch("qa.build_trusted_update_release.verify_archive",
                   side_effect=verify_private_snapshot) as verify:
            receipt = build(self.cpanel, self.root, VERSION, SOURCE_SHA)
        verify.assert_called_once()
        self.assertFalse(verify.call_args.args[0].exists())  # private source removed
        self.archive = self.root / receipt["artifact_file"]
        self.meta = {key: value for key, value in receipt.items()
                     if key != "publisher_signature_verified"}
        self.assertFalse(receipt["publisher_signature_verified"])
        pair = subprocess.run([PHP, "-r",
            '$kp=sodium_crypto_sign_keypair();echo json_encode(['
            '"secret"=>base64_encode(sodium_crypto_sign_secretkey($kp)),'
            '"public"=>base64_encode(sodium_crypto_sign_publickey($kp))]);'],
            capture_output=True, check=True)
        self.keypair = json.loads(pair.stdout)
        signer = ROOT / "qa" / "sign_trusted_update_release.php"
        env = dict(os.environ, WALIDSITE_PUBLISHER_SECRET_KEY_B64=self.keypair["secret"])
        signed = subprocess.run([PHP, str(signer), str(self.root)],
                                input=json.dumps(self.meta).encode(), capture_output=True,
                                env=env, check=False)
        self.assertEqual(signed.returncode, 0, signed.stderr.decode(errors="replace"))
        self.assertNotIn(self.keypair["secret"].encode(), signed.stdout + signed.stderr)
        self.catalog = self.root / "latest.json"
        self.envelope = self.catalog.read_bytes()

    def _consume(self, envelope=None, key=None, origin=ORIGIN, installed="1.0.0",
                 inspect=False):
        source = self.root / "candidate-envelope.json"
        source.write_bytes(self.envelope if envelope is None else envelope)
        return subprocess.run([
            PHP, "-r", CONSUMER,
            str(ROOT / "backend/app/Domain/Deployment/TrustedReleaseMetadata.php"),
            str(ROOT / "backend/app/Domain/Deployment/TrustedReleaseArchive.php"),
            str(source), str(self.root), str(self.archive),
            key if key is not None else self.keypair["public"], installed, origin,
            "inspect" if inspect else "metadata",
        ], capture_output=True, check=False)

    def _has_zip(self):
        return subprocess.run([PHP, "-r", 'exit(extension_loaded("zip") ? 0 : 1);'],
                              capture_output=True, check=False).returncode == 0

    def test_actual_production_trust_consumer_accepts_matching_publisher_metadata(self):
        result = self._consume()
        self.assertEqual(result.returncode, 0, result.stderr.decode(errors="replace"))
        parsed = json.loads(result.stdout)
        self.assertEqual(parsed["version"], VERSION)
        self.assertEqual(parsed["sha256"], self.meta["artifact_sha256"])
        self.assertEqual(self.catalog.stat().st_mode & 0o777, 0o600)
        self.assertFalse((self.root / "WalidSite-update-v1.2.3.zip").is_symlink())

    def test_actual_production_zip_inspector_accepts_signed_code_only_archive(self):
        if not self._has_zip():
            self.skipTest("PHP ZipArchive unavailable: real archive inspection NOT TESTED")
        result = self._consume(inspect=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode(errors="replace"))
        self.assertGreaterEqual(json.loads(result.stdout)["files"], 4)
        with zipfile.ZipFile(self.archive) as archive:
            names = set(archive.namelist())
        self.assertNotIn("application/storage/app/site-installed.lock", names)
        self.assertNotIn("public_html/uploads/owner.png", names)
        self.assertNotIn("application/.env.example", names)

    def test_bad_publisher_origin_replay_and_signature_fail_closed(self):
        changed = json.loads(self.envelope)
        raw = bytearray(base64.b64decode(changed["payload"]))
        raw[0] ^= 1
        changed["payload"] = base64.b64encode(raw).decode()
        self.assertNotEqual(self._consume(envelope=json.dumps(changed).encode()).returncode, 0)
        self.assertNotEqual(self._consume(key=base64.b64encode(bytes(32)).decode()).returncode, 0)
        self.assertNotEqual(self._consume(installed=VERSION).returncode, 0)
        self.assertNotEqual(self._consume(installed="2.0.0").returncode, 0)
        self.assertNotEqual(self._consume(origin="http://updates.example.test").returncode, 0)
        self.assertNotEqual(self._consume(origin="https://updates.example.test:443").returncode, 0)

    def test_archive_byte_mutation_is_not_authorized_by_correct_signature(self):
        if not self._has_zip():
            self.skipTest("PHP ZipArchive unavailable: staged byte-mutation test NOT TESTED")
        original = self._consume(inspect=True)
        self.assertEqual(original.returncode, 0, original.stderr.decode(errors="replace"))
        with self.archive.open("ab") as handle:
            handle.write(b"unexpected trailing bytes")
        # Signature still validates its immutable metadata, but the actual
        # staged ZIP hash MUST be rechecked before any extraction or cutover.
        self.assertEqual(self._consume().returncode, 0)
        self.assertNotEqual(self._consume(inspect=True).returncode, 0)


if __name__ == "__main__":
    unittest.main()
