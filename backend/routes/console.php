<?php

use App\AI\EditorialPublicationAudit;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * T022: one bounded page per scheduled invocation. No resident daemon, AI
 * inference, or automatic draft approval. Host cron must separately execute
 * Laravel's schedule:run; installing this code does not activate hosting.
 */
Artisan::command('editorial:audit-publications {--limit=20}', function () {
    $option = (string) $this->option('limit');
    if (! ctype_digit($option) || (int) $option < 1 || (int) $option > 50) {
        $this->error('Limit must be an integer between 1 and 50.');
        return 1;
    }

    $result = app(EditorialPublicationAudit::class)->run((int) $option);
    if ($result['locked']) {
        $this->line('Publication audit already running; skipped.');
        return 0;
    }

    $this->line(sprintf(
        'Publication audit: checked=%d revoked=%d more=%s',
        $result['checked'],
        $result['revoked'],
        $result['has_more'] ? 'yes' : 'no'
    ));
    return 0;
})->purpose('Recheck up to 20 published articles and revoke invalid public evidence');

Schedule::command('editorial:audit-publications --limit=20')
    ->everyTenMinutes()
    ->withoutOverlapping(9);


/*
 * Opt-in unattended database migrations after a separately deployed,
 * reviewed release. Needs a real host scheduler; not a network patcher.
 */
Artisan::command('site:updates:apply', function () {
    if (! config('site.auto_migrate')) {
        $this->line('Automatic updates disabled; nothing changed.');
        return 0;
    }

    try {
        $result = app(\App\Support\Deployment\SiteUpdater::class)->apply();
        $this->line('Trusted migrations: '.$result['state'].' ('.$result['applied'].').');
        return in_array($result['state'], ['applied', 'current', 'busy'], true) ? 0 : 1;
    } catch (\Throwable $exception) {
        \Illuminate\Support\Facades\Log::error('site.updates.command_failed', [
            'type' => $exception::class,
        ]);
        $this->error('Trusted migration update failed. Check private application log.');
        return 1;
    }
})->purpose('Apply trusted checked-in migrations after a reviewed deployment (opt-in)');

Schedule::command('site:updates:apply')
    ->everyFiveMinutes()
    ->when(fn () => config('site.auto_migrate'))
    ->withoutOverlapping(10);
