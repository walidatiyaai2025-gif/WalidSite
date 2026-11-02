<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminOperationsVisualStructureTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(18)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    public function test_four_authorized_admin_journeys_share_original_navigation_shell_in_both_locales(): void
    {
        config(['portfolio.github_public_repos' => []]);
        Http::fake();
        $this->actingAs($this->account(), 'web');

        $screens = [
            'github' => ['GitHub Sync', 'مزامنة GitHub'],
            'projects' => ['Projects & CV', 'المشاريع والسيرة المهنية'],
            'audience' => ['Audience & consent', 'الجمهور والموافقة'],
            'analytics' => ['Verified analytics', 'التحليلات المتحقّق منها'],
        ];
        $navigation = [
            'admin.home', 'admin.content.index', 'admin.providers.index',
            'admin.routing.index', 'admin.media.index', 'admin.quality.index',
            'admin.operations.github', 'admin.operations.projects',
            'admin.operations.audience', 'admin.operations.analytics',
            'admin.approvals.index', 'admin.audit.index',
        ];
        foreach (['en', 'ar'] as $locale) {
            foreach ($screens as $section => $titles) {
                $response = $this->get(route('admin.operations.'.$section, ['lang' => $locale]))
                    ->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertSee('role="banner"', false)
                    ->assertSee('class="workspace-header"', false)
                    ->assertSee('class="page-heading"', false)
                    ->assertSee('class="layout"', false)
                    ->assertSee('class="brand"', false)
                    ->assertSee('class="health-unverified"', false)
                    ->assertSee('lang="'.$locale.'"', false)
                    ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee('href="#content"', false)
                    ->assertSee('tabindex="-1"', false)
                    ->assertSee('prefers-reduced-motion:reduce', false)
                    ->assertSee('@media(max-width:720px)', false)
                    ->assertDontSee('SYSTEM HEALTHY')
                    ->assertDontSee('Automatic / audited');
                foreach ($navigation as $name) {
                    $response->assertSee('href="'.route($name, ['lang' => $locale]).'"', false);
                }
                if ($locale === 'en') {
                    $response->assertSee('SYSTEM STATUS UNVERIFIED');
                } else {
                    $response->assertSee('حالة النظام غير متحقّق منها');
                }
                $html = $response->getContent();
                $this->assertSame(1, preg_match_all('/<h1>[^<]*<\/h1>/', $html));
                $this->assertStringContainsString('<h1>'.$titles[$locale === 'ar' ? 1 : 0].'</h1>', $html);
                $this->assertSame(1, preg_match_all('/aria-current="page"/', $html));
                $this->assertSame(1, preg_match(
                    '~<a\\s+href="'.preg_quote(route('admin.operations.'.$section, ['lang' => $locale]), '~').'"\\s+aria-current="page"~',
                    $html
                ), 'Only the active screen should carry aria-current regardless of Blade whitespace.');
                $response->assertSee('href="'.route('admin.operations.'.$section, [
                    'lang' => $locale === 'ar' ? 'en' : 'ar',
                ]), false);
            }
        }
        Http::assertNothingSent();
    }

    public function test_notification_workflow_has_six_honest_stages_and_no_send_control(): void
    {
        $this->actingAs($this->account(), 'web');
        foreach (['en', 'ar'] as $locale) {
            $html = $this->get('/admin/audience?lang='.$locale)->assertOk()->getContent();
            $this->assertSame(6, substr_count($html, '<span class="sequence">'));
            $this->assertSame(5, substr_count($html, 'Unavailable — nothing sent') + substr_count($html, 'غير متاح، لم يُرسل شيء'));
            $this->assertStringContainsString(
                $locale === 'en' ? 'Consent preview only' : 'معاينة الموافقات فقط',
                $html
            );
            $this->assertStringNotContainsString('action="/admin/audience/send"', $html);
            $this->assertStringContainsString('role="status"', $html);
        }
    }

    public function test_guest_and_reader_cannot_access_visualized_admin_data(): void
    {
        foreach (['github', 'projects', 'audience', 'analytics'] as $section) {
            $this->get('/admin/'.$section.'?lang=ar')->assertRedirect('/admin/login');
        }

        $this->actingAs($this->account('reader'), 'web');
        foreach (['github', 'projects', 'audience', 'analytics'] as $section) {
            $this->get('/admin/'.$section.'?lang=en')->assertForbidden();
        }
    }
}
