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

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->first();

        return $preference !== null
            && $preference->enabled === true
            && $preference->consented_at !== null
            && $preference->revoked_at === null;
    }
}
