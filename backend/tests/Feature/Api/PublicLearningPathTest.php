<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicLearningPathTest extends TestCase
{
    use RefreshDatabase;

    /** @param mixed $outline */
    private function path(string $slug, bool $published = true, mixed $outline = null): string
    {
        $id = (string) Str::uuid();
        DB::table('learning_paths')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => 'Learning track '.$slug,
            'outline_json' => json_encode($outline ?? [
                ['title' => 'Safe first lesson', 'description' => 'Independent practice'],
            ], JSON_THROW_ON_ERROR),
            'published' => $published,
        ]);
        return $id;
    }

    public function test_empty_catalog_is_real_public_api_with_canonical_private_progress_table(): void
    {
        $this->assertTrue(Schema::hasTable('learning_paths'));
        $this->assertTrue(Schema::hasTable('learning_progress'));
        Http::fake();

        $this->getJson('/api/v1/mobile/learn')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more_pages', false);
        Http::assertNothingSent();
    }

    public function test_guest_sees_only_published_well_formed_tracks_without_private_metadata(): void
    {
        Http::fake();
        $public = $this->path('public-course', outline: [
            ['title' => 'مقدمة آمنة', 'description' => 'الدرس الأول'],
            ['title' => 'Second lesson'],
        ]);
        $draft = $this->path('unpublished-draft', published: false);
        $secret = $this->path('private-metadata', outline: [
            ['title' => 'Sensitive draft', 'secret_token' => 'DONT_EXPOSE_LESSON_TOKEN'],
        ]);
        $this->path('empty-outline', outline: []);
        $this->path('overlong-outline', outline: [
            ['title' => str_repeat('x', 221)],
        ]);
        $this->path('invalid-shape', outline: ['admin' => 'private']);
        $this->path('oversized-json', outline: [
            ['title' => 'Huge', 'description' => str_repeat('x', 1600)],
        ]);

        foreach (['en', 'ar'] as $lang) {
            $response = $this->getJson('/api/v1/mobile/learn?lang='.$lang)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $public)
                ->assertJsonPath('data.0.slug', 'public-course')
                ->assertJsonPath('data.0.outline.0.title', 'مقدمة آمنة')
                ->assertJsonPath('data.0.outline.1.description', '')
                ->assertJsonPath('meta.has_more_pages', false)
                ->assertJsonMissingPath('data.0.progress')
                ->assertJsonMissingPath('data.0.user_id');
            foreach ([$draft, $secret] as $id) {
                $response->assertDontSee($id);
            }
            $response->assertDontSee('DONT_EXPOSE_LESSON_TOKEN');
            $response->assertDontSee('unpublished-draft');
            $response->assertDontSee('Sensitive draft');
        }
        Http::assertNothingSent();
    }

    public function test_verified_pagination_ignores_invalid_prefix_before_page_slots(): void
    {
        for ($i = 1; $i <= 24; $i++) {
            $this->path(sprintf('a-private-%02d', $i), outline: [
                ['title' => 'Unsafe', 'internal_credentials' => 'must-not-publish'],
            ]);
        }
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $ids[] = $this->path(sprintf('z-safe-%02d', $i));
        }

        $first = $this->getJson('/api/v1/mobile/learn?page=1')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more_pages', true);
        $second = $this->getJson('/api/v1/mobile/learn?page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/mobile/learn?page=3')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $this->assertSame($ids, array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id')
        ));
        $first->assertDontSee('must-not-publish');
        $second->assertDontSee('a-private-');
    }

    public function test_invalid_query_is_rejected_and_private_progress_never_reaches_guest(): void
    {
        foreach (['?page=0', '?page=10001', '?page=abc', '?lang=fr'] as $query) {
            $this->getJson('/api/v1/mobile/learn'.$query)->assertUnprocessable();
        }

        $path = $this->path('progress-is-private');
        $user = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $user, 'email' => 'learning-test@example.org', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('learning_progress')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user,
            'path_id' => $path,
            'progress_json' => json_encode(['secret_note' => 'PRIVATE_PROGRESS_SENTINEL'],
                JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/mobile/learn')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertDontSee('PRIVATE_PROGRESS_SENTINEL')
            ->assertDontSee($user)
            ->assertJsonMissingPath('data.0.progress');
        $this->assertDatabaseCount('learning_progress', 1);

        // A removed learning path cannot orphan an authenticated user's data.
        DB::table('learning_paths')->where('id', $path)->delete();
        $this->assertDatabaseCount('learning_progress', 0);
        $this->getJson('/api/v1/mobile/learn')->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
