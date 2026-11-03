"""Contract guard for installer runbooks (not proof of a live cPanel deployment).

Runs under the existing QA unittest discovery, without a new workflow. The
checks deliberately tie critical operational instructions to the actual
first-run classes so a later code change cannot silently stale both guides.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(relative):
    return (ROOT / relative).read_text(encoding="utf-8")


class InstallerDocumentationContractTest(unittest.TestCase):
    def test_auto_cpanel_key_and_private_token_match_first_run_code(self):
        provider = read("backend/app/Providers/AppServiceProvider.php")
        bootstrap = read("backend/app/Support/Deployment/FirstRunBootstrap.php")
        site = read("backend/config/site.php")
        guide = read("docs/INSTALL_AND_UPDATES.md")
        arabic = read("docs/CPANEL_PACKAGE_AR.md")
        self.assertIn("FirstRunBootstrap())->prepare()", provider)
        self.assertIn("filled(config('app.key'))", bootstrap)
        self.assertIn("random_bytes(32)", bootstrap)
        self.assertIn("'token' => bin2hex(random_bytes(32))", bootstrap)
        self.assertIn("chmod($path, 0600)", bootstrap)
        self.assertIn("'bootstrap_path' => storage_path('app/site-first-run.json')", site)
        self.assertIn("## A. cPanel ZIP", guide)
        self.assertIn("## B. Manually managed Laravel source checkout", guide)
        self.assertIn("site-first-run.json", guide)
        self.assertIn("site-first-run.json", arabic)
        self.assertIn("60", arabic)  # private file permissions, not a web secret

    def test_operator_site_url_and_body_only_database_save(self):
        controller = read("backend/app/Http/Controllers/SiteDatabaseController.php")
        configuration = read("backend/app/Support/Deployment/DatabaseConfiguration.php")
        en = read("docs/INSTALL_AND_UPDATES.md")
        ar = read("docs/CPANEL_PACKAGE_AR.md")
        self.assertIn("'site_url' => ['required'", controller)
        self.assertIn("$database->canonicalSiteUrl($data['site_url'])", controller)
        self.assertIn("$database->probe($data)", controller)
        self.assertIn("'APP_URL' => $appUrl", configuration)
        self.assertIn("'APP_KEY' => $key", configuration)
        self.assertIn("chmod($path, 0600)", configuration)
        self.assertIn("APP_URL", en)
        self.assertIn("site_url", en)
        self.assertIn("site_url", ar)
        self.assertIn("HTTPS", ar)

    def test_activation_revokes_setup_and_has_independent_lock(self):
        controller = read("backend/app/Http/Controllers/SiteSetupController.php")
        installer = read("backend/app/Support/Deployment/SiteInstaller.php")
        config = read("backend/app/Support/Deployment/DatabaseConfiguration.php")
        en = read("docs/INSTALL_AND_UPDATES.md")
        ar = read("docs/CPANEL_PACKAGE_AR.md")
        self.assertIn("'min:14', 'max:128', 'confirmed'", controller)
        self.assertIn("config('site.install_lock_path')", installer)
        self.assertIn("whereIn('name', ['owner', 'admin'])", installer)
        self.assertIn("->disableSetup()", installer)
        self.assertIn("'SITE_SETUP_ENABLED' => 'false'", config)
        self.assertIn("'SITE_SETUP_TOKEN' => ''", config)
        self.assertIn("@unlink($bootstrap)", config)
        self.assertIn("14–128", en)
        self.assertIn("14–128", ar)
        self.assertIn("site-installed.lock", en)
        self.assertIn("site-installed.lock", ar)

    def test_update_docs_do_not_misrepresent_release_or_recovery(self):
        updater = read("backend/app/Support/Deployment/SiteUpdater.php")
        en = read("docs/INSTALL_AND_UPDATES.md")
        ar = read("docs/CPANEL_PACKAGE_AR.md")
        self.assertIn("Artisan::call('migrate'", updater)
        self.assertIn("SITE_AUTO_MIGRATE=false", en)
        self.assertIn("SITE_AUTO_MIGRATE=false", ar)
        self.assertIn("## Trusted patch and migration handling", en)
        self.assertIn("migrate:rollback", en)
        self.assertIn("migrate:rollback", ar)
        self.assertIn("not live installation evidence", en)
        self.assertIn("ليس نشرًا حقيقيًا", ar)


if __name__ == "__main__":
    unittest.main()
