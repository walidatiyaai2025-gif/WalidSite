<?php

namespace App\Domain\Privacy;

use App\Models\NotificationPreference;
use App\Models\User;

final class NotificationConsentGate
{
    public const PURPOSES = [
        'account_activity',
        'editorial_updates',
        'product_updates',
    ];

    public const CHANNELS = [
        'email',
        'push',
    ];

    public function allows(User $user, string $purpose, string $channel): bool
    {
        if ($user->status !== 'active'
            || ! in_array($purpose, self::PURPOSES, true)
            || ! in_array($channel, self::CHANNELS, true)) {
            return false;
        }

        // Consult persisted account state instead of a potentially stale User
        // object. Suspension/deletion must revoke eligibility immediately.
        // A non-null *future* timestamp is not proof of consent yet.
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->whereHas('user', static fn ($query) => $query->where('status', 'active'))
            ->where('enabled', true)
            ->whereNotNull('consented_at')
            ->where('consented_at', '<=', now('UTC'))
            ->whereNull('revoked_at')
            ->exists();
    }
}
