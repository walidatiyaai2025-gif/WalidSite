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
