<?php

namespace App\Domain\LegacyImport;

use InvalidArgumentException;
use JsonException;

/**
 * Offline, non-executing intake for owner-authorized legacy content.
 * Never evaluates SQL, HTML, paths, URLs, credentials, or remote resources.
 */
final class LegacyContentIntake
{
    private const TABLES = [
        'articles' => ['slug', 'title_en', 'title_ar', 'summary_en', 'summary_ar'],
        'projects' => ['slug', 'title', 'summary_en', 'summary_ar', 'role_en', 'role_ar'],
        'runbooks' => ['slug', 'title_en', 'title_ar', 'summary_en', 'summary_ar'],
    ];

    public function inspect(string $payload): array
    {
        if (strlen($payload) > 1048576 || ! mb_check_encoding($payload, 'UTF-8')) {
            throw new InvalidArgumentException('Input exceeds 1 MiB or is not UTF-8.');
        }

        try {
            $document = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Only JSON v1 content exports are accepted.', 0, $exception);
        }

        if (! is_array($document) || array_keys($document) !== ['version', 'tables']
            || $document['version'] !== 1 || ! is_array($document['tables']) || array_is_list($document['tables'])) {
            throw new InvalidArgumentException('Unsupported content export schema.');
        }

        $accepted = [];
        $rejected = [];
        foreach ($document['tables'] as $table => $rows) {
            if (! is_string($table) || ! isset(self::TABLES[$table]) || ! is_array($rows) || ! array_is_list($rows)) {
                $rejected[] = ['table' => (string) $table, 'reason' => 'table_not_allowlisted'];
                continue;
            }

            foreach ($rows as $index => $row) {
                $reason = $this->validateRow($table, $row);
                if ($reason !== null) {
                    $rejected[] = ['table' => $table, 'row' => $index, 'reason' => $reason];
                    continue;
                }

                $canonical = [];
                foreach (self::TABLES[$table] as $column) {
                    $canonical[$column] = trim($row[$column] ?? '');
                }
                $accepted[] = [
                    'table' => $table,
                    'sha256' => hash('sha256', json_encode([$table, $canonical], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'data' => $canonical,
                    'status' => 'draft',
                ];
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected,
            'source_sha256' => hash('sha256', $payload), 'dry_run' => true];
    }

    private function validateRow(string $table, mixed $row): ?string
    {
        if (! is_array($row) || array_is_list($row)
            || array_diff(array_keys($row), self::TABLES[$table])
            || ! isset($row['slug']) || ! is_string($row['slug'])
            || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $row['slug'])) {
            return 'invalid_columns_or_slug';
        }

        foreach ($row as $value) {
            if (! is_string($value) || strlen($value) > 4000
                || preg_match('/[<>\\x00-\\x1f]/u', $value)
                || preg_match('~(?:https?://|\\.\\./|\\\\|(?:password|token|secret|api[_-]?key)\\s*[:=])~iu', $value)) {
                return 'unsafe_content';
            }
        }

        return null;
    }
}
