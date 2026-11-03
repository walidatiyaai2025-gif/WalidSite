<?php

namespace App\Http\Controllers;

use App\Domain\Content\PageContentDefaults;
use App\Models\AboutMediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminAboutMediaController extends Controller
{
    private function asset(Request $request, string $slot): AboutMediaAsset
    {
        abort_unless($request->user()?->status === 'active'
            && $request->user()->hasRole('owner'), 403);
        abort_unless(in_array($slot, array_column(PageContentDefaults::aboutMedia(), 'slot'), true), 404);
        return AboutMediaAsset::query()->whereKey($slot)->firstOrFail();
    }

    public function save(Request $request, string $slot): RedirectResponse
    {
        $asset = $this->asset($request, $slot);
        $data = $request->validate([
            'image' => ['required', 'file', 'max:1536', 'mimetypes:image/png,image/jpeg,image/webp'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        $uploaded = $request->file('image');
        $actual = @getimagesize($uploaded->getRealPath());
        $mime = $actual['mime'] ?? '';
        abort_unless(in_array($mime, ['image/png','image/jpeg','image/webp'], true)
            && $mime === $uploaded->getMimeType(), 422);
        $extension = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime];
        $file = 'about-media/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->putFileAs('about-media', $uploaded, basename($file));
        try {
            DB::transaction(function () use ($asset, $data, $request, $file, $mime): void {
                $row = AboutMediaAsset::query()->whereKey($asset->slot)->lockForUpdate()->firstOrFail();
                abort_unless($row->revision === (int) $data['revision'], 409);
                $before = $row->draft_path;
                $row->forceFill([
                    'draft_path' => $file, 'draft_mime' => $mime,
                    'edited_by' => $request->user()->id, 'revision' => $row->revision+1,
                ])->save();
                $this->event($row, $request, 'draft_uploaded', $before, $file, null);
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($file);
            throw $e;
        }
        return $this->back($request, 'Image draft saved; published site unchanged.');
    }

    public function publish(Request $request, string $slot): RedirectResponse
    {
        $asset = $this->asset($request, $slot);
        $data = $request->validate([
            'revision' => ['required','integer','min:0'],
            'source_reviewed' => ['accepted'],
            'publication_rights_confirmed' => ['accepted'],
            'source_note' => ['required','string','min:12','max:240'],
        ]);
        DB::transaction(function () use ($asset, $data, $request): void {
            $row = AboutMediaAsset::query()->whereKey($asset->slot)->lockForUpdate()->firstOrFail();
            abort_unless($row->revision === (int) $data['revision'], 409);
            if (! $row->draft_path || ! $row->draft_mime
                || ! Storage::disk('local')->exists($row->draft_path)) {
                throw ValidationException::withMessages(['image'=>'No valid image draft to publish.']);
            }
            $before = $row->published_path;
            $row->forceFill([
                'published_path' => $row->draft_path,
                'published_mime' => $row->draft_mime,
                'draft_path' => null, 'draft_mime' => null,
                'published_at' => now('UTC'),
                'published_by' => $request->user()->id,
                'revision' => $row->revision+1,
            ])->save();
            $this->event($row, $request, 'published', $before,
                $row->published_path, trim($data['source_note']));
        }, 3);
        return $this->back($request, 'Reviewed image published.');
    }

    public function retract(Request $request, string $slot): RedirectResponse
    {
        $asset = $this->asset($request, $slot);
        $data = $request->validate(['revision'=>['required','integer','min:0']]);
        DB::transaction(function () use ($asset, $request, $data): void {
            $row = AboutMediaAsset::query()->whereKey($asset->slot)->lockForUpdate()->firstOrFail();
            abort_unless($row->revision === (int) $data['revision'], 409);
            $before = $row->published_path;
            $row->forceFill([
                'published_path'=>null, 'published_mime'=>null,
                'published_at'=>null, 'published_by'=>null,
                'revision'=>$row->revision+1,
            ])->save();
            $this->event($row, $request, 'retracted', $before, null, null);
        }, 3);
        return $this->back($request, 'Image withdrawn; safe built-in icon restored.');
    }

    public function draft(Request $request, string $slot): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $asset = $this->asset($request, $slot);
        abort_unless($asset->draft_path && $asset->draft_mime
            && Storage::disk('local')->exists($asset->draft_path), 404);
        $response = response()->file(Storage::disk('local')->path($asset->draft_path), [
            'Content-Type' => $asset->draft_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function event(
        AboutMediaAsset $asset, Request $request, string $action,
        ?string $before, ?string $after, ?string $sourceNote
    ): void {
        DB::table('about_media_events')->insert([
            'id'=>(string) Str::uuid(), 'slot'=>$asset->slot,
            'actor_id'=>$request->user()->id, 'action'=>$action,
            'revision'=>$asset->revision, 'source_note'=>$sourceNote,
            'old_hash'=>$before ? hash('sha256', $before) : null,
            'new_hash'=>$after ? hash('sha256', $after) : null,
            'created_at'=>now('UTC'),
        ]);
    }

    private function back(Request $request, string $message): RedirectResponse
    {
        return redirect()->route('admin.page-content.index', [
            'page_key'=>'about', 'lang'=>$request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status',$message);
    }
}
