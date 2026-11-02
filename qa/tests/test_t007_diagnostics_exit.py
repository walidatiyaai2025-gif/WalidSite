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

        screen_ids = ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12")

        def exit_code(statuses, font_probe=None):
            report = {
                "screens": {sid: {"status": status} for sid, status in zip(screen_ids, statuses)},
                "font_probe": {"inter_400": True} if font_probe is None else font_probe,
            }
            return eval(check, {"report": report, "SCREEN_IDS": screen_ids})

        self.assertEqual(exit_code(["exact"] * 4 + ["mismatch"]), 0)
        self.assertEqual(exit_code(["exact"] * 4 + ["render_failed"]), 1)
        self.assertEqual(exit_code(["wrong_dimensions"] + ["mismatch"] * 4), 1)
        self.assertEqual(exit_code(["render_failed", "wrong_dimensions"] + ["exact"] * 3), 1)
        self.assertEqual(exit_code(["exact"] * 4), 1)
        self.assertEqual(exit_code(["exact"] * 5, {"error": "TimeoutError"}), 1)


if __name__ == "__main__":
    unittest.main()
