<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class SiteUpdater
{
    /**
     * Compare checked-in local Laravel migration files with the actual database
     * migration repository. An unreachable database or a migration record from
     * an unknown (possibly newer) release must never be reported as current.
     *
     * @return array{ready: bool, pending: list<string>, history_mismatch: bool}
     */
    public function inspect(): array
    {
        try {
            DB::connection()->getPdo();
            $files = glob(database_path('migrations/*.php')) ?: [];
            $known = array_map(static fn (string $file): string => basename($file, '.php'), $files);
            $ran = Schema::hasTable('migrations')
                ? DB::table('migrations')->pluck('migration')->all()
                : [];

            // A DB previously migrated by newer/different source is NOT
            // compatible with this release merely because nothing is pending.
            // Fail closed without exposing migration identifiers in logs/UI.
            $unexpected = array_values(array_diff($ran, $known));
            if ($unexpected !== []) {
                Log::warning('site.updates.unknown_applied_migrations', [
                    'count' => count($unexpected),
                ]);

                return ['ready' => false, 'pending' => [], 'history_mismatch' => true];
            }

            $pending = array_values(array_diff($known, $ran));
            sort($pending, SORT_STRING);

            return ['ready' => true, 'pending' => $pending, 'history_mismatch' => false];
        } catch (Throwable $exception) {
            // Do not display database error messages or credentials in the UI.
            Log::warning('site.updates.database_unavailable', ['type' => $exception::class]);

            return ['ready' => false, 'pending' => [], 'history_mismatch' => false];
        }
    }

    /**
     * Single-flight, additive migration runner; never accepts uploaded PHP or
     * caller-supplied Artisan options. Safe to retry after an interruption.
     *
     * @return array{state: string, applied: int}
     */
    public function apply(): array
    {
        $lock = Cache::lock('walidsite:trusted-migrations', 600);
        if (! $lock->get()) {
            return ['state' => 'busy', 'applied' => 0];
        }

        try {
            $before = $this->inspect();
            if (! $before['ready']) {
                return ['state' => $before['history_mismatch'] ? 'schema_mismatch' : 'database_unavailable',
                    'applied' => 0];
            }
            if ($before['pending'] === []) {
                return ['state' => 'current', 'applied' => 0];
            }

            $exit = Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exit !== 0) {
                throw new RuntimeException('Migration command returned a nonzero status.');
            }

            $after = $this->inspect();
            if (! $after['ready'] || $after['pending'] !== []) {
                throw new RuntimeException('Migration state could not be verified after applying.');
            }

            Log::notice('site.updates.applied', [
                'migration_count' => count($before['pending']),
                'release_ref' => config('site.release_ref'),
            ]);

            return ['state' => 'applied', 'applied' => count($before['pending'])];
        } finally {
            $lock->release();
        }
    }
}
