"""Real ZIP fixture tests for unsigned candidate artifacts and fail-closed release gating."""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from qa.build_cpanel_package import PRIVATE_GUARD_PATHS, PRIVATE_TREE_DENY
from qa.prepare_release_candidate import prepare

SHA = 'c' * 40


class ReleaseCandidateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.source = root / 'source.zip'
        self.out = root / 'out'
        self.out.mkdir()
        self.entries = {
            'application/bootstrap/app.php': b'<?php return 1;',
            'application/vendor/autoload.php': b'<?php return 2;',
            'application/composer.lock': b'{"packages":[]}',
            'application/VERSION': b'1.2.3\n',
            'application/.env.example': b'APP_KEY=NEVER_COMMIT',
            'public_html/index.php': b'<?php echo "ok";',
            'public_html/.htaccess': b'RewriteEngine On',
            'public_html/assets/portraits/portrait-light-derived.jpg': b'photo',
            'public_html/assets/icons/briefcase.svg': b'<svg/>',
            'public_html/assets/technology-logos/laravel.svg': b'<svg/>',
            'contracts/owner-cv-baseline.json': b'{}',
            'INSTALL-CPANEL-AR.md': b'Guide',
        }
        self.entries.update({name: PRIVATE_TREE_DENY for name in PRIVATE_GUARD_PATHS})
        self.make_source()

    def make_source(self):
        inventory = {name: {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
                     for name, data in self.entries.items()}
        proof = {'schema': 'walidsite.cpanel-package.v1', 'source_sha': SHA,
                 'production_vendor_included': True, 'secrets_included': False,
                 'version': '1.2.3',
                 'files': inventory}
        with zipfile.ZipFile(self.source, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name, data in self.entries.items():
                archive.writestr(name, data)
            archive.writestr('PACKAGE-MANIFEST.json', json.dumps(proof).encode())

    def test_both_archives_same_source_no_secrets_checksums_and_not_published(self):
        proof = prepare(self.source, self.out, '1.2.3', SHA)
        self.assertFalse(proof['production_ready'])
        self.assertFalse(proof['publisher_signature_verified'])
        self.assertFalse(proof['published'])
        self.assertFalse(any(proof['acceptance'].values()))
        self.assertEqual(proof['source_sha'], SHA)
        self.assertEqual(proof['update']['publisher_upload_name'], 'WalidSite-update-v1.2.3.zip')
        setup = self.out / 'WalidSite-Setup-v1.2.3.zip'
        update = self.out / 'WalidSite-Update-v1.2.3.zip'
        self.assertEqual(setup.read_bytes(), self.source.read_bytes())
        for name in (setup.name, update.name):
            digest = hashlib.sha256((self.out / name).read_bytes()).hexdigest()
            self.assertEqual((self.out / (name + '.sha256')).read_text(), f'{digest}  {name}\n')
        with zipfile.ZipFile(update) as archive:
            names = set(archive.namelist())
            self.assertIn('application/vendor/autoload.php', names)
            self.assertNotIn('application/.env.example', names)
            self.assertNotIn('PACKAGE-MANIFEST.json', names)
        self.assertFalse((self.out / 'latest.json').exists())
        self.assertEqual(json.loads((self.out / 'WalidSite-Release-Manifest-v1.2.3.json').read_text()), proof)
        self.assertIn('UNPUBLISHED', (self.out / 'WalidSite-Release-Notes-v1.2.3.md').read_text())
        with self.assertRaisesRegex(ValueError, 'already exists'):
            prepare(self.source, self.out, '1.2.3', SHA)

    def test_corrupt_archive_wrong_sha_invalid_version_and_symlinks_rejected(self):
        with zipfile.ZipFile(self.source, 'a') as archive:
            archive.writestr('application/vendor/autoload.php', b'tampered')
        with self.assertRaises(ValueError):
            prepare(self.source, self.out, '1.2.3', SHA)
        self.assertEqual(list(self.out.iterdir()), [])
        self.make_source()
        # Valid but different SemVer must not relabel an attested installer.
        with self.assertRaisesRegex(ValueError, 'does not match verified source VERSION'):
            prepare(self.source, self.out, '1.2.4', SHA)
        with self.assertRaises(ValueError):
            prepare(self.source, self.out, '1.2.3', 'f' * 40)
        for version in ('1.2', '01.2.3', '1.2.3-rc1', '../../x'):
            with self.assertRaises(ValueError):
                prepare(self.source, self.out, version, SHA)
        link = self.source.with_name('link.zip')
        link.symlink_to(self.source)
        with self.assertRaises(ValueError):
            prepare(link, self.out, '1.2.3', SHA)
        self.assertEqual(list(self.out.iterdir()), [])

    def test_workflow_guards_tested_sha_and_blocks_release_promotion(self):
        workflow = (Path(__file__).resolve().parents[2] / '.github/workflows/release.yml').read_text()
        for item in ('workflow_dispatch:', 'required-ci-gate', 'build-cpanel-package',
                     'qa/prepare_release_candidate.py', 'actions/upload-artifact@v4',
                     'exit 1', 'github.sha'):
            self.assertIn(item, workflow)
        self.assertNotIn('sign_trusted_update_release.php', workflow)
        self.assertNotIn('gh release create', workflow)


if __name__ == '__main__':
    unittest.main()
