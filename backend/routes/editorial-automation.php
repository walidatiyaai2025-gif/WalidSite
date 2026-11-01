<?php

use App\AI\EditorialScheduleRunner;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

Route::middleware('auth:web')->prefix('admin/editorial/automation')
    ->name('admin.editorial.')->group(function (): void {
        Route::get('/', function (Request $request) {
            abort_unless($request->user()?->canAccessAdmin(), 403);
            $input = $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
            $locale = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
            $policy = DB::table('editorial_policies')->where('id', 1)->first();
            $schedules = DB::table('editorial_schedules as schedule')
                ->join('articles as article', 'article.id', '=', 'schedule.article_id')
                ->select('schedule.id', 'schedule.article_id', 'schedule.revision_no',
                    'schedule.status', 'schedule.due_at', 'schedule.refresh_due_at',
                    'article.title_en', 'article.title_ar')
                ->orderByDesc('schedule.created_at')->limit(25)->get();
            $events = DB::table('editorial_events')
                ->select('action', 'reason', 'revision_no', 'created_at')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(25)->get();
            $candidates = Article::query()->orderByDesc('updated_at')->limit(25)->get()
                ->filter(fn (Article $article) => $article->status !== 'published'
                    && $article->isReadyToPublish())
                ->values();

            return view('admin.editorial.automation', compact(
                'locale', 'policy', 'schedules', 'events', 'candidates'
            ));
        })->name('index');

        Route::post('/policy', function (Request $request, EditorialScheduleRunner $runner) {
            abort_unless($request->user()?->canAccessAdmin(), 403);
            $input = $request->validate([
                'enabled' => ['required', 'boolean'],
                'max_per_tick' => ['required', 'integer', 'between:1,20'],
                'refresh_days' => ['required', 'integer', 'between:1,365'],
            ]);
            DB::transaction(function () use ($request, $input, $runner): void {
                DB::table('editorial_policies')->insertOrIgnore([
                    'id' => 1, 'enabled' => false, 'max_per_tick' => 10,
                    'refresh_days' => 30, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('editorial_policies')->where('id', 1)->lockForUpdate()->first();
                DB::table('editorial_policies')->where('id', 1)->update([
                    'enabled' => (bool) $input['enabled'],
                    'max_per_tick' => (int) $input['max_per_tick'],
                    'refresh_days' => (int) $input['refresh_days'],
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
                $runner->event('policy:'.Str::uuid(), null, null,
                    $request->user()->id, 'policy_updated', 'settings_changed');
            }, 3);
            return redirect()->route('admin.editorial.index',
                ['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en'])
                ->with('status', 'Editorial automation policy saved.');
        })->middleware('throttle:10,1')->name('policy');

        Route::post('/schedule', function (Request $request, EditorialScheduleRunner $runner) {
            abort_unless($request->user()?->canAccessAdmin(), 403);
            $input = $request->validate([
                'article_id' => ['required', 'uuid'],
                'due_at' => ['required', 'date', 'after:now'],
                'confirmed' => ['required', 'accepted'],
            ]);
            DB::transaction(function () use ($request, $input, $runner): void {
                $article = Article::query()->whereKey($input['article_id'])
                    ->lockForUpdate()->firstOrFail();
                $revision = $article->revisions()->orderByDesc('revision_no')->first();
                $checks = $revision?->validation_json;
                if ($article->status === 'published' || ! $article->isReadyToPublish()
                    || ($checks['risk_level'] ?? null) !== 'low'
                    || ($checks['auto_publish_approved'] ?? null) !== true
                    || ($checks['contains_professional_claims'] ?? null) !== false) {
                    throw ValidationException::withMessages([
                        'article_id' => 'Only explicitly reviewed low-risk content may be scheduled.',
                    ]);
                }

                $existing = DB::table('editorial_schedules')
                    ->where('article_id', $article->id)
                    ->where('revision_no', $revision->revision_no)
                    ->lockForUpdate()->first();
                $id = $existing?->id ?? (string) Str::uuid();
                $details = [
                    'approved_by' => $request->user()->id,
                    'due_at' => $input['due_at'],
                    'status' => 'pending',
                    'refresh_due_at' => null,
                    'updated_at' => now(),
                ];
                if ($existing) {
                    DB::table('editorial_schedules')->where('id', $id)->update($details);
                } else {
                    DB::table('editorial_schedules')->insert(array_merge($details, [
                        'id' => $id, 'article_id' => $article->id,
                        'revision_no' => $revision->revision_no, 'created_at' => now(),
                    ]));
                }
                $runner->event('schedule:'.Str::uuid(), $article->id,
                    $revision->revision_no, $request->user()->id, 'scheduled', 'low_risk_approved');
            }, 3);
            return redirect()->route('admin.editorial.index',
                ['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en'])
                ->with('status', 'Reviewed low-risk article scheduled.');
        })->middleware('throttle:10,1')->name('schedule');

        Route::post('/{article}/manual-review', function (
            Request $request, Article $article, EditorialScheduleRunner $runner
        ) {
            abort_unless($request->user()?->canAccessAdmin(), 403);
            $input = $request->validate([
                'reason' => ['required', 'in:source_reviewed,technical_reviewed,legal_reviewed'],
                'confirmed' => ['required', 'accepted'],
            ]);
            DB::transaction(function () use ($article, $request, $runner, $input): void {
                $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isReadyToPublish()) {
                    throw ValidationException::withMessages([
                        'article' => 'Independent citation, technical and media review is incomplete.',
                    ]);
                }
                $revision = $locked->revisions()->orderByDesc('revision_no')->firstOrFail();
                $checks = $revision->validation_json;
                // Professional, high and unknown risk require a real owner
                // decision; admin alone may never override these gates.
                if (($checks['risk_level'] ?? null) !== 'low'
                    || ($checks['contains_professional_claims'] ?? null) !== false) {
                    abort_unless($request->user()->hasRole('owner'), 403);
                }
                $locked->publishVerified();
                $runner->event('manual:'.Str::uuid(), $locked->id, $revision->revision_no,
                    $request->user()->id, 'manual_published', $input['reason']);
            }, 3);
            return redirect()->route('admin.editorial.index')
                ->with('status', 'Manual editorial decision recorded.');
        })->middleware('throttle:10,1')->name('manual');
    });
