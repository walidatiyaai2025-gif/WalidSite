"""WEB-01..14 plan tests. Mock slugs here are NEVER runtime screenshot proof."""
import json
import unittest
from pathlib import Path
from qa.build_web_visual_plan import WEB_IDS, WebPlanError, build_web_plan
from qa.capture_visual_batch import validate_plan


ROOT = Path(__file__).resolve().parents[2]


class WebVisualPlanTest(unittest.TestCase):
    def test_static_12_routable_web_pages_include_implemented_ai_engineering(self):
        plan = build_web_plan(ROOT)
        entries = validate_plan(ROOT, plan)
        self.assertEqual(len(entries), 12)
        self.assertEqual(
            [row["screen_id"] for row in entries],
            [sid for sid in WEB_IDS if sid not in ("WEB-05", "WEB-08")],
        )
        self.assertNotIn("{slug}", json.dumps(plan))
        for row in entries:
            self.assertEqual(row["renderer_command"],
                             ["python3", "qa/render_web_screenshot.py"])
            self.assertEqual(row["locale"], "en")
            self.assertEqual(row["dpr"], 1.0)

    def test_requires_explicit_verified_published_slugs_for_full_plan(self):
        with self.assertRaisesRegex(WebPlanError, "verified published slugs"):
            build_web_plan(ROOT, require_all=True)
        plan = build_web_plan(
            ROOT, project_slug="real-published-project",
            article_slug="real-reviewed-article",
            require_all=True,
        )
        self.assertEqual(len(validate_plan(ROOT, plan)), 14)
        routes = {row["screen_id"]: row["route"] for row in plan["captures"]}
        self.assertEqual(routes["WEB-05"], "/projects/real-published-project")
        self.assertEqual(routes["WEB-08"], "/blog/real-reviewed-article")
        self.assertEqual(routes["WEB-01"], "/")
        self.assertEqual(routes["WEB-06"], "/ai-engineering")

    def test_rejects_unsafe_slugs_locale_dpr_timeout_and_false_full_plan(self):
        for bad in ("", "a/b", "../private", "a?lang=en", "hello world",
                    "%2fadmin", "x" * 201, "a\\b"):
            with self.subTest(slug=bad), self.assertRaises(WebPlanError):
                build_web_plan(ROOT, project_slug=bad)
        for kwargs in ({"locale": "fr"}, {"dpr": 0}, {"dpr": True},
                       {"timeout": 0}, {"timeout": True}, {"timeout": 3601}):
            with self.subTest(kwargs=kwargs), self.assertRaises(WebPlanError):
                build_web_plan(ROOT, **kwargs)
        with self.assertRaises(WebPlanError):
            build_web_plan(ROOT, project_slug="safe", require_all=True)
        with self.assertRaises(WebPlanError):
            build_web_plan(ROOT, include_ai_engineering=1)

    def test_arabic_and_independent_dynamic_content_paths(self):
        plan = build_web_plan(ROOT, article_slug="approved-article",
                              locale="ar", dpr=2, timeout=240)
        entries = validate_plan(ROOT, plan)
        self.assertEqual(len(entries), 13)
        self.assertEqual(entries[0]["locale"], "ar")
        self.assertEqual(entries[0]["dpr"], 2)
        self.assertEqual(entries[0]["timeout"], 240)

    def test_rejects_contract_surface_drift(self):
        import tempfile
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "contracts").mkdir()
            source = json.loads((ROOT / "contracts/screens.json").read_text())
            for row in source["screens"]:
                if row["id"] == "WEB-08":
                    row["route"] = "/blog/{id}"
            (root / "contracts/screens.json").write_text(json.dumps(source))
            with self.assertRaisesRegex(WebPlanError, "dynamic route"):
                build_web_plan(root, article_slug="safe", include_ai_engineering=False)


    def test_web06_route_must_be_registered_get_not_comment_or_post(self):
        import tempfile
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "contracts").mkdir()
            (root / "backend/routes").mkdir(parents=True)
            (root / "contracts/screens.json").write_text(
                (ROOT / "contracts/screens.json").read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            routes = root / "backend/routes/web.php"
            for source in (
                "",
                "Route::post('/ai-engineering', fn () => null);",
                "// Route::get('/ai-engineering', fn () => null);",
            ):
                routes.write_text(source, encoding="utf-8")
                with self.assertRaisesRegex(WebPlanError, "WEB-06 registered GET"):
                    build_web_plan(root)
            routes.write_text(
                "Route::get('/ai-engineering', fn () => null)->name('public.ai-engineering');",
                encoding="utf-8",
            )
            full_static = build_web_plan(root)
            self.assertEqual(len(full_static["captures"]), 12)
            self.assertIn("WEB-06", {row["screen_id"] for row in full_static["captures"]})
            partial = build_web_plan(root, include_ai_engineering=False)
            self.assertEqual(len(partial["captures"]), 11)
            self.assertNotIn("WEB-06", {row["screen_id"] for row in partial["captures"]})
            with self.assertRaises(WebPlanError):
                build_web_plan(
                    root, project_slug="published-project", article_slug="published-article",
                    include_ai_engineering=False, require_all=True,
                )


if __name__ == "__main__":
    unittest.main()
