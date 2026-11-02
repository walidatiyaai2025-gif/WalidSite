"""T007 diagnostics must fail closed when real browser captures are unusable."""
import ast
import unittest
from pathlib import Path


class T007DiagnosticExitTest(unittest.TestCase):
    def test_renderer_commit_is_bound_to_checked_out_git_head(self):
        source = Path(__file__).resolve().parents[1] / "capture_t007_diagnostics.py"
        code = source.read_text(encoding="utf-8")
        self.assertIn('["git", "rev-parse", "HEAD"]', code)
        self.assertIn("checked_out != args.commit", code)
        self.assertIn("Renderer commit must equal the checked-out Git HEAD.", code)

    def test_exit_policy_rejects_failed_and_wrong_size_captures(self):
        source = Path(__file__).resolve().parents[1] / "capture_t007_diagnostics.py"
        tree = ast.parse(source.read_text(encoding="utf-8"))
        main = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == "main")
        final_return = next(node for node in reversed(main.body) if isinstance(node, ast.Return))
        policy = ast.Expression(body=final_return.value)
        ast.fix_missing_locations(policy)
        check = compile(policy, str(source), "eval")

        screen_ids = ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12")

        def exit_code(statuses, font_probe=None, responsive_ok=True):
            report = {
                "screens": {sid: {"status": status} for sid, status in zip(screen_ids, statuses)},
                "font_probe": {"inter_400": True, "inter_800": True} if font_probe is None else font_probe,
            }
            report["summary"] = {"font_probe_ok": (isinstance(report["font_probe"], dict)
                and "error" not in report["font_probe"]
                and report["font_probe"].get("inter_400") is True
                and report["font_probe"].get("inter_800") is True),
                "responsive_ok": responsive_ok}
            return eval(check, {"report": report, "SCREEN_IDS": screen_ids})

        self.assertEqual(exit_code(["exact"] * 4 + ["mismatch"]), 0)
        self.assertEqual(exit_code(["exact"] * 4 + ["render_failed"]), 1)
        self.assertEqual(exit_code(["wrong_dimensions"] + ["mismatch"] * 4), 1)
        self.assertEqual(exit_code(["render_failed", "wrong_dimensions"] + ["exact"] * 3), 1)
        self.assertEqual(exit_code(["exact"] * 4), 1)
        self.assertEqual(exit_code(["exact"] * 5, {"error": "TimeoutError"}), 1)
        self.assertEqual(exit_code(["exact"] * 5, {}), 1)
        self.assertEqual(exit_code(["exact"] * 5, {"inter_400": True, "inter_800": False}), 1)
        self.assertEqual(exit_code(["exact"] * 5, responsive_ok=False), 1)
        self.assertEqual(exit_code(["mismatch"] * 5, responsive_ok=True), 0)

    def test_rasterization_sweep_is_diagnostic_only(self):
        source = (Path(__file__).resolve().parents[1] /
                  "capture_t007_diagnostics.py").read_text(encoding="utf-8")
        self.assertIn('"baseline_disable_lcd": ["--disable-lcd-text"]', source)
        self.assertIn('"--font-render-hinting=none"', source)
        self.assertIn('"current_slight_hint"', source)
        self.assertIn('"--font-render-hinting=slight"', source)
        self.assertIn('"--font-render-hinting=medium"', source)
        self.assertIn('"--font-render-hinting=full"', source)
        self.assertIn('"default": []', source)
        self.assertIn('report["rasterization_sweep"]', source)
        # The diagnostic result is intentionally absent from the final exit
        # policy and pixel acceptance; it must never turn a mismatch green.
        tree = ast.parse(source)
        main = next(node for node in tree.body
                    if isinstance(node, ast.FunctionDef) and node.name == "main")
        final_return = next(node for node in reversed(main.body)
                            if isinstance(node, ast.Return))
        self.assertNotIn("rasterization_sweep", ast.unparse(final_return.value))

    def test_runtime_audit_covers_mobile_tablet_arabic_english_and_keyboard(self):
        source = (Path(__file__).resolve().parents[1] /
                  "capture_t007_diagnostics.py").read_text(encoding="utf-8")
        self.assertIn('((390, 844), (768, 1024))', source)
        self.assertIn('("ar", "en")', source)
        self.assertIn('page.keyboard.press("Tab")', source)
        self.assertIn('supplemental_keyboard_link_visible', source)
        self.assertIn('"responsive_checks": len(report["responsive_audit"])', source)
        self.assertIn('len(report["responsive_audit"]) == 20', source)


if __name__ == "__main__":
    unittest.main()
