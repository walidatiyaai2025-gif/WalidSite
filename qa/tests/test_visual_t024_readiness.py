"""T024 safety regressions: fixture bookkeeping is NOT real runtime evidence."""
import hashlib
import io
import json
import shutil
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

from qa.t024_readiness import (
    ROOT, audit, main, openapi_operations, proof_ok,
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
            self.assertFalse(proof_ok(root, {**entry, "sha256": "0" * 64}))
            self.assertFalse(proof_ok(root, {**entry, "path": "../outside.json"}))
            self.assertFalse(proof_ok(root, {**entry, "command": ""}))
            self.assertFalse(proof_ok(root, {**entry, "private_token": "secret"}))
            link = directory / "alias.json"
            link.symlink_to(file)
            self.assertFalse(proof_ok(root, {**entry, "path": "qa/t024-evidence/alias.json"}))

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
