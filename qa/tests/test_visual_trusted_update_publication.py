"""Release publisher fixture tests: never a real signed production or staging release."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

from qa.build_trusted_update_release import build, code_path

SHA = 'a' * 40
VERSION = '1.2.3'


class TrustedUpdatePublicationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'WalidSite-cPanel.zip'
        self.entries = {
            'application/bootstrap/app.php': b'<?php return 1;',
            'application/vendor/autoload.php': b'<?php return 2;',
            'public_html/index.php': b'<?php echo "hello";',
            'public_html/.htaccess': b'Deny from all',
            'application/.env.example': b'APP_KEY=',
            'application/storage/app/private/.gitignore': b'*',
            'application/storage/app/site-installed.lock': b'private',
            'public_html/uploads/photo.png': b'private user image',
            'contracts/owner-cv-baseline.json': b'{}',
        }
        self.make_zip()

    def make_zip(self):
        with zipfile.ZipFile(self.source, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name, blob in self.entries.items():
                archive.writestr(name, blob)

    def make_update(self):
        # Source package verification is tested by the original packager suite.
        # Here assert it is invoked with exact SHA, then inspect derived code.
        with patch('qa.build_trusted_update_release.verify_archive', return_value={'version': VERSION}) as verifier:
            result = build(self.source, self.root, VERSION, SHA)
        verifier.assert_called_once_with(self.source, SHA)
        return result

    def test_payload_is_complete_code_without_owner_or_installer_state(self):
        result = self.make_update()
        self.assertFalse(result['publisher_signature_verified'])
        name = result['artifact_file']
        with zipfile.ZipFile(self.root / name) as archive:
            names = set(archive.namelist())
            self.assertIn('application/vendor/autoload.php', names)
            self.assertIn('public_html/.htaccess', names)
            self.assertIn('contracts/owner-cv-baseline.json', names)
            self.assertNotIn('application/.env.example', names)
            self.assertNotIn('application/storage/app/site-installed.lock', names)
            self.assertNotIn('public_html/uploads/photo.png', names)
            self.assertNotIn('application/storage/app/private/.gitignore', names)
        self.assertEqual(result['artifact_sha256'], hashlib.sha256((self.root / name).read_bytes()).hexdigest())
        with self.assertRaises(ValueError):
            self.make_update()  # Immutable: never overwrite.

    def test_update_rejects_a_different_version_from_setup_manifest(self):
        with patch('qa.build_trusted_update_release.verify_archive', return_value={'version': '9.9.9'}):
            with self.assertRaisesRegex(ValueError, 'version does not match'):
                build(self.source, self.root, VERSION, SHA)

    def test_dangerous_paths_and_incomplete_archive_rejected(self):
        for name in ('application/.env', 'application/.env.local', 'application/storage/x',
                     'public_html/uploads/x', 'public_html/../escape.php',
                     'application/vendor/.git/config', 'application//x',
                     'public_html/a\\b.php'):
            self.assertFalse(code_path(name), name)
        del self.entries['application/vendor/autoload.php']
        self.make_zip()
        with patch('qa.build_trusted_update_release.verify_archive', return_value={'version': VERSION}):
            with self.assertRaises(ValueError):
                build(self.source, self.root, VERSION, SHA)
        for version in ('1.2', '01.2.3', '1.2.3-beta', '../../1.2.3'):
            with self.assertRaises(ValueError):
                build(self.source, self.root, version, SHA)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI not installed')
    def test_real_ed25519_signing_roundtrip_and_tamper_rejection(self):
        meta = self.make_update()
        meta.pop('publisher_signature_verified')
        proc = subprocess.run(['php', '-r',
            '$kp=sodium_crypto_sign_keypair(); echo json_encode(['
            '"secret"=>base64_encode(sodium_crypto_sign_secretkey($kp)),'
            '"public"=>base64_encode(sodium_crypto_sign_publickey($kp))]);'],
            capture_output=True, check=True)
        pair = json.loads(proc.stdout)
        script = Path(__file__).resolve().parents[1] / 'sign_trusted_update_release.php'
        env = dict(os.environ, WALIDSITE_PUBLISHER_SECRET_KEY_B64=pair['secret'])
        payload = json.dumps(meta).encode()
        signed = subprocess.run(['php', str(script), str(self.root)], input=payload,
                                capture_output=True, env=env)
        self.assertEqual(signed.returncode, 0, signed.stderr)
        envelope = json.loads((self.root / 'latest.json').read_bytes())
        raw = base64.b64decode(envelope['payload'], validate=True)
        self.assertEqual(json.loads(raw), meta)
        verify = subprocess.run(['php', '-r',
            '$e=json_decode(stream_get_contents(STDIN),true);'
            'exit(sodium_crypto_sign_verify_detached(base64_decode($e["signature"]),'
            'base64_decode($e["payload"]),base64_decode(getenv("PUB")))?0:1);'],
            input=json.dumps(envelope).encode(), env=dict(os.environ, PUB=pair['public']))
        self.assertEqual(verify.returncode, 0)
        # Replacing unsigned metadata cannot authorize a second publisher.
        envelope['payload'] = base64.b64encode(raw.replace(b'1.2.3', b'2.2.3')).decode()
        verify = subprocess.run(['php', '-r',
            '$e=json_decode(stream_get_contents(STDIN),true);'
            'exit(sodium_crypto_sign_verify_detached(base64_decode($e["signature"]),'
            'base64_decode($e["payload"]),base64_decode(getenv("PUB")))?0:1);'],
            input=json.dumps(envelope).encode(), env=dict(os.environ, PUB=pair['public']))
        self.assertNotEqual(verify.returncode, 0)
        repeated = subprocess.run(['php', str(script), str(self.root)], input=payload,
                                  capture_output=True, env=env)
        self.assertNotEqual(repeated.returncode, 0)
        self.assertNotIn(pair['secret'].encode(), repeated.stderr)
        # An incorrect artifact hash fails CLOSED before writing a catalog.
        (self.root / 'latest.json').unlink()
        meta['artifact_sha256'] = '0' * 64
        bad = subprocess.run(['php', str(script), str(self.root)], input=json.dumps(meta).encode(),
                             capture_output=True, env=env)
        self.assertNotEqual(bad.returncode, 0)
        self.assertFalse((self.root / 'latest.json').exists())


if __name__ == '__main__':
    unittest.main()
