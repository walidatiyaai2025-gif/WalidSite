<?php

namespace App\Domain\Privacy;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * ADMIN-10: read-only, non-identifying consent and suppression snapshot.
 *
 * All five buckets are disjoint. Only active accounts are considered and the
 * eligible bucket exactly mirrors NotificationConsentGate::allows(). A single
 * grouped SQL statement prevents a revoke occurring between independent count
 * queries from producing contradictory audience totals. No user identifiers,
 * email addresses, device tokens or provider payloads are selected or returned.
 */
final class AudienceConsentPreview
{
    /**
     * @return array{active_accounts:int,consented:int,revoked:int,disabled:int,future:int,missing:int,suppressed:int}
     */
    public function forSegment(string $purpose, string $channel): array
    {
        if (! in_array($purpose, NotificationConsentGate::PURPOSES, true)
            || ! in_array($channel, NotificationConsentGate::CHANNELS, true)) {
            throw new \InvalidArgumentException('Unsupported notification audience segment.');
        }

        $at = now('UTC');
        $candidates = DB::table('users as account')
            ->leftJoin('notification_preferences as preference', static function (JoinClause $join) use ($purpose, $channel): void {
                $join->on('preference.user_id', '=', 'account.id')
                    ->where('preference.purpose', '=', $purpose)
                    ->where('preference.channel', '=', $channel);
            })
            ->where('account.status', 'active')
            ->selectRaw(
                "CASE
                    WHEN preference.revoked_at IS NOT NULL THEN 'revoked'
                    WHEN preference.enabled = 0 THEN 'disabled'
                    WHEN preference.enabled = 1
                         AND preference.consented_at IS NOT NULL
                         AND preference.consented_at <= ? THEN 'consented'
                    WHEN preference.enabled = 1
                         AND preference.consented_at > ? THEN 'future'
                    ELSE 'missing'
                END AS consent_bucket",
                [$at, $at],
            );

        $groups = DB::query()->fromSub($candidates, 'consent_candidates')
            ->select('consent_bucket')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('consent_bucket')
            ->get();

        $result = [
            'active_accounts' => 0,
            'consented' => 0,
            'revoked' => 0,
            'disabled' => 0,
            'future' => 0,
            'missing' => 0,
            'suppressed' => 0,
        ];
        foreach ($groups as $group) {
            $bucket = (string) $group->consent_bucket;
            if (! in_array($bucket, ['consented', 'revoked', 'disabled', 'future', 'missing'], true)) {
                throw new \LogicException('Unrecognized consent state.');
            }
            $count = (int) $group->total;
            $result[$bucket] = $count;
            $result['active_accounts'] += $count;
        }
        $result['suppressed'] = $result['active_accounts'] - $result['consented'];

        return $result;
    }
}
