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

from qa.build_trusted_update_release import build, code_path, frozen_setup_archive

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
            'application/composer.json': b'{"require":{"php":"^8.3"}}',
            'application/composer.lock': b'{"packages":[]}',
            'application/VERSION': b'1.2.3\n',
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
        verifier.assert_called_once()
        frozen, expected_sha = verifier.call_args.args
        self.assertEqual(expected_sha, SHA)
        self.assertIsInstance(frozen, Path)
        self.assertNotEqual(frozen, self.source)
        self.assertEqual(frozen.name, "source.zip")
        # The verified source is a private temporary snapshot; never the
        # operator's mutable original pathname. It is removed after build.
        self.assertFalse(frozen.exists())
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

    def test_unverified_path_replacement_after_verification_cannot_change_code(self):
        """Attacker rewriting original ZIP cannot affect verified private bytes."""
        approved = self.entries['application/bootstrap/app.php']
        replacement = b'<?php exit("unverified replacement");'
        verified = []

        def swap_source_after_verification(frozen, source_sha):
            self.assertEqual(source_sha, SHA)
            self.assertNotEqual(frozen, self.source)
            self.assertTrue(frozen.exists())
            self.assertEqual(zipfile.ZipFile(frozen).read('application/bootstrap/app.php'), approved)
            self.entries['application/bootstrap/app.php'] = replacement
            self.make_zip()  # Same pathname but different PHP payload.
            verified.append(frozen)
            return {'version': VERSION}

        with patch('qa.build_trusted_update_release.verify_archive',
                   side_effect=swap_source_after_verification) as mock:
            receipt = build(self.source, self.root, VERSION, SHA)
        mock.assert_called_once()
        with zipfile.ZipFile(self.root / receipt['artifact_file']) as candidate:
            self.assertEqual(candidate.read('application/bootstrap/app.php'), approved)
            self.assertNotEqual(candidate.read('application/bootstrap/app.php'), replacement)
        self.assertFalse(verified[0].exists())  # private snapshot cleaned
        self.assertFalse(receipt['publisher_signature_verified'])

    def test_snapshot_is_private_and_rejects_symlink_and_nonregular_source(self):
        if os.name != 'posix' or not getattr(os, 'O_NOFOLLOW', 0):
            self.skipTest("Release build intentionally requires POSIX no-follow")
        original = self.source.read_bytes()
        with frozen_setup_archive(self.source) as frozen:
            self.assertEqual(frozen.read_bytes(), original)
            self.assertEqual(frozen.parent.stat().st_mode & 0o777, 0o700)
            self.assertNotEqual(frozen, self.source)
            self.source.write_bytes(b'forged source after private snapshot')
            self.assertEqual(frozen.read_bytes(), original)
        self.assertFalse(frozen.exists())
        self.source.unlink()
        other = self.root / 'external.zip'
        other.write_bytes(original)
        self.source.symlink_to(other)
        with self.assertRaises(ValueError):
            self.make_update()
        self.source.unlink()
        os.mkfifo(self.source)
        with self.assertRaises(ValueError):
            self.make_update()

    def test_hardlinked_source_archive_is_rejected_without_output(self):
        if os.name != 'posix':
            self.skipTest('Hardlink identity check requires POSIX')
        alias = self.root / 'second-source.zip'
        os.link(self.source, alias)
        with self.assertRaisesRegex(ValueError, 'Unsafe, unstable or oversized Setup source'):
            self.make_update()
        self.assertFalse((self.root / f'WalidSite-update-v{VERSION}.zip').exists())
        alias.unlink()
        self.assertEqual(self.make_update()['version'], VERSION)

    def test_update_is_private_until_complete_then_published_without_overwrite(self):
        if os.name != 'posix':
            self.skipTest('Immutable ZIP publishing requires POSIX hard links')
        original_link = os.link
        checks = []

        def inspect_then_publish(staged, destination, **kwargs):
            self.assertFalse(destination.exists())
            self.assertTrue(staged.is_file())
            self.assertEqual(staged.parent.stat().st_mode & 0o777, 0o700)
            self.assertTrue(staged.parent.name.startswith('.walidsite-update-staging-'))
            self.assertEqual(destination.name, f'WalidSite-update-v{VERSION}.zip')
            with zipfile.ZipFile(staged) as archive:
                self.assertIn('application/vendor/autoload.php', archive.namelist())
            checks.append('sealed-before-publication')
            return original_link(staged, destination, **kwargs)

        with patch('qa.build_trusted_update_release.os.link', side_effect=inspect_then_publish):
            receipt = self.make_update()
        self.assertEqual(checks, ['sealed-before-publication'])
        self.assertEqual(receipt['artifact_sha256'],
                         hashlib.sha256((self.root / receipt['artifact_file']).read_bytes()).hexdigest())
        self.assertEqual(list(self.root.glob('.walidsite-update-staging-*')), [])

    def test_competing_update_publisher_wins_without_artifact_deletion(self):
        if os.name != 'posix':
            self.skipTest('Immutable ZIP publishing requires POSIX hard links')
        output = self.root / f'WalidSite-update-v{VERSION}.zip'
        marker = b'competing publisher must survive'

        def compete(staged, destination, **kwargs):
            self.assertTrue(staged.is_file())
            self.assertEqual(destination, output)
            output.write_bytes(marker)
            raise FileExistsError('Another immutable publication already exists')

        with patch('qa.build_trusted_update_release.os.link', side_effect=compete):
            with self.assertRaises(FileExistsError):
                self.make_update()
        self.assertEqual(output.read_bytes(), marker)
        self.assertEqual(list(self.root.glob('.walidsite-update-staging-*')), [])

    def test_missing_secure_nofollow_primitives_fail_closed(self):
        with patch('qa.build_trusted_update_release.os.O_NOFOLLOW', 0):
            with self.assertRaisesRegex(ValueError, 'Secure source archive descriptors unavailable'):
                self.make_update()
        self.assertFalse((self.root / f'WalidSite-update-v{VERSION}.zip').exists())

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI not installed')
    def test_publisher_rejects_rehashed_malicious_zip_before_signing(self):
        if subprocess.run(['php', '-r', 'exit(extension_loaded("zip") ? 0 : 1);'],
                          capture_output=True).returncode:
            self.skipTest('PHP ZIP extension required for publisher ZIP inspection')
        import io
        import stat
        import warnings

        receipt = self.make_update()
        archive_path = self.root / receipt['artifact_file']
        original = archive_path.read_bytes()
        keygen = subprocess.run(['php', '-r',
            '$kp=sodium_crypto_sign_keypair();'
            'echo base64_encode(sodium_crypto_sign_secretkey($kp));'],
            capture_output=True, check=True)
        signer = Path(__file__).resolve().parents[1] / 'sign_trusted_update_release.php'
        env = dict(os.environ, WALIDSITE_PUBLISHER_SECRET_KEY_B64=keygen.stdout.decode())

        for case in ('traversal', 'duplicate', 'symlink', 'missing', 'version', 'private_state'):
            with self.subTest(case=case):
                archive_path.write_bytes(original)
                if case in ('traversal', 'duplicate', 'symlink', 'private_state'):
                    with zipfile.ZipFile(archive_path, 'a') as candidate:
                        if case == 'traversal':
                            candidate.writestr('application/../outside.php', '<?php exit;')
                        elif case == 'duplicate':
                            with warnings.catch_warnings():
                                warnings.simplefilter('ignore', UserWarning)
                                candidate.writestr('application/bootstrap/app.php', '<?php altered;')
                        elif case == 'symlink':
                            link = zipfile.ZipInfo('application/app/linked.php')
                            link.create_system = 3
                            link.external_attr = (stat.S_IFLNK | 0o777) << 16
                            candidate.writestr(link, '../../outside.php')
                        else:
                            candidate.writestr('public_html/uploads/owner.png', 'private owner upload')
                else:
                    payload = io.BytesIO(original)
                    with zipfile.ZipFile(payload) as source:
                        remaining = [(info, source.read(info.filename))
                                     for info in source.infolist()
                                     if not (case == 'missing' and info.filename == 'application/vendor/autoload.php')]
                    with zipfile.ZipFile(archive_path, 'w', zipfile.ZIP_DEFLATED) as candidate:
                        for info, body in remaining:
                            candidate.writestr(info.filename,
                                b'9.9.9\n' if case == 'version' and info.filename == 'application/VERSION'
                                else body)
                meta = {k: receipt[k] for k in (
                    'product', 'version', 'source_sha', 'artifact_sha256',
                    'artifact_size', 'min_php', 'artifact_file')}
                meta['artifact_sha256'] = hashlib.sha256(archive_path.read_bytes()).hexdigest()
                meta['artifact_size'] = archive_path.stat().st_size
                result = subprocess.run(['php', str(signer), str(self.root)],
                    input=json.dumps(meta).encode(), capture_output=True, env=env)
                self.assertNotEqual(result.returncode, 0, case)
                self.assertFalse((self.root / 'latest.json').exists(), case)
                self.assertNotIn(keygen.stdout, result.stdout + result.stderr)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI not installed')
    def test_real_ed25519_signing_roundtrip_and_tamper_rejection(self):
        if subprocess.run(['php', '-r', 'exit(extension_loaded("zip") ? 0 : 1);'],
                          capture_output=True).returncode:
            self.skipTest('PHP ZIP extension required for publisher ZIP inspection')
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
