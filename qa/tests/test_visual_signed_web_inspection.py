"""Actual private candidate -> PHP Ed25519 publisher -> PHP consumer acceptance.

Only disposable ephemeral fixture keys. No production signing or host deploy.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

from qa.prepare_signed_web_inspection import prepare

ROOT = Path(__file__).resolve().parents[2]
VERSION = "1.2.3"
SHA = "a" * 40
PHP = shutil.which("php")


class ManualSignedWebInspectionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.candidates = self.root / "candidates"
        self.private = self.root / "private"
        self.candidates.mkdir()
        self.private.mkdir(mode=0o700)
        self.update = self.candidates / f"WalidSite-Update-v{VERSION}.zip"
        entries = {
            "application/bootstrap/app.php": b"<?php return 1;",
            "application/vendor/autoload.php": b"<?php return 2;",
            "application/composer.json": b'{"require":{"php":"^8.3"}}',
            "application/composer.lock": b'{"packages":[]}',
            "application/VERSION": b"1.2.3\n",
            "public_html/index.php": b"<?php echo 1;",
            "public_html/.htaccess": b"Options -Indexes",
        }
        with zipfile.ZipFile(self.update, "w", zipfile.ZIP_DEFLATED) as z:
            for name, content in entries.items():
                z.writestr(name, content)
        self.digest = hashlib.sha256(self.update.read_bytes()).hexdigest()
        self.manifest_name = f"WalidSite-Release-Manifest-v{VERSION}.json"
        self.notes_name = f"WalidSite-Release-Notes-v{VERSION}.md"
        self.manifest = {
            "schema": "walidsite.release-candidate.v1",
            "product": "walidsite", "version": VERSION, "source_sha": SHA,
            "published": False, "publisher_signature_verified": False,
            "production_ready": False,
            "update": {
                "file": self.update.name,
                "sha256": self.digest,
                "bytes": self.update.stat().st_size,
                "min_php": "8.3.0",
                "publisher_upload_name": f"WalidSite-update-v{VERSION}.zip",
            },
        }
        self.publish_fixture()

    def checksum(self, file: Path):
        (file.parent / (file.name + ".sha256")).write_text(
            hashlib.sha256(file.read_bytes()).hexdigest() +
            "  " + file.name + "\n", encoding="ascii"
        )

    def publish_fixture(self):
        (self.candidates / self.manifest_name).write_text(
            json.dumps(self.manifest), encoding="utf-8")
        (self.candidates / self.notes_name).write_text(
            "# WalidSite signed inspection fixture (NOT a release)\n", encoding="utf-8")
        for path in (self.candidates / self.manifest_name,
                     self.candidates / self.notes_name, self.update):
            self.checksum(path)

    def prepare(self):
        return prepare(self.candidates, self.private, VERSION, SHA)

    def test_exact_candidate_is_frozen_in_private_inode_without_signing(self):
        receipt = self.prepare()
        self.assertTrue(receipt["unsigned_payload_prepared"])
        self.assertFalse(receipt["production_ready"])
        self.assertFalse(receipt["publisher_signature_verified"])
        archive = self.private / f"WalidSite-update-v{VERSION}.zip"
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), self.digest)
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        payload = json.loads((self.private / "unsigned-payload.json").read_text())
        self.assertEqual(payload["artifact_sha256"], self.digest)
        self.assertEqual(payload["source_sha"], SHA)
        self.assertEqual(payload["release_notes"],
                         (self.candidates / self.notes_name).read_text())
        self.assertFalse((self.private / "latest.json").exists())

    def test_false_identity_archive_tampering_and_private_path_fail_closed(self):
        for change in ("product", "version", "source_sha", "digest", "missing", "bad_notes"):
            with self.subTest(change=change):
                old = json.loads(json.dumps(self.manifest))
                if change == "product":
                    self.manifest["product"] = "other"
                elif change == "version":
                    self.manifest["version"] = "1.2.4"
                elif change == "source_sha":
                    self.manifest["source_sha"] = "b" * 40
                elif change == "digest":
                    self.manifest["update"]["sha256"] = "b" * 64
                elif change == "missing":
                    self.manifest["update"].pop("publisher_upload_name")
                else:
                    (self.candidates / self.notes_name).write_text(
                        "unsafe\x00notes", encoding="utf-8")
                self.publish_fixture() if change != "bad_notes" else self.checksum(
                    self.candidates / self.notes_name)
                with self.assertRaises(ValueError):
                    self.prepare()
                self.manifest = old
                self.publish_fixture()
        self.private.chmod(0o755)
        with self.assertRaises(ValueError):
            self.prepare()
        self.private.chmod(0o700)
        (self.candidates / self.manifest_name).write_text(
            '{"product":"walidsite","product":"other"}', encoding="utf-8"
        )
        self.checksum(self.candidates / self.manifest_name)
        with self.assertRaises(ValueError):
            self.prepare()

    def test_actual_zip_byte_mutation_fails_even_with_stale_sidecar(self):
        with self.update.open("ab") as stream:
            stream.write(b"tampered")
        with self.assertRaises(ValueError):
            self.prepare()

    @unittest.skipUnless(PHP, "PHP signing engine unavailable")
    def test_actual_php_publisher_and_consumer_verify_before_any_promotion(self):
        extensions = subprocess.run(
            [PHP, "-r", 'exit(extension_loaded("sodium") && extension_loaded("zip") ? 0 : 1);'],
            capture_output=True, check=False)
        if extensions.returncode:
            self.skipTest("PHP zip+sodium missing; no signature evidence")
        self.prepare()
        keygen = subprocess.run([
            PHP, "-r",
            '$k=sodium_crypto_sign_keypair(); echo json_encode(['
            '"public"=>base64_encode(sodium_crypto_sign_publickey($k)),'
            '"secret"=>base64_encode(sodium_crypto_sign_secretkey($k))]);',
        ], capture_output=True, check=True)
        keys = json.loads(keygen.stdout)
        signer = ROOT / "qa/sign_trusted_update_release.php"
        consumer = ROOT / "qa/verify_signed_web_inspection.php"
        sign = subprocess.run(
            [PHP, str(signer), str(self.private)],
            input=(self.private / "unsigned-payload.json").read_bytes(),
            env={**os.environ, "WALIDSITE_PUBLISHER_SECRET_KEY_B64": keys["secret"]},
            capture_output=True, check=False)
        self.assertEqual(sign.returncode, 0, sign.stderr.decode(errors="replace"))
        self.assertNotIn(keys["secret"].encode(), sign.stdout + sign.stderr)
        def verify(public, origin="https://updates.example.com", version=VERSION):
            return subprocess.run(
                [PHP, str(consumer), str(self.private), version, SHA],
                env={**os.environ,
                     "WALIDSITE_PUBLISHER_PUBLIC_KEY_B64": public,
                     "WALIDSITE_TRUSTED_RELEASE_ORIGIN": origin},
                capture_output=True, check=False)
        good = verify(keys["public"])
        self.assertEqual(good.returncode, 0, good.stderr.decode(errors="replace"))
        inspected = json.loads(good.stdout)
        self.assertTrue(inspected["publisher_signature_verified"])
        self.assertTrue(inspected["archive_integrity_verified"])
        self.assertFalse(inspected["published"])
        self.assertFalse(inspected["production_ready"])
        self.assertNotEqual(verify("AA==").returncode, 0)
        self.assertNotEqual(verify(keys["public"], "http://updates.example.com").returncode, 0)
        self.assertNotEqual(verify(keys["public"], version="1.2.4").returncode, 0)
        archive = self.private / f"WalidSite-update-v{VERSION}.zip"
        with archive.open("ab") as stream:
            stream.write(b"modified after signed approval")
        self.assertNotEqual(verify(keys["public"]).returncode, 0)


if __name__ == "__main__":
    unittest.main()
