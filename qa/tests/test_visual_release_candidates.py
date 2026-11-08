"""Real ZIP fixture tests for unsigned candidate artifacts and fail-closed release gating."""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile
from unittest.mock import patch

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
            'application/composer.lock': b'{"packages":[{"name":"laravel/framework","version":"v12.1.0"}]}',
            'application/database/migrations/2026_11_01_000001_create_tables.php': b'<?php // immutable test migration',
            'application/VERSION': b'1.2.3\n',
            'application/cron/release-poll.php': b'<?php // private CLI fixture; never public',
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
        self.assertEqual(proof['build_info']['production_dependencies'], 1)
        self.assertEqual(proof['build_info']['migration_files'], 1)
        build_file = self.out / proof['build_info']['file']
        details = json.loads(build_file.read_text())
        self.assertEqual(details['source_sha'], SHA)
        self.assertFalse(details['publisher_signature_verified'])
        self.assertFalse(details['production_ready'])
        self.assertFalse(details['upgrade_compatibility_verified'])
        self.assertEqual(details['production_dependencies'], [
            {'name': 'laravel/framework', 'version': 'v12.1.0'},
        ])
        migration = '2026_11_01_000001_create_tables.php'
        self.assertEqual(details['migration_sha256'][migration], hashlib.sha256(
            self.entries['application/database/migrations/' + migration]).hexdigest())
        self.assertEqual(details['composer_lock_sha256'], hashlib.sha256(
            self.entries['application/composer.lock']).hexdigest())
        build_digest = hashlib.sha256(build_file.read_bytes()).hexdigest()
        self.assertEqual(build_digest, proof['build_info']['sha256'])
        self.assertEqual((self.out / (build_file.name + '.sha256')).read_text(),
                         f'{build_digest}  {build_file.name}\n')
        setup = self.out / 'WalidSite-Setup-v1.2.3.zip'
        update = self.out / 'WalidSite-Update-v1.2.3.zip'
        self.assertEqual(setup.read_bytes(), self.source.read_bytes())
        self.assertEqual(details['setup_sha256'], hashlib.sha256(setup.read_bytes()).hexdigest())
        self.assertEqual(details['update_sha256'], hashlib.sha256(update.read_bytes()).hexdigest())
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

    def test_source_path_replacement_after_verification_cannot_mix_setup_and_update(self):
        original = self.source.read_bytes()
        approved_code = self.entries['application/bootstrap/app.php']
        verified_paths = []
        from qa.build_cpanel_package import verify_archive as real_verify

        def replace_original_after_verified(frozen, sha):
            manifest = real_verify(frozen, sha)
            if frozen.name == 'source.zip':
                self.assertNotEqual(frozen, self.source)
                self.assertTrue(frozen.exists())
                verified_paths.append(frozen)
                # An operator-controlled pathname is replaced after the trusted
                # verifier finishes, before either artifact is copied.
                self.entries['application/bootstrap/app.php'] = b'<?php exit("INJECTED");'
                self.make_source()
            return manifest

        with patch('qa.prepare_release_candidate.verify_archive',
                   side_effect=replace_original_after_verified) as verifier:
            receipt = prepare(self.source, self.out, '1.2.3', SHA)
        self.assertEqual(verifier.call_count, 2,
                         'Verify the frozen source and the emitted Setup ZIP')
        self.assertFalse(verified_paths[0].exists())
        setup = self.out / receipt['setup']['file']
        update = self.out / receipt['update']['file']
        self.assertEqual(setup.read_bytes(), original)
        self.assertNotEqual(self.source.read_bytes(), original)
        with zipfile.ZipFile(update) as archive:
            self.assertEqual(archive.read('application/bootstrap/app.php'), approved_code)
        with zipfile.ZipFile(self.source) as replaced:
            self.assertNotEqual(replaced.read('application/bootstrap/app.php'), approved_code)
        self.assertEqual(receipt['setup']['sha256'], hashlib.sha256(original).hexdigest())
        build = json.loads((self.out / receipt['build_info']['file']).read_text())
        self.assertEqual(build['source_sha'], SHA)
        self.assertEqual(build['setup_sha256'], hashlib.sha256(original).hexdigest())
        self.assertEqual(build['migration_sha256']['2026_11_01_000001_create_tables.php'],
                         hashlib.sha256(self.entries['application/database/migrations/2026_11_01_000001_create_tables.php']).hexdigest())
        self.assertFalse(receipt['publisher_signature_verified'])

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

    def test_invalid_frozen_composer_dependencies_fail_without_release_artifacts(self):
        original = self.entries['application/composer.lock']
        for payload in (
            b'not valid JSON',
            b'{"packages":{}}',
            b'{"packages":[{"name":"bad/secret","version":""}]}',
            b'{"packages":[{"name":"laravel/framework","version":"v1"},'
            b'{"name":"laravel/framework","version":"v2"}]}',
        ):
            with self.subTest(payload=payload):
                self.entries['application/composer.lock'] = payload
                self.make_source()
                with self.assertRaises(ValueError):
                    prepare(self.source, self.out, '1.2.3', SHA)
                self.assertEqual(list(self.out.iterdir()), [])
        self.entries['application/composer.lock'] = original

    def test_composer_provenance_rejects_control_and_bidi_versions(self):
        # Source checksum verification is separate from safe presentation
        # of publisher-facing package version metadata in Build Info.
        for invalid in ('v1.2.3\nINJECTED', 'dev-main\t', 'v1.0.0\u202e',
                        'dev-main\u200b', 'v1.0.0\x7f'):
            with self.subTest(version=repr(invalid)):
                self.entries['application/composer.lock'] = json.dumps({
                    'packages': [{'name': 'laravel/framework', 'version': invalid}],
                }).encode('utf-8')
                self.make_source()
                with self.assertRaisesRegex(ValueError, 'dependency identity'):
                    prepare(self.source, self.out, '1.2.3', SHA)
                self.assertEqual(list(self.out.iterdir()), [])

        # Branch-qualified Composer versions remain legitimate, even though
        # they are not simple MAJOR.MINOR.PATCH release numbers.
        self.entries['application/composer.lock'] = json.dumps({
            'packages': [{'name': 'vendor/library', 'version': 'dev-feature/topic'}],
        }).encode('utf-8')
        self.make_source()
        proof = prepare(self.source, self.out, '1.2.3', SHA)
        self.assertEqual(
            json.loads((self.out / proof['build_info']['file']).read_text())
            ['production_dependencies'],
            [{'name': 'vendor/library', 'version': 'dev-feature/topic'}],
        )

    def test_manifest_and_notes_have_immutable_independently_verifiable_checksums(self):
        proof = prepare(self.source, self.out, '1.2.3', SHA)
        for filename in ('WalidSite-Release-Manifest-v1.2.3.json',
                         'WalidSite-Release-Notes-v1.2.3.md'):
            with self.subTest(filename=filename):
                document = self.out / filename
                sidecar = self.out / (filename + '.sha256')
                self.assertTrue(sidecar.is_file())
                self.assertEqual(sidecar.read_text('ascii'),
                                 f'{hashlib.sha256(document.read_bytes()).hexdigest()}  {filename}\n')
                # A modification invalidates the recorded digest; the
                # release remains UNSIGNED and never approved for deployment.
                recorded = sidecar.read_text('ascii').split('  ', 1)[0]
                with document.open('ab') as stream:
                    stream.write(b'UNAPPROVED CHANGE')
                self.assertNotEqual(hashlib.sha256(document.read_bytes()).hexdigest(),
                                    recorded)
        self.assertFalse(proof['production_ready'])

    def test_existing_manifest_or_notes_sidecar_is_not_overwritten(self):
        for filename in ('WalidSite-Release-Manifest-v1.2.3.json.sha256',
                         'WalidSite-Release-Notes-v1.2.3.md.sha256'):
            with self.subTest(filename=filename):
                existing = self.out / filename
                marker = b'prior independent checksum evidence'
                existing.write_bytes(marker)
                with self.assertRaisesRegex(ValueError, 'already exists'):
                    prepare(self.source, self.out, '1.2.3', SHA)
                self.assertEqual(existing.read_bytes(), marker)
                self.assertEqual(sorted(item.name for item in self.out.iterdir()),
                                 [filename])
                existing.unlink()

    def test_existing_build_info_is_immutable_and_never_overwritten(self):
        existing = self.out / 'WalidSite-Build-Info-v1.2.3.json'
        existing.write_bytes(b'previous publisher build evidence')
        with self.assertRaisesRegex(ValueError, 'already exists'):
            prepare(self.source, self.out, '1.2.3', SHA)
        self.assertEqual(existing.read_bytes(), b'previous publisher build evidence')
        self.assertEqual(sorted(item.name for item in self.out.iterdir()),
                         [existing.name])

    def test_concurrent_update_output_is_never_overwritten(self):
        target = self.out / 'WalidSite-Update-v1.2.3.zip'
        marker = b'competing immutable publisher release'

        def competing_publisher(source, destination):
            self.assertEqual(destination, target)
            target.write_bytes(marker)
            raise FileExistsError('concurrent output claimed')

        with patch('qa.prepare_release_candidate.os.link', side_effect=competing_publisher):
            with self.assertRaises(FileExistsError):
                prepare(self.source, self.out, '1.2.3', SHA)
        self.assertEqual(target.read_bytes(), marker)
        self.assertEqual(sorted(p.name for p in self.out.iterdir()), [target.name])

    def test_failed_builder_never_deletes_unowned_update_output(self):
        generated = self.out / 'WalidSite-update-v1.2.3.zip'
        marker = b'already claimed by a second publisher'

        def another_build_claimed_path(*args):
            generated.write_bytes(marker)
            raise FileExistsError('concurrent publisher claimed update name')

        with patch('qa.prepare_release_candidate.build_update',
                   side_effect=another_build_claimed_path):
            with self.assertRaises(FileExistsError):
                prepare(self.source, self.out, '1.2.3', SHA)
        self.assertEqual(generated.read_bytes(), marker)
        self.assertEqual(sorted(p.name for p in self.out.iterdir()), [generated.name])

    def test_real_update_zip_matches_php_signed_release_archive_contract(self):
        """The exact built candidate must pass Laravel's real ZIP validator,
        not just Python's manifest and CRC inspection. A throwaway Ed25519
        signer exercises signatures without approving a production release.
        """
        import os
        import shutil
        import subprocess
        php = shutil.which('php')
        root = Path(__file__).resolve().parents[2]
        if not php or not (root / 'backend/vendor/autoload.php').is_file():
            self.skipTest('PHP and Composer are required for the cross-language gate')
        candidate = prepare(self.source, self.out, '1.2.3', SHA)
        script = root / 'qa/cpanel_signed_update_artifact_probe.php'
        env = os.environ.copy()
        env['GITHUB_ACTIONS'] = 'true'
        result = subprocess.run(
            [php, str(script), str(self.out.resolve()), '1.2.3', SHA],
            cwd=root, env=env, text=True, capture_output=True, check=True,
        )
        receipt = json.loads(result.stdout)
        self.assertEqual(receipt['schema'], 'walidsite.signed-zip-ci-acceptance.v1')
        self.assertEqual(receipt['source_sha'], SHA)
        self.assertEqual(receipt['version'], '1.2.3')
        self.assertEqual(receipt['artifact_sha256'], candidate['update']['sha256'])
        self.assertGreaterEqual(receipt['verified_files'], 4)
        self.assertEqual(receipt['negative_rejections'], 7)
        self.assertTrue(receipt['test_only_ephemeral_key'])
        self.assertFalse(receipt['publisher_signature_verified'])
        self.assertFalse(receipt['code_update_performed'])
        self.assertFalse(receipt['production_ready'])
        self.assertNotIn('PRIVATE KEY', result.stdout)
        self.assertNotIn('signature', result.stdout.lower().replace(
            'publisher_signature_verified', ''))

        # A post-build artifact modification must be rejected BEFORE a valid
        # synthetic signature could be used to inspect its contents.
        with (self.out / candidate['update']['file']).open('ab') as out:
            out.write(b'candidate has changed')
        failed = subprocess.run(
            [php, str(script), str(self.out.resolve()), '1.2.3', SHA],
            cwd=root, env=env, text=True, capture_output=True,
        )
        self.assertEqual(failed.returncode, 1)
        self.assertNotIn('PRIVATE KEY', failed.stderr)
        self.assertEqual(failed.stdout, '')

    def test_main_ci_generates_complete_unpublished_release_train_from_same_setup_zip(self):
        workflow = (Path(__file__).resolve().parents[2] /
                    '.github/workflows/ci.yml').read_text()
        for expected in (
            'python3 -m qa.prepare_release_candidate',
            '--cpanel-zip "$package"',
            '--version "$version" --sha "$source_sha"',
            'sha256sum --check ./*.sha256',
            'WalidSite-UNPUBLISHED-release-train-',
            'dist/release-train-receipt.json',
            'php qa/cpanel_signed_update_artifact_probe.php',
            'dist/release-train/signed-zip-acceptance.json',
            'proof["test_only_ephemeral_key"] is True',
            'proof["publisher_signature_verified"] is False',
            'receipt["publisher_signature_verified"] is False',
            'receipt["production_ready"] is False',
            'assert all(value is False for value in receipt["acceptance"].values())',
            'assert not (directory / "latest.json").exists()',
        ):
            self.assertIn(expected, workflow)
        self.assertNotIn('cp docs/releases/WalidSite-v0.1.1.md', workflow)
        self.assertNotIn('name: WalidSite-Production-release-train', workflow)

    def test_release_candidate_shell_command_is_one_invocation_with_proper_notes(self):
        """The main-only build job is not exercised by ordinary PR CI.
        Execute its command layout with harmless printf in a real Bash shell;
        double-backslash YAML edits previously broke physical line joining.
        """
        import shutil
        import subprocess
        if not shutil.which('bash'):
            self.skipTest('Bash acceptance is required on the Ubuntu runner')
        lines = (Path(__file__).resolve().parents[2] /
                 '.github/workflows/ci.yml').read_text().splitlines()
        start = next(i for i, line in enumerate(lines)
                     if 'PYTHONPATH="$PWD" python3 -m qa.prepare_release_candidate' in line)
        command_lines = [line.strip() for line in lines[start:start + 4]]
        for part in command_lines[:3]:
            self.assertTrue(part.endswith('\\'), part)
            self.assertFalse(part.endswith('\\\\'),
                             'Bash uses two backslashes as an escaped literal, not continuation')
        self.assertEqual(command_lines[3], '> dist/release-train-receipt.json')

        script = '\n'.join(command_lines)
        script = script.replace('PYTHONPATH="$PWD" python3 -m qa.prepare_release_candidate',
                                r"printf '%s\n'")
        script = script.replace('> dist/release-train-receipt.json', '')
        script = ('package=setup.zip; version=1.2.3; source_sha=' + SHA +
                  '\n' + script)
        executed = subprocess.run(['bash', '-e', '-c', script], capture_output=True,
                                  text=True, check=True)
        self.assertEqual(executed.stdout.splitlines(), [
            '--cpanel-zip', 'setup.zip',
            '--output-dir', 'dist/release-train',
            '--version', '1.2.3',
            '--sha', SHA,
        ])

        note = next(line for line in lines
                    if line.strip().startswith("printf '# WalidSite v%s"))
        self.assertIn(r'only\n\nSource SHA: %s\n\nNot published', note)
        self.assertNotIn(r'only\\n', note)
        self.assertTrue(note.endswith('\\'))
        self.assertFalse(note.endswith('\\\\'))

    def test_workflow_guards_tested_sha_and_blocks_release_promotion(self):
        workflow = (Path(__file__).resolve().parents[2] / '.github/workflows/release.yml').read_text()
        for item in ('workflow_dispatch:', 'qa/verify_exact_main_release_ci.py',
                     'actions/runs/${ci_run_id}/jobs?per_page=100',
                     'qa/prepare_release_candidate.py', 'actions/upload-artifact@v4',
                     'exit 1', 'github.sha'):
            self.assertIn(item, workflow)
        verifier = (Path(__file__).resolve().parents[2] /
                    'qa/verify_exact_main_release_ci.py').read_text()
        for required in ('required-ci-gate', 'build-cpanel-package',
                         'build-android-qa-apk', 'android-emulator-install-launch',
                         'verify-source-pinned-release-bundle'):
            self.assertIn(f'"{required}"', verifier)
        # Automatic Release candidates cannot sign, while the dedicated
        # manual-only job may inspect genuine publisher-signed Web bytes.
        before_manual, manual = workflow.split('  signed-web-inspection:', 1)
        manual, after_manual = manual.split('  signed-android-inspection:', 1)
        self.assertNotIn('sign_trusted_update_release.php', before_manual)
        self.assertIn("if: github.event_name == 'workflow_dispatch'", manual)
        self.assertIn('sign_trusted_update_release.php', manual)
        self.assertIn('qa/verify_signed_web_inspection.php', manual)
        self.assertIn('exit 1', after_manual.split('  release-gate:', 1)[1])
        self.assertNotIn('gh release create', workflow)


if __name__ == '__main__':
    unittest.main()
