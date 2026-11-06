<?php

namespace App\Domain\Deployment;

use PDO;

/**
 * Server-provisioned capability, never instantiated from request/config input.
 *
 * Implementations must independently establish and continuously verify that
 * ALL non-Laravel/direct-SQL/foreign-cron writers are stopped. Laravel HTTP,
 * queue and console writers are covered separately by the shared/exclusive
 * CpanelConsoleWriterLease. The disposable PDO MUST connect to a distinct,
 * initially empty recovery-only MariaDB database with no production grants.
 *
 * No default implementation is provided: an unsupported shared host MUST
 * fail closed and must not claim it can stop arbitrary external writers.
 */
interface CpanelOwnerBackupHost
{
    public function externalWritersStopped(): bool;

    public function disposableRestoreConnection(): PDO;
}
