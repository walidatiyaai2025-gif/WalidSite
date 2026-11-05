#!/usr/bin/env python3
"""Build the code-only archive consumed by TrustedReleaseArchive from a verified cPanel ZIP.

Integrity packaging only: Ed25519 signing/publishing is an independent privileged step.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import tempfile
import zipfile
from contextlib import contextmanager
from pathlib import Path

from qa.build_cpanel_package import verify_archive

REQUIRED = frozenset({
    'application/bootstrap/app.php', 'application/vendor/autoload.php',
    'public_html/index.php', 'public_html/.htaccess',
})
MAX_ENTRIES = 20000
MAX_TOTAL = 1073741824
MAX_FILE = 134217728
MAX_RATIO = 200
VERSION = re.compile(r'(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\Z')
NAME = re.compile(r'(?:application|public_html|contracts)(?:/[A-Za-z0-9_.@+ -]+)+\Z')


def code_path(name: str) -> bool:
    """Only versioned application code/assets; no secret, installation or user state."""
    if not NAME.fullmatch(name) or len(name) > 512 or '//' in name:
        return False
    parts = name.split('/')
    if any(p in ('', '.', '..') or (p.startswith('.') and p != '.htaccess') for p in parts):
        return False
    if any(p.lower() in ('site-installed.lock', 'site-first-run.json', 'site-setup-token', 'id_rsa', 'id_ed25519')
           or p.lower().startswith('.env') for p in parts):
        return False
    if name.startswith(('application/storage/', 'public_html/storage/', 'public_html/uploads/')):
        return False
    return True


@contextmanager
def frozen_setup_archive(source_zip: Path):
    """Pin source bytes before the verifier and Update ZIP builder use them.

    A verified source pathname can otherwise be replaced before a later
    ZipFile(path) read. A private, frozen copy is the single source for both
    operations; cross-user access to its temporary directory is prohibited.
    Linux/POSIX no-follow descriptors are mandatory for release packaging.
    """
    if (os.name != 'posix' or not getattr(os, 'O_NOFOLLOW', 0)
            or not getattr(os, 'O_NONBLOCK', 0)):
        raise ValueError('Secure source archive descriptors unavailable')
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | getattr(os, 'O_CLOEXEC', 0)

    def identity(s):
        return (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)

    with tempfile.TemporaryDirectory(prefix='walidsite-verified-setup-') as private:
        frozen = Path(private) / 'source.zip'
        with os.fdopen(os.open(source_zip, flags), 'rb') as original:
            before = os.fstat(original.fileno())
            named_before = os.stat(source_zip, follow_symlinks=False)
            if (not stat.S_ISREG(before.st_mode)
                    or not stat.S_ISREG(named_before.st_mode)
                    or identity(before) != identity(named_before)
                    or before.st_nlink != 1 or named_before.st_nlink != 1
                    or not 0 < before.st_size <= MAX_TOTAL):
                raise ValueError('Unsafe, unstable or oversized Setup source')

            copied = 0
            with frozen.open('xb') as output:
                while True:
                    chunk = original.read(1024 * 1024)
                    if not chunk:
                        break
                    copied += len(chunk)
                    if copied > MAX_TOTAL:
                        raise ValueError('Setup source grew past release limit')
                    output.write(chunk)
                output.flush()
            after = os.fstat(original.fileno())
            named_after = os.stat(source_zip, follow_symlinks=False)
            if (copied != before.st_size
                    or identity(before) != identity(after)
                    or identity(after) != identity(named_after)
                    or after.st_nlink != 1 or named_after.st_nlink != 1
                    or not stat.S_ISREG(named_after.st_mode)):
                raise ValueError('Setup source changed during release snapshot')
        # Only our private (0700) temporary directory exposes this file.
        # verify_archive and ZipFile now consume exactly these immutable bytes.
        yield frozen


def build(source_zip: Path, output_dir: Path, version: str, source_sha: str) -> dict:
    if not VERSION.fullmatch(version) or not re.fullmatch('[a-f0-9]{40}', source_sha):
        raise ValueError('Invalid immutable release version or source SHA')
    if source_zip.is_symlink() or not source_zip.is_file() or output_dir.is_symlink() or not output_dir.is_dir():
        raise ValueError('Unsafe source archive or target directory')
    with frozen_setup_archive(source_zip) as frozen:
        # Require the original packager's checked-in guard bytes, full byte inventory,
        # source SHA, clean contents and provenance before deriving an updater ZIP.
        manifest = verify_archive(frozen, source_sha)
        if manifest.get('version') != version:
            raise ValueError('Update version does not match the verified Setup ZIP')
        files = {}
        total = 0
        with zipfile.ZipFile(frozen) as package:
            if len(package.infolist()) > MAX_ENTRIES:
                raise ValueError('Excessive cPanel source entry count')
            for info in package.infolist():
                name = info.filename
                if name in ('PACKAGE-MANIFEST.json', 'INSTALL-CPANEL-AR.md'):
                    continue
                if not name.startswith(('application/', 'public_html/', 'contracts/')):
                    raise ValueError('Unexpected cPanel package entry')
                if not code_path(name):
                    # The installer has .env.example and storage scaffolding; these
                    # MUST NOT be carried into a code-only update.
                    continue
                if info.is_dir() or info.file_size > MAX_FILE or info.compress_size == 0 and info.file_size != 0:
                    raise ValueError('Invalid code release entry')
                if info.file_size > 1048576 and info.file_size > info.compress_size * MAX_RATIO:
                    raise ValueError('Excessive compression ratio')
                if stat.S_IFMT(info.external_attr >> 16) == stat.S_IFLNK:
                    raise ValueError('Symbolic links are forbidden')
                total += info.file_size
                if total > MAX_TOTAL:
                    raise ValueError('Expanded release size exceeds limit')
                files[name] = package.read(info)
    if not REQUIRED.issubset(files):
        raise ValueError('Incomplete update code payload')
    if len(files) > MAX_ENTRIES:
        raise ValueError('Excessive release file count')
    filename = f'WalidSite-update-v{version}.zip'
    target = output_dir / filename
    if target.exists() or target.is_symlink():
        raise ValueError('Refusing to overwrite an existing immutable release')
    with target.open('xb') as stream:
        try:
            with zipfile.ZipFile(stream, 'w', zipfile.ZIP_DEFLATED, compresslevel=8) as archive:
                for name in sorted(files):
                    entry = zipfile.ZipInfo(name, (2026, 11, 1, 0, 0, 0))
                    entry.compress_type = zipfile.ZIP_DEFLATED
                    entry.external_attr = 0o100644 << 16
                    archive.writestr(entry, files[name])
        except Exception:
            target.unlink(missing_ok=True)
            raise
    size = target.stat().st_size
    if size > MAX_TOTAL:
        target.unlink()
        raise ValueError('Compressed update archive exceeds 1 GiB')
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    # EXACT signed payload schema of PHP TrustedReleaseMetadata::verify().
    # This JSON is UNSIGNED. Do not publish latest.json or enable Admin apply.
    return {'product': 'walidsite', 'version': version, 'source_sha': source_sha,
            'artifact_sha256': digest, 'artifact_size': size,
            'min_php': '8.3.0', 'artifact_file': filename,
            'publisher_signature_verified': False}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--cpanel-zip', required=True, type=Path)
    parser.add_argument('--output-dir', required=True, type=Path)
    parser.add_argument('--version', required=True)
    parser.add_argument('--sha', required=True)
    args = parser.parse_args()
    try:
        result = build(args.cpanel_zip, args.output_dir, args.version, args.sha)
    except (ValueError, OSError, zipfile.BadZipFile):
        print(json.dumps({'update_artifact_prepared': False, 'publisher_signature_verified': False}))
        return 1
    print(json.dumps({'update_artifact_prepared': True, **result}, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
