#!/usr/bin/env python3
"""Derive two UNSIGNED, UNPUBLISHED release-candidate ZIPs from one verified cPanel build.

This is reproducible packaging/inspection, not authority to publish or install code.
A separately controlled publisher must sign and promote any approved update.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import unicodedata
import zipfile
from pathlib import Path

from qa.build_cpanel_package import verify_archive
from qa.build_trusted_update_release import build as build_update, frozen_setup_archive

SEMVER = re.compile(r'(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\Z')
SOURCE_SHA = re.compile(r'[0-9a-f]{40}\Z')


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def prepare(cpanel_zip: Path, output_dir: Path, version: str, sha: str) -> dict:
    if not SEMVER.fullmatch(version) or not SOURCE_SHA.fullmatch(sha):
        raise ValueError('Invalid immutable SemVer or source SHA')
    if (cpanel_zip.is_symlink() or not cpanel_zip.is_file()
            or output_dir.is_symlink() or not output_dir.is_dir()):
        raise ValueError('Unsafe source ZIP or output directory')

    setup = output_dir / f'WalidSite-Setup-v{version}.zip'
    update = output_dir / f'WalidSite-Update-v{version}.zip'
    generated_update = output_dir / f'WalidSite-update-v{version}.zip'
    manifest = output_dir / f'WalidSite-Release-Manifest-v{version}.json'
    notes = output_dir / f'WalidSite-Release-Notes-v{version}.md'
    build_info = output_dir / f'WalidSite-Build-Info-v{version}.json'
    build_info_checksum = output_dir / (build_info.name + '.sha256')
    candidates = [setup, update, generated_update, manifest, notes,
                  build_info, build_info_checksum,
                  output_dir / (setup.name + '.sha256'),
                  output_dir / (update.name + '.sha256'),
                  output_dir / (manifest.name + '.sha256'),
                  output_dir / (notes.name + '.sha256')]
    if any(path.exists() or path.is_symlink() for path in candidates):
        raise ValueError('Immutable candidate output already exists')

    # All verification, Setup bytes and Update bytes must derive from one
    # private no-follow snapshot. Reopening the operator-controlled pathname
    # after verifying it could combine two different releases in one receipt.
    with frozen_setup_archive(cpanel_zip) as frozen:
        # Verify every source file hash, required private guards and source SHA
        # BEFORE emitting artifacts. Valid ZIP CRC alone is not sufficient.
        original_manifest = verify_archive(frozen, sha)
        # The release name must be the application's own attested SemVer, not
        # arbitrary operator input. The cPanel verifier binds application/VERSION
        # byte-for-byte to the manifest before we reach this check.
        if original_manifest.get('version') != version:
            raise ValueError('Candidate version does not match verified source VERSION')
        inventory = original_manifest['files']
        composer_lock = inventory['application/composer.lock']['sha256']
        # The dependency graph and migration hashes come from the SAME frozen,
        # fully verified Setup ZIP, never the mutable repository checkout.
        with zipfile.ZipFile(frozen) as source_archive:
            lock = json.loads(source_archive.read('application/composer.lock'))
        if not isinstance(lock, dict) or not isinstance(lock.get('packages'), list):
            raise ValueError('Invalid frozen production Composer lock')
        dependencies = []
        for package in lock['packages']:
            if (not isinstance(package, dict)
                    or not isinstance(package.get('name'), str)
                    or not re.fullmatch(r'[a-z0-9_.-]+/[a-z0-9_.-]+', package['name'])
                    or not isinstance(package.get('version'), str)
                    or not package['version'] or len(package['version']) > 80
                    # Source-verified Composer metadata is still untrusted
                    # display/provenance input. Reject invisible format,
                    # control and whitespace characters (including bidi
                    # overrides) before serializing immutable Build Info.
                    or any(unicodedata.category(char)[0] in ('C', 'Z')
                           for char in package['version'])):
                raise ValueError('Invalid frozen Composer dependency identity')
            dependencies.append({'name': package['name'], 'version': package['version']})
        if len(dependencies) > 2000 or len({item['name'] for item in dependencies}) != len(dependencies):
            raise ValueError('Unsafe frozen Composer dependency inventory')
        dependencies.sort(key=lambda item: item['name'])
        migrations = {
            name.removeprefix('application/database/migrations/'): inventory[name]['sha256']
            for name in sorted(inventory)
            if name.startswith('application/database/migrations/') and name.endswith('.php')
        }
        created: list[Path] = []
        try:
            with frozen.open('rb') as incoming, setup.open('xb') as outgoing:
                created.append(setup)
                shutil.copyfileobj(incoming, outgoing, length=1024 * 1024)
            if _sha256(setup) != _sha256(frozen):
                raise ValueError('Setup copy integrity mismatch')
            verify_archive(setup, sha)

            update_receipt = build_update(frozen, output_dir, version, sha)
            created.append(generated_update)
            if update_receipt['publisher_signature_verified'] is not False:
                raise ValueError('Unexpected purported publisher signature')
            # Keep a user-facing Update candidate while recording the canonical
            # lowercase publisher ZIP name. No signed latest.json is emitted here.
            # rename() can overwrite a competing immutable release created
            # after our preflight. Same-directory link() is no-replace on POSIX:
            # a competing publisher's output must never be replaced or removed.
            os.link(generated_update, update)
            created.append(update)
            generated_update.unlink()
            created.remove(generated_update)
            update_hash = _sha256(update)
            if update_hash != update_receipt['artifact_sha256']:
                raise ValueError('Update artifact digest mismatch')

            build_details = {
                'schema': 'walidsite.unpublished-build-info.v1',
                'product': 'walidsite',
                'version': version,
                'source_sha': sha,
                'setup_sha256': _sha256(setup),
                'update_sha256': update_hash,
                'composer_lock_sha256': composer_lock,
                'production_dependencies': dependencies,
                'migration_sha256': migrations,
                'minimum_php': update_receipt['min_php'],
                'publisher_signature_verified': False,
                'upgrade_compatibility_verified': False,
                'production_ready': False,
            }
            serialized_build = (json.dumps(build_details, indent=2, sort_keys=True) + '\n').encode('utf-8')
            build_digest = hashlib.sha256(serialized_build).hexdigest()
            with build_info.open('xb') as stream:
                created.append(build_info)
                stream.write(serialized_build)
            with build_info_checksum.open('x', encoding='ascii') as stream:
                created.append(build_info_checksum)
                stream.write(f'{build_digest}  {build_info.name}\n')

            result = {
                'schema': 'walidsite.release-candidate.v1',
                'product': 'walidsite', 'version': version, 'source_sha': sha,
                'published': False, 'production_ready': False,
                'publisher_signature_verified': False,
                'build_info': {'file': build_info.name, 'sha256': build_digest,
                               'composer_lock_sha256': composer_lock,
                               'production_dependencies': len(dependencies),
                               'migration_files': len(migrations)},
                'setup': {'file': setup.name, 'sha256': _sha256(setup),
                          'bytes': setup.stat().st_size,
                          'source_manifest_schema': original_manifest['schema']},
                'update': {'file': update.name, 'sha256': update_hash,
                           'bytes': update.stat().st_size, 'min_php': update_receipt['min_php'],
                           'signed_catalog_included': False,
                           'publisher_upload_name': update_receipt['artifact_file']},
                'acceptance': {
                    'fresh_install_on_live_host': False,
                    'admin_full_code_apply_and_recovery': False,
                    'signed_publisher_release': False,
                    'authorized_https_staging': False,
                },
            }
            for archive, digest in ((setup, result['setup']['sha256']),
                                    (update, result['update']['sha256'])):
                sidecar = output_dir / (archive.name + '.sha256')
                with sidecar.open('x', encoding='ascii') as stream:
                    created.append(sidecar)
                    stream.write(f'{digest}  {archive.name}\n')
            with manifest.open('x', encoding='utf-8') as stream:
                created.append(manifest)
                json.dump(result, stream, indent=2, sort_keys=True)
                stream.write('\n')
            with notes.open('x', encoding='utf-8') as stream:
                created.append(notes)
                stream.write(f'# WalidSite v{version} — UNPUBLISHED candidate\n\n')
                stream.write(f'Exact source SHA: `{sha}`. This candidate is **not a production release**.\n\n')
                stream.write('The Setup ZIP is byte-identical to the source-verified cPanel ZIP. '
                             'The Update ZIP is code-only; it has **no publisher signature or trusted latest.json**.\n\n')
                stream.write('The checksummed Build Info binds production Composer dependency versions, '
                             'Composer lock SHA-256 and migration SHA-256 inventory to both ZIP hashes. '
                             'This metadata is **unsigned evidence**, not a successful upgrade test.\n\n')
                stream.write('Release blocked pending trusted publisher signing, compatible initial '
                             'cPanel layout, live browser install, Admin code apply, backup/restore '
                             'and HTTPS staging smoke. Do not deploy this Update ZIP as a trusted release.\n')
            # The manifest and notes are immutable candidate evidence too.
            # SHA-256 detects accidental/tampered bytes but does not establish
            # publisher authenticity without a separate trusted signature.
            for document in (manifest, notes):
                sidecar = output_dir / (document.name + '.sha256')
                with sidecar.open('x', encoding='ascii') as stream:
                    created.append(sidecar)
                    stream.write(f'{_sha256(document)}  {document.name}\\n')
            return result
        except Exception:
            for path in reversed(created):
                path.unlink(missing_ok=True)
            # build_update() owns its own failed output cleanup. Never unlink
            # a pathname we did not create: it may belong to a concurrent run.
            raise


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--cpanel-zip', required=True, type=Path)
    parser.add_argument('--output-dir', required=True, type=Path)
    parser.add_argument('--version', required=True)
    parser.add_argument('--sha', required=True)
    args = parser.parse_args()
    try:
        result = prepare(args.cpanel_zip, args.output_dir, args.version, args.sha)
    except (ValueError, OSError, zipfile.BadZipFile, KeyError):
        print(json.dumps({'candidate_prepared': False, 'production_ready': False}))
        return 1
    print(json.dumps({'candidate_prepared': True, **result}, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
