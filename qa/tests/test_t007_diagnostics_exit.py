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

        def exit_code(statuses, font_probe=None, responsive_ok=True, navigation_ok=True):
            report = {
                "screens": {sid: {"status": status} for sid, status in zip(screen_ids, statuses)},
                "font_probe": {"inter_400": True, "inter_800": True} if font_probe is None else font_probe,
            }
            report["summary"] = {"font_probe_ok": (isinstance(report["font_probe"], dict)
                and "error" not in report["font_probe"]
                and report["font_probe"].get("inter_400") is True
                and report["font_probe"].get("inter_800") is True),
                "responsive_ok": responsive_ok, "navigation_ok": navigation_ok}
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
        self.assertEqual(exit_code(["exact"] * 5, navigation_ok=False), 1)
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
        self.assertIn('report["style_geometry_sweep"]', source)
        self.assertIn('"subtitle_geometry"', source)
        self.assertIn('"card_link_weight"', source)
        self.assertIn('"card_title_geometry"', source)
        self.assertIn('"all_geometry"', source)
        # The diagnostic results are intentionally absent from the final exit
        # policy and pixel acceptance; it must never turn a mismatch green.
        tree = ast.parse(source)
        main = next(node for node in tree.body
                    if isinstance(node, ast.FunctionDef) and node.name == "main")
        final_return = next(node for node in reversed(main.body)
                            if isinstance(node, ast.Return))
        policy = ast.unparse(final_return.value)
        self.assertNotIn("rasterization_sweep", policy)
        self.assertNotIn("style_geometry_sweep", policy)

    def test_runtime_audit_covers_mobile_tablet_arabic_english_and_keyboard(self):
        source = (Path(__file__).resolve().parents[1] /
                  "capture_t007_diagnostics.py").read_text(encoding="utf-8")
        self.assertIn('RESPONSIVE_VIEWPORTS = ((390, 844), (768, 1024), (1024, 768), (1280, 800))', source)
        self.assertIn('("ar", "en")', source)
        self.assertIn('page.keyboard.press("Tab")', source)
        self.assertIn('supplemental_keyboard_link_visible', source)
        self.assertIn('"responsive_checks": len(report["responsive_audit"])', source)
        self.assertIn('"responsive_ok": responsive_audit_complete(report["responsive_audit"])', source)


    def test_complete_responsive_audit_rejects_missing_replaced_or_failed_cases(self):
        source = Path(__file__).resolve().parents[1] / "capture_t007_diagnostics.py"
        tree = ast.parse(source.read_text(encoding="utf-8"))
        helper = next(node for node in tree.body
                      if isinstance(node, ast.FunctionDef) and
                      node.name == "responsive_audit_complete")
        standalone = ast.Module(body=[helper], type_ignores=[])
        ast.fix_missing_locations(standalone)
        scope = {
            "SCREEN_IDS": ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12"),
            "RESPONSIVE_VIEWPORTS": ((390, 844), (768, 1024), (1024, 768), (1280, 800)),
            "RESPONSIVE_LOCALES": ("ar", "en"),
        }
        exec(compile(standalone, str(source), "exec"), scope)
        complete = scope["responsive_audit_complete"]
        rows = {
            f"{screen_id}:{lang}:{width}": {"passed": True}
            for width, _ in scope["RESPONSIVE_VIEWPORTS"]
            for lang in scope["RESPONSIVE_LOCALES"]
            for screen_id in scope["SCREEN_IDS"]
        }
        self.assertEqual(len(rows), 40)
        self.assertTrue(complete(rows))

        missing = rows.copy()
        missing.pop("WEB-01:ar:390")
        self.assertFalse(complete(missing))

        extra = rows.copy()
        extra["unexpected:en:390"] = {"passed": True}
        self.assertFalse(complete(extra))

        replacement = missing.copy()
        replacement["WEB-01:ar:391"] = {"passed": True}
        self.assertEqual(len(replacement), 40)
        self.assertFalse(complete(replacement))

        failed = rows.copy()
        failed["WEB-01:ar:390"] = {"passed": False}
        self.assertFalse(complete(failed))

        unknown = rows.copy()
        unknown["WEB-01:ar:390"] = {"status": "pending"}
        self.assertFalse(complete(unknown))


    def test_navigation_audit_fail_closed_for_missing_locales_or_dead_actions(self):
        source = Path(__file__).resolve().parents[1] / "capture_t007_diagnostics.py"
        tree = ast.parse(source.read_text(encoding="utf-8"))
        helper = next(n for n in tree.body if isinstance(n, ast.FunctionDef)
                      and n.name == "navigation_audit_complete")
        module = ast.Module(body=[helper], type_ignores=[])
        ast.fix_missing_locations(module)
        scope = {"SCREEN_IDS": ("WEB-01", "WEB-02", "WEB-03", "WEB-06", "WEB-12"),
                 "RESPONSIVE_LOCALES": ("ar", "en")}
        exec(compile(module, str(source), "exec"), scope)
        valid = {f"{screen}:{lang}": {"passed": True, "checked_links": 9, "hero_actions": 2}
                 for screen in scope["SCREEN_IDS"] for lang in scope["RESPONSIVE_LOCALES"]}
        self.assertTrue(scope["navigation_audit_complete"](valid))
        missing = valid.copy()
        del missing["WEB-06:ar"]
        self.assertFalse(scope["navigation_audit_complete"](missing))
        failed = valid.copy()
        failed["WEB-01:en"] = {"passed": True, "checked_links": 8, "hero_actions": 2}
        self.assertFalse(scope["navigation_audit_complete"](failed))
        failed["WEB-01:en"] = {"passed": False, "checked_links": 9, "hero_actions": 2}
        self.assertFalse(scope["navigation_audit_complete"](failed))



if __name__ == "__main__":
    unittest.main()
