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
            'checksum' => hash('sha256', $content),
            'created_at' => now()->subMinutes(6),
        ]);

        return $id;
    }

    public function test_empty_contract_and_normalized_database_schema_have_no_fabricated_guides(): void
    {
        $this->assertTrue(Schema::hasTable('runbooks'));
        $this->assertTrue(Schema::hasTable('runbook_versions'));
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
}
