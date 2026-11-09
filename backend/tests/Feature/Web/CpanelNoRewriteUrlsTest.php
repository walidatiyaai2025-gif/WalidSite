<?php

namespace Tests\Feature\Web;

use App\Domain\Deployment\CpanelNoRewriteUrls;
use Illuminate\Routing\UrlGenerator;
use InvalidArgumentException;
use Tests\TestCase;

class CpanelNoRewriteUrlsTest extends TestCase
{
    public function test_clean_url_mode_remains_the_default(): void
    {
        $this->assertFalse(config('app.front_controller_urls'));
        $this->assertSame('/admin/login', parse_url(route('login'), PHP_URL_PATH));
    }

    public function test_opt_in_prefixes_dynamic_routes_and_redirects_but_not_public_assets(): void
    {
        /** @var UrlGenerator $urls */
        $urls = app('url');

        try {
            CpanelNoRewriteUrls::apply($urls, 'https://walidatiya.com');

            $this->assertSame(
                'https://walidatiya.com/index.php/admin/login?lang=ar',
                route('login', ['lang' => 'ar']),
            );
            $this->assertSame(
                'https://walidatiya.com/index.php/admin/login?lang=en',
                route('admin.login.submit', ['lang' => 'en']),
            );
            $this->assertSame(
                'https://walidatiya.com/index.php/admin/updates',
                url('/admin/updates'),
            );
            $this->assertSame(
                'https://walidatiya.com/index.php/admin',
                redirect()->to('/admin')->getTargetUrl(),
            );
            $this->assertSame(
                'https://walidatiya.com/assets/icons/briefcase.svg',
                asset('assets/icons/briefcase.svg'),
            );
        } finally {
            $urls->useOrigin(null);
            $urls->useAssetOrigin(null);
        }
    }

    public function test_explicit_asset_origin_remains_independent_of_dynamic_php_routing(): void
    {
        /** @var UrlGenerator $urls */
        $urls = app('url');

        try {
            CpanelNoRewriteUrls::apply(
                $urls,
                'https://walidatiya.com',
                'https://static.walidatiya.com',
            );

            $this->assertSame('https://walidatiya.com/index.php/projects', url('/projects'));
            $this->assertSame(
                'https://static.walidatiya.com/assets/icons/briefcase.svg',
                asset('assets/icons/briefcase.svg'),
            );
        } finally {
            $urls->useOrigin(null);
            $urls->useAssetOrigin(null);
        }
    }

    public function test_untrusted_or_malformed_site_origin_is_rejected(): void
    {
        /** @var UrlGenerator $urls */
        $urls = app('url');

        foreach ([
            'http://walidatiya.com',
            'https://walidatiya.com/other-root',
            'https://walidatiya.com?lang=ar',
            'https://attacker.test@walidatiya.com',
            'https://walidatiya.com:8443',
            '//walidatiya.com',
        ] as $origin) {
            try {
                CpanelNoRewriteUrls::apply($urls, $origin);
                $this->fail('Accepted an invalid site origin: '.$origin);
            } catch (InvalidArgumentException) {
                $this->assertSame('/admin/login', parse_url(route('login'), PHP_URL_PATH));
            }
        }
    }
}
