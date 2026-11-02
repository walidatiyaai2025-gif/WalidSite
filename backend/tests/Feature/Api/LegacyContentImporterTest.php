<?php

namespace Tests\Feature\Api;

use App\Domain\LegacyImport\LegacyContentImporter;
use App\Models\Article;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyContentImporterTest extends TestCase
{
    use RefreshDatabase;

    private function validSql(): string
    {
        return <<<'SQL'
INSERT INTO legacy_articles (legacy_key, slug, title_en, title_ar, canonical_lang, body_md, citations_json) VALUES ('article-1', 'legacy-arabic-guide', 'Legacy reviewed later', 'دليل عربي قديم', 'ar', '# خطوات عربية آمنة', '["https://docs.example.org/legacy-guide"]');
INSERT INTO legacy_projects (legacy_key, slug, title, summary_en, summary_ar, role_en, role_ar, status) VALUES ('project-1', 'legacy-project', 'Legacy Project', 'Imported privately for later review.', 'تم الاستيراد بشكل خاص للمراجعة اللاحقة.', 'Engineer', 'مهندس', 'completed');
INSERT INTO legacy_project_evidence (legacy_key, project_slug, evidence_type, url) VALUES ('evidence-1', 'legacy-project', 'documentation', 'https://docs.example.org/legacy-project');
INSERT INTO legacy_runbooks (legacy_key, slug, title, body_md, version) VALUES ('runbook-1', 'legacy-recovery', 'Legacy Recovery', '# استعادة آمنة', 3);
SQL;
    }

    public function test_dry_run_then_import_preserves_arabic_and_keeps_everything_unpublished(): void
    {
        $importer = app(LegacyContentImporter::class);

        $dry = $importer->dryRunSql($this->validSql(), 'approved-export.sql');

        $this->assertTrue($dry['dry_run']);
        $this->assertSame(4, $dry['parsed_rows']);
        $this->assertSame(0, $dry['rejected_count']);
        $this->assertSame([
            'article' => 1,
            'project' => 1,
            'project_evidence' => 1,
            'runbook' => 1,
        ], $dry['accepted']);
        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('runbooks', 0);
        $this->assertDatabaseCount('legacy_import_receipts', 0);

        $applied = $importer->importSql($this->validSql(), 'approved-export.sql');

        $this->assertFalse($applied['dry_run']);
        $this->assertSame([
            'article' => 1,
            'project' => 1,
            'project_evidence' => 1,
            'runbook' => 1,
        ], $applied['created']);
        $this->assertDatabaseCount('legacy_import_receipts', 4);

        $article = Article::query()->where('slug', 'legacy-arabic-guide')->firstOrFail();
        $this->assertSame('دليل عربي قديم', $article->title_ar);
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $revision = $article->revisions()->firstOrFail();
        $this->assertSame('# خطوات عربية آمنة', $revision->body_md);
        $this->assertSame('draft', $revision->status);
        $this->assertFalse($revision->validation_json['citations_passed']);
        $this->assertFalse($revision->citations_json[0]['verified']);

        $project = Project::query()->where('slug', 'legacy-project')->firstOrFail();
        $this->assertSame('private', $project->visibility);
        $this->assertNull($project->published_at);
        $evidence = $project->evidence()->firstOrFail();
        $this->assertSame('private', $evidence->visibility);
        $this->assertNull($evidence->verified_at);

        $runbook = DB::table('runbooks')->where('slug', 'legacy-recovery')->first();
        $this->assertNotNull($runbook);
        $this->assertSame('# استعادة آمنة', $runbook->body_md);
        $this->assertNull($runbook->validated_at);
        $this->assertSame(0, (int) $runbook->offline_ok);
        $version = DB::table('runbook_versions')->where('runbook_id', $runbook->id)->first();
        $this->assertNotNull($version);
        $this->assertNull($version->reviewed_title);
        $this->assertSame(hash('sha256', '# استعادة آمنة'), $version->checksum);

        $retry = $importer->importSql($this->validSql(), 'renamed-retry.sql');
        $this->assertSame(4, $retry['duplicate_count']);
        $this->assertSame([
            'article' => 0,
            'project' => 0,
            'project_evidence' => 0,
            'runbook' => 0,
        ], $retry['created']);
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_evidence', 1);
        $this->assertDatabaseCount('runbooks', 1);
        $this->assertDatabaseCount('legacy_import_receipts', 4);
    }

    public function test_users_ddl_unknown_columns_xss_and_unsafe_media_are_rejected_without_execution(): void
    {
        $owner = User::create([
            'email' => 'owner-'.Str::lower(Str::random(6)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);

        $sql = <<<'SQL'
INSERT INTO users (email, password_hash) VALUES ('attacker@example.test', 'secret');
DROP TABLE users;
INSERT INTO legacy_projects (slug, title, summary_en, summary_ar, status, password) VALUES ('bad-columns', 'Bad', 'Bad', 'سيئ', 'research', 'secret');
INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('xss-guide', 'XSS', '<script>alert(1)</script>', 1);
INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('media-guide', 'Media', '![stolen](https://images.example.org/person.jpg)', 1);
SQL;

        $importer = app(LegacyContentImporter::class);
        $dry = $importer->dryRunSql($sql, 'hostile.sql');

        $this->assertGreaterThanOrEqual(5, $dry['rejected_count']);
        $reasons = collect($dry['rejections'])->pluck('reason')->all();
        $this->assertContains('source_table_not_allowlisted', $reasons);
        $this->assertContains('only_literal_insert_statements_are_allowed', $reasons);
        $this->assertContains('source_column_not_allowlisted', $reasons);
        $this->assertContains('body_md_contains_unsafe_markup', $reasons);
        $this->assertContains('unlicensed_or_remote_media_rejected', $reasons);

        try {
            $importer->importSql($sql, 'hostile.sql');
            $this->fail('Hostile source should never be applied.');
        } catch (DomainException) {
            // Expected fail-closed refusal before any transaction writes.
        }

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => $owner->email]);
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.test']);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('runbooks', 0);
        $this->assertDatabaseCount('legacy_import_receipts', 0);
    }

    public function test_broken_foreign_keys_signed_urls_and_manual_target_conflicts_fail_closed(): void
    {
        Project::create([
            'slug' => 'verified-existing',
            'title' => 'Existing verified project',
            'summary_en' => 'Existing source-owned record.',
            'summary_ar' => 'سجل موجود مسبقًا.',
            'role_en' => 'Owner',
            'role_ar' => 'المالك',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $sql = <<<'SQL'
INSERT INTO legacy_projects (slug, title, summary_en, summary_ar, status) VALUES ('verified-existing', 'Overwrite attempt', 'No', 'لا', 'completed');
INSERT INTO legacy_project_evidence (project_slug, evidence_type, url) VALUES ('missing-project', 'documentation', 'https://docs.example.org/missing');
INSERT INTO legacy_articles (slug, title_en, title_ar, canonical_lang, body_md, citations_json) VALUES ('signed-citation', 'Signed', 'رابط موقع', 'en', 'Body', '["https://docs.example.org/private?access_token=secret"]');
SQL;

        $dry = app(LegacyContentImporter::class)->dryRunSql($sql, 'conflicts.sql');

        $this->assertSame(3, $dry['rejected_count']);
        $reasons = collect($dry['rejections'])->pluck('reason')->all();
        $this->assertContains('target_already_exists_outside_legacy_import', $reasons);
        $this->assertContains('project_evidence_references_unimported_project', $reasons);
        $this->assertContains('citation_url_unsafe', $reasons);

        $this->expectException(DomainException::class);
        app(LegacyContentImporter::class)->importSql($sql, 'conflicts.sql');
    }

    public function test_literal_parser_rejects_expressions_comments_and_ambiguous_escapes(): void
    {
        $sources = [
            "INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('expr', NOW(), 'Body', 1);",
            "-- comment\nINSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('commented', 'Title', 'Body', 1);",
            "INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('escape', 'Title', 'path\\\\name', 1);",
            "INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('missing-end', 'Title', 'Body', 1)",
        ];

        foreach ($sources as $index => $sql) {
            $report = app(LegacyContentImporter::class)
                ->dryRunSql($sql, 'parser-'.$index.'.sql');
            $this->assertGreaterThan(0, $report['rejected_count'], 'Source '.$index.' should fail.');
        }

        $this->assertDatabaseCount('runbooks', 0);
        $this->assertDatabaseCount('legacy_import_receipts', 0);
    }

    public function test_file_reader_enforces_root_extension_symlink_and_size_bounds(): void
    {
        $root = storage_path('framework/testing/legacy-'.Str::random(8));
        $outside = storage_path('framework/testing/outside-'.Str::random(8).'.sql');
        File::makeDirectory($root, 0700, true);
        file_put_contents($root.'/safe.sql', $this->validSql());
        file_put_contents($root.'/wrong.txt', $this->validSql());
        file_put_contents($outside, $this->validSql());

        try {
            $report = app(LegacyContentImporter::class)->dryRunFile($root.'/safe.sql', $root);
            $this->assertSame(0, $report['rejected_count']);

            try {
                app(LegacyContentImporter::class)->dryRunFile($outside, $root);
                $this->fail('Path outside root should be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('escapes', $exception->getMessage());
            }

            try {
                app(LegacyContentImporter::class)->dryRunFile($root.'/wrong.txt', $root);
                $this->fail('Wrong extension should be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('.sql', $exception->getMessage());
            }

            if (function_exists('symlink')) {
                $link = $root.'/linked.sql';
                @symlink($outside, $link);
                if (is_link($link)) {
                    try {
                        app(LegacyContentImporter::class)->dryRunFile($link, $root);
                        $this->fail('Symlink source should be rejected.');
                    } catch (DomainException $exception) {
                        $this->assertStringContainsString('unsafe', $exception->getMessage());
                    }
                }
            }

            $large = $root.'/large.sql';
            file_put_contents($large, str_repeat('x', LegacyContentImporter::MAX_BYTES + 1));
            try {
                app(LegacyContentImporter::class)->dryRunFile($large, $root);
                $this->fail('Oversized source should be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('size', $exception->getMessage());
            }
        } finally {
            File::deleteDirectory($root);
            File::delete($outside);
        }
    }

    public function test_empty_and_unterminated_sources_never_create_receipts(): void
    {
        $importer = app(LegacyContentImporter::class);
        foreach (['', 'INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('] as $source) {
            $report = $importer->dryRunSql($source, 'malformed.sql');
            $this->assertGreaterThan(0, $report['rejected_count']);
            try {
                $importer->importSql($source, 'malformed.sql');
                $this->fail('Malformed import must fail closed.');
            } catch (DomainException) {
                // Expected: no partial writes or provenance receipts.
            }
        }
        $this->assertDatabaseCount('legacy_import_receipts', 0);
        $this->assertDatabaseCount('runbooks', 0);
    }

    public function test_conflicting_rows_for_same_target_are_reported_before_writes(): void
    {
        $sql = <<<'SQL'
INSERT INTO legacy_runbooks (slug, title, body_md, version) VALUES ('same-target', 'One', 'Body one', 1), ('same-target', 'Two', 'Body two', 2);
SQL;

        $report = app(LegacyContentImporter::class)->dryRunSql($sql, 'duplicate-target.sql');

        $this->assertSame(1, $report['rejected_count']);
        $this->assertSame('conflicting_duplicate_target_key', $report['rejections'][0]['reason']);
        $this->expectException(DomainException::class);
        app(LegacyContentImporter::class)->importSql($sql, 'duplicate-target.sql');
    }
}
