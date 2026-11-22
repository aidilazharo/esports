<?php
declare(strict_types=1);

/**
 * Hostinger CLI cron: refresh private search snapshots every midnight.
 * This script is never callable through a web request.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (($argv[1] ?? '') !== '--run') {
    fwrite(STDERR, "Usage: php cron/build_directory_snapshots.php --run\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/directory_snapshot.php';

$queries = [
    'schools' => "SELECT s.id, s.school_code AS code, s.school_name AS name,
                         s.school_type AS type, s.state, s.district,
                         EXISTS(SELECT 1 FROM coaches c WHERE c.school_id=s.id LIMIT 1) AS has_coach
                  FROM schools s
                  ORDER BY s.school_name, s.school_code",
    'schools_admin' => "SELECT s.id, s.school_code, s.school_name, s.school_type,
                               s.state, s.district, s.contact_email, s.jpn,
                               (SELECT COUNT(*) FROM students st WHERE st.school_id=s.id) AS student_count
                        FROM schools s ORDER BY s.school_name, s.school_code",
    'teachers' => "SELECT t.id, t.school_id, t.full_name, t.email,
                          t.position, t.email_verified_at, t.created_at,
                          s.school_code, s.school_name
                   FROM teachers t JOIN schools s ON s.id=t.school_id
                   ORDER BY t.created_at DESC",
    'students' => "SELECT st.id, st.school_id, st.student_number, st.full_name,
                          st.class_name, st.membership_status, st.membership_next_due_at,
                          st.created_at, s.school_code, s.school_name
                   FROM students st JOIN schools s ON s.id=st.school_id
                   ORDER BY st.created_at DESC",
    'parents' => "SELECT g.id, g.guardian_number, g.full_name, g.phone, g.email,
                         g.email_verified_at, g.created_at,
                         COUNT(DISTINCT sg.student_id) AS child_count,
                         GROUP_CONCAT(DISTINCT st.school_id ORDER BY st.school_id) AS school_ids
                  FROM guardians g
                  LEFT JOIN student_guardians sg ON sg.guardian_id=g.id
                  LEFT JOIN students st ON st.id=sg.student_id
                  GROUP BY g.id, g.guardian_number, g.full_name, g.phone,
                           g.email, g.email_verified_at, g.created_at
                  ORDER BY g.created_at DESC",
];

try {
    $dir = esportsDirectorySnapshotDir();
    $lock = fopen($dir . '/.build.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another snapshot export is running.');
    }
    try {
        foreach ($queries as $kind => $sql) {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                throw new RuntimeException('Unable to query the ' . $kind . ' directory.');
            }
            $rows = (static function () use ($statement, $kind): Generator {
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($kind === 'parents') {
                        $linked = trim((string) ($row['school_ids'] ?? ''));
                        $row['school_ids'] = $linked === ''
                            ? []
                            : array_map('intval', explode(',', $linked));
                    }
                    yield $row;
                }
            })();
            $count = esportsDirectorySnapshotWrite($kind, $rows);
            echo $kind . ': ' . $count . ' rows published' . PHP_EOL;
            $statement->closeCursor();
        }
        echo 'Completed at ' . date('Y-m-d H:i:s T') . PHP_EOL;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $e) {
    error_log('Nightly directory snapshot generation failed: ' . $e->getMessage());
    fwrite(STDERR, "Snapshot export failed. See the PHP error log.\n");
    exit(1);
}
