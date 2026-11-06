"""Synthetic tests for T026 release artifact integrity; no signing/deployment claims."""
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from contextlib import contextmanager, redirect_stdout, redirect_stderr
import io
import os

from qa import release_artifact_gate as gate
from qa.release_artifact_gate import verify, main, artifact_name, _stable_reader

SHA = "a" * 40


class ReleaseArtifactGateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.pkg = self.root / "walidsite-1.2.3.zip"
        self.pkg.write_bytes(b"test fixture, not a production release")
        self.entry = {"name": self.pkg.name, "kind": "web-cpanel-zip",
                      "bytes": self.pkg.stat().st_size,
                      "sha256": hashlib.sha256(self.pkg.read_bytes()).hexdigest()}
        self.data = {"schema": "walidsite.release-artifact-inventory.v1",
                     "version": "1.2.3", "source_sha": SHA,
                     "artifacts": [self.entry]}
        self.manifest = self.root / "manifest.json"
        self.save()

    def save(self):
        self.manifest.write_text(json.dumps(self.data), encoding="utf-8")

    def test_valid_offline_bytes_never_imply_signed_or_shipped(self):
        report = verify(self.root, self.manifest, SHA)
        self.assertTrue(report["artifact_integrity_verified"])
        self.assertFalse(report["production_ready"])
        for name in ("reproducible_build_verified", "signing_identity_verified",
                     "store_distribution_verified", "staging_deployment_verified"):
            self.assertFalse(report[name])
        self.assertEqual(report["source_sha"], SHA)

    def test_wrong_hash_size_or_mutated_artifact_fail_closed(self):
        for key, value in (("sha256", "0" * 64), ("bytes", self.entry["bytes"] + 1),
                           ("bytes", True), ("sha256", "x" * 64)):
            with self.subTest(key=key, value=value):
                self.data["artifacts"] = [{**self.entry, key: value}]
                self.save()
                with self.assertRaises(ValueError):
                    verify(self.root, self.manifest, SHA)
        self.data["artifacts"] = [self.entry]
        self.save()
        self.pkg.write_bytes(b"tampered")
        with self.assertRaises(ValueError):
            verify(self.root, self.manifest, SHA)

    def test_wrong_source_and_invalid_versions_fail(self):
        with self.assertRaises(ValueError):
            verify(self.root, self.manifest, "b" * 40)
        for value in ("1", "1.2", "01.2.3", "1.2.3\n", "1.2.3/../../"):
            with self.subTest(value=value):
                self.data["version"] = value
                self.save()
                with self.assertRaises(ValueError):
                    verify(self.root, self.manifest, SHA)

    def test_path_traversal_duplicates_and_wrong_extension_fail(self):
        for name in ("../private.zip", "/etc/passwd", "nested/file.zip", ".hidden.zip",
                     "..zip", "bad\\path.zip", "evil%2F.zip"):
            self.assertFalse(artifact_name(name), name)
        for item in ([self.entry, self.entry], [{**self.entry, "name": "../outside.zip"}],
                     [{**self.entry, "kind": "ios-ipa"}],
                     [{**self.entry, "kind": []}]):
            self.data["artifacts"] = item
            self.save()
            with self.assertRaises(ValueError):
                verify(self.root, self.manifest, SHA)

    def test_symlink_artifact_and_manifest_are_rejected(self):
        target = self.root / "target.zip"
        target.write_bytes(self.pkg.read_bytes())
        self.pkg.unlink()
        self.pkg.symlink_to(target)
        with self.assertRaises(ValueError):
            verify(self.root, self.manifest, SHA)
        self.pkg.unlink()
        self.pkg.write_bytes(target.read_bytes())
        alternative = self.root / "alternate.json"
        alternative.symlink_to(self.manifest)
        with self.assertRaises(ValueError):
            verify(self.root, alternative, SHA)

    def test_group_or_world_writable_release_inputs_are_not_attested(self):
        # Rehashing attacker-writable files is not a release-provenance proof:
        # another local hosting principal can change the pathname after PASS.
        for subject in ("artifact", "manifest", "directory"):
            with self.subTest(subject=subject):
                path = {"artifact": self.pkg, "manifest": self.manifest,
                        "directory": self.root}[subject]
                original_mode = path.stat().st_mode & 0o777
                try:
                    path.chmod(original_mode | 0o020)
                    with self.assertRaisesRegex(ValueError, "changed during verification"):
                        verify(self.root, self.manifest, SHA)
                    path.chmod(original_mode | 0o002)
                    with self.assertRaisesRegex(ValueError, "changed during verification"):
                        verify(self.root, self.manifest, SHA)
                finally:
                    path.chmod(original_mode)
                self.assertTrue(verify(self.root, self.manifest, SHA)[
                    "artifact_integrity_verified"])

    def test_manifest_outside_artifact_directory_is_supported(self):
        # Publication keeps the manifest beside (not necessarily inside)
        # the directory containing the actual release artifacts.
        release_dir = self.root / "artifacts"
        release_dir.mkdir()
        self.pkg.rename(release_dir / self.pkg.name)
        report = verify(release_dir, self.manifest, SHA)
        self.assertTrue(report["artifact_integrity_verified"])
        self.assertFalse(report["production_ready"])

    def test_manifest_parent_must_be_private_even_when_manifest_is_read_only(self):
        parent = self.root / "manifest-owner"
        parent.mkdir(mode=0o700)
        candidate = parent / "manifest.json"
        self.manifest.rename(candidate)
        original = parent.stat().st_mode & 0o777
        try:
            parent.chmod(0o770)
            with self.assertRaisesRegex(ValueError, "directory changed"):
                verify(self.root, candidate, SHA)
            parent.chmod(0o702)
            with self.assertRaisesRegex(ValueError, "directory changed"):
                verify(self.root, candidate, SHA)
        finally:
            parent.chmod(original)
        self.assertTrue(verify(self.root, candidate, SHA)["artifact_integrity_verified"])

    def test_manifest_parent_swap_during_read_is_rejected(self):
        parent = self.root / "manifest-owner"
        parent.mkdir(mode=0o700)
        candidate = parent / "manifest.json"
        self.manifest.rename(candidate)
        retired = self.root / "retired-manifest-owner"
        original_reader = gate._stable_reader
        swapped = False

        @contextmanager
        def swap_manifest_parent(path, *, dir_fd=None):
            nonlocal swapped
            with original_reader(path, dir_fd=dir_fd) as item:
                yield item
                if path == "manifest.json" and dir_fd is not None and not swapped:
                    parent.rename(retired)
                    parent.mkdir(mode=0o700)
                    (parent / "manifest.json").write_bytes(
                        (retired / "manifest.json").read_bytes())
                    swapped = True

        with patch.object(gate, "_stable_reader", swap_manifest_parent):
            with self.assertRaisesRegex(ValueError, "directory changed"):
                verify(self.root, candidate, SHA)
        self.assertTrue(swapped)

    def test_manifest_inode_swapped_during_artifact_hashing_is_rejected(self):
        # The original verifier released the manifest descriptor immediately
        # after parsing JSON. A later identical-byte replacement then passed.
        original_reader = gate._stable_reader
        retired = self.root / "retired-manifest.json"
        swapped = False

        @contextmanager
        def swap_after_artifact(path, *, dir_fd=None):
            nonlocal swapped
            with original_reader(path, dir_fd=dir_fd) as item:
                yield item
                if path == self.pkg.name and dir_fd is not None and not swapped:
                    self.manifest.rename(retired)
                    self.manifest.write_bytes(retired.read_bytes())
                    swapped = True

        with patch.object(gate, "_stable_reader", swap_after_artifact):
            with self.assertRaisesRegex(ValueError, r"Release input (?:pathname )?changed"):
                verify(self.root, self.manifest, SHA)
        self.assertTrue(swapped)

    def test_manifest_parent_swapped_during_artifact_hashing_is_rejected(self):
        # A pinned parent during manifest parsing is insufficient: the parent
        # must remain the named directory throughout referenced ZIP hashing.
        parent = self.root / "publisher-manifest"
        parent.mkdir(mode=0o700)
        candidate = parent / "manifest.json"
        self.manifest.rename(candidate)
        retired = self.root / "retired-publisher-manifest"
        original_reader = gate._stable_reader
        swapped = False

        @contextmanager
        def swap_after_artifact(path, *, dir_fd=None):
            nonlocal swapped
            with original_reader(path, dir_fd=dir_fd) as item:
                yield item
                if path == self.pkg.name and dir_fd is not None and not swapped:
                    parent.rename(retired)
                    parent.mkdir(mode=0o700)
                    candidate.write_bytes((retired / "manifest.json").read_bytes())
                    swapped = True

        with patch.object(gate, "_stable_reader", swap_after_artifact):
            with self.assertRaisesRegex(ValueError, "directory changed"):
                verify(self.root, candidate, SHA)
        self.assertTrue(swapped)

    def test_artifact_directory_symlink_fails_closed(self):
        directory_link = self.root / "alias-artifacts"
        directory_link.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(ValueError):
            verify(directory_link, self.manifest, SHA)

    def test_root_never_resolves_racy_untrusted_path_before_no_follow_open(self):
        # Path.resolve() here would allow a root swapped to a symlink after
        # is_symlink() preflight to be trusted as an outside release folder.
        # Directory O_NOFOLLOW must be applied to the ORIGINAL pathname.
        with patch.object(Path, "resolve", side_effect=AssertionError(
            "Root pathname must not be resolved before secure open"
        )):
            result = verify(self.root, self.manifest, SHA)
        self.assertTrue(result["artifact_integrity_verified"])

    def test_open_descriptor_detects_in_place_change_even_when_size_unchanged(self):
        with self.assertRaisesRegex(ValueError, "changed during verification"):
            with _stable_reader(self.pkg) as (stream, metadata):
                self.assertEqual(stream.read(), self.pkg.read_bytes())
                # Size is the same; a pre-read Path.stat() could not catch
                # this write, and hashing a path after reopening is racy.
                self.pkg.write_bytes(b"Z" * metadata.st_size)

    def test_racy_path_stat_cannot_authorize_swapped_artifact(self):
        # Before the fix Path.is_file() used Path.stat() and then reopened
        # the path. A competitor could swap it to an outside symlink between
        # those steps. The old verifier accepted matching outside bytes.
        outside = self.root / "outside-secret.bin"
        malicious = b"adversary-controlled-digest"
        outside.write_bytes(malicious)
        self.pkg.write_bytes(b"X" * len(malicious))
        self.data["artifacts"] = [{
            **self.entry,
            "bytes": len(malicious),
            "sha256": hashlib.sha256(malicious).hexdigest(),
        }]
        self.save()
        original_stat = Path.stat
        changed = False

        def swap_during_path_stat(path, *args, **kwargs):
            nonlocal changed
            metadata = original_stat(path, *args, **kwargs)
            if path == self.pkg and not changed:
                changed = True
                self.pkg.unlink()
                self.pkg.symlink_to(outside)
            return metadata

        with patch.object(Path, "stat", autospec=True, side_effect=swap_during_path_stat):
            with self.assertRaises(ValueError):
                verify(self.root, self.manifest, SHA)
        # The new verifier never calls the attackable Path.stat on the
        # artifact; it hashes one opened, no-follow regular-file descriptor.
        self.assertFalse(changed)

    def test_named_artifact_and_manifest_swaps_fail_even_if_original_fd_is_stable(self):
        for name in ("artifact", "manifest"):
            with self.subTest(name=name):
                path = self.pkg if name == "artifact" else self.manifest
                original = path.read_bytes()
                relocated = path.with_name("relocated-" + path.name)
                with self.assertRaisesRegex(ValueError, "Release input (?:pathname )?changed during verification"):
                    with _stable_reader(path) as (stream, info):
                        self.assertEqual(stream.read(), original)
                        # Preserve all original bytes on the still-open inode:
                        # descriptor-only fstat checks would incorrectly pass.
                        path.rename(relocated)
                        path.write_bytes(original)
                        self.assertNotEqual(os.stat(path).st_ino, info.st_ino)
                path.unlink()
                relocated.rename(path)

    def test_hardlinked_manifest_and_artifact_never_receive_attestation(self):
        for path in (self.manifest, self.pkg):
            with self.subTest(path=path.name):
                linked = path.with_name("linked-" + path.name)
                os.link(path, linked)
                try:
                    with self.assertRaisesRegex(ValueError, "Release input (?:pathname )?changed during verification"):
                        verify(self.root, self.manifest, SHA)
                finally:
                    linked.unlink()

    def test_directory_path_swapped_mid_artifact_read_fails_even_with_pinned_fd(self):
        release_dir = self.root / "artifacts"
        release_dir.mkdir()
        self.pkg.rename(release_dir / self.pkg.name)
        original_reader = gate._stable_reader
        retired = self.root / "retired-artifacts"
        swapped = False

        @contextmanager
        def swap_after_artifact_read(path, *, dir_fd=None):
            nonlocal swapped
            with original_reader(path, dir_fd=dir_fd) as result:
                yield result
                if path == self.pkg.name and dir_fd is not None and not swapped:
                    # Relative artifact reads remain pinned to the old
                    # directory FD. The pathname now resolves to new bytes.
                    release_dir.rename(retired)
                    release_dir.mkdir()
                    (release_dir / self.pkg.name).write_bytes(b"unsafe replacement")
                    swapped = True

        with patch.object(gate, "_stable_reader", swap_after_artifact_read):
            with self.assertRaisesRegex(ValueError, "directory changed"):
                gate.verify(release_dir, self.manifest, SHA)
        self.assertTrue(swapped)
        self.assertEqual((retired / self.pkg.name).read_bytes(),
                         b"test fixture, not a production release")

    def test_duplicate_and_nonfinite_manifest_json_never_attested(self):
        # Default Python json.loads silently keeps the last duplicate key,
        # even when the first key uses Unicode JSON escapes. That allows an
        # ambiguous inventory to pass checksum verification.
        raw = json.dumps(self.data)
        version_duplicate = raw[:-1] + ', "version": "1.2.3"}'
        escaped_duplicate = raw[:-1] + r', "\u0076ersion": "1.2.3"}'
        digest_duplicate = raw.replace(
            '"sha256": ',
            '"sha256": "' + "0" * 64 + '", "sha256": ', 1,
        )
        nonfinite_masked = raw.replace(
            f'"bytes": {self.entry["bytes"]}',
            f'"bytes": NaN, "bytes": {self.entry["bytes"]}', 1,
        )
        for kind, content in (
            ("top_level_duplicate", version_duplicate),
            ("escaped_duplicate", escaped_duplicate),
            ("nested_checksum_duplicate", digest_duplicate),
            ("masked_nonfinite", nonfinite_masked),
        ):
            with self.subTest(kind=kind):
                self.manifest.write_text(content, encoding="utf-8")
                with self.assertRaisesRegex(ValueError, "Invalid JSON release manifest"):
                    verify(self.root, self.manifest, SHA)
        # Valid unambiguous manifests remain accepted by the same verifier.
        self.save()
        self.assertTrue(verify(self.root, self.manifest, SHA)[
            "artifact_integrity_verified"])

    def test_schema_unknown_fields_and_missing_artifacts_rejected(self):
        for mutation in (
            {**self.data, "unexpected": True},
            {**self.data, "schema": "other"},
            {**self.data, "artifacts": []},
            {**self.data, "artifacts": [{**self.entry, "signed": True}]},
        ):
            self.manifest.write_text(json.dumps(mutation), encoding="utf-8")
            with self.assertRaises(ValueError):
                verify(self.root, self.manifest, SHA)

    def test_cli_returns_observed_failure_without_leaking_paths(self):
        with redirect_stdout(io.StringIO()) as output:
            self.assertEqual(main(["--dir", str(self.root), "--manifest",
                                   str(self.manifest), "--sha", SHA]), 0)
        self.assertFalse(json.loads(output.getvalue())["production_ready"])
        with redirect_stderr(io.StringIO()) as error:
            self.assertEqual(main(["--dir", str(self.root), "--manifest",
                                   str(self.manifest), "--sha", "b" * 40]), 1)
        self.assertNotIn(str(self.root), error.getvalue())
        self.assertFalse(json.loads(error.getvalue())["production_ready"])


if __name__ == "__main__":
    unittest.main()
