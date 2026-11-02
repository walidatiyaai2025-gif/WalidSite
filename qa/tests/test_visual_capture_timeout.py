"""Invalid runtime screenshot timeouts must fail before invoking any renderer."""
import unittest
from pathlib import Path
from unittest.mock import patch

from qa.capture_visual_evidence import CaptureError, capture


class CaptureTimeoutTest(unittest.TestCase):
    def test_invalid_timeout_rejected_before_audit_or_process(self):
        with patch("qa.capture_visual_evidence.audit") as audit, patch("qa.capture_visual_evidence.subprocess.run") as run:
            for timeout in (0, -1, 3601):
                with self.subTest(timeout=timeout):
                    with self.assertRaisesRegex(CaptureError, "timeout"):
                        capture(Path("."), "WEB-01", commit="a" * 40, locale="en",
                                dpr=1, route="/", command=["true"], timeout=timeout)
            audit.assert_not_called()
            run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
