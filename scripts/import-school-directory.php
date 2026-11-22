<?php
declare(strict_types=1);

/**
 * School directory bootstrap. NEVER sends emails, changes registered schools,
 * or imports email/phone columns from source CSVs.
 *
 * php scripts/import-school-directory.php /private/kpm-schools.csv --dry-run
 * php scripts/import-school-directory.php /private/kpm-schools.csv --apply --backup-confirmed
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
$filename = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
if ($filename === '' || ($apply && !in_array('--backup-confirmed', $argv, true))) {
    fwrite(STDERR, "Usage: php scripts/import-school-directory.php /private/schools.csv [--dry-run|--apply --backup-confirmed]\n");
    exit(2);
}
$root = realpath(dirname(__DIR__));
$sourcePath = realpath($filename);
if ($root === false || $sourcePath === false
    || $sourcePath === $root
    || str_starts_with($sourcePath, $root . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Keep the reviewed school CSV outside the website repository/document root.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/school_directory_import.php';
$records = schoolDirectoryParseCsv($sourcePath);
echo 'CSV validated: ' . count($records) . " unique schools.\n";

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/registration_api_cache.php';
require_once dirname(__DIR__) . '/includes/school_email_policy.php';

if (!schoolEmailSuppressionColumnExists($pdo)) {
    fwrite(STDERR, "Missing schools.suppress_school_email. Back up MySQL and run: php scripts/migrate.php apply --backup-confirmed\n");
    exit(3);
}

$columns = [];
$sql = $pdo->query(
    "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools'"
);
foreach ($sql->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $columns[(string) $row['COLUMN_NAME']] = $row;
}

$needed = ['school_code', 'school_name', 'state', 'suppress_school_email', 'jpn', 'unsubscribed'];
foreach ($needed as $key) {
    if (!isset($columns[$key])) {
        throw new RuntimeException('The schools table is missing required column ' . $key);
    }
}

// Explicitly set directory fields and no-email safeguards.
// Extra contact fields in a CSV are intentionally ignored.
$fixed = [
    'suppress_school_email' => 1,
    'jpn' => 0,
    'unsubscribed' => 1,
    'invite_email' => 0,
    'invite_read' => 0,
    'download_file' => 0,
    'total_students' => 0,
];
$emptyContact = ['contact_email', 'contact_name', 'contact_phone', 'address', 'school_logo', 'referrer'];
foreach ($emptyContact as $key) {
    if (isset($columns[$key])) {
        $fixed[$key] = strtoupper((string) $columns[$key]['IS_NULLABLE']) === 'YES' ? null : '';
    }
}

$insertFields = array_values(array_filter(
    array_merge(['school_code', 'school_name', 'school_type', 'state', 'district'], array_keys($fixed)),
    static fn(string $key): bool => array_key_exists($key, $columns)
));
foreach ($columns as $key => $meta) {
    $extra = strtolower((string) ($meta['EXTRA'] ?? ''));
    $required = strtoupper((string) $meta['IS_NULLABLE']) === 'NO'
        && $meta['COLUMN_DEFAULT'] === null
        && !str_contains($extra, 'auto_increment')
        && !str_contains($extra, 'generated');
    if ($required && !in_array($key, $insertFields, true)) {
        throw new RuntimeException(
            "Schema requires {$key} without a default; review the production schools schema before importing. No rows changed."
        );
    }
}

$select = $pdo->prepare('SELECT id FROM schools WHERE UPPER(TRIM(school_code)) = ? LIMIT 1');
$existing = 0;
$pending = [];
foreach ($records as $record) {
    $select->execute([$record['school_code']]);
    if ($select->fetchColumn() !== false) {
        $existing++;
    } else {
        $pending[] = $record;
    }
}
echo "Already in MySQL (preserved): {$existing}\n";
echo 'New directory-only schools: ' . count($pending) . "\n";
echo "Email suppressed on every new row. No existing contact details are overwritten.\n";
if (!$apply) {
    echo "DRY RUN — no database writes. To import after backup, supply --apply --backup-confirmed.\n";
    exit(0);
}

$lock = (int) $pdo->query("SELECT GET_LOCK('esports_school_directory_import', 20)")->fetchColumn();
if ($lock !== 1) {
    throw new RuntimeException('Another school import is active.');
}
$added = 0;
$skipped = 0;
try {
    $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
    $quoted = implode(', ', array_map(static fn($x) => chr(96) . $x . chr(96), $insertFields));
    $insert = $pdo->prepare(
        'INSERT INTO schools (' . $quoted . ') VALUES (' . $placeholders . ')'
    );

    foreach (array_chunk($pending, 250) as $batch) {
        $pdo->beginTransaction();
        try {
            foreach ($batch as $record) {
                // Check again under lock for idempotency on retries.
                $select->execute([$record['school_code']]);
                if ($select->fetchColumn() !== false) {
                    $skipped++;
                    continue;
                }
                $values = [];
                foreach ($insertFields as $field) {
                    $values[] = $record[$field] ?? $fixed[$field] ?? null;
                }
                $insert->execute($values);
                $added++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    if ($added > 0) {
        registrationSchoolCacheInvalidate();
    }
    echo "IMPORTED {$added} directory schools. Concurrently existing: {$skipped}.\n";
    echo "No Brevo messages were requested by this script.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed after ' . $added . ' insert attempts: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Earlier completed batches may be committed. Check records and rerun; duplicates are skipped.\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('esports_school_directory_import')");
}
