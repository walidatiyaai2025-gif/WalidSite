<?php

namespace Database\Seeders;

use App\Domain\Content\PageContentDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First-install page-copy seed: idempotent insert-only. A reinstall/re-run
 * cannot overwrite any owner edited/published data or its audit history.
 */
final class WebsitePageContentSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');
        $rows = [];
        foreach (PageContentDefaults::all() as $page => $sections) {
            foreach ($sections as $section => $fields) {
                foreach ($fields as $field => $translations) {
                    foreach (['en', 'ar'] as $locale) {
                        $approvedHome = $page === 'home';
                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'page_key' => $page,
                            'section_key' => $section,
                            'field_key' => $field,
                            'locale' => $locale,
                            // The currently owner-approved home copy is
                            // public; the unapproved Expertise/other-page
                            // proposals stay private editorial drafts.
                            'published_text' => $approvedHome ? $translations[$locale] : null,
                            'draft_text' => $approvedHome ? null : $translations[$locale],
                            'source' => $approvedHome ? 'owner_approved_2026' : 'owner_design_review',
                            'revision' => 0,
                            'published_at' => $approvedHome ? $now : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
        }
        DB::transaction(static function () use ($rows): void {
            foreach (array_chunk($rows, 150) as $batch) {
                DB::table('site_page_texts')->insertOrIgnore($batch);
            }
        });
    }
}
