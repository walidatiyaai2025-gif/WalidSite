<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminArticleListTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(14).'@example.test',
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function token(string $role = 'owner', string $ability = 'admin', string $status = 'active'): string
    {
        return $this->user($role, $status)
            ->createToken('admin-editorial', [$ability], now()->addHour())->plainTextToken;
    }

    public function test_rejects_guest_mobile_reader_inactive_and_session_only_admin_access(): void
    {
        $url = '/api/v1/admin/articles';
        $this->getJson($url)->assertUnauthorized();

        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['admin', 'admin', 'inactive'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status))
                ->getJson($url)->assertForbidden();
        }

        Auth::forgetGuards();
        $this->actingAs($this->user(), 'web')->getJson($url)->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($this->token('admin'))
            ->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_paginates_real_private_metadata_and_localizes_without_exposing_drafts(): void
    {
        Http::fake();
        for ($i = 1; $i <= 21; $i++) {
            $slug = sprintf('review-queue-%02d', $i);
            $article = Article::create([
                'slug' => $slug,
                'title_en' => 'English work in progress '.$i,
                'title_ar' => 'مسودة للمراجعة '.$i,
                'canonical_lang' => 'en',
            ]);
            DB::table('articles')->where('id', $article->id)->update([
                'created_at' => now()->subMinutes($i),
            ]);
        }
        $published = Article::create([
            'slug' => 'metadata-only-not-verified',
            'title_en' => 'Published status without current evidence',
            'title_ar' => 'حالة منشورة بدون إثبات حالي',
            'canonical_lang' => 'en',
        ]);
        DB::table('articles')->where('id', $published->id)->update([
            'created_at' => now()->subHours(3),
            'status' => 'published',
            'published_at' => now()->subHours(3),
        ]);

        $this->withToken($this->token('owner'));
        $first = $this->getJson('/api/v1/admin/articles?lang=en')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.slug', 'review-queue-01')
            ->assertJsonPath('data.0.title', 'English work in progress 1')
            ->assertJsonPath('meta.total', 22)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.status_is_not_public_evidence_verification', true);
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));

        $this->getJson('/api/v1/admin/articles?page=2&lang=ar')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'review-queue-21')
            ->assertJsonPath('data.0.title', 'مسودة للمراجعة 21')
            ->assertJsonPath('data.1.slug', 'metadata-only-not-verified')
            ->assertJsonPath('meta.lang', 'ar');

        $publishedStatus = $this->getJson('/api/v1/admin/articles?status=published')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('body_md', $publishedStatus->getContent());
        $this->assertStringNotContainsString('citations_json', $publishedStatus->getContent());
        $this->assertStringNotContainsString('secret_ciphertext', $publishedStatus->getContent());

        $this->getJson('/api/v1/admin/articles?status=draft')
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
        $this->getJson('/api/v1/admin/articles?page=3')
            ->assertOk()->assertJsonCount(0, 'data');
        Http::assertNothingSent();
    }

    public function test_invalid_locale_status_and_page_fail_validation_without_server_errors(): void
    {
        $this->withToken($this->token());
        foreach ([
            'lang=fr', 'lang[]=ar', 'status=unverified',
            'status[]=draft', 'page=0', 'page=10001', 'page[]=2',
        ] as $invalid) {
            $this->getJson('/api/v1/admin/articles?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_production_http_is_rejected_before_authenticated_article_read(): void
    {
        $original = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->withToken($this->token());
            $this->getJson('http://localhost/api/v1/admin/articles')->assertForbidden();
            Auth::forgetGuards();
            $this->getJson('https://localhost/api/v1/admin/articles')
                ->assertOk()->assertJsonCount(0, 'data');
        } finally {
            $this->app->instance('env', $original);
        }
    }
}
