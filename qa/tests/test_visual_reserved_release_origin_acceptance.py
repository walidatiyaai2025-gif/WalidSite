"""W10 #27: fail-closed acceptance for reserved release publisher DNS suffixes.

Executes the production TrustedReleaseMetadata PHP class (not a copied regex).
The publisher origin is administrator-configured, not supplied by a visitor.
A valid signature must not permit local/reserved network egress.
"""
from pathlib import Path
import json
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
VERIFIER = ROOT / "backend/app/Domain/Deployment/TrustedReleaseMetadata.php"
PHP = shutil.which("php")
PHP_RUNNER = r"""
require $argv[1];
foreach (json_decode($argv[2], true, 8, JSON_THROW_ON_ERROR) as $host) {
    try {
        \App\Domain\Deployment\TrustedReleaseMetadata::assertPinnedHttpsOrigin($host);
        $decision = 'accepted';
    } catch (\DomainException $e) {
        $decision = 'rejected';
    }
    echo json_encode([$host, $decision], JSON_THROW_ON_ERROR), "\n";
}
"""


@unittest.skipUnless(PHP, "PHP CLI required for real release verifier acceptance")
class PublisherOriginReservedNetworkAcceptance(unittest.TestCase):
    def verdicts(self, origins):
        self.assertTrue(VERIFIER.is_file(), "Real production class must be checked out")
        proc = subprocess.run(
            [PHP, "-d", "display_errors=stderr", "-r", PHP_RUNNER,
             str(VERIFIER), json.dumps(origins)],
            capture_output=True, text=True, timeout=15,
        )
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertFalse(proc.stderr, proc.stderr)
        decoded = [json.loads(line) for line in proc.stdout.splitlines()]
        self.assertEqual(len(decoded), len(origins))
        return dict(decoded)

    def test_reserved_and_private_name_suffixes_are_rejected_before_http(self):
        blocked = [
            "https://publisher.localhost",
            "https://publisher.deep.localhost",
            "https://publisher.local",
            "https://publisher.internal",
            "https://publisher.invalid",
            "https://publisher.test",
            "https://publisher.example",
            "https://publisher.home.arpa",
        ]
        outcomes = self.verdicts(blocked)
        self.assertEqual(
            outcomes, dict.fromkeys(blocked, "rejected"),
            f"Private/reserved publisher hosts accepted: "
            f"{[s for s in blocked if outcomes[s] != 'rejected']}",
        )

    def test_normal_public_pinned_https_hostname_remains_supported(self):
        approved = ["https://updates.example.com", "https://releases.vendor.co.uk"]
        self.assertEqual(self.verdicts(approved), dict.fromkeys(approved, "accepted"))

    def test_url_parser_abuse_remains_rejected(self):
        blocked = [
            "http://updates.example.com", "https://127.0.0.1",
            "https://localhost", "https://updates.example.com:8443",
            "https://updates.example.com@localhost",
            "https://updates.example.com/path",
        ]
        self.assertEqual(self.verdicts(blocked), dict.fromkeys(blocked, "rejected"))


if __name__ == "__main__":
    unittest.main()
