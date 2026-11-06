<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use Throwable;

/**
 * Final trust boundary for the *actual* production and disposable PDO handles.
 *
 * The Admin controller is not the only possible caller of an install service:
 * no caller may bypass this preflight by supplying a second PDO handle that
 * points at production, a populated database, or an unsupported driver.
 * This check is intentionally read-only and must run before creating a
 * maintenance lease, writing backups, or executing disposable restore DDL.
 */
final class CpanelDisposableMariaDbGuard
{
    /**
     * @return array{source_schema:string,target_schema:string}
     */
    public function assertIndependentAndEmpty(PDO $source, PDO $target): array
    {
        if ($source === $target) {
            throw new DomainException('Production PDO cannot be used for recovery.');
        }
        try {
            if ($source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                throw new DomainException('MariaDB connections are required for recovery.');
            }

            $sourceIdentity = $this->identity($source);
            $targetIdentity = $this->identity($target);
            if ($sourceIdentity === $targetIdentity) {
                throw new DomainException('Production database cannot be its own restore target.');
            }

            // SHOW FULL TABLES sees actual tables and views in the current
            // target schema. Only an empty, operator-provisioned schema can
            // safely receive the irreversible CREATE TABLE/INSERT rehearsal.
            $tables = $target->query('SHOW FULL TABLES')->fetch(PDO::FETCH_NUM);
            if ($tables !== false) {
                throw new DomainException('Disposable recovery target is not empty.');
            }
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable) {
            // Never leak live SQL, credentials, DSNs, or host topology.
            throw new DomainException('Database isolation could not be independently verified.');
        }

        return [
            'source_schema' => $sourceIdentity[3],
            'target_schema' => $targetIdentity[3],
        ];
    }

    /** @return array{string,string,string,string} */
    private function identity(PDO $db): array
    {
        $row = $db->query(
            'SELECT @@hostname AS host, @@port AS port, @@server_id AS server_id, DATABASE() AS db'
        )->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)
            || ! is_string($row['host'] ?? null) || $row['host'] === ''
            || ! is_numeric($row['port'] ?? null)
            || ! is_numeric($row['server_id'] ?? null)
            || ! is_string($row['db'] ?? null) || $row['db'] === '') {
            throw new DomainException('Database server and schema identity unavailable.');
        }

        return [
            $row['host'], (string) $row['port'],
            (string) $row['server_id'], $row['db'],
        ];
    }
}
