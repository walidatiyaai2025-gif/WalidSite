"""T007 diagnostics must fail closed when real browser captures are unusable."""
import ast
import unittest
from pathlib import Path


class T007DiagnosticExitTest(unittest.TestCase):
    def test_exit_policy_rejects_failed_and_wrong_size_captures(self):
        source = Path(__file__).resolve().parents[1] / "capture_t007_diagnostics.py"
        tree = ast.parse(source.read_text(encoding="utf-8"))
        main = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == "main")
        final_return = next(node for node in reversed(main.body) if isinstance(node, ast.Return))
        policy = ast.Expression(body=final_return.value)
        ast.fix_missing_locations(policy)
        check = compile(policy, str(source), "eval")

        def exit_code(statuses):
            report = {"screens": {str(i): {"status": status} for i, status in enumerate(statuses)}}
            return eval(check, {"report": report})

        self.assertEqual(exit_code(["exact", "mismatch"]), 0)
        self.assertEqual(exit_code(["exact", "render_failed"]), 1)
        self.assertEqual(exit_code(["wrong_dimensions", "mismatch"]), 1)
        self.assertEqual(exit_code(["render_failed", "wrong_dimensions"]), 1)


if __name__ == "__main__":
    unittest.main()
