"""Guard against false approval and accidental WEB-01/03/04 design changes."""
import json
import tempfile
import unittest
from pathlib import Path

from qa.validate_owner_design_freeze import (
    MANIFEST_PATH,
    SCREENS,
    audit,
    git_blob_sha1,
)


class OwnerApprovedWebDesignFreezeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / "design").mkdir()
        self.manifest = json.loads(
            (Path(__file__).resolve().parents[2] / MANIFEST_PATH).read_text(encoding="utf-8")
        )
        for _sid, (_route, paths) in SCREENS.items():
            for path in paths:
                local = self.root / path
                local.parent.mkdir(parents=True, exist_ok=True)
                local.write_bytes(b"owner-approved-template\n")
                self.manifest["screens"][_sid]["files"][path]["git_blob_sha1"] = git_blob_sha1(
                    local.read_bytes()
                )
        self.write()

    def write(self):
        (self.root / MANIFEST_PATH).write_text(
            json.dumps(self.manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )

    def test_baseline_accepts_all_seven_unchanged_templates(self):
        self.assertEqual(audit(self.root), [])

    def test_visual_edits_of_each_owner_approved_screen_are_rejected(self):
        for sid, (_route, paths) in SCREENS.items():
            with self.subTest(sid=sid):
                target = self.root / sorted(paths)[0]
                old = target.read_bytes()
                target.write_bytes(old + b"unauthorized-worker-css-edit\n")
                errors = audit(self.root)
                self.assertTrue(any(sid in err and "frozen design changed" in err for err in errors))
                target.write_bytes(old)

    def test_missing_template_is_rejected(self):
        path = self.root / "backend/resources/views/public/projects-2026-live.blade.php"
        path.unlink()
        self.assertTrue(any("WEB-04" in e and "missing/unsafe" in e for e in audit(self.root)))

    def test_owner_directed_home_radio_override_cannot_be_repurposed(self):
        self.manifest["owner_directed_overrides"]["WEB-01"]["new_git_blob_sha1"] = "0" * 40
        self.write()
        self.assertTrue(any("owner-directed" in e.lower() for e in audit(self.root)))

    def test_owner_directed_override_cannot_be_extended_to_other_screens(self):
        self.manifest["owner_directed_overrides"]["WEB-03"] = {
            "path": "backend/resources/views/public/expertise-screen.blade.php",
            "new_git_blob_sha1": "a" * 40,
        }
        self.write()
        self.assertTrue(any("owner-directed" in e.lower() for e in audit(self.root)))

    def test_fake_pixel_parity_attestation_is_rejected(self):
        self.manifest["runtime_zero_rgba_comparison"] = "matched"
        self.write()
        self.assertTrue(any("runtime_zero_rgba_comparison" in e for e in audit(self.root)))

    def test_freeze_cannot_silently_drop_screen_or_template(self):
        self.manifest["screens"].pop("WEB-03")
        self.write()
        self.assertTrue(any("exactly WEB-01" in e for e in audit(self.root)))

    def test_owner_approval_cannot_be_relabelled_to_another_main_revision(self):
        self.manifest["approved_main_sha"] = "0" * 40
        self.write()
        self.assertTrue(any("approved_main_sha" in e for e in audit(self.root)))

    def test_freeze_inventory_cannot_silently_become_smaller(self):
        self.manifest["screens"]["WEB-01"]["files"].pop(
            "backend/resources/views/welcome.blade.php"
        )
        self.write()
        self.assertTrue(any("WEB-01" in e and "inventory" in e for e in audit(self.root)))

    def test_actual_tracked_checkout_matches_owner_freeze(self):
        current = Path(__file__).resolve().parents[2]
        self.assertEqual(audit(current), [])


if __name__ == "__main__":
    unittest.main()
