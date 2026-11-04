#!/usr/bin/env python3
"""Derive two UNSIGNED, UNPUBLISHED release-candidate ZIPs from one verified cPanel build.

This is reproducible packaging/inspection, not authority to publish or install code.
A separately controlled publisher must sign and promote any approved update.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import zipfile
from pathlib import Path

from qa.build_cpanel_package import verify_archive
from qa.build_trusted_update_release import build as build_update

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
    candidates = [setup, update, generated_update, manifest, notes,
                  output_dir / (setup.name + '.sha256'),
                  output_dir / (update.name + '.sha256')]
    if any(path.exists() or path.is_symlink() for path in candidates):
        raise ValueError('Immutable candidate output already exists')

    # Verify every source file hash, required private guards and source SHA
    # BEFORE emitting artifacts. Valid ZIP CRC alone is not sufficient.
    original_manifest = verify_archive(cpanel_zip, sha)
    created: list[Path] = []
    try:
        with cpanel_zip.open('rb') as incoming, setup.open('xb') as outgoing:
            created.append(setup)
            shutil.copyfileobj(incoming, outgoing, length=1024 * 1024)
        if _sha256(setup) != _sha256(cpanel_zip):
            raise ValueError('Setup copy integrity mismatch')
        verify_archive(setup, sha)

        update_receipt = build_update(cpanel_zip, output_dir, version, sha)
        created.append(generated_update)
        if update_receipt['publisher_signature_verified'] is not False:
            raise ValueError('Unexpected purported publisher signature')
        # Keep a user-facing Update candidate while recording the canonical
        # lowercase publisher ZIP name. No signed latest.json is emitted here.
        generated_update.rename(update)
        created.remove(generated_update)
        created.append(update)
        update_hash = _sha256(update)
        if update_hash != update_receipt['artifact_sha256']:
            raise ValueError('Update artifact digest mismatch')

        result = {
            'schema': 'walidsite.release-candidate.v1',
            'product': 'walidsite', 'version': version, 'source_sha': sha,
            'published': False, 'production_ready': False,
            'publisher_signature_verified': False,
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
            stream.write('Release blocked pending trusted publisher signing, compatible initial '
                         'cPanel layout, live browser install, Admin code apply, backup/restore '
                         'and HTTPS staging smoke. Do not deploy this Update ZIP as a trusted release.\n')
        return result
    except Exception:
        for path in reversed(created):
            path.unlink(missing_ok=True)
        generated_update.unlink(missing_ok=True)
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
