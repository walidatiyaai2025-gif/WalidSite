"""T024 safety regressions: fixture bookkeeping is NOT real runtime evidence."""
import hashlib
import io
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

from qa.t024_readiness import (
    ROOT, audit, main, openapi_operations, proof_ok, source_proof_ok,
)


class T024ReadinessTest(unittest.TestCase):
    def test_real_contract_inventory_does_not_claim_unverified_qa(self):
        report, errors = audit(ROOT)
        self.assertEqual(errors, [])
        self.assertEqual(report["screens"], {
            "total": 40, "visually_matched": 0, "unmatched": 40
        })
        self.assertEqual(report["screen_actions"]["total"], 97)
        self.assertEqual(report["openapi"]["paths"], 42)
        self.assertEqual(report["openapi"]["operations"], 46)
        self.assertEqual(report["gates"], {
            "total": 20, "with_pinned_proof": 0,
            "deferred_external": 2, "unverified": 18
        })
        self.assertEqual(len(report["unverified_actions"]), 97)
        self.assertEqual(len(report["unverified_api_operations"]), 46)
        self.assertEqual(len(report["pending_screen_ids"]), 40)
        self.assertFalse(report["automatic_runtime_certification"])
        self.assertFalse(report["engineering_ready"])
        with redirect_stdout(io.StringIO()):
            self.assertEqual(main(["--root", str(ROOT)]), 0)
            self.assertEqual(main(["--root", str(ROOT), "--require-ready"]), 2)

    def test_openapi_parser_counts_only_paths_methods_and_rejects_duplicates(self):
        paths, operations = openapi_operations(
            "openapi: 3.1.0\npaths:\n  /public/home:\n"
            "    get:\n      responses:\n        '200':\n"
            "    post:\n      schema:\n        get:\n"
            "components:\n  schemas:\n    post:\n"
        )
        self.assertEqual(paths, {"/public/home"})
        self.assertEqual(operations, {"/public/home#get", "/public/home#post"})
        with self.assertRaises(ValueError):
            openapi_operations("paths:\n  /one:\n    get:\n    get:\n")
        with self.assertRaises(ValueError):
            openapi_operations("paths:\n  /one:\n    get:\n  /one:\n    post:\n")
        with self.assertRaises(ValueError):
            openapi_operations("paths:\n    get:\n")

    def test_integrity_pinned_proofs_refuse_untrusted_paths_and_symlinks(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            directory = root / "qa/t024-evidence"
            directory.mkdir(parents=True)
            file = directory / "fixture.json"
            file.write_text('{"synthetic_test_only":true}', encoding="utf-8")
            entry = {
                "path": "qa/t024-evidence/fixture.json",
                "sha256": hashlib.sha256(file.read_bytes()).hexdigest(),
                "source_sha": "a" * 40,
                "command": "python -m unittest qa.tests.test_visual_t024_readiness",
            }
            self.assertTrue(proof_ok(root, entry))
            # The SHA256 of empty bytes is valid cryptographically, but an
            # empty file is not evidence of an executed QA/runtime check.
            empty = directory / "empty.json"
            empty.write_bytes(b"")
            self.assertFalse(proof_ok(root, {
                **entry,
                "path": "qa/t024-evidence/empty.json",
                "sha256": hashlib.sha256(b"").hexdigest(),
            }))
            self.assertFalse(proof_ok(root, {**entry, "sha256": "0" * 64}))
            self.assertFalse(proof_ok(root, {**entry, "path": "../outside.json"}))
            self.assertFalse(proof_ok(root, {**entry, "command": ""}))
            self.assertFalse(proof_ok(root, {**entry, "private_token": "secret"}))
            link = directory / "alias.json"
            link.symlink_to(file)
            self.assertFalse(proof_ok(root, {**entry, "path": "qa/t024-evidence/alias.json"}))

    def test_evidence_descriptor_verification_rejects_path_and_inode_races(self):
        """Adversarial local files must not turn a fake QA receipt into proof."""
        if os.name != "posix" or not hasattr(os, "O_NOFOLLOW"):
            self.skipTest("Requires POSIX descriptor-relative no-follow opens")
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            directory = root / "qa/t024-evidence"
            directory.mkdir(parents=True)
            fixture = directory / "proof.json"
            original = b'{"fixture":"not-runtime-proof"}'
            fixture.write_bytes(original)
            entry = {
                "path": "qa/t024-evidence/proof.json",
                "sha256": hashlib.sha256(original).hexdigest(),
                "source_sha": "a" * 40,
                "command": "python3 -m unittest",
            }
            self.assertTrue(proof_ok(root, entry))
            for bad_path in (
                "qa/t024-evidence//proof.json",
                "qa/t024-evidence/./proof.json",
                "qa/t024-evidence/../t024-evidence/proof.json",
                "qa/t024-evidence/proof.json/",
            ):
                with self.subTest(bad_path=bad_path):
                    self.assertFalse(proof_ok(root, {**entry, "path": bad_path}))

            outside = root / "outside.json"
            outside.write_bytes(original)
            (directory / "link.json").symlink_to(outside)
            self.assertFalse(proof_ok(root, {**entry, "path": "qa/t024-evidence/link.json"}))
            (directory / "elsewhere").symlink_to(root, target_is_directory=True)
            self.assertFalse(proof_ok(root, {
                **entry, "path": "qa/t024-evidence/elsewhere/outside.json",
            }))

            # Emulate a same-hash symlink replacing the proof just before
            # open(): O_NOFOLLOW must refuse even though target bytes match.
            real_open = os.open
            saved = directory / "prior.json"
            swapped = False

            def replace_before_open(path, flags, *args, **kwargs):
                nonlocal swapped
                if path == "proof.json" and not swapped:
                    swapped = True
                    fixture.rename(saved)
                    fixture.symlink_to(outside)
                return real_open(path, flags, *args, **kwargs)

            with patch("qa.t024_readiness.os.open", side_effect=replace_before_open):
                self.assertFalse(proof_ok(root, entry))
            self.assertTrue(swapped)
            fixture.unlink()
            saved.rename(fixture)
            self.assertTrue(proof_ok(root, entry))

            # The descriptor remains on the verified old inode. A pathname
            # replacement with identical bytes must still fail the final
            # inode check, not count as accepted runtime evidence.
            real_read = os.read
            replaced = False

            def replace_after_read(fd, count):
                nonlocal replaced
                data = real_read(fd, count)
                if data and not replaced:
                    replaced = True
                    fixture.unlink()
                    fixture.write_bytes(original)
                return data

            with patch("qa.t024_readiness.os.read", side_effect=replace_after_read):
                self.assertFalse(proof_ok(root, entry))
            self.assertTrue(replaced)
            self.assertTrue(proof_ok(root, entry))

            # In-place edits while hashing invalidate the pinned file state,
            # even if the bytes read first had the expected digest.
            edited = False

            def mutate_after_read(fd, count):
                nonlocal edited
                data = real_read(fd, count)
                if data and not edited:
                    edited = True
                    fixture.write_bytes(b"X" * len(original))
                    os.utime(fixture, ns=(1_000_000_000, 1_000_000_000))
                return data

            with patch("qa.t024_readiness.os.read", side_effect=mutate_after_read):
                self.assertFalse(proof_ok(root, entry))
            self.assertTrue(edited)

            fixture.write_bytes(original)
            oversized = directory / "huge.bin"
            oversized.write_bytes(b"x" * (2 * 1024 * 1024 + 1))
            self.assertFalse(proof_ok(root, {
                **entry, "path": "qa/t024-evidence/huge.bin",
                "sha256": hashlib.sha256(oversized.read_bytes()).hexdigest(),
            }))
            fifo = directory / "named-pipe"
            os.mkfifo(fifo)
            self.assertFalse(proof_ok(root, {
                **entry, "path": "qa/t024-evidence/named-pipe",
            }))
            with patch("qa.t024_readiness.os.O_NOFOLLOW", 0):
                self.assertFalse(proof_ok(root, entry))

    def test_wrong_gate_or_extra_action_cannot_be_reported_ready(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            for path in ("contracts/screens.json", "contracts/acceptance-gates.json",
                         "design/visual-fidelity-evidence.json",
                         "design/t024-gate-evidence.json", "api/openapi-contract.yaml"):
                target = root / path
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / path, target)
            evidence_path = root / "design/t024-gate-evidence.json"
            original = json.loads(evidence_path.read_text(encoding="utf-8"))
            with patch("qa.t024_readiness.visual_audit", return_value=([], 0, 40)):
                report, errors = audit(root)
                self.assertEqual(errors, [])
                self.assertFalse(report["engineering_ready"])
                bad = json.loads(json.dumps(original))
                bad["gates"]["G-01"]["status"] = "verified"
                evidence_path.write_text(json.dumps(bad), encoding="utf-8")
                report, errors = audit(root)
                self.assertTrue(any("G-01" in x for x in errors))
                bad = json.loads(json.dumps(original))
                bad["gates"]["G-01"]["status"] = "deferred_external"
                evidence_path.write_text(json.dumps(bad), encoding="utf-8")
                report, errors = audit(root)
                self.assertTrue(any("G-01" in x for x in errors))
                bad = json.loads(json.dumps(original))
                bad["actions"]["WEB-99/fake_download"] = []
                evidence_path.write_text(json.dumps(bad), encoding="utf-8")
                report, errors = audit(root)
                self.assertTrue(any("uncontracted" in x for x in errors))
                bad = json.loads(json.dumps(original))
                bad["api_operations"]["/fake/secret#post"] = []
                evidence_path.write_text(json.dumps(bad), encoding="utf-8")
                report, errors = audit(root)
                self.assertTrue(any("uncontracted" in x for x in errors))
                bad = json.loads(json.dumps(original))
                bad["gates"].pop("G-02")
                evidence_path.write_text(json.dumps(bad), encoding="utf-8")
                report, errors = audit(root)
                self.assertTrue(any("enumerate all" in x for x in errors))

    @staticmethod
    def git(root: Path, *args: str) -> str:
        output = subprocess.run(
            ["git", "-C", str(root), *args],
            capture_output=True, text=True, check=True, timeout=10,
        )
        return output.stdout.strip()

    def test_real_git_provenance_rejects_unknown_stale_and_dirty_sources(self):
        # Synthetic commits prove Git ancestry and source integrity only.
        # They are not evidence of any actual WalidSite visual/QA gate.
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / "backend/routes").mkdir(parents=True)
            (root / "backend/routes/api.php").write_text("<?php // baseline\n")
            self.git(root, "init", "-q")
            self.git(root, "config", "user.name", "QA Fixture")
            self.git(root, "config", "user.email", "qa@example.test")
            self.git(root, "add", "backend")
            self.git(root, "commit", "-qm", "source baseline")
            baseline = self.git(root, "rev-parse", "HEAD")

            (root / "qa/t024-evidence").mkdir(parents=True)
            (root / "qa/t024-evidence/proof.json").write_text('{"fixture": true}')
            (root / "docs").mkdir()
            (root / "docs/readme.md").write_text("Documentation-only update\n")
            self.git(root, "add", ".")
            self.git(root, "commit", "-qm", "evidence and docs only")
            self.assertTrue(source_proof_ok(root, baseline))
            self.assertFalse(source_proof_ok(root, "0" * 40))
            self.assertFalse(source_proof_ok(root / "docs", baseline))
            self.assertFalse(source_proof_ok(root, "not-a-commit"))

            # A newly staged or uncommitted implementation cannot pass as the
            # earlier tested commit, even before a new HEAD is created.
            (root / "backend/routes/api.php").write_text("<?php // unsafe change\n")
            self.assertFalse(source_proof_ok(root, baseline))
            self.git(root, "add", "backend/routes/api.php")
            self.assertFalse(source_proof_ok(root, baseline))
            self.git(root, "commit", "-qm", "changed product after capture")
            self.assertFalse(source_proof_ok(root, baseline))
            new_source = self.git(root, "rev-parse", "HEAD")
            self.assertTrue(source_proof_ok(root, new_source))

            # QA evidence append alone does not invalidate the current SHA.
            (root / "qa/t024-evidence/proof.json").write_text('{"fixture": "updated"}')
            self.assertTrue(source_proof_ok(root, new_source))
            (root / "mobile").mkdir()
            (root / "mobile/new_screen.dart").write_text("// untracked implementation\n")
            self.assertFalse(source_proof_ok(root, new_source))

    def test_t024_audit_accepts_only_real_git_backed_unchanged_proof(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            for source in (
                "contracts/screens.json", "contracts/acceptance-gates.json",
                "design/visual-fidelity-evidence.json",
                "design/t024-gate-evidence.json", "api/openapi-contract.yaml",
            ):
                target = root / source
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / source, target)
            self.git(root, "init", "-q")
            self.git(root, "config", "user.name", "QA Fixture")
            self.git(root, "config", "user.email", "qa@example.test")
            self.git(root, "add", ".")
            self.git(root, "commit", "-qm", "tested source")
            source_sha = self.git(root, "rev-parse", "HEAD")
            artifact = root / "qa/t024-evidence/test.json"
            artifact.parent.mkdir(parents=True)
            artifact.write_text('{"synthetic": true}', encoding="utf-8")
            proof = {
                "path": "qa/t024-evidence/test.json",
                "sha256": hashlib.sha256(artifact.read_bytes()).hexdigest(),
                "source_sha": source_sha,
                "command": "python -m unittest qa.tests.test_visual_t024_readiness",
            }
            ledger = root / "design/t024-gate-evidence.json"
            data = json.loads(ledger.read_text(encoding="utf-8"))
            data["actions"]["WEB-01/open_projects"] = [proof]
            data["gates"]["G-01"]["status"] = "verified"
            data["gates"]["G-01"]["evidence"] = [proof]
            ledger.write_text(json.dumps(data), encoding="utf-8")
            self.git(root, "add", ".")
            self.git(root, "commit", "-qm", "attach synthetic test proof")

            with patch("qa.t024_readiness.visual_audit", return_value=([], 0, 40)):
                report, errors = audit(root)
                self.assertEqual(errors, [])
                self.assertEqual(report["screen_actions"]["with_pinned_proof"], 1)
                self.assertEqual(report["gates"]["with_pinned_proof"], 1)
                self.assertFalse(report["engineering_ready"])

                # Hash-correct and well-formed proof is insufficient when the
                # claimed source revision cannot be corroborated by Git.
                data["actions"]["WEB-01/open_projects"][0]["source_sha"] = "0" * 40
                ledger.write_text(json.dumps(data), encoding="utf-8")
                report, errors = audit(root)
                self.assertTrue(any("Action WEB-01" in e for e in errors))
                self.assertEqual(report["screen_actions"]["with_pinned_proof"], 0)
                data["actions"]["WEB-01/open_projects"][0]["source_sha"] = source_sha
                ledger.write_text(json.dumps(data), encoding="utf-8")

                # Even an ancestor SHA must not justify the same evidence
                # after any deployed API source changes.
                (root / "api/openapi-contract.yaml").write_text(
                    (root / "api/openapi-contract.yaml").read_text() + "\n# drift\n"
                )
                self.git(root, "add", "api/openapi-contract.yaml", "design/t024-gate-evidence.json")
                self.git(root, "commit", "-qm", "change API after QA proof")
                report, errors = audit(root)
                self.assertTrue(any("source-pinned proof" in e for e in errors))
                self.assertEqual(report["gates"]["with_pinned_proof"], 0)
