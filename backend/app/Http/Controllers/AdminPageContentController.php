<?php

namespace App\Http\Controllers;

use App\Domain\Content\PageContentDefaults;
use App\Models\SitePageText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AdminPageContentController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless($request->user()?->status === 'active'
            && $request->user()->hasRole('owner'), 403);
    }

    private function locale(Request $request): string
    {
        return $request->query('lang') === 'ar' ? 'ar' : 'en';
    }

    public function index(Request $request): View
    {
        $this->owner($request);
        $input = $request->validate([
            'lang' => ['sometimes','in:ar,en'],
            'page_key' => ['sometimes','in:home,about,projects,expertise'],
            'section_key' => ['sometimes','string','max:64'],
            'page' => ['sometimes','integer','between:1,10000'],
        ]);
        $page = $input['page_key'] ?? 'home';
        $sections = array_keys(PageContentDefaults::all()[$page]);
        $section = isset($input['section_key']) && in_array($input['section_key'], $sections, true)
            ? $input['section_key'] : $sections[0];
        $rows = SitePageText::query()->where('page_key', $page)
            ->where('section_key', $section)
            ->orderBy('field_key')->orderBy('locale')
            ->paginate(20)->withQueryString();

        return view('admin.page-content.index', [
            'rows' => $rows, 'selectedPage' => $page, 'selectedSection' => $section,
            'catalog' => PageContentDefaults::all(), 'locale' => $this->locale($request),
        ]);
    }

    /**
     * Add a missing approved-layout slot, not an arbitrary HTML element.
     * New custom professional claims must go through the existing guarded
     * CV/projects/articles content domains, never this copy-only surface.
     */
    public function add(Request $request): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'page_key'=>['required','string','max:40'],
            'section_key'=>['required','string','max:64'],
            'field_key'=>['required','string','max:80'],
            'locale'=>['required','in:ar,en'],
        ]);
        abort_unless(PageContentDefaults::isAllowed(
            $data['page_key'], $data['section_key'], $data['field_key'], $data['locale']
        ), 422);
        $now = now('UTC');
        DB::table('site_page_texts')->insertOrIgnore([
            'id'=>(string) Str::uuid(),
            'page_key'=>$data['page_key'], 'section_key'=>$data['section_key'],
            'field_key'=>$data['field_key'], 'locale'=>$data['locale'],
            'published_text'=>null,
            'draft_text'=>PageContentDefaults::all()[$data['page_key']][$data['section_key']][$data['field_key']][$data['locale']],
            'source'=>'admin_restored_slot', 'revision'=>0,
            'created_at'=>$now, 'updated_at'=>$now,
        ]);

        return redirect()->route('admin.page-content.index', [
            'page_key'=>$data['page_key'], 'section_key'=>$data['section_key'],
            'lang'=>$this->locale($request),
        ])->with('status', 'Missing design copy slot restored as a private draft.');
    }

    public function save(Request $request, SitePageText $entry): RedirectResponse
    {
        $this->owner($request);
        abort_unless(PageContentDefaults::isAllowed(
            $entry->page_key, $entry->section_key, $entry->field_key, $entry->locale
        ), 404);
        $data = $request->validate([
            'draft_text'=>['required','string','min:1','max:1500',
                static function ($attribute, $value, $fail): void {
                    if (preg_match('/[<>]|[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', $value)) {
                        $fail('Only plain text without HTML or control characters is allowed.');
                    }
                }],
            'revision'=>['required','integer','min:0'],
        ]);
        DB::transaction(function () use ($entry, $data, $request): void {
            $row = SitePageText::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->revision === (int) $data['revision'], 409);
            $before = $row->draft_text;
            $row->forceFill([
                'draft_text'=>trim($data['draft_text']),
                'edited_by'=>$request->user()->id,
                'revision'=>$row->revision+1,
            ])->save();
            $this->event($row, $request, 'draft_saved', $before, $row->draft_text, null);
        }, 3);

        return $this->backTo($entry, $request, 'Draft saved privately. Public copy unchanged.');
    }

    public function publish(Request $request, SitePageText $entry): RedirectResponse
    {
        $this->owner($request);
        abort_unless(PageContentDefaults::isAllowed(
            $entry->page_key, $entry->section_key, $entry->field_key, $entry->locale
        ), 404);
        $data = $request->validate([
            'revision'=>['required','integer','min:0'],
            'source_reviewed'=>['accepted'],
            'publication_rights_confirmed'=>['accepted'],
            'source_note'=>['required','string','min:12','max:240'],
        ]);
        DB::transaction(function () use ($entry, $data, $request): void {
            $row = SitePageText::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->revision === (int) $data['revision'], 409);
            if ($row->draft_text === null || trim($row->draft_text) === '') {
                throw ValidationException::withMessages(['draft_text'=>'No reviewed draft to publish.']);
            }
            $before = $row->published_text;
            $row->forceFill([
                'published_text'=>$row->draft_text,
                'draft_text'=>null,
                'published_by'=>$request->user()->id,
                'published_at'=>now('UTC'),
                'revision'=>$row->revision+1,
            ])->save();
            $this->event($row, $request, 'published', $before, $row->published_text, trim($data['source_note']));
        }, 3);

        return $this->backTo($entry, $request, 'Reviewed page copy published.');
    }

    private function event(
        SitePageText $row, Request $request, string $action,
        ?string $before, ?string $after, ?string $note
    ): void {
        DB::table('site_page_text_events')->insert([
            'id'=>(string) Str::uuid(),
            'site_page_text_id'=>$row->id,
            'actor_id'=>$request->user()->id,
            'action'=>$action,
            'revision'=>$row->revision,
            'source_note'=>$note,
            'old_hash'=>$before === null ? null : hash('sha256', $before),
            'new_hash'=>$after === null ? null : hash('sha256', $after),
            'created_at'=>now('UTC'),
        ]);
    }

    private function backTo(SitePageText $row, Request $request, string $message): RedirectResponse
    {
        return redirect()->route('admin.page-content.index', [
            'page_key'=>$row->page_key, 'section_key'=>$row->section_key,
            'lang'=>$this->locale($request),
        ])->with('status', $message);
    }
}
