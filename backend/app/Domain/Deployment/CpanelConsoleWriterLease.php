<?php

namespace App\Domain\Deployment;

use DomainException;

/**
 * Cooperative, cross-process CLI writer drain for installed cPanel sites.
 *
 * Every Laravel console invocation holds a shared private file lease for its
 * entire execution (including queue:work and schedule:run). A later, trusted
 * owner update MUST obtain this same lock exclusively before marking HTTP
 * maintenance and proving independently restorable backups.
 *
 * This does NOT itself stop in-flight HTTP requests, foreign cron processes,
 * direct SQL writers or PHP outside Laravel. Those remain separate mandatory
 * gates before Backup Now / Install Update can be enabled.
 */
final class CpanelConsoleWriterLease
{
    /** @var resource|null */
    private $stream = null;

    private int $depth = 0;

    /** @var array{path:string,dev:int,ino:int,uid:int,mode:int}|null */
    private ?array $home = null;

    private int $mode = 0;

    /**
     * Called for every console CommandStarting event. Nested commands share
     * the existing held lease rather than shortening its lifetime.
     */
    public function beginForConsole(string $applicationBase): void
    {
        if ($this->depth > 0) {
            ++$this->depth;
            return;
        }

        $home = $this->findInstalledHome($applicationBase);
        if ($home !== null) {
            $this->acquire($home, LOCK_SH);
        }
        $this->depth = 1;
    }

    /**
     * Reserved for a future *authorized* update orchestrator. Must be held
     * through HTTP maintenance, writer verification, backup, cutover and
     * health/recovery; creating this lease alone is NEVER a backup proof.
     */
    public function beginExclusiveForUpdate(string $homeDirectory): void
    {
        if ($this->depth !== 0) {
            throw new DomainException('A writer isolation lease is already held.');
        }
        $home = $this->privateHome($homeDirectory);
        if (! is_dir($homeDirectory.'/application') || is_link($homeDirectory.'/application')
            || ! is_dir($homeDirectory.'/public_html') || is_link($homeDirectory.'/public_html')) {
            throw new DomainException('Installed cPanel layout is required for writer drain.');
        }
        $this->acquire($home['path'], LOCK_EX);
        $this->depth = 1;
    }

    /**
     * Check the actual lease descriptor and its pathname. This check does not
     * treat a boolean callback, environment variable or session claim as
     * evidence that writers have stopped.
     */
    public function assertExclusiveHeld(): void
    {
        if ($this->depth === 0 || $this->mode !== LOCK_EX
            || ! is_resource($this->stream) || $this->home === null) {
            throw new DomainException('Exclusive console writer drain is not held.');
        }
        $this->assertPrivateLock($this->stream, $this->home);
    }

    public function end(): void
    {
        if ($this->depth === 0 || --$this->depth !== 0) {
            return;
        }
        if (is_resource($this->stream)) {
            @flock($this->stream, LOCK_UN);
            fclose($this->stream);
        }
        $this->stream = null;
        $this->home = null;
        $this->mode = 0;
    }

    public function __destruct()
    {
        while ($this->depth > 0) {
            $this->end();
        }
    }

    private function findInstalledHome(string $applicationBase): ?string
    {
        $base = realpath($applicationBase);
        if ($base === false || $base !== $applicationBase || is_link($applicationBase)) {
            throw new DomainException('Console application path cannot be verified.');
        }

        if (preg_match(
            '#\A(.+)/\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}/application\z#D',
            $base, $match,
        )) {
            $home = $match[1];
        } elseif (basename($base) === 'application') {
            $home = dirname($base);
        } else {
            // Ordinary local Laravel checkouts are not cPanel installations;
            // no production lock is created in a developer's worktree.
            return null;
        }

        if (! is_dir($home.'/application') && ! is_dir($home.'/public_html')) {
            return null;
        }
        $this->privateHome($home);
        if (realpath($home.'/application') !== $home.'/application'
            || ! is_dir($home.'/public_html') || is_link($home.'/public_html')
            || is_link($home.'/application')) {
            throw new DomainException('Installed cPanel roots changed during writer isolation.');
        }

        return $home;
    }

    /** @return array{path:string,dev:int,ino:int,uid:int,mode:int} */
    private function privateHome(string $homeDirectory): array
    {
        clearstatcache(true, $homeDirectory);
        $details = @lstat($homeDirectory);
        if (! is_array($details) || is_link($homeDirectory)
            || realpath($homeDirectory) !== $homeDirectory
            || ! is_dir($homeDirectory) || ! is_writable($homeDirectory)
            || ($details['mode'] & 0170000) !== 0040000
            || ($details['mode'] & 0022) !== 0) {
            throw new DomainException('cPanel writer lock requires an unchanged private account home.');
        }
        return [
            'path' => $homeDirectory,
            'dev' => (int) $details['dev'],
            'ino' => (int) $details['ino'],
            'uid' => (int) $details['uid'],
            'mode' => (int) $details['mode'],
        ];
    }

    /** @param array{path:string,dev:int,ino:int,uid:int,mode:int} $baseline */
    private function assertHome(array $baseline): void
    {
        if ($this->privateHome($baseline['path']) !== $baseline) {
            throw new DomainException('cPanel writer drain account home changed.');
        }
    }

    private function markerAbsent(string $home): void
    {
        $marker = $home.'/.walidsite-update-maintenance.json';
        clearstatcache(true, $marker);
        // An expired, broken or maliciously linked marker MUST NOT allow a
        // background writer to resume while recovery is still unresolved.
        if (@lstat($marker) !== false) {
            throw new DomainException('cPanel release maintenance blocks background writers.');
        }
    }

    private function acquire(string $homeDirectory, int $lockMode): void
    {
        $home = $this->privateHome($homeDirectory);
        $this->markerAbsent($homeDirectory);
        $path = $homeDirectory.'/.walidsite-writer-drain.lock';
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new DomainException('Private background writer lock is unsafe.');
        }

        $created = false;
        if (! file_exists($path)) {
            $stream = @fopen($path, 'x+b');
            $created = is_resource($stream);
        } else {
            $stream = false;
        }
        if (! is_resource($stream)) {
            $stream = @fopen($path, 'c+b');
        }
        if (! is_resource($stream)) {
            throw new DomainException('Unable to open private writer isolation lock.');
        }

        try {
            if ($created && ! @chmod($path, 0600)) {
                throw new DomainException('Cannot secure new background writer lock.');
            }
            $this->assertPrivateLock($stream, $home);
            if (! @flock($stream, $lockMode | LOCK_NB)) {
                throw new DomainException('Writer drain is busy: another process is active.');
            }
            $this->assertPrivateLock($stream, $home);
            $this->markerAbsent($homeDirectory);
            $this->stream = $stream;
            $this->home = $home;
            $this->mode = $lockMode;
        } catch (\Throwable $exception) {
            @flock($stream, LOCK_UN);
            fclose($stream);
            throw $exception;
        }
    }

    /**
     * @param resource $stream
     * @param array{path:string,dev:int,ino:int,uid:int,mode:int} $home
     */
    private function assertPrivateLock($stream, array $home): void
    {
        $this->assertHome($home);
        $path = $home['path'].'/.walidsite-writer-drain.lock';
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named) || is_link($path)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0777) !== 0600
            || ($named['mode'] & 0777) !== 0600
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || $opened['uid'] !== $home['uid']
            || $named['uid'] !== $home['uid']
            || $opened['dev'] !== $named['dev']
            || $opened['ino'] !== $named['ino']) {
            throw new DomainException('Private writer drain lock identity or mode is unsafe.');
        }
        $this->assertHome($home);
    }
}
