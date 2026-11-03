<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminApprovalsAuditJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function article(
        string $risk = 'low',
        bool $claims = false,
        bool $verified = true
    ): Article {
        $article = Article::create([
            'slug' => 'approval-'.Str::lower(Str::random(12)),
            'title_en' => 'Evidence reviewed candidate',
            'title_ar' => 'محتوى موثق للمراجعة',
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'status' => $verified ? 'validated' : 'draft',
            'body_md' => 'PRIVATE-DRAFT-BODY-NEVER-RENDER DO-NOT-LEAK',
            'citations_json' => [[
                'source_url' => 'https://example.org/reviewed-source',
                'verified' => $verified,
            ]],
            'validation_json' => [
                'citations_passed' => $verified,
                'technical_passed' => $verified,
                'risk_level' => $risk,
                'contains_professional_claims' => $claims,
            ],
        ]);

        // Fixture has a real distinct writer identity. Browser-side checker
        // restrictions must not be bypassed by actorless legacy snapshots.
        $author = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        DB::table('article_revisions')->where('id', $revision->id)->update([
            'author_user_id' => $author->id,
            'last_editor_user_id' => $author->id,
        ]);

        return $article;
    }

    private function generatedAsset(): Asset
    {
        Storage::fake('local');
        $bytes = 'private-generated-raster';
        $asset = Asset::create([
            'asset_key' => 'generated-'.Str::lower(Str::random(10)),
            'mime_type' => 'image/png',
            'storage_key' => 'placeholder.png',
            'sha256' => hash('sha256', $bytes),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);
        $path = 'ai-artwork/'.$asset->id.'.png';
        $asset->update(['storage_key' => $path]);
        Storage::disk('local')->put($path, $bytes);
        Storage::disk('local')->put($path.'.provenance.json', json_encode([
            'asset_id' => $asset->id,
            'sha256' => $asset->sha256,
            'provider_id' => 'PRIVATE-PROVIDER-ID',
            'model' => 'PRIVATE-MODEL-NAME',
            'prompt_sha256' => hash('sha256', 'PRIVATE PROMPT'),
        ], JSON_THROW_ON_ERROR));

        return $asset->fresh();
    }

    public function test_approvals_are_admin_only_bilingual_and_reachable_without_private_draft_data(): void
    {
        $article = $this->article();

        $this->get('/admin/approvals')->assertRedirect('/admin/login');
        $this->post('/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'defer',
            'reason' => 'source_reviewed',
        ])->assertRedirect('/admin/login');

        $this->actingAs($this->user('reader'), 'web');
        $this->get('/admin/approvals')->assertForbidden();
        $this->post('/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'defer',
            'reason' => 'source_reviewed',
        ])->assertForbidden();

        $this->actingAs($this->user('admin'), 'web');
        $this->get('/admin?lang=en')->assertOk()
            ->assertSee('href="'.route('admin.approvals.index', ['lang' => 'en']).'"', false)
            ->assertSee('Approvals');

        $english = $this->get('/admin/approvals?lang=en')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('Approvals and exception review')
            ->assertSee('Pending reviews (1)')
            ->assertSee('Approve & publish')
            ->assertSee('Reject')
            ->assertSee('Defer')
            ->assertSee('id="content" tabindex="-1"', false)
            ->assertSee('name="robots" content="noindex,nofollow"', false)
            ->assertDontSee('PRIVATE-DRAFT-BODY-NEVER-RENDER')
            ->assertDontSee('DO-NOT-LEAK');
        $this->assertStringContainsString('no-store', $english->headers->get('Cache-Control'));

        $this->get('/admin/approvals?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الموافقات والمراجعة الاستثنائية')
            ->assertSee('المراجعات المعلقة (1)')
            ->assertSee('اعتماد ونشر')
            ->assertDontSee('PRIVATE-DRAFT-BODY-NEVER-RENDER');

        foreach (['lang=xx', 'page=0', 'page[]=1'] as $query) {
            $this->get('/admin/approvals?'.$query)
                ->assertRedirect()->assertSessionHasErrors();
        }
    }

    public function test_defer_reject_and_low_risk_approve_are_durable_real_decisions(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web');

        $deferred = $this->article();
        $this->post('/admin/approvals/'.$deferred->id.'/decision?lang=en', [
            'decision' => 'defer',
            'reason' => 'source_reviewed',
        ])->assertRedirect(route('admin.approvals.index', ['lang' => 'en']))
            ->assertSessionHas('status', 'Decision deferred; nothing was published.');
        $this->assertSame('draft', $deferred->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $deferred->id,
            'revision_no' => 1,
            'actor_id' => $admin->id,
            'action' => 'requires_review',
            'reason' => 'source_reviewed',
        ]);

        $rejected = $this->article();
        $this->post('/admin/approvals/'.$rejected->id.'/decision?lang=en', [
            'decision' => 'reject',
            'reason' => 'technical_reviewed',
        ])->assertRedirect()->assertSessionHas(
            'status', 'Revision rejected and recorded for review.'
        );
        $this->assertSame('draft', $rejected->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $rejected->id,
            'action' => 'manual_rejected',
            'reason' => 'technical_reviewed',
        ]);

        $approved = $this->article();
        $this->post('/admin/approvals/'.$approved->id.'/decision?lang=en', [
            'decision' => 'approve',
            'reason' => 'source_reviewed',
        ])->assertRedirect()->assertSessionHas(
            'status', 'Verified revision approved and published.'
        );
        $this->assertSame('published', $approved->fresh()->status);
        $this->assertSame(1, $approved->fresh()->published_revision_no);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $approved->id,
            'action' => 'manual_published',
            'reason' => 'source_reviewed',
        ]);

        foreach ([
            ['decision' => 'skip', 'reason' => 'source_reviewed'],
            ['decision' => 'defer', 'reason' => 'invented_reason'],
            ['decision' => 'defer', 'reason' => 'source_reviewed', 'unsafe' => 'x'],
        ] as $payload) {
            $fresh = $this->article();
            $this->post('/admin/approvals/'.$fresh->id.'/decision', $payload)
                ->assertRedirect()->assertSessionHasErrors();
            $this->assertSame('draft', $fresh->fresh()->status);
        }
    }

    public function test_high_risk_or_professional_claim_approval_requires_owner_but_defer_does_not_publish(): void
    {
        $admin = $this->user('admin');
        $highRisk = $this->article('high', true);
        $this->actingAs($admin, 'web');

        $highRiskPage = $this->get('/admin/approvals?lang=en')->assertOk()
            ->assertSee('Owner approval required');
        // Blade renders the disabled attribute on its own line; assert the
        // actual approve button, not fragile adjacent HTML whitespace.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*value="approve"\\s+disabled\\b/s',
            $highRiskPage->getContent()
        );

        $this->post('/admin/approvals/'.$highRisk->id.'/decision', [
            'decision' => 'approve',
            'reason' => 'legal_reviewed',
        ])->assertForbidden();
        $this->assertSame('draft', $highRisk->fresh()->status);

        $this->post('/admin/approvals/'.$highRisk->id.'/decision', [
            'decision' => 'defer',
            'reason' => 'legal_reviewed',
        ])->assertRedirect();
        $this->assertSame('draft', $highRisk->fresh()->status);

        $owner = $this->user('owner');
        $this->actingAs($owner, 'web');
        $this->post('/admin/approvals/'.$highRisk->id.'/decision', [
            'decision' => 'approve',
            'reason' => 'legal_reviewed',
        ])->assertRedirect();
        $this->assertSame('published', $highRisk->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $highRisk->id,
            'actor_id' => $owner->id,
            'action' => 'manual_published',
            'reason' => 'legal_reviewed',
        ]);
    }

    public function test_media_rights_approval_and_revoke_are_audited_once_without_private_provenance(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web');
        $asset = $this->generatedAsset();

        $this->post('/admin/ai/images/'.$asset->id.'/approve?lang=en', [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('editorial_events', [
            'actor_id' => $admin->id,
            'action' => 'media_rights_approved',
            'reason' => 'asset:'.$asset->id,
        ]);

        $this->post('/admin/ai/images/'.$asset->id.'/revoke?lang=en')
            ->assertRedirect();
        $this->post('/admin/ai/images/'.$asset->id.'/revoke?lang=en')
            ->assertRedirect();
        $this->assertSame(1, DB::table('editorial_events')
            ->where('action', 'media_rights_revoked')
            ->where('reason', 'asset:'.$asset->id)->count());

        $audit = $this->get('/admin/audit?lang=en&domain=media')->assertOk()
            ->assertSee('Audit & security')
            ->assertSee('Editorial & media decisions (2)')
            ->assertSee('media_rights_approved')
            ->assertSee('media_rights_revoked')
            ->assertSee('asset:'.$asset->id)
            ->assertSee('Login and access history')
            ->assertSee('Unavailable in the current data schema.')
            ->assertDontSee($asset->storage_key)
            ->assertDontSee('PRIVATE-PROVIDER-ID')
            ->assertDontSee('PRIVATE-MODEL-NAME')
            ->assertDontSee('PRIVATE PROMPT')
            ->assertDontSee($admin->email);
        $this->assertStringContainsString('no-store', $audit->headers->get('Cache-Control'));
    }

    public function test_cross_domain_audit_filters_and_exports_bounded_safe_events(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'web');
        $article = $this->article();

        $this->post('/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'defer',
            'reason' => 'technical_reviewed',
        ])->assertRedirect();

        $editorial = $this->get('/admin/audit?domain=editorial&action=requires_review')
            ->assertOk()
            ->assertSee('Editorial & media decisions (1)')
            ->assertSee('requires_review')
            ->assertSee('Evidence reviewed candidate')
            ->assertDontSee('PRIVATE-DRAFT-BODY-NEVER-RENDER')
            ->assertDontSee('DO-NOT-LEAK');

        $csv = $this->get('/admin/audit?domain=editorial&format=csv&lang=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Export-Truncated', 'false');
        $data = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $data);
        $this->assertStringContainsString('"UTC time",Provider,Action,"Changed field names",Domain,Subject', $data);
        $this->assertStringContainsString('requires_review', $data);
        $this->assertStringContainsString('editorial', $data);
        $this->assertStringContainsString('Evidence reviewed candidate', $data);
        $this->assertStringNotContainsString('PRIVATE-DRAFT-BODY-NEVER-RENDER', $data);
        $this->assertStringNotContainsString('DO-NOT-LEAK', $data);
        $this->assertStringNotContainsString($admin->email, $data);

        foreach ([
            'domain=secret', 'domain[]=editorial', 'action=login_success',
            'editorial_page=0', 'editorial_page[]=1',
        ] as $query) {
            $this->get('/admin/audit?'.$query)
                ->assertRedirect()->assertSessionHasErrors();
        }
    }
    public function test_admin_audit_all_domain_excludes_unclassified_editorial_events_from_html_and_csv(): void
    {
        $admin = $this->user('admin');
        $article = $this->article();
        $this->actingAs($admin, 'web');

        // A legitimate review action remains visible through the shared log.
        $this->post('/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'defer',
            'reason' => 'source_reviewed',
        ])->assertRedirect();

        // Other internal editorial events can occupy the same physical table;
        // they are not approved Admin-13 audit actions and must not leak via
        // the unfiltered "all" screen or its CSV export.
        DB::table('editorial_events')->insert([
            'id' => (string) Str::uuid(),
            'event_key' => 'internal-'.Str::random(16),
            'article_id' => $article->id,
            'revision_no' => 1,
            'actor_id' => $admin->id,
            'action' => 'internal_diagnostic',
            'reason' => 'private_internal_context',
            'created_at' => now(),
        ]);

        $this->get('/admin/audit?domain=all&lang=en')->assertOk()
            ->assertSee('requires_review')
            ->assertSee('Evidence reviewed candidate')
            ->assertDontSee('internal_diagnostic')
            ->assertDontSee('private_internal_context');

        $allCsv = $this->get('/admin/audit?domain=all&format=csv')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('requires_review', $allCsv);
        $this->assertStringNotContainsString('internal_diagnostic', $allCsv);
        $this->assertStringNotContainsString('private_internal_context', $allCsv);

        $this->get('/admin/audit?domain=editorial&lang=ar')->assertOk()
            ->assertSee('requires_review')
            ->assertDontSee('internal_diagnostic');

        $this->get('/admin/audit?domain=media')->assertOk()
            // Filter controls legitimately list possible action names;
            // assert the actual query result count, not the select options.
            ->assertSee('Editorial & media decisions (0)')
            ->assertDontSee('internal_diagnostic');
    }


    public function test_web_approval_requires_independent_documented_reviewer_and_persists_signoff(): void
    {
        $actor = $this->user('admin');
        $this->actingAs($actor, 'web');

        $ownWork = $this->article();
        DB::table('article_revisions')->where('article_id', $ownWork->id)->update([
            'author_user_id' => $actor->id,
            'last_editor_user_id' => $actor->id,
        ]);
        foreach (['en', 'ar'] as $lang) {
            $page = $this->get('/admin/approvals?lang='.$lang)->assertOk()
                ->assertSee('value="approve"', false);
            $this->assertMatchesRegularExpression(
                '/<button[^>]*value="approve"\\s+disabled\\b/s',
                $page->getContent()
            );
            $page->assertSee($lang === 'ar'
                ? 'الاعتماد محجوب: يلزم مراجع مستقل'
                : 'Approval blocked: independent reviewer required.');
        }
        $this->post('/admin/approvals/'.$ownWork->id.'/decision', [
            'decision' => 'approve', 'reason' => 'source_reviewed',
        ])->assertForbidden();
        $this->assertSame('draft', $ownWork->fresh()->status);

        $lastEdited = $this->article();
        DB::table('article_revisions')->where('article_id', $lastEdited->id)->update([
            'last_editor_user_id' => $actor->id,
        ]);
        $this->post('/admin/approvals/'.$lastEdited->id.'/decision', [
            'decision' => 'approve', 'reason' => 'source_reviewed',
        ])->assertForbidden();

        $legacy = $this->article();
        DB::table('article_revisions')->where('article_id', $legacy->id)->update([
            'author_user_id' => null,
            'last_editor_user_id' => null,
        ]);
        $this->post('/admin/approvals/'.$legacy->id.'/decision', [
            'decision' => 'approve', 'reason' => 'technical_reviewed',
        ])->assertForbidden();
        $this->assertDatabaseMissing('editorial_events', [
            'article_id' => $legacy->id, 'action' => 'manual_published',
        ]);

        $independent = $this->article();
        $revision = $independent->revisions()->firstOrFail();
        $this->post('/admin/approvals/'.$independent->id.'/decision?lang=en', [
            'decision' => 'approve', 'reason' => 'technical_reviewed',
        ])->assertRedirect();
        $this->assertSame('published', $independent->fresh()->status);
        $this->assertDatabaseHas('article_revisions', [
            'id' => $revision->id, 'reviewed_by_user_id' => $actor->id,
        ]);
        $this->assertNotNull($revision->fresh()->reviewed_at);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $independent->id,
            'actor_id' => $actor->id,
            'action' => 'manual_published',
        ]);
    }
}
