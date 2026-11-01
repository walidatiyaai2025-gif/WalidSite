<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicRunbookPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function guide(
        string $slug,
        bool $approved = true,
        bool $offline = true,
        int $version = 1,
        ?string $body = null
    ): string {
        $id = (string) Str::uuid();
        $content = $body ?? '# Reviewed recovery checklist: '. $slug;
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => 'Reviewed guide '. $slug,
            'body_md' => $content,
            'version' => $version,
            'validated_at' => $approved ? now()->subMinutes(5) : null,
            'offline_ok' => $offline,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => $version,
            'body_md' => $content,
            'reviewed_title' => 'Reviewed guide '. $slug,
            'checksum' => hash('sha256', $content),
            'created_at' => now()->subMinutes(6),
        ]);

        return $id;
    }

    public function test_empty_contract_and_normalized_database_schema_have_no_fabricated_guides(): void
    {
        $this->assertTrue(Schema::hasTable('runbooks'));
        $this->assertTrue(Schema::hasTable('runbook_versions'));
        $this->assertTrue(Schema::hasColumn('runbook_versions', 'reviewed_title'));
        Http::fake();

        $this->getJson('/api/v1/mobile/runbooks')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/mobile/solutions/'.Str::uuid())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_anonymous_list_and_uuid_detail_share_the_same_verified_version_snapshot(): void
    {
        $id = $this->guide('database-backup');
        Http::fake();

        $this->getJson('/api/v1/mobile/runbooks')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.title', 'Reviewed guide database-backup')
            ->assertJsonPath('data.0.markdown', '# Reviewed recovery checklist: database-backup')
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertJsonStructure(['data' => [['id', 'title', 'markdown', 'version', 'validated_at']]])
            ->assertJsonMissingPath('data.0.slug')
            ->assertJsonMissingPath('data.0.checksum');

        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.markdown', '# Reviewed recovery checklist: database-backup')
            ->assertJsonMissingPath('data.slug')
            ->assertJsonMissingPath('data.checksum');
        Http::assertNothingSent();
    }

    public function test_draft_future_offline_disabled_and_bad_checksums_are_invisible(): void
    {
        $draft = $this->guide('draft-guide', approved: false);
        $offlineDenied = $this->guide('not-for-offline', offline: false);
        $future = $this->guide('future-review');
        $wrongChecksum = $this->guide('wrong-checksum');
        $editedBody = $this->guide('changed-after-review');
        $noVersion = $this->guide('no-matching-version');
        $safe = $this->guide('approved-visible');

        DB::table('runbooks')->where('id', $future)
            ->update(['validated_at' => now()->addDay()]);
        DB::table('runbook_versions')->where('runbook_id', $wrongChecksum)
            ->update(['checksum' => str_repeat('0', 64)]);
        DB::table('runbooks')->where('id', $editedBody)
            ->update(['body_md' => '# Unreviewed replacement']);
        DB::table('runbooks')->where('id', $noVersion)
            ->update(['version' => 2]);
        Http::fake();

        $response = $this->getJson('/api/v1/mobile/runbooks')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $safe)
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertDontSee('Unreviewed replacement');
        foreach ([$draft, $offlineDenied, $future, $wrongChecksum, $editedBody, $noVersion] as $id) {
            $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();
            $response->assertDontSee($id);
        }
        Http::assertNothingSent();
    }

    public function test_old_version_cannot_leak_or_authorize_an_updated_current_body(): void
    {
        $id = $this->guide('versioned-guide', body: '# Approved version one');
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 2,
            'body_md' => '# Approved version two',
            'reviewed_title' => 'Reviewed guide versioned-guide',
            'checksum' => hash('sha256', '# Approved version two'),
            'created_at' => now()->subMinutes(2),
        ]);

        // Before changing the current version, an unpinned later record
        // must not replace the previously reviewed public snapshot.
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.markdown', '# Approved version one');

        DB::table('runbooks')->where('id', $id)->update(['version' => 2]);
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();
        // Even a matching body and checksum must not inherit the OLD
        // validation timestamp: this newer version did not yet exist then.
        DB::table('runbooks')->where('id', $id)
            ->update(['body_md' => '# Approved version two']);
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();

        // A new independent review can make this exact stored version
        // available without ever returning the old body.
        DB::table('runbooks')->where('id', $id)
            ->update(['validated_at' => now()->subMinute()]);
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.markdown', '# Approved version two');
    }

    public function test_public_pagination_excludes_invalid_candidates_before_counting_page_slots(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $id = $this->guide(sprintf('a-unreviewed-%02d', $i));
            DB::table('runbook_versions')->where('runbook_id', $id)
                ->update(['checksum' => str_repeat('0', 64)]);
        }

        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $ids[] = $this->guide(sprintf('z-reviewed-%02d', $i));
        }
        $first = $this->getJson('/api/v1/mobile/runbooks?page=1')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more_pages', true)
            ->assertDontSee('a-unreviewed-');
        $second = $this->getJson('/api/v1/mobile/runbooks?page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertDontSee('a-unreviewed-');
        $this->getJson('/api/v1/mobile/runbooks?page=3')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $returned = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id')
        );
        $this->assertEqualsCanonicalizing($ids, $returned);
    }

    public function test_untrusted_queries_and_non_uuid_solution_paths_fail_closed(): void
    {
        foreach ([
            '/api/v1/mobile/runbooks?page=0',
            '/api/v1/mobile/runbooks?page=10001',
            '/api/v1/mobile/runbooks?page[]=1',
            '/api/v1/mobile/runbooks?page=3.14',
        ] as $uri) {
            $this->getJson($uri)->assertUnprocessable();
        }
        foreach (['not-a-uuid', '123', 'abc%2Fdef'] as $id) {
            $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();
        }
    }

    public function test_version_history_enforces_per_runbook_uniqueness_and_cascades(): void
    {
        $id = $this->guide('foreign-key-contract');
        $this->assertDatabaseCount('runbook_versions', 1);
        DB::table('runbooks')->where('id', $id)->delete();
        $this->assertDatabaseCount('runbook_versions', 0);
    }

    public function test_post_review_title_change_is_withdrawn_from_list_and_detail_without_touching_body_checksum(): void
    {
        $id = $this->guide('title-must-be-reviewed');
        Http::fake();
        $this->getJson('/api/v1/mobile/runbooks')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Reviewed guide title-must-be-reviewed');
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()
            ->assertJsonPath('data.title', 'Reviewed guide title-must-be-reviewed');

        // A change to a live display heading is NOT a new editorial review,
        // even when the body and version checksum are byte-identical.
        DB::table('runbooks')->where('id', $id)
            ->update(['title' => 'Unreviewed new operating procedure']);
        $this->getJson('/api/v1/mobile/runbooks')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertDontSee('Unreviewed new operating procedure');
        $this->getJson('/api/v1/mobile/solutions/'.$id)
            ->assertNotFound()
            ->assertDontSee('Unreviewed new operating procedure');

        // Revert the live metadata to its reviewed snapshot. Do not change
        // any review or version state in order to restore the original title.
        DB::table('runbooks')->where('id', $id)
            ->update(['title' => 'Reviewed guide title-must-be-reviewed']);
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()
            ->assertJsonPath('data.title', 'Reviewed guide title-must-be-reviewed');
        Http::assertNothingSent();
    }

    public function test_unreviewed_or_legacy_null_title_never_inherits_published_status(): void
    {
        $valid = $this->guide('legitimate-title');
        $legacy = $this->guide('legacy-without-reviewed-title');
        $wrong = $this->guide('title-review-mismatch');
        $blank = $this->guide('blank-reviewed-title');

        DB::table('runbook_versions')->where('runbook_id', $legacy)
            ->update(['reviewed_title' => null]);
        DB::table('runbook_versions')->where('runbook_id', $wrong)
            ->update(['reviewed_title' => 'Another guide title']);
        DB::table('runbook_versions')->where('runbook_id', $blank)
            ->update(['reviewed_title' => '  ']);
        $listed = $this->getJson('/api/v1/mobile/runbooks')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $valid)
            ->assertDontSee('Another guide title')
            ->assertDontSee('legacy-without-reviewed-title');
        foreach ([$legacy, $wrong, $blank] as $id) {
            $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();
            $listed->assertDontSee($id);
        }

        // New review is explicit; migrations must never copy a live title
        // into existing version records automatically.
        DB::table('runbook_versions')->where('runbook_id', $legacy)
            ->update(['reviewed_title' => 'Reviewed guide legacy-without-reviewed-title']);
        $this->getJson('/api/v1/mobile/solutions/'.$legacy)->assertOk()
            ->assertJsonPath('data.title', 'Reviewed guide legacy-without-reviewed-title');
    }

    public function test_new_version_requires_matching_title_and_a_review_later_than_that_snapshot(): void
    {
        $id = $this->guide('reviewed-new-version');
        $newTitle = 'Reviewed next-version heading';
        $newBody = '# Revised approved body';
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 2,
            'body_md' => $newBody,
            'reviewed_title' => $newTitle,
            'checksum' => hash('sha256', $newBody),
            'created_at' => now()->subMinute(),
        ]);
        DB::table('runbooks')->where('id', $id)->update([
            'title' => $newTitle, 'body_md' => $newBody, 'version' => 2,
        ]);
        // Old review predates v2, so it cannot implicitly approve its title.
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();
        DB::table('runbooks')->where('id', $id)
            ->update(['validated_at' => now()]);
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()
            ->assertJsonPath('data.title', $newTitle)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.markdown', $newBody);

        DB::table('runbooks')->where('id', $id)
            ->update(['title' => 'A new edit without review']);
        $this->getJson('/api/v1/mobile/solutions/'.$id)->assertNotFound();
    }

}
