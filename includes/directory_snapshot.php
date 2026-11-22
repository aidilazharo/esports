<?php
declare(strict_types=1);

/**
 * Read-only, nightly-generated directory snapshots.
 * Never store snapshot files under the public website document root.
 */
function esportsDirectorySnapshotKinds(): array
{
    return ['schools', 'schools_admin', 'teachers', 'students', 'parents'];
}

function esportsDirectorySnapshotDir(): string
{
    $applicationRoot = realpath(dirname(__DIR__));
    if ($applicationRoot === false) {
        throw new RuntimeException('Application directory not available.');
    }

    $configured = trim((string) (getenv('ESPORTS_DIRECTORY_SNAPSHOT_DIR') ?: ''));
    $folder = $configured !== ''
        ? rtrim($configured, DIRECTORY_SEPARATOR)
        : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'esports-directory-'
            . substr(hash('sha256', $applicationRoot), 0, 20);

    if (is_link($folder)) {
        throw new RuntimeException('Snapshot directory must not be a symlink.');
    }
    if (!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('Unable to create private snapshot directory.');
    }

    $resolved = realpath($folder);
    if ($resolved === false
        || $resolved === $applicationRoot
        || str_starts_with($resolved, $applicationRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Snapshot directory must be outside the website root.');
    }
    if (!@chmod($resolved, 0700) || !is_readable($resolved) || !is_writable($resolved)) {
        throw new RuntimeException('Private snapshot directory must be writable and mode 0700.');
    }
    return $resolved;
}

function esportsDirectorySnapshotFile(string $kind): string
{
    if (!in_array($kind, esportsDirectorySnapshotKinds(), true)) {
        throw new InvalidArgumentException('Invalid directory snapshot type.');
    }
    return esportsDirectorySnapshotDir() . DIRECTORY_SEPARATOR . $kind . '.json';
}

function esportsDirectorySnapshotRead(string $kind): ?array
{
    $file = esportsDirectorySnapshotFile($kind);
    if (is_link($file) || !is_file($file) || !is_readable($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('Directory snapshot JSON invalid: ' . $kind);
        return null;
    }
    if (!is_array($data)
        || (int) ($data['schema_version'] ?? 0) !== 1
        || !is_string($data['generated_at'] ?? null)
        || !is_array($data['rows'] ?? null)
        || !array_is_list($data['rows'])) {
        return null;
    }
    return $data;
}

/**
 * Atomically export rows from a PDOStatement or array. Run only in CLI cron.
 */
function esportsDirectorySnapshotWrite(string $kind, iterable $rows): int
{
    $file = esportsDirectorySnapshotFile($kind);
    if (is_link($file)) {
        throw new RuntimeException('Refusing to replace a symlink snapshot.');
    }

    $temporary = dirname($file) . DIRECTORY_SEPARATOR . '.snapshot-'
        . bin2hex(random_bytes(12)) . '.tmp';
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to create a temporary snapshot.');
    }
    @chmod($temporary, 0600);

    $count = 0;
    $ok = false;
    $write = static function (string $text) use ($handle): void {
        $length = strlen($text);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($text, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Failed to write directory snapshot.');
            }
            $written += $result;
        }
    };

    try {
        $generated = gmdate('Y-m-d\TH:i:s\Z');
        $write('{"schema_version":1,"generated_at":'
            . json_encode($generated, JSON_THROW_ON_ERROR)
            . ',"rows":[');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Unexpected directory snapshot row type.');
            }
            if ($count > 0) {
                $write(',');
            }
            $write(json_encode(
                $row,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));
            $count++;
        }
        $write(']}');
        if (!fflush($handle)) {
            throw new RuntimeException('Directory snapshot flush failed.');
        }
        $ok = true;
    } finally {
        fclose($handle);
        if (!$ok) {
            @unlink($temporary);
        }
    }

    if (!@rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException('Directory snapshot publish failed.');
    }
    @chmod($file, 0600);
    return $count;
}

function esportsDirectorySnapshotInvalidate(string $kind): void
{
    $file = esportsDirectorySnapshotFile($kind);
    if (is_file($file) && !is_link($file)) {
        @unlink($file);
    }
}

/**
 * Scope by the authenticated session, never by a caller-supplied school ID.
 * The private parent index holds linked school IDs but no identity numbers.
 */
function esportsDirectorySnapshotVisible(
    string $kind,
    array $row,
    ?string $role,
    int $sessionSchoolId = 0,
    int $sessionParentId = 0
): bool {
    if ($kind === 'schools') {
        return true;
    }
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'parent') {
        return $kind === 'parents'
            && $sessionParentId > 0
            && (int) ($row['id'] ?? 0) === $sessionParentId;
    }
    if (($role === 'school' || $role === 'teacher') && $sessionSchoolId > 0) {
        if ($kind === 'parents') {
            return in_array($sessionSchoolId, array_map(
                'intval',
                (array) ($row['school_ids'] ?? [])
            ), true);
        }
        return (int) ($row['school_id'] ?? 0) === $sessionSchoolId;
    }
    return false;
}

/**
 * Pagination and text filters run solely on JSON data; no PDO calls.
 */
function esportsDirectorySnapshotSearch(
    array $rows,
    string $kind,
    ?string $role,
    int $sessionSchoolId,
    int $sessionParentId,
    string $query,
    string $state,
    int $requestedSchoolId,
    int $page,
    int $limit
): array {
    $query = trim(substr($query, 0, 120));
    $state = trim(substr($state, 0, 100));
    $limit = max(1, min(50, $limit));
    $page = max(1, min(100000, $page));
    $matches = [];

    foreach ($rows as $row) {
        if (!is_array($row)
            || !esportsDirectorySnapshotVisible(
                $kind, $row, $role, $sessionSchoolId, $sessionParentId
            )) {
            continue;
        }
        if ($requestedSchoolId > 0) {
            if ($kind === 'parents') {
                if (!in_array($requestedSchoolId, array_map(
                    'intval',
                    (array) ($row['school_ids'] ?? [])
                ), true)) {
                    continue;
                }
            } elseif ((int) ($row['school_id'] ?? $row['id'] ?? 0)
                !== $requestedSchoolId) {
                continue;
            }
        }
        if ($state !== '' && strcasecmp((string) ($row['state'] ?? ''), $state) !== 0) {
            continue;
        }
        if ($query !== '') {
            $haystack = implode(' ', array_map(
                'strval',
                array_filter($row, 'is_scalar')
            ));
            $found = function_exists('mb_stripos')
                ? mb_stripos($haystack, $query, 0, 'UTF-8') !== false
                : stripos($haystack, $query) !== false;
            if (!$found) {
                continue;
            }
        }
        // Scope metadata is never exposed in the API payload.
        unset($row['school_ids']);
        $matches[] = $row;
    }

    return [
        'total' => count($matches),
        'page' => $page,
        'limit' => $limit,
        'rows' => array_slice($matches, ($page - 1) * $limit, $limit),
    ];
}
