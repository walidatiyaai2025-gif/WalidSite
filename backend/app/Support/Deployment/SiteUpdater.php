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
        // Installed sites have no independently proven DB/file rollback
        // receipt here. Even owner or cron callers cannot run pending schema.
        return $this->applyWithPolicy(false);
    }

    /**
     * Only possession-authorized first-install controllers should call this
     * entrypoint. Recheck installation state while holding the migration
     * lock, immediately before running any SQL.
     *
     * @return array{state: string, applied: int}
     */
    public function applyForFirstInstall(): array
    {
        return $this->applyWithPolicy(true);
    }

    /** @return array{state: string, applied: int} */
    private function applyWithPolicy(bool $initialInstall): array
    {
        $lock = Cache::lock('walidsite:trusted-migrations', 600);
        if (! $lock->get()) {
            return ['state' => 'busy', 'applied' => 0];
        }

        try {
            // A caller flag alone cannot reopen setup; installed marker,
            // existing credentials and revoked/suspended users must lock it.
            if ($initialInstall && ! app(SiteInstaller::class)->allowed()) {
                return ['state' => 'setup_closed', 'applied' => 0];
            }

            $before = $this->inspect();
            if (! $before['ready']) {
                return ['state' => $before['history_mismatch'] ? 'schema_mismatch' : 'database_unavailable',
                    'applied' => 0];
            }
            // The wizard checked the database at connection time, but its
            // contents may change before Step 11. Reinspect under the
            // migration lock so an existing/foreign database cannot be
            // overwritten by a stale "empty database" probe.
            if ($initialInstall && ! $this->safeFirstInstallSchema()) {
                return ['state' => 'database_not_empty', 'applied' => 0];
            }
            if ($before['pending'] === []) {
                return ['state' => 'current', 'applied' => 0];
            }
            // A warning is not verified DB+file recovery. Until there is
            // an independent restore and proven write/queue isolation,
            // installed-site migrations MUST NOT modify the database.
            if (! $initialInstall) {
                return ['state' => 'recovery_required', 'applied' => 0];
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

    /**
     * Newly selected MySQL/MariaDB databases may contain no tables (or only
     * Laravel's still-empty migration repository). If a trusted migration
     * history has already started, preserve the interrupted first run and
     * let inspect() enforce that every recorded migration belongs to the
     * checked-in release. Foreign tables without a migration history are a
     * hard stop: never assume they are safe to replace.
     */
    private function safeFirstInstallSchema(): bool
    {
        try {
            if (Schema::hasTable('migrations')
                && DB::table('migrations')->exists()) {
                return true;
            }
            $tables = Schema::getTableListing();
            $views = Schema::getViews();

            // Do not use a broad count of only Laravel models: any custom
            // table or view belongs to an existing database until proven
            // otherwise. Even a partial unrecorded migration needs manual
            // recovery, not speculative SQL on potentially valuable data.
            return $views === [] && ($tables === []
                || (count($tables) === 1 && $tables[0] === 'migrations'));
        } catch (Throwable) {
            // Schema-inventory failures cannot authorize destructive SQL.
            return false;
        }
    }
}
