"""Release-freeze #699: guard the merged mobile paid-AI protocol against drift.

These are source/contract regression checks, NOT provider billing, concurrent
HTTP load, native app runtime or lost-ACK-after-logout acceptance evidence.
"""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "api/openapi-contract.yaml"
BACKEND = ROOT / "backend/app/Http/Controllers/Api/MobileAiDiagnoseController.php"
FLUTTER = ROOT / "mobile/lib/ai/ai_troubleshooter.dart"


def diagnosis_contract(text: str) -> str:
    match = re.search(
        r"(?ms)^  /mobile/ai/diagnose:\n(.*?)(?=^  /|\\Z)", text
    )
    if match is None:
        raise AssertionError("Mobile diagnosis endpoint missing from OpenAPI")
    return match.group(1)


def protocol_errors(contract: str) -> list[str]:
    """Fail closed if essential already-implemented API semantics are removed."""
    checks = {
        "header parameter": "      - in: header\n        name: Idempotency-Key\n",
        "legacy compatibility": "        required: false\n",
        "string key": "          type: string\n",
        "key minimum": "          minLength: 16\n",
        "key maximum": "          maxLength: 128\n",
        "key alphabet": "          pattern: '^[A-Za-z0-9_-]{16,128}$'\n",
        "replay success": "        '200':\n",
        "authentication": "        '401':\n",
        "authorization": "        '403':\n",
        "conflict": "        '409':\n",
        "invalid key": "        '422':\n",
        "quota": "        '429':\n",
        "unavailable provider": "        '503':\n",
        "bearer security": "      - bearerAuth: []\n",
        "consented body": "$ref: '#/components/schemas/AiDiagnoseInput'",
        "ambiguous ACK": "lost HTTP",
        "no silent new key": "MUST NOT be silently",
        "unresolved session recovery": "unresolved acceptance risk",
    }
    return [name for name, needle in checks.items() if needle not in contract]


class MobileAiIdempotencyContractTest(unittest.TestCase):
    def test_real_contract_documents_replay_and_conflict_fully(self) -> None:
        block = diagnosis_contract(CONTRACT.read_text(encoding="utf-8"))
        self.assertEqual([], protocol_errors(block))
        # Ensure this is explicitly a POST operation, not a detached schema.
        self.assertTrue(block.startswith("    post:\n"))
        self.assertIn("same data.id", block)
        self.assertIn("different request fingerprint", block)
        self.assertIn("without a second paid admission", block)
        self.assertIn("No prompt, bearer or raw opaque key", block)

    def test_backend_and_flutter_current_protocol_agree_with_contract(self) -> None:
        backend = BACKEND.read_text(encoding="utf-8")
        mobile = FLUTTER.read_text(encoding="utf-8")
        self.assertIn("$request->header('Idempotency-Key')", backend)
        self.assertIn("[A-Za-z0-9_-]{16,128}", backend)
        self.assertIn("hash_hmac('sha256', $key", backend)
        self.assertIn("existingForKey(", backend)
        self.assertIn("return $this->jobResponse($prior, $runbooks);", backend)
        self.assertIn("abort(409, 'Idempotency key is already in use.');", backend)
        self.assertIn("request.headers.set('Idempotency-Key', idempotencyKey);", mobile)
        self.assertIn("MobileAiIntentKey.generate()", mobile)
        self.assertIn("await _intentStore.saveKey(accountEmail, attempt.key);", mobile)
        self.assertIn("final job = await _keyedDiagnose(", mobile)
        self.assertIn("attempt.key,", mobile)
        # A signed-in session has explicit same-intent recovery, not an
        # automatic second potentially paid POST on a lost acknowledgement.
        self.assertIn("_attemptSnapshot ?? MobileAiPaidAttempt(", mobile)

    def test_critical_contract_drift_is_detected(self) -> None:
        block = diagnosis_contract(CONTRACT.read_text(encoding="utf-8"))
        mutations = {
            "missing header": ("        name: Idempotency-Key", "        name: Request-Key"),
            "key range widened": ("          maxLength: 128", "          maxLength: 4096"),
            "key alphabet relaxed": (
                "          pattern: '^[A-Za-z0-9_-]{16,128}$'",
                "          pattern: '.*'",
            ),
            "missing 409": ("        '409':", "        '418':"),
            "missing quota": ("        '429':", "        '420':"),
            "undocumented lost ACK": ("lost HTTP", "normal HTTP"),
        }
        for label, (before, after) in mutations.items():
            with self.subTest(label=label):
                self.assertIn(before, block)
                self.assertNotEqual([], protocol_errors(block.replace(before, after, 1)))

    def test_contract_does_not_falsely_certify_logout_recovery(self) -> None:
        block = diagnosis_contract(CONTRACT.read_text(encoding="utf-8"))
        self.assertIn("logout/session expiry", block)
        self.assertIn("not evidence that reconciliation works", block)
        # This risk is tracked for W3/W1 and is not repaired by a LEAD
        # schema update. It must not be mistaken for live financial QA.


if __name__ == "__main__":
    unittest.main()
