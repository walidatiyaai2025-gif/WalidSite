<?php

namespace App\Domain\Profile;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class OwnerCvPublisher
{
    private const PUBLIC_EXPERIENCE = [
        'public_high_level',
        'role_and_non_sensitive_summary_only',
    ];

    /**
     * Promote only canonical owner-supplied CV rows after the real owner
     * account has been activated. The import seed itself intentionally stays
     * fail-closed; this transition is the post-activation publication gate.
     *
     * @return array{career_published:int,skills_published:int,career_skipped:int,skills_skipped:int}
     */
    public function publishAfterVerifiedActivation(User $owner): array
    {
        $cv = $this->canonicalCv();
        $canonicalEmail = Str::lower((string) ($cv['identity']['contact']['email'] ?? ''));

        if ($owner->status !== 'active'
            || Str::lower((string) $owner->email) !== $canonicalEmail
            || ! $owner->hasRole('owner')) {
            throw new RuntimeException('Canonical owner activation is required before CV publication.');
        }

        return DB::transaction(function () use ($owner, $cv): array {
            $profile = Profile::query()
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->firstOrFail();

            $canonicalName = (string) ($cv['identity']['full_name'] ?? '');
            $canonicalSummary = (string) ($cv['professional_summary']['text'] ?? '');
            if ($canonicalName === ''
                || $canonicalSummary === ''
                || ! hash_equals($canonicalName, (string) $profile->public_name)
                || ! hash_equals($canonicalSummary, (string) $profile->bio_en)
                || ! in_array($profile->publish_status, ['review', 'published'], true)) {
                throw new RuntimeException('Owner profile no longer matches the canonical CV source.');
            }

            $careerPublished = 0;
            $careerSkipped = 0;
            foreach (($cv['experience'] ?? []) as $experience) {
                if (! is_array($experience)
                    || ! in_array($experience['publication'] ?? null, self::PUBLIC_EXPERIENCE, true)) {
                    continue;
                }

                $startYear = filter_var($experience['period']['start'] ?? null, FILTER_VALIDATE_INT);
                $endRaw = $experience['period']['end'] ?? null;
                $endYear = $endRaw === null
                    ? null : filter_var($endRaw, FILTER_VALIDATE_INT);
                $roles = $experience['roles'] ?? null;
                $refs = $experience['source_refs'] ?? null;
                if ($startYear === false || $startYear < 1900 || $startYear > 2200
                    || ($endRaw !== null && ($endYear === false || $endYear < $startYear || $endYear > 2200))
                    || ! is_array($roles) || $roles === []
                    || ! is_array($refs) || $refs === []) {
                    throw new RuntimeException('Canonical CV experience record is malformed.');
                }

                $start = sprintf('%04d-01-01', $startYear);
                $end = $endYear === null ? null : sprintf('%04d-12-31', $endYear);
                $role = implode(' / ', array_values(array_unique(array_map(
                    static fn ($value): string => (string) $value,
                    $roles
                ))));

                $row = DB::table('career_positions')
                    ->where('profile_id', $profile->id)
                    ->where('org_name', (string) ($experience['organization'] ?? ''))
                    ->whereDate('started_on', $start)
                    ->lockForUpdate()
                    ->first();

                if ($row === null
                    || ! hash_equals($role, (string) $row->role_en)
                    || ! hash_equals($role, (string) $row->role_ar)
                    || $this->dateString($row->ended_on) !== $end
                    || (string) $row->date_precision !== 'year'
                    || ! $this->sameStringList($row->source_refs_json, $refs)) {
                    $careerSkipped++;

                    continue;
                }

                DB::table('career_positions')->where('id', $row->id)->update([
                    'publishable' => true,
                    'verified' => true,
                    'updated_at' => now(),
                ]);
                $careerPublished++;
            }

            $skillsPublished = 0;
            $skillsSkipped = 0;
            if (($cv['publication_rules']['default'] ?? null) !== 'public_high_level') {
                throw new RuntimeException('Canonical competency publication policy is not public.');
            }

            foreach (($cv['competencies'] ?? []) as $group) {
                if (! is_array($group) || ! is_string($group['group'] ?? null)
                    || ! is_array($group['items'] ?? null)) {
                    throw new RuntimeException('Canonical CV competency record is malformed.');
                }

                foreach ($group['items'] as $name) {
                    if (! is_string($name) || trim($name) === '') {
                        throw new RuntimeException('Canonical CV competency name is malformed.');
                    }

                    $slug = Str::slug($name);
                    $skill = DB::table('skills')
                        ->where('slug', $slug)
                        ->lockForUpdate()
                        ->first();

                    $linked = $skill !== null && DB::table('profile_skill')
                        ->where('profile_id', $profile->id)
                        ->where('skill_id', $skill->id)
                        ->exists();

                    if (! $linked
                        || ! hash_equals($name, (string) $skill->name_en)
                        || ! hash_equals($name, (string) $skill->name_ar)
                        || ! hash_equals((string) $group['group'], (string) $skill->category)
                        || ! in_array((string) $skill->evidence_status, ['owner_cv', 'verified'], true)) {
                        $skillsSkipped++;

                        continue;
                    }

                    DB::table('skills')->where('id', $skill->id)->update([
                        'evidence_status' => 'verified',
                        'updated_at' => now(),
                    ]);
                    $skillsPublished++;
                }
            }

            $profile->forceFill(['publish_status' => 'published'])->save();

            return [
                'career_published' => $careerPublished,
                'skills_published' => $skillsPublished,
                'career_skipped' => $careerSkipped,
                'skills_skipped' => $skillsSkipped,
            ];
        }, 3);
    }

    private function canonicalCv(): array
    {
        $path = base_path('../contracts/owner-cv-baseline.json');
        if (! is_readable($path)) {
            throw new RuntimeException('Canonical owner CV data is missing.');
        }

        $cv = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($cv['schema'] ?? null) !== 'walidsite.owner-cv-baseline.v1') {
            throw new RuntimeException('Unexpected owner CV baseline schema.');
        }

        return $cv;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function sameStringList(mixed $json, array $expected): bool
    {
        if (! is_string($json)) {
            return false;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return false;
        }

        return array_values($decoded) === array_values($expected);
    }
}
