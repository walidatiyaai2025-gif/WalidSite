<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminEditorialExceptionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(14)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function article(
        ?string $risk = 'high',
        ?bool $professionalClaims = false,
        bool $verified = true
    ): Article {
        $article = Article::create([
            'slug' => 'exception-'.Str::lower(Str::random(10)),
            'title_en' => 'Exception review candidate',
            'title_ar' => 'مقال يحتاج مراجعة استثنائية',
            'canonical_lang' => 'en',
        ]);

        $validation = [
            'citations_passed' => $verified,
            'technical_passed' => $verified,
        ];
        if ($risk !== null) {
            $validation['risk_level'] = $risk;
        }
        if ($professionalClaims !== null) {
            $validation['contains_professional_claims'] = $professionalClaims;
        }

        $article->revisions()->create([
            'revision_no' => 1,
            'status' => $verified ? 'validated' : 'draft',
            'body_md' => 'PRIVATE-EXCEPTION-DRAFT-BODY',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/private-review-source',
                'verified' => $verified,
            ]],
            'validation_json' => $validation,
        ]);

        return $article;
    }

    public function test_owner_sees_reasoned_exception_form_and_publishes_with_redacted_audit_event(): void
    {
        $owner = $this->actor('owner');
        $this->actingAs($owner, 'web');
        $article = $this->article('high', false);

        $this->get('/admin/content?article='.$article->id.'&lang=en')->assertOk()
            ->assertSee('Exceptional owner review required')
            ->assertSee('Decision reason')
            ->assertSee('value="source_reviewed"', false)
            ->assertSee('value="technical_reviewed"', false)
            ->assertSee('value="legal_reviewed"', false)
            ->assertSee('name="confirmed"', false)
            ->assertSee('Record decision and publish')
            ->assertDontSee('Approve and publish verified revision')
            ->assertDontSee('PRIVATE-EXCEPTION-DRAFT-BODY')
            ->assertDontSee('private-review-source');

        $this->post('/admin/editorial/automation/'.$article->id.'/manual-review?lang=en', [
            'reason' => 'technical_reviewed',
            'confirmed' => '1',
        ])->assertRedirect();

        $article = $article->fresh();
        $this->assertSame('published', $article->status);
        $this->assertSame(1, $article->published_revision_no);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'revision_no' => 1,
            'actor_id' => $owner->id,
            'action' => 'manual_published',
            'reason' => 'technical_reviewed',
        ]);
        $event = DB::table('editorial_events')
            ->where('article_id', $article->id)
            ->where('action', 'manual_published')
            ->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('PRIVATE-EXCEPTION', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-review-source', json_encode($event, JSON_THROW_ON_ERROR));
        $this->get('/blog/'.$article->slug)->assertOk();
    }

    public function test_admin_cannot_use_high_risk_exception_or_generic_publish(): void
    {
        $admin = $this->actor('admin');
        $this->actingAs($admin, 'web');
        $article = $this->article('high', false);

        $this->get('/admin/content?article='.$article->id)->assertOk()
            ->assertSee('Exceptional owner review required')
            ->assertSee('Owner approval is required.')
            ->assertDontSee('Record decision and publish')
            ->assertDontSee('Approve and publish verified revision')
            ->assertDontSee('name="reason"', false);

        $this->post('/admin/content/'.$article->id.'/publish')
            ->assertSessionHasErrors('publish');
        $this->assertSame('draft', $article->fresh()->status);

        $this->post('/admin/editorial/automation/'.$article->id.'/manual-review', [
            'reason' => 'source_reviewed',
            'confirmed' => '1',
        ])->assertForbidden();
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseMissing('editorial_events', [
            'article_id' => $article->id,
            'action' => 'manual_published',
        ]);
    }

    public function test_low_risk_non_professional_content_keeps_ordinary_publish_path(): void
    {
        $this->actingAs($this->actor('admin'), 'web');
        $article = $this->article('low', false);

        $this->get('/admin/content?article='.$article->id.'&lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('اعتماد ونشر الإصدار الموثّق')
            ->assertDontSee('مراجعة استثنائية مطلوبة')
            ->assertDontSee('name="reason"', false);

        $this->post('/admin/content/'.$article->id.'/publish')
            ->assertRedirect();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'action' => 'manual_published',
            'reason' => 'content_reviewed',
        ]);
    }

    public function test_unknown_risk_or_unknown_claim_state_fails_closed_to_owner_exception(): void
    {
        $owner = $this->actor('owner');
        $this->actingAs($owner, 'web');

        foreach ([
            $this->article(null, false),
            $this->article('low', null),
        ] as $article) {
            $this->get('/admin/content?article='.$article->id)->assertOk()
                ->assertSee('Exceptional owner review required')
                ->assertSee('Record decision and publish')
                ->assertDontSee('Approve and publish verified revision');

            $this->post('/admin/content/'.$article->id.'/publish')
                ->assertSessionHasErrors('publish');
            $this->assertSame('draft', $article->fresh()->status);
        }
    }

    public function test_incomplete_evidence_never_exposes_any_publish_action(): void
    {
        $this->actingAs($this->actor('owner'), 'web');
        $article = $this->article('high', false, false);

        $this->get('/admin/content?article='.$article->id)->assertOk()
            ->assertSee('Publishing blocked until citation, technical and image-rights gates pass.')
            ->assertDontSee('Record decision and publish')
            ->assertDontSee('Approve and publish verified revision')
            ->assertDontSee('name="reason"', false);

        $this->post('/admin/editorial/automation/'.$article->id.'/manual-review', [
            'reason' => 'legal_reviewed',
            'confirmed' => '1',
        ])->assertSessionHasErrors('article');
        $this->assertSame('draft', $article->fresh()->status);
        $this->get('/blog/'.$article->slug)->assertNotFound();
    }
}
