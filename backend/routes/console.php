<?php

use App\AI\EditorialPublicationAudit;
use App\AI\EditorialScheduleRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * T011: exactly one checkpoint per invocation, never a resident daemon.
 * No job is claimed until a trusted T014 handler has been registered on the
 * server. No raw prompts, provider credentials or errors printed to console.
 */
Artisan::command('ai:tick', function () {
    $handlers = app(\App\AI\AiStepHandlerRegistry::class)->handlers();
    $result = app(\App\AI\BoundedAiWorker::class)->tick(
        'cpanel:'.\Illuminate\Support\Str::random(24), $handlers
    );
    $this->line('AI tick: '.$result['state']);
    return in_array($result['state'], ['retry', 'lease_lost'], true) ? 1 : 0;
})->purpose('Execute one bounded registered AI checkpoint and exit');

Schedule::command('ai:tick')
    ->everyMinute()
    ->when(fn () => app(\App\AI\AiStepHandlerRegistry::class)->hasHandlers())
    ->withoutOverlapping(4);

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
 * T022: publish only persisted, explicitly approved low-risk schedules and
 * refresh their publication deadline; opt-in policy defaults disabled.
 * Both commands use one host schedule:run cron; no long-lived process.
 */
Artisan::command('editorial:publish-due {--limit=20}', function () {
    $option = (string) $this->option('limit');
    if (! ctype_digit($option) || (int) $option < 1 || (int) $option > 20) {
        $this->error('Limit must be an integer between 1 and 20.');
        return 1;
    }
    $result = app(EditorialScheduleRunner::class)->run((int) $option);
    $this->line(sprintf(
        'Editorial due: state=%s checked=%d published=%d review=%d refreshed=%d',
        $result['state'], $result['checked'], $result['published'],
        $result['review'], $result['refreshed']
    ));
    return 0;
})->purpose('Process a bounded page of admin-approved editorial schedules');

Schedule::command('editorial:publish-due --limit=20')
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
