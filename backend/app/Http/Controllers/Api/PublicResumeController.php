<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicResumeController extends Controller
{
    /**
     * WEB-11: The API and printable resume must use identical publication
     * gates. A role filter changes emphasis, never the verified career record.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'track' => ['sometimes', 'in:all,database,software,delivery'],
        ]);
        $lang = $input['lang'] ?? 'en';
        $track = $input['track'] ?? 'all';
        $categories = [
            'all' => [],
            'database' => ['Database & Reliability'],
            'software' => ['Software & Integration', 'Infrastructure & Security'],
            'delivery' => ['Project Delivery'],
        ];

        // Reuse the public-profile gate and its exact owner selection. This
        // also ensures claims always belong to the SAME selected owner when
        // several active owner-role accounts happen to exist.
        $public = app(PublicPortfolioController::class)
            ->profile($request)->getData(true)['data'] ?? null;
        if ($public === null) {
            return response()->json(['data' => null]);
        }
        $profile = Profile::query()->published()
            ->whereKey($public['id'])
            ->whereHas('user', fn ($user) => $user->where('status', 'active')
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
            ->firstOrFail();

        $arabic = $lang === 'ar';
        $positions = CareerPosition::query()->publiclyVerified()
            ->where('profile_id', $profile->id)
            ->orderByDesc('started_on')->orderBy('id')->get()
            ->map(static fn (CareerPosition $item): array => [
                'role' => $arabic ? $item->role_ar : $item->role_en,
                'organization' => $item->org_name,
                'started_on' => $item->started_on?->toDateString(),
                'ended_on' => $item->ended_on?->toDateString(),
            ])->values()->all();

        // The verified model scope also checks the linked proof asset's
        // current publication rights; never expose storage keys or raw asset IDs.
        $certifications = Certification::query()->publiclyVerified()
            ->where('profile_id', $profile->id)
            ->orderBy('title')->orderBy('id')->get()
            ->map(static fn (Certification $item): array => [
                'title' => $item->title,
                'issuer' => $item->issuer,
                'issued_on' => $item->issued_on?->toDateString(),
            ])->values()->all();

        $skillsQuery = Skill::query()->withVerifiedEvidence()
            ->whereIn('id', DB::table('profile_skill')
                ->where('profile_id', $profile->id)->select('skill_id'));
        if ($track !== 'all') {
            $skillsQuery->whereIn('category', $categories[$track]);
        }
        $skills = $skillsQuery->orderBy('category')->orderBy('name_en')->orderBy('id')
            ->get()->map(static fn (Skill $item): array => [
                'slug' => $item->slug,
                'name' => $arabic ? $item->name_ar : $item->name_en,
                'category' => $item->category,
            ])->values()->all();

        // Reuse the existing evidence URL policy, including internal-DNS,
        // revoked and signed-link exclusions; do not duplicate a weaker check.
        $claims = $public['claims'];

        return response()->json(['data' => [
            'name' => $profile->public_name,
            'track' => $track,
            'positions' => $positions,
            'certifications' => $certifications,
            'skills' => $skills,
            'claims' => $claims,
            'resume_url' => route('public.resume', ['lang' => $lang, 'track' => $track]),
            'contact_url' => route('public.contact', ['lang' => $lang]),
        ]]);
    }
}
