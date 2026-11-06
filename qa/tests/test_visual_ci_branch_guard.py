"""Issue #481: CI must never silently skip real visual diagnostics on fix/chore branches.

This tests the workflow admission contract, not screenshot authenticity or pixel parity.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CI = ROOT / ".github" / "workflows" / "ci.yml"
RUNTIME_NAME = "Issue 481 real authenticated Admin runtime diagnostics"
UPLOAD_NAME = "Upload Issue 481 Admin runtime visual diagnostics"
BRANCH_CONDITION = "(" + " || ".join(
    "startsWith(github.head_ref, '%s/481-')" % prefix
    for prefix in ("feat", "fix", "chore")
) + ")"
PR_CONDITION = "github.event_name == 'pull_request' && " + BRANCH_CONDITION
MAIN_CONDITION = "github.event_name == 'push' && github.ref == 'refs/heads/main'"
VISUAL_CONDITION = "(" + MAIN_CONDITION + ") || (" + PR_CONDITION + ")"


class Issue481VisualCiAdmissionTest(unittest.TestCase):
    def condition(self, lines: list[str], name: str) -> str:
        target = "      - name: " + name
        self.assertEqual(lines.count(target), 1, "Expected exactly one " + name)
        index = lines.index(target)
        prefix = "        if: ${{ "
        suffix = " }}"
        self.assertTrue(lines[index + 1].startswith(prefix), name + " must be conditional")
        self.assertTrue(lines[index + 1].endswith(suffix), name + " needs a closed condition")
        return lines[index + 1][len(prefix):-len(suffix)]

    def assert_ci_contract(self, source: str) -> None:
        lines = source.splitlines()
        self.assertEqual(self.condition(lines, RUNTIME_NAME), VISUAL_CONDITION)
        self.assertEqual(self.condition(lines, UPLOAD_NAME), "always() && (" + VISUAL_CONDITION + ")")

        upload_index = lines.index("      - name: " + UPLOAD_NAME)
        next_step = next((i for i in range(upload_index + 1, len(lines))
                          if lines[i].startswith("      - name: ")), len(lines))
        upload = "\n".join(lines[upload_index:next_step])
        self.assertIn("uses: actions/upload-artifact@v4", upload)
        self.assertIn("name: issue-481-admin-and-web-runtime-visual-diagnostics", upload)
        self.assertIn("path: qa/visual-diagnostics/", upload)
        self.assertIn("if-no-files-found: error", upload)

    def test_real_runtime_and_artifact_share_the_same_issue_branch_admission(self):
        self.assert_ci_contract(CI.read_text(encoding="utf-8"))

    def test_removing_fix_or_chore_admission_or_weakening_artifacts_is_detected(self):
        source = CI.read_text(encoding="utf-8")
        for mutation in (
            source.replace("fix/481-", "fix/other-", 1),
            source.replace("chore/481-", "chore/other-", 1),
            source.replace("if-no-files-found: error", "if-no-files-found: warn", 1),
            source.replace("always() && (", "(", 1),
            source.replace("refs/heads/main", "refs/heads/other", 1),
        ):
            with self.subTest(mutation=mutation != source):
                self.assertNotEqual(mutation, source)
                with self.assertRaises(AssertionError):
                    self.assert_ci_contract(mutation)


if __name__ == "__main__":
    unittest.main()
