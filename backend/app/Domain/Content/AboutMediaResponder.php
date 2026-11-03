<?php

namespace App\Domain\Content;

use App\Models\AboutMediaAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AboutMediaResponder
{
    public function show(string $slot): Response
    {
        $catalog = collect(PageContentDefaults::aboutMedia())->firstWhere('slot', $slot);
        abort_unless($catalog, 404);
        $asset = null;
        try {
            if (Schema::hasTable('about_media_assets')) {
                $asset = AboutMediaAsset::query()->whereKey($slot)->first();
            }
        } catch (Throwable) {
            // A fresh uninstalled site retains its original static assets.
        }

        if ($asset?->published_path && in_array($asset->published_mime, [
            'image/png','image/jpeg','image/webp',
        ], true) && Storage::disk('local')->exists($asset->published_path)) {
            return response()->file(Storage::disk('local')->path($asset->published_path), [
                'Content-Type' => $asset->published_mime,
                'Cache-Control' => 'no-cache, public',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }
        if ($slot === 'portrait') {
            $primary = public_path('assets/portraits/portrait-light-derived.jpg');
            $source = base_path('../assets/portraits/portrait-light-derived.jpg');
            $path = is_readable($primary) ? $primary : $source;
            abort_unless(is_file($path) && is_readable($path), 404);
            return response()->file($path, [
                'Content-Type'=>'image/jpeg', 'X-Content-Type-Options'=>'nosniff',
                'Cache-Control'=>'public, max-age=86400',
            ]);
        }
        // Real technology SVG logos are locally bundled from the MIT-licensed
        // Devicon project, not generated or remotely fetched by site visitors.
        $logo = $catalog['logo'] ?? null;
        if (is_string($logo) && in_array($logo, [
            'sql','laravel','flutter','php','mariadb','redis',
            'git','github','dotnet','windows','cicd',
        ], true)) {
            $primary = public_path('assets/technology-logos/'.$logo.'.svg');
            $source = base_path('../assets/technology-logos/'.$logo.'.svg');
            $path = is_readable($primary) ? $primary : $source;
            if (is_file($path) && is_readable($path)) {
                return response()->file($path, [
                    'Content-Type'=>'image/svg+xml',
                    'X-Content-Type-Options'=>'nosniff',
                    'Content-Security-Policy'=>"default-src 'none'; sandbox",
                    'Cache-Control'=>'public, max-age=86400',
                ]);
            }
        }
        // Icons belong to the checked-in owner pack; employer marks are
        // deliberately neutral until a rights-cleared real logo is uploaded.
        $name = $catalog['icon'];
        abort_unless(in_array($name, [
            'about','briefcase','database','code','github','store','api',
            'ai','workflow','projects','security',
        ], true), 404);
        $primary = public_path('assets/icons/'.$name.'.svg');
        $source = base_path('../assets/icons/'.$name.'.svg');
        $path = is_readable($primary) ? $primary : $source;
        abort_unless(is_file($path) && is_readable($path), 404);
        return response()->file($path, [
            'Content-Type'=>'image/svg+xml', 'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'none'; sandbox",
            'Cache-Control'=>'public, max-age=86400',
        ]);
    }
}
