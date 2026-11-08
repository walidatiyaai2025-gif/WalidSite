"""Real old-installed 0.1.0 VERSION cannot be trusted as an automatic updater identity."""
import tempfile
from pathlib import Path
import unittest

from qa.legacy_version_preflight import classify_version_bytes, main


class LegacyVersionPreflightTests(unittest.TestCase):
    def test_canonical_identity_is_readable_but_not_deployment_authority(self):
        result = classify_version_bytes(b"0.1.2\\n")
        self.assertEqual(result["version"], "0.1.2")
        self.assertTrue(result["current_reader_compatible"])
        self.assertFalse(result["automatic_adoption_authorized"])

    def test_reported_live_legacy_version_is_detected_not_silently_rewritten(self):
        result = classify_version_bytes(b"0.1.0\\n2026-11-04T09:02:48+00:00\\n")
        self.assertEqual(result["format"], "legacy_0_1_0_timestamp")
        self.assertFalse(result["current_reader_compatible"])
        self.assertFalse(result["automatic_adoption_authorized"])

    def test_untrusted_shapes_rejected(self):
        for value in [b"", b"0.1.0", b"0.1.0\\nextra\\n",
                      b"0.1.0\\n2026-02-30T09:02:48+00:00\\n",
                      b"0.1.0\\n2026-11-04T09:02:48+03:00\\n",
                      b"0.1.1\\n2026-11-04T09:02:48+00:00\\n",
                      b"0.1.0\\r\\n", b"0.1.0\\n\\n",
                      b"0.1.0\\n" + b"A" * 200, b"0.1.0\\x00\\n",
                      b"01.1.0\\n", b"0.1.0-beta\\n", b"0.1.0\\n-1\\n"]:
            with self.subTest(data=value[:50]):
                with self.assertRaises(ValueError):
                    classify_version_bytes(value)

    def test_read_only_cli_does_not_change_version(self):
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp) / "VERSION"
            original = b"0.1.0\\n2026-11-04T09:02:48+00:00\\n"
            file.write_bytes(original)
            self.assertEqual(main(["preflight", str(file)]), 0)
            self.assertEqual(file.read_bytes(), original)


if __name__ == "__main__":
    unittest.main()
