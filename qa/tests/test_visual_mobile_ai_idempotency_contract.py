"""Release-freeze #699: prevent source drift in the v6 contract amendment.

The canonical v6 OpenAPI source is checksum-pinned. The normative additive
runtime contract lives in docs/API_CONTRACT_AMENDMENTS.md. These are source
tests, NOT live financial/provider, parallel HTTP or device acceptance.
"""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
AMENDMENTS = ROOT / "docs/API_CONTRACT_AMENDMENTS.md"
BASELINE = ROOT / "api/openapi-contract.yaml"
BACKEND = ROOT / "backend/app/Http/Controllers/Api/MobileAiDiagnoseController.php"
FLUTTER = ROOT / "mobile/lib/ai/ai_troubleshooter.dart"
HEADING = "## 2026-11-03 — Issue #699 paid mobile AI diagnosis idempotency"


def amendment_section(text: str) -> str:
    if text.count(HEADING) != 1:
        raise AssertionError("Expected exactly one #699 normative amendment")
    after = text.split(HEADING, 1)[1]
    return after.split("\n## ", 1)[0]


def protocol_errors(contract: str) -> list[str]:
    checks = {
        "existing diagnosis path": "POST /api/v1/mobile/ai/diagnose",
        "header name": "Idempotency-Key:",
        "UUID4 client": "UUIDv4",
        "header minimum/maximum": "16–128",
        "header alphabet": "[A-Za-z0-9_-]",
        "legacy optionality": "optional in the server implementation",
        "200 replay": "200 success / replay",
        "stable job ID": "same `data.id`",
        "no second provider call": "without an additional AI job or provider call",
        "409 conflict": "409 conflict",
        "invalid input": "`422`",
        "revoked bearer": "`401`",
        "inactive actor": "`403`",
        "quota": "`429`",
        "provider unavailable": "`503`",
        "cache safety": "Cache-Control: no-store",
        "logout recovery risk": "unresolved #699 duplicate-admission risk",
        "no fake close": "Keep #699 OPEN",
    }
    return [name for name, needle in checks.items() if needle not in contract]


class MobileAiIdempotencyContractTest(unittest.TestCase):
    def test_amendment_is_complete_and_authoritative(self) -> None:
        block = amendment_section(AMENDMENTS.read_text(encoding="utf-8"))
        self.assertEqual([], protocol_errors(block))
        self.assertIn("not a new endpoint", block)
        self.assertIn("do not certify provider billing", block)
        self.assertIn("After reauthentication", block)

    def test_immutable_baseline_is_not_rewritten_for_runtime_amendment(self) -> None:
        source = BASELINE.read_text(encoding="utf-8")
        match = re.search(
            r"(?ms)^  /mobile/ai/diagnose:\n(.*?)(?=^  /|\\Z)", source
        )
        self.assertIsNotNone(match)
        self.assertIn("operationId: mobileAiDiagnose", match.group(1))
        # The immutable v6 baseline is intentionally untouched by this
        # released-code protocol. CI independently validates its SHA256.
        self.assertNotIn("Idempotency-Key", match.group(1))

    def test_backend_and_flutter_match_documented_contract(self) -> None:
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
        self.assertIn("_attemptSnapshot ?? MobileAiPaidAttempt(", mobile)

    def test_critical_contract_omissions_are_detected(self) -> None:
        block = amendment_section(AMENDMENTS.read_text(encoding="utf-8"))
        mutations = {
            "missing header": ("Idempotency-Key:", "Other-Key:"),
            "key bounds": ("16–128", "1–4096"),
            "alphabet": ("[A-Za-z0-9_-]", ".*"),
            "missing 409": ("409 conflict", "418 conflict"),
            "misleading replay": (
                "without an additional AI job or provider call",
                "with another AI job and provider call",
            ),
            "undocumented logout failure": (
                "unresolved #699 duplicate-admission risk", "resolved success",
            ),
        }
        for label, (before, after) in mutations.items():
            with self.subTest(label=label):
                self.assertIn(before, block)
                self.assertNotEqual([], protocol_errors(block.replace(before, after, 1)))


if __name__ == "__main__":
    unittest.main()
