<?php

namespace App\Support\Deployment;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\OwnerCvBaselineSeeder;
use Database\Seeders\WebsitePageContentSeeder;
use Database\Seeders\OwnerProjectsPortfolioSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class SiteInstaller
{
    public function isInstalled(): bool
    {
        if (is_file((string) config('site.install_lock_path'))) {
            return true;
        }

        try {
            // Refuse to re-bootstrap a database with an existing administrator
            // even when a lock file was accidentally removed.
            return Schema::hasTable('users') && Schema::hasTable('role_user')
                && User::query()->where('status', 'active')
                    ->whereHas('roles', static fn ($q) => $q->whereIn('name', ['owner', 'admin']))
                    ->exists();
        } catch (Throwable) {
            // An unavailable database is shown as an error by the setup wizard.
            return false;
        }
    }

    public function allowed(): bool
    {
        return config('site.setup_enabled') === true
            && strlen((string) config('site.setup_token')) >= 32
            && ! $this->isInstalled();
    }

    public function ownerEmail(): string
    {
        $source = base_path('../contracts/owner-cv-baseline.json');
        if (! is_readable($source)) {
            throw new RuntimeException('Canonical owner CV data is missing.');
        }
        $cv = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        $email = $cv['identity']['contact']['email'] ?? null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Canonical owner email cannot be verified.');
        }

        return $email;
    }

    /**
     * The first login belongs to the real owner from the approved CV, never
     * a randomly created public signup or a fictional identity.
     */
    public function complete(string $password, SiteUpdater $updater): bool
    {
        $lock = Cache::lock('walidsite:first-install', 180);
        if (! $lock->get()) {
            return false;
        }

        try {
            if (! $this->allowed()) {
                throw new RuntimeException('Setup has already been disabled or completed.');
            }
            $status = $updater->inspect();
            if (! $status['ready'] || $status['pending'] !== []) {
                throw new RuntimeException('Database migrations must be completed first.');
            }
            $email = $this->ownerEmail();
            $path = (string) config('site.install_lock_path');
            if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
                throw new RuntimeException('Installation state directory is not writable.');
            }

            DB::transaction(function () use ($email, $password): void {
                // Fail closed for previously initialized databases.
                if (User::query()->whereNotNull('password_hash')->exists()) {
                    throw new RuntimeException('An account is already configured.');
                }
                $result = Artisan::call('db:seed', [
                    '--class' => OwnerCvBaselineSeeder::class,
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
                if ($result !== 0) {
                    throw new RuntimeException('Owner CV initialization failed.');
                }
                // The approved home text and draft-only proposed page copy
                // must exist after the very first browser install. Run the
                // insert-only seed in the same install transaction; owner
                // edits are never clobbered by a subsequent seeder invocation.
                $pageSeed = Artisan::call('db:seed', [
                    '--class' => WebsitePageContentSeeder::class,
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
                if ($pageSeed !== 0) {
                    throw new RuntimeException('Managed page-copy initialization failed.');
                }
                $owner = User::query()->where('email', $email)->firstOrFail();
                $role = Role::firstOrCreate(['name' => 'owner']);
                $owner->forceFill([
                    'password_hash' => Hash::make($password),
                    'status' => 'active',
                ])->save();
                $owner->roles()->syncWithoutDetaching([$role->id]);

                // The baseline import intentionally starts private. Only after
                // the canonical owner is active and owns this site may the
                // source-classified public CV subset become visible.
                app(\App\Domain\Profile\OwnerCvPublisher::class)
                    ->publishAfterVerifiedActivation($owner->fresh());
                // Import the owner's real portfolio records as part of the
                // initial web setup. CV-only employer work stays private;
                // the seven publicly reviewed repository summaries have
                // public README evidence, never private infrastructure data.
                $projectsSeed = Artisan::call('db:seed', [
                    '--class' => OwnerProjectsPortfolioSeeder::class,
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
                if ($projectsSeed !== 0) {
                    throw new RuntimeException('Owner project portfolio initialization failed.');
                }
            });

            // Exclusive filesystem creation is a second permanent installation
            // guard. The active privileged owner in DB also prevents reinstall
            // if this write fails.
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException('Could not lock the completed installation.');
            }
            fwrite($handle, now()->toIso8601String().PHP_EOL);
            fclose($handle);
            if (config('site.setup_database_ready')) {
                // Revoke the temporary setup token after owner activation.
                app(DatabaseConfiguration::class)->disableSetup();
            }

            return true;
        } finally {
            $lock->release();
        }
    }
}
