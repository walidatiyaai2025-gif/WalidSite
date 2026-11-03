<?php

namespace App\Domain\Content;

use App\Models\SitePageText;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Published-only projection. Never expose draft/claim-only content on public
 * pages; no unapproved fallback from the database.
 */
final class PageContentRepository
{
    /** @return array<string,array<string,string>> */
    public function publishedMap(string $page, string $locale): array
    {
        if (! in_array($locale, ['en','ar'], true)
            || ! in_array($page, ['home','about','projects','expertise'], true)) {
            return [];
        }
        try {
            // Fresh packages can render their safe static landing before
            // migrations; no DB draft may become public in that state.
            if (! Schema::hasTable('site_page_texts')) {
                return [];
            }
            $rows = SitePageText::query()
                ->where('page_key', $page)
                ->where('locale', $locale)
                ->whereNotNull('published_text')
                ->get(['section_key','field_key','published_text']);
        } catch (Throwable) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            if (PageContentDefaults::isAllowed($page, $row->section_key, $row->field_key, $locale)) {
                $map[$row->section_key][$row->field_key] = $row->published_text;
            }
        }

        return $map;
    }
}
