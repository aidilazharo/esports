<?php
declare(strict_types=1);

/**
 * CLI-only, versioned MySQL migration runner.
 * Example: php scripts/migrate.php apply --backup-confirmed
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$command = $argv[1] ?? 'status';
if (!in_array($command, ['status', 'apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [status|apply --backup-confirmed]\n");
    exit(2);
}
if ($command === 'apply' && !in_array('--backup-confirmed', $argv, true)) {
    fwrite(STDERR, "Back up the MySQL database first; pass --backup-confirmed to apply.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config.php';

$directory = dirname(__DIR__) . '/database/migrations';
$files = glob($directory . '/*.php') ?: [];
sort($files, SORT_STRING);

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (!preg_match('/^[0-9]{8}_[0-9]{6}_[a-z0-9_]+\\.php$/D', $name)) {
        throw new RuntimeException('Invalid migration filename: ' . $name);
    }
    $pending[$name] = ['path' => $file, 'checksum' => hash_file('sha256', $file)];
}

$lockHeld = false;
try {
    if ($command === 'apply') {
        $statement = $pdo->query("SELECT GET_LOCK('esports_schema_migrations', 30)");
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire migration lock.');
        }
        $lockHeld = true;

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(255) PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    $existsStmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
    );
    $exists = (int) $existsStmt->fetchColumn() > 0;
    $applied = [];

    if ($exists) {
        foreach ($pdo->query('SELECT name, checksum FROM schema_migrations')->fetchAll() as $row) {
            $applied[(string) $row['name']] = (string) $row['checksum'];
        }
    }

    foreach ($pending as $name => $item) {
        if (isset($applied[$name]) && !hash_equals($applied[$name], $item['checksum'])) {
            throw new RuntimeException('Applied migration was modified: ' . $name);
        }
    }

    // Validate all pending migration callbacks before executing any.
    $callbacks = [];
    foreach ($pending as $name => $item) {
        if (!isset($applied[$name])) {
            if ($command === 'apply') {
                $callback = require $item['path'];
                if (!is_callable($callback)) {
                    throw new RuntimeException('Migration must return a callable: ' . $name);
                }
                $callbacks[$name] = $callback;
            }
        }
        echo (isset($applied[$name]) ? 'APPLIED ' : 'PENDING ') . $name . PHP_EOL;
    }

    if ($command === 'apply') {
        foreach ($callbacks as $name => $callback) {
            echo 'RUNNING ' . $name . PHP_EOL;
            // MySQL DDL can implicitly commit: do not pretend this is rollback-safe.
            $callback($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO schema_migrations (name, checksum) VALUES (?, ?)'
            );
            $insert->execute([$name, $pending[$name]['checksum']]);
            echo 'DONE ' . $name . PHP_EOL;
        }
    }
    echo 'Migration check completed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'If a DDL statement partially succeeded, reconcile manually before retrying.' . PHP_EOL);
    exit(1);
} finally {
    if ($lockHeld) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('esports_schema_migrations')");
        } catch (Throwable $ignored) {
        }
    }
}
