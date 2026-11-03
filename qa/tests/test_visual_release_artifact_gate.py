"""Synthetic tests for T026 release artifact integrity; no signing/deployment claims."""
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from contextlib import redirect_stdout, redirect_stderr
import io

from qa.release_artifact_gate import verify, main, artifact_name

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
                     [{**self.entry, "kind": "ios-ipa"}]):
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
