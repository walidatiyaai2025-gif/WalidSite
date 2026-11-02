<?php

namespace App\Domain\LegacyImport;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class LegacyContentImporter
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BYTES = 2_000_000;
    public const MAX_ROWS = 500;

    /** @var array<string, array{target:string,required:list<string>,allowed:list<string>}> */
    private const TABLES = [
        'articles' => [
            'target' => 'article',
            'required' => ['slug', 'title_en', 'title_ar', 'canonical_lang', 'body_md'],
            'allowed' => ['legacy_key', 'slug', 'title_en', 'title_ar', 'canonical_lang', 'body_md', 'citations_json'],
        ],
        'legacy_articles' => [
            'target' => 'article',
            'required' => ['slug', 'title_en', 'title_ar', 'canonical_lang', 'body_md'],
            'allowed' => ['legacy_key', 'slug', 'title_en', 'title_ar', 'canonical_lang', 'body_md', 'citations_json'],
        ],
        'projects' => [
            'target' => 'project',
            'required' => ['slug', 'title', 'summary_en', 'summary_ar', 'status'],
            'allowed' => ['legacy_key', 'slug', 'title', 'summary_en', 'summary_ar', 'role_en', 'role_ar', 'status'],
        ],
        'legacy_projects' => [
            'target' => 'project',
            'required' => ['slug', 'title', 'summary_en', 'summary_ar', 'status'],
            'allowed' => ['legacy_key', 'slug', 'title', 'summary_en', 'summary_ar', 'role_en', 'role_ar', 'status'],
        ],
        'project_evidence' => [
            'target' => 'project_evidence',
            'required' => ['project_slug', 'evidence_type', 'url'],
            'allowed' => ['legacy_key', 'project_slug', 'evidence_type', 'url'],
        ],
        'legacy_project_evidence' => [
            'target' => 'project_evidence',
            'required' => ['project_slug', 'evidence_type', 'url'],
            'allowed' => ['legacy_key', 'project_slug', 'evidence_type', 'url'],
        ],
        'runbooks' => [
            'target' => 'runbook',
            'required' => ['slug', 'title', 'body_md', 'version'],
            'allowed' => ['legacy_key', 'slug', 'title', 'body_md', 'version'],
        ],
        'legacy_runbooks' => [
            'target' => 'runbook',
            'required' => ['slug', 'title', 'body_md', 'version'],
            'allowed' => ['legacy_key', 'slug', 'title', 'body_md', 'version'],
        ],
    ];

    /** @return array<string, mixed> */
    public function dryRunFile(string $path, string $allowedRoot): array
    {
        [$sql, $sourceName] = $this->readAuthorizedFile($path, $allowedRoot);

        return $this->dryRunSql($sql, $sourceName);
    }

    /** @return array<string, mixed> */
    public function importFile(string $path, string $allowedRoot): array
    {
        [$sql, $sourceName] = $this->readAuthorizedFile($path, $allowedRoot);

        return $this->importSql($sql, $sourceName);
    }

    /** @return array<string, mixed> */
    public function dryRunSql(string $sql, string $sourceName = 'legacy.sql'): array
    {
        $plan = $this->buildPlan($sql, $sourceName);

        return $this->publicReport($plan, true, [
            'article' => 0,
            'project' => 0,
            'project_evidence' => 0,
            'runbook' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    public function importSql(string $sql, string $sourceName = 'legacy.sql'): array
    {
        $plan = $this->buildPlan($sql, $sourceName);
        if ($plan['rejections'] !== []) {
            throw new DomainException(
                'Legacy import rejected '.$plan['rejected_count'].' record(s); run dry-run and correct the source.'
            );
        }

        $created = [
            'article' => 0,
            'project' => 0,
            'project_evidence' => 0,
            'runbook' => 0,
        ];

        DB::transaction(function () use ($plan, &$created): void {
            /** @var array<string, string> $projectIds */
            $projectIds = [];

            foreach ($plan['pending'] as $record) {
                if ($record['target'] !== 'project') {
                    continue;
                }
                $targetId = $this->importProject($record);
                $projectIds[$record['data']['slug']] = $targetId;
                $this->writeReceipt($plan, $record, $targetId);
                $created['project']++;
            }

            foreach ($plan['pending'] as $record) {
                if ($record['target'] === 'project') {
                    continue;
                }

                $targetId = match ($record['target']) {
                    'article' => $this->importArticle($record),
                    'runbook' => $this->importRunbook($record),
                    'project_evidence' => $this->importProjectEvidence($record, $projectIds),
                    default => throw new DomainException('Unsupported legacy target.'),
                };

                $this->writeReceipt($plan, $record, $targetId);
                $created[$record['target']]++;
            }
        }, 3);

        return $this->publicReport($plan, false, $created);
    }

    /**
     * @return array{
     * schema_version:?int,source_name:string,source_sha256:string,parsed_rows:int,rejected_count:int,
     * duplicate_count:int,rejections:list<array{statement:int,row:int,table:string,reason:string}>,
     * pending:list<array<string,mixed>>,accepted:array<string,int>
     * }
     */
    private function buildPlan(string $sql, string $sourceName): array
    {
        $sourceName = $this->safeSourceName($sourceName);
        $sourceSha = hash('sha256', $sql);
        $base = [
            'schema_version' => null,
            'source_name' => $sourceName,
            'source_sha256' => $sourceSha,
            'parsed_rows' => 0,
            'rejected_count' => 0,
            'duplicate_count' => 0,
            'rejections' => [],
            'pending' => [],
            'accepted' => [
                'article' => 0,
                'project' => 0,
                'project_evidence' => 0,
                'runbook' => 0,
            ],
        ];

        if (strlen($sql) > self::MAX_BYTES) {
            $base['rejections'][] = $this->rejection(0, 0, 'source', 'source_exceeds_max_bytes');
            $base['rejected_count'] = 1;

            return $base;
        }
        if ($sql === '' || preg_match('//u', $sql) !== 1 || str_contains($sql, "\0")) {
            $base['rejections'][] = $this->rejection(0, 0, 'source', 'source_not_valid_utf8_text');
            $base['rejected_count'] = 1;

            return $base;
        }

        try {
            $base['schema_version'] = $this->schemaVersion($sql);
            $sql = $this->withoutSchemaHeader($sql);
        } catch (DomainException $exception) {
            $base['rejections'][] = $this->rejection(0, 0, 'source', $exception->getMessage());
            $base['rejected_count'] = 1;

            return $base;
        }

        try {
            $statements = $this->splitStatements($sql);
        } catch (DomainException $exception) {
            $base['rejections'][] = $this->rejection(0, 0, 'source', $exception->getMessage());
            $base['rejected_count'] = 1;

            return $base;
        }

        $candidate = [];
        $seenHashes = [];
        $seenTargets = [];

        foreach ($statements as $statementIndex => $statement) {
            try {
                [$table, $columns, $rows] = $this->parseInsert($statement);
            } catch (DomainException $exception) {
                $base['rejections'][] = $this->rejection(
                    $statementIndex + 1, 0, 'unknown', $exception->getMessage()
                );
                continue;
            }

            foreach ($rows as $rowIndex => $values) {
                $base['parsed_rows']++;
                if ($base['parsed_rows'] > self::MAX_ROWS) {
                    $base['rejections'][] = $this->rejection(
                        $statementIndex + 1, $rowIndex + 1, $table, 'source_exceeds_max_rows'
                    );
                    continue;
                }

                try {
                    $row = $this->combineColumns($columns, $values);
                    $record = $this->normalizeRecord($table, $row);
                } catch (DomainException $exception) {
                    $base['rejections'][] = $this->rejection(
                        $statementIndex + 1, $rowIndex + 1, $table, $exception->getMessage()
                    );
                    continue;
                }

                $hash = $record['record_hash'];
                if (isset($seenHashes[$hash])) {
                    $base['duplicate_count']++;
                    continue;
                }
                $seenHashes[$hash] = true;

                $targetKey = $record['target_key'];
                if (isset($seenTargets[$targetKey]) && $seenTargets[$targetKey] !== $hash) {
                    $base['rejections'][] = $this->rejection(
                        $statementIndex + 1, $rowIndex + 1, $table, 'conflicting_duplicate_target_key'
                    );
                    continue;
                }
                $seenTargets[$targetKey] = $hash;

                $record['statement'] = $statementIndex + 1;
                $record['row'] = $rowIndex + 1;
                $candidate[] = $record;
            }
        }

        $plannedProjects = [];
        foreach ($candidate as $record) {
            if ($record['target'] === 'project') {
                $plannedProjects[$record['data']['slug']] = true;
            }
        }

        foreach ($candidate as $record) {
            if ($record['target'] === 'project_evidence') {
                $slug = $record['data']['project_slug'];
                if (! isset($plannedProjects[$slug]) && ! $this->isImportedProjectSlug($slug)) {
                    $base['rejections'][] = $this->rejection(
                        $record['statement'], $record['row'], $record['source_table'],
                        'project_evidence_references_unimported_project'
                    );
                    continue;
                }
            }

            $receipt = DB::table('legacy_import_receipts')
                ->where('record_hash', $record['record_hash'])->first();
            if ($receipt !== null) {
                if (! $this->targetExists((string) $receipt->target_type, (string) $receipt->target_id)) {
                    $base['rejections'][] = $this->rejection(
                        $record['statement'], $record['row'], $record['source_table'],
                        'provenance_receipt_target_missing'
                    );
                } else {
                    $base['duplicate_count']++;
                }
                continue;
            }

            if ($this->targetConflict($record, $plannedProjects)) {
                $base['rejections'][] = $this->rejection(
                    $record['statement'], $record['row'], $record['source_table'],
                    'target_already_exists_outside_legacy_import'
                );
                continue;
            }

            $base['pending'][] = $record;
            $base['accepted'][$record['target']]++;
        }

        $base['rejected_count'] = count($base['rejections']);

        return $base;
    }

    /** @return array{0:string,1:string} */
    private function readAuthorizedFile(string $path, string $allowedRoot): array
    {
        $root = realpath($allowedRoot);
        $file = realpath($path);

        if ($root === false || ! is_dir($root)) {
            throw new DomainException('Legacy import root is unavailable.');
        }
        if ($file === false || ! is_file($file) || is_link($path)) {
            throw new DomainException('Legacy import file is unavailable or unsafe.');
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($file, $rootPrefix)) {
            throw new DomainException('Legacy import path escapes the approved root.');
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'sql') {
            throw new DomainException('Legacy import file must use the .sql extension.');
        }

        $size = filesize($file);
        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw new DomainException('Legacy import file size is outside the allowed range.');
        }

        $content = file_get_contents($file);
        if (! is_string($content)) {
            throw new DomainException('Legacy import file could not be read.');
        }

        return [$content, basename($file)];
    }

    private function schemaVersion(string $sql): int
    {
        if (preg_match(
            '/\\A(?:\\xEF\\xBB\\xBF)?[ \\t]*--[ \\t]*walidsite-legacy-schema:[ \\t]*([0-9]+)[ \\t]*(?:\\r\\n|\\n|\\r)/',
            $sql,
            $matches
        ) !== 1) {
            throw new DomainException('schema_version_missing');
        }

        $version = (int) $matches[1];
        if ($version !== self::SCHEMA_VERSION) {
            throw new DomainException('schema_version_unsupported');
        }

        return $version;
    }

    private function withoutSchemaHeader(string $sql): string
    {
        $stripped = preg_replace(
            '/\\A(?:\\xEF\\xBB\\xBF)?[ \\t]*--[ \\t]*walidsite-legacy-schema:[ \\t]*[0-9]+[ \\t]*(?:\\r\\n|\\n|\\r)/',
            '',
            $sql,
            1
        );

        return is_string($stripped) ? $stripped : $sql;
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quoted = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quoted && $char === '\\') {
                throw new DomainException('ambiguous_backslash_escape_rejected');
            }

            if ($char === "'") {
                if ($quoted && $i + 1 < $length && $sql[$i + 1] === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                $quoted = ! $quoted;
                $buffer .= $char;
                continue;
            }

            if ($char === ';' && ! $quoted) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($quoted) {
            throw new DomainException('unterminated_sql_string');
        }
        if (trim($buffer) !== '') {
            throw new DomainException('statement_missing_semicolon');
        }
        if ($statements === []) {
            throw new DomainException('no_insert_statements_found');
        }

        return $statements;
    }

    /** @return array{0:string,1:list<string>,2:list<list<string|int|null>>} */
    private function parseInsert(string $statement): array
    {
        if (preg_match(
            '/\AINSERT\s+INTO\s+(?:\x60)?([a-z_]+)(?:\x60)?\s*\(([^)]+)\)\s+VALUES\s+(.+)\z/is',
            $statement,
            $matches
        ) !== 1) {
            throw new DomainException('only_literal_insert_statements_are_allowed');
        }

        $table = strtolower($matches[1]);
        if (! isset(self::TABLES[$table])) {
            throw new DomainException('source_table_not_allowlisted');
        }

        $columns = [];
        foreach (explode(',', $matches[2]) as $column) {
            $column = strtolower(trim(trim($column), chr(96)));
            if (preg_match('/\A[a-z_]+\z/', $column) !== 1 || in_array($column, $columns, true)) {
                throw new DomainException('invalid_or_duplicate_column');
            }
            $columns[] = $column;
        }

        $spec = self::TABLES[$table];
        if (array_diff($columns, $spec['allowed']) !== []) {
            throw new DomainException('source_column_not_allowlisted');
        }
        if (array_diff($spec['required'], $columns) !== []) {
            throw new DomainException('required_source_column_missing');
        }

        $rows = $this->parseTuples($matches[3]);
        if ($rows === []) {
            throw new DomainException('insert_contains_no_rows');
        }

        return [$table, $columns, $rows];
    }

    /** @return list<list<string|int|null>> */
    private function parseTuples(string $input): array
    {
        $rows = [];
        $i = 0;
        $length = strlen($input);

        while (true) {
            $this->skipWhitespace($input, $i);
            if ($i >= $length) {
                break;
            }
            if ($input[$i] !== '(') {
                throw new DomainException('invalid_values_tuple');
            }
            $i++;
            $row = [];

            while (true) {
                $this->skipWhitespace($input, $i);
                if ($i >= $length) {
                    throw new DomainException('unterminated_values_tuple');
                }

                [$value, $i] = $this->parseLiteral($input, $i);
                $row[] = $value;
                $this->skipWhitespace($input, $i);

                if ($i >= $length) {
                    throw new DomainException('unterminated_values_tuple');
                }
                if ($input[$i] === ',') {
                    $i++;
                    continue;
                }
                if ($input[$i] === ')') {
                    $i++;
                    break;
                }
                throw new DomainException('invalid_values_separator');
            }

            $rows[] = $row;
            $this->skipWhitespace($input, $i);
            if ($i >= $length) {
                break;
            }
            if ($input[$i] !== ',') {
                throw new DomainException('trailing_sql_expression_rejected');
            }
            $i++;
        }

        return $rows;
    }

    /** @return array{0:string|int|null,1:int} */
    private function parseLiteral(string $input, int $i): array
    {
        $length = strlen($input);
        if ($input[$i] === "'") {
            $i++;
            $value = '';
            while ($i < $length) {
                $char = $input[$i];
                if ($char === '\\') {
                    throw new DomainException('ambiguous_backslash_escape_rejected');
                }
                if ($char === "'") {
                    if ($i + 1 < $length && $input[$i + 1] === "'") {
                        $value .= "'";
                        $i += 2;
                        continue;
                    }

                    return [$value, $i + 1];
                }
                $value .= $char;
                $i++;
            }
            throw new DomainException('unterminated_sql_string');
        }

        $start = $i;
        while ($i < $length && $input[$i] !== ',' && $input[$i] !== ')') {
            $i++;
        }
        $token = trim(substr($input, $start, $i - $start));
        if (strcasecmp($token, 'NULL') === 0) {
            return [null, $i];
        }
        if (preg_match('/\A-?[0-9]+\z/', $token) === 1) {
            return [(int) $token, $i];
        }

        throw new DomainException('non_literal_sql_value_rejected');
    }

    private function skipWhitespace(string $input, int &$i): void
    {
        $length = strlen($input);
        while ($i < $length && ctype_space($input[$i])) {
            $i++;
        }
    }

    /** @param list<string> $columns @param list<string|int|null> $values */
    private function combineColumns(array $columns, array $values): array
    {
        if (count($columns) !== count($values)) {
            throw new DomainException('column_value_count_mismatch');
        }

        return array_combine($columns, $values);
    }

    /** @return array<string,mixed> */
    private function normalizeRecord(string $sourceTable, array $row): array
    {
        $target = self::TABLES[$sourceTable]['target'];

        $data = match ($target) {
            'article' => $this->normalizeArticle($row),
            'project' => $this->normalizeProject($row),
            'project_evidence' => $this->normalizeEvidence($row),
            'runbook' => $this->normalizeRunbook($row),
            default => throw new DomainException('unsupported_target'),
        };

        $sourceKey = $this->optionalKey($row['legacy_key'] ?? null)
            ?? match ($target) {
                'project_evidence' => $data['project_slug'].'|'.$data['url'],
                default => $data['slug'],
            };

        $encoded = json_encode(
            ['target' => $target, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $encoded);

        $targetKey = match ($target) {
            'project_evidence' => 'project_evidence:'.hash('sha256', $data['project_slug']."\n".$data['url']),
            default => $target.':'.$data['slug'],
        };

        return [
            'source_table' => $sourceTable,
            'source_key' => $sourceKey,
            'target' => $target,
            'target_key' => $targetKey,
            'record_hash' => $hash,
            'data' => $data,
        ];
    }

    private function normalizeArticle(array $row): array
    {
        $citations = [];
        if (($row['citations_json'] ?? null) !== null) {
            if (! is_string($row['citations_json'])) {
                throw new DomainException('citations_json_must_be_quoted_json');
            }
            try {
                $decoded = json_decode($row['citations_json'], true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new DomainException('citations_json_invalid');
            }
            if (! is_array($decoded) || count($decoded) > 20) {
                throw new DomainException('citations_json_invalid');
            }
            foreach ($decoded as $url) {
                if (! is_string($url) || ! $this->isSafeHttpsUrl($url)) {
                    throw new DomainException('citation_url_unsafe');
                }
                $citations[] = ['source_url' => $url, 'verified' => false];
            }
        }

        $lang = $this->text($row['canonical_lang'] ?? null, 'canonical_lang', 2, false);
        if (! in_array($lang, ['ar', 'en'], true)) {
            throw new DomainException('canonical_lang_invalid');
        }

        return [
            'slug' => $this->slug($row['slug'] ?? null, 180),
            'title_en' => $this->text($row['title_en'] ?? null, 'title_en', 250, false),
            'title_ar' => $this->text($row['title_ar'] ?? null, 'title_ar', 250, false),
            'canonical_lang' => $lang,
            'body_md' => $this->markdown($row['body_md'] ?? null, 200000),
            'citations' => $citations,
        ];
    }

    private function normalizeProject(array $row): array
    {
        $status = $this->text($row['status'] ?? null, 'status', 24, false);
        if (! in_array($status, ['completed', 'in_progress', 'research', 'archived'], true)) {
            throw new DomainException('project_status_invalid');
        }

        return [
            'slug' => $this->slug($row['slug'] ?? null, 140),
            'title' => $this->text($row['title'] ?? null, 'title', 220, false),
            'summary_en' => $this->text($row['summary_en'] ?? null, 'summary_en', 10000, true),
            'summary_ar' => $this->text($row['summary_ar'] ?? null, 'summary_ar', 10000, true),
            'role_en' => $this->nullableText($row['role_en'] ?? null, 'role_en', 200),
            'role_ar' => $this->nullableText($row['role_ar'] ?? null, 'role_ar', 200),
            'status' => $status,
        ];
    }

    private function normalizeEvidence(array $row): array
    {
        $type = $this->text($row['evidence_type'] ?? null, 'evidence_type', 40, false);
        if (! in_array($type, ['github', 'documentation', 'case_study', 'reference', 'other'], true)) {
            throw new DomainException('evidence_type_invalid');
        }

        $url = $this->text($row['url'] ?? null, 'url', 2048, false);
        if (! $this->isSafeHttpsUrl($url)) {
            throw new DomainException('evidence_url_unsafe');
        }

        return [
            'project_slug' => $this->slug($row['project_slug'] ?? null, 140),
            'evidence_type' => $type,
            'url' => $url,
        ];
    }

    private function normalizeRunbook(array $row): array
    {
        $version = $row['version'] ?? null;
        if (! is_int($version) || $version < 1 || $version > 100000) {
            throw new DomainException('runbook_version_invalid');
        }

        return [
            'slug' => $this->slug($row['slug'] ?? null, 150),
            'title' => $this->text($row['title'] ?? null, 'title', 220, false),
            'body_md' => $this->markdown($row['body_md'] ?? null, 200000),
            'version' => $version,
        ];
    }

    private function slug(mixed $value, int $max): string
    {
        $slug = $this->text($value, 'slug', $max, false);
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw new DomainException('slug_invalid');
        }

        return $slug;
    }

    private function optionalKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = $this->text($value, 'legacy_key', 180, false);
        if (preg_match('/\A[A-Za-z0-9._:-]+\z/', $key) !== 1) {
            throw new DomainException('legacy_key_invalid');
        }

        return $key;
    }

    private function nullableText(mixed $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->text($value, $field, $max, false);
    }

    private function markdown(mixed $value, int $max): string
    {
        $text = $this->text($value, 'body_md', $max, true);
        if (preg_match('/!\s*\[[^\]]*\]\s*\(/u', $text) === 1
            || preg_match('/<\s*img\b/i', $text) === 1) {
            throw new DomainException('unlicensed_or_remote_media_rejected');
        }

        return $text;
    }

    private function text(mixed $value, string $field, int $max, bool $newlines): string
    {
        if (! is_string($value)) {
            throw new DomainException($field.'_must_be_quoted_text');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max || preg_match('//u', $value) !== 1) {
            throw new DomainException($field.'_invalid_length_or_encoding');
        }

        $controlPattern = $newlines
            ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'
            : '/[\x00-\x1F\x7F]/u';
        if (preg_match($controlPattern, $value) === 1) {
            throw new DomainException($field.'_contains_control_characters');
        }

        if (preg_match(
            '/<\s*(?:script|iframe|object|embed|form|svg|math)\b|\bon[a-z]+\s*=|javascript\s*:|data\s*:/iu',
            $value
        ) === 1) {
            throw new DomainException($field.'_contains_unsafe_markup');
        }

        return $value;
    }

    private function isSafeHttpsUrl(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        foreach (['localhost', '.localhost', '.local', '.internal', '.svc', '.lan', '.home', '.test', '.invalid', '.onion'] as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return false;
            }
        }

        $parameters = (string) ($parts['query'] ?? '').'&'.(string) ($parts['fragment'] ?? '');
        for ($depth = 0; $depth < 3; $depth++) {
            foreach (preg_split('/[&;?#]/', $parameters) as $parameter) {
                $key = strtolower(explode('=', ltrim($parameter, '?#'), 2)[0]);
                if (preg_match('/(?:token|secret|password|passwd|api[_-]?key|signature|session|jwt|bearer|private[_-]?key|access[_-]?key)/i', $key) === 1) {
                    return false;
                }
            }
            $decoded = rawurldecode($parameters);
            if ($decoded === $parameters) {
                return true;
            }
            $parameters = $decoded;
        }

        return rawurldecode($parameters) === $parameters;
    }

    /** @param array<string,bool> $plannedProjects */
    private function targetConflict(array $record, array $plannedProjects): bool
    {
        return match ($record['target']) {
            'article' => Article::query()->where('slug', $record['data']['slug'])->exists(),
            'project' => Project::query()->where('slug', $record['data']['slug'])->exists(),
            'runbook' => DB::table('runbooks')->where('slug', $record['data']['slug'])->exists(),
            'project_evidence' => isset($plannedProjects[$record['data']['project_slug']])
                ? false
                : $this->evidenceConflict($record['data']),
            default => true,
        };
    }

    private function isImportedProjectSlug(string $slug): bool
    {
        $project = Project::query()->where('slug', $slug)->first();
        if ($project === null) {
            return false;
        }

        return DB::table('legacy_import_receipts')
            ->where('target_type', 'project')
            ->where('target_id', $project->id)
            ->exists();
    }

    private function evidenceConflict(array $data): bool
    {
        $project = Project::query()->where('slug', $data['project_slug'])->first();
        if ($project === null) {
            return false;
        }
        if (! DB::table('legacy_import_receipts')
            ->where('target_type', 'project')
            ->where('target_id', $project->id)->exists()) {
            return true;
        }

        return ProjectEvidence::query()
            ->where('project_id', $project->id)
            ->where('url', $data['url'])
            ->exists();
    }

    private function targetExists(string $targetType, string $targetId): bool
    {
        return match ($targetType) {
            'article' => Article::query()->whereKey($targetId)->exists(),
            'project' => Project::query()->whereKey($targetId)->exists(),
            'project_evidence' => ProjectEvidence::query()->whereKey($targetId)->exists(),
            'runbook' => DB::table('runbooks')->where('id', $targetId)->exists(),
            default => false,
        };
    }

    private function importArticle(array $record): string
    {
        $data = $record['data'];
        if (Article::query()->where('slug', $data['slug'])->exists()) {
            throw new DomainException('Article slug became occupied during import.');
        }

        $article = Article::create([
            'slug' => $data['slug'],
            'title_en' => $data['title_en'],
            'title_ar' => $data['title_ar'],
            'canonical_lang' => $data['canonical_lang'],
            'hero_asset_id' => null,
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => $data['body_md'],
            'citations_json' => $data['citations'],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => false,
                'legacy_import' => true,
            ],
            'status' => 'draft',
        ]);

        return $article->id;
    }

    private function importProject(array $record): string
    {
        $data = $record['data'];
        if (Project::query()->where('slug', $data['slug'])->exists()) {
            throw new DomainException('Project slug became occupied during import.');
        }

        $project = Project::create([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'summary_en' => $data['summary_en'],
            'summary_ar' => $data['summary_ar'],
            'role_en' => $data['role_en'],
            'role_ar' => $data['role_ar'],
            'status' => $data['status'],
            'visibility' => 'private',
            'github_repo_id' => null,
            'published_at' => null,
        ]);

        return $project->id;
    }

    /** @param array<string,string> $projectIds */
    private function importProjectEvidence(array $record, array $projectIds): string
    {
        $data = $record['data'];
        $projectId = $projectIds[$data['project_slug']] ?? null;
        if ($projectId === null) {
            $project = Project::query()->where('slug', $data['project_slug'])->first();
            if ($project === null || ! DB::table('legacy_import_receipts')
                ->where('target_type', 'project')
                ->where('target_id', $project->id)
                ->exists()) {
                throw new DomainException('Evidence project is not importer-owned.');
            }
            $projectId = $project->id;
        }

        $evidence = ProjectEvidence::create([
            'project_id' => $projectId,
            'evidence_type' => $data['evidence_type'],
            'url' => $data['url'],
            'visibility' => 'private',
            'verified_at' => null,
        ]);

        return $evidence->id;
    }

    private function importRunbook(array $record): string
    {
        $data = $record['data'];
        if (DB::table('runbooks')->where('slug', $data['slug'])->exists()) {
            throw new DomainException('Runbook slug became occupied during import.');
        }

        $id = (string) Str::uuid();
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $data['slug'],
            'title' => $data['title'],
            'body_md' => $data['body_md'],
            'version' => $data['version'],
            'validated_at' => null,
            'offline_ok' => false,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => $data['version'],
            'body_md' => $data['body_md'],
            'checksum' => hash('sha256', $data['body_md']),
            'created_at' => now('UTC'),
            'reviewed_title' => null,
        ]);

        return $id;
    }

    private function writeReceipt(array $plan, array $record, string $targetId): void
    {
        DB::table('legacy_import_receipts')->insert([
            'id' => (string) Str::uuid(),
            'source_schema_version' => $plan['schema_version'],
            'source_name_hash' => hash('sha256', $plan['source_name']),
            'source_sha256' => $plan['source_sha256'],
            'record_hash' => $record['record_hash'],
            'source_table' => $record['source_table'],
            'source_key_hash' => hash('sha256', $record['source_key']),
            'target_type' => $record['target'],
            'target_id' => $targetId,
            'imported_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);
    }

    /** @return array{statement:int,row:int,table:string,reason:string} */
    private function rejection(int $statement, int $row, string $table, string $reason): array
    {
        return [
            'statement' => $statement,
            'row' => $row,
            'table' => preg_match('/\A[a-z_]+\z/', $table) === 1 ? $table : 'unknown',
            'reason' => preg_replace('/[^a-z0-9_]+/i', '_', strtolower($reason)) ?: 'rejected',
        ];
    }

    private function safeSourceName(string $sourceName): string
    {
        $sourceName = basename($sourceName);
        if (preg_match('/\A[A-Za-z0-9._-]{1,180}\.sql\z/', $sourceName) !== 1) {
            throw new DomainException('Legacy source name must be a simple .sql filename.');
        }

        return $sourceName;
    }

    /** @return array<string,mixed> */
    private function publicReport(array $plan, bool $dryRun, array $created): array
    {
        return [
            'dry_run' => $dryRun,
            'schema_version' => $plan['schema_version'],
            'source_name' => $plan['source_name'],
            'source_sha256' => $plan['source_sha256'],
            'parsed_rows' => $plan['parsed_rows'],
            'accepted' => $plan['accepted'],
            'created' => $created,
            'duplicate_count' => $plan['duplicate_count'],
            'rejected_count' => $plan['rejected_count'],
            'rejections' => $plan['rejections'],
        ];
    }
}
