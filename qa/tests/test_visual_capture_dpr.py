"""Validate device pixel ratio types before invoking capture side effects."""
import unittest
from pathlib import Path
from unittest.mock import patch

from qa.capture_visual_evidence import CaptureError, capture


class CaptureDprTest(unittest.TestCase):
    def test_invalid_dpr_types_rejected_before_audit_or_process(self):
        with patch("qa.capture_visual_evidence.audit") as audit, patch("qa.capture_visual_evidence.subprocess.run") as run:
            for dpr in (None, "1", [], {}, 0, -1, 9):
                with self.subTest(dpr=dpr):
                    with self.assertRaisesRegex(CaptureError, "device pixel ratio"):
                        capture(Path("."), "WEB-01", commit="a" * 40, locale="en",
                                dpr=dpr, route="/", command=["true"])
            audit.assert_not_called()
            run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
