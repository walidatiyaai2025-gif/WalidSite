"""Issue #1120: keytool/apksigner signer identity formatting parity."""
import subprocess
import sys
import unittest

from qa.android_signer_fingerprint import canonical_fingerprint


class AndroidSignerFingerprintTest(unittest.TestCase):
    def test_valid_keytool_colon_and_apksigner_plain_are_equal(self):
        raw = "f71420262263131552ced4549e18c756" * 2
        colon = ":".join(raw[i:i+2] for i in range(0, 64, 2)).upper()
        self.assertEqual(canonical_fingerprint(colon), raw)
        self.assertEqual(canonical_fingerprint(raw.upper()), raw)

    def test_rejects_malformed_and_ambiguous_fingerprints(self):
        digest = "ab" * 32
        for candidate in ["", digest + " ", " " + digest, digest[:-2],
                          digest + "ef", digest.replace("ab", "a:b", 1),
                          ":".join(["ab"] * 31), ":".join(["ab"] * 33),
                          ":".join(["ab"] * 32) + ":", "zz" * 32,
                          digest[:4] + "-" + digest[4:]]:
            with self.subTest(candidate=candidate):
                with self.assertRaises(ValueError):
                    canonical_fingerprint(candidate)

    def test_cli_rejects_invalid_without_emitting_a_digest(self):
        proc = subprocess.run([sys.executable, "-m", "qa.android_signer_fingerprint", "bad:sha"],
                              capture_output=True, text=True)
        self.assertNotEqual(proc.returncode, 0)
        self.assertEqual(proc.stdout, "")


if __name__ == "__main__":
    unittest.main()
