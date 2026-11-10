"""Guard against PHP bootstrap warnings that block private cPanel release polling.

PHP emits E_WARNING for an ineffective global-namespace `use PDO;` while
compiling config/database.php. Laravel can promote that warning to an
ErrorException when the Cron runner boots its application configuration.
"""
from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
DATABASE_CONFIG = ROOT / "backend" / "config" / "database.php"


class DatabaseConfigBootstrapTest(unittest.TestCase):
    def test_pdo_constants_stay_available_without_noop_global_import(self):
        source = DATABASE_CONFIG.read_text(encoding="utf-8")
        self.assertIsNone(
            re.search(r"(?m)^\s*use\s+PDO\s*;", source),
            "Do not add a redundant global PDO import: PHP emits E_WARNING.",
        )
        self.assertIn("PDO::MYSQL_ATTR_SSL_CA", source)

    def test_php_compiler_reports_no_config_warnings(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is unavailable; the static PDO guard still runs.")
        result = subprocess.run(
            [php, "-d", "error_reporting=-1", "-d", "display_errors=stderr", "-l", str(DATABASE_CONFIG)],
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=30,
        )
        output = result.stdout + result.stderr
        self.assertEqual(result.returncode, 0, output)
        self.assertNotRegex(output, r"(?i)\b(?:warning|deprecated|notice)\b")


if __name__ == "__main__":
    unittest.main()
