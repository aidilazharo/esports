<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $checks = [];

    $checks['guardians_table'] = (bool)$pdo
        ->query("SELECT 1 FROM guardians LIMIT 1")
        ->fetchColumn();

    try {
        $pdo->query("SELECT 1 FROM student_guardians LIMIT 1");
        $checks['student_guardians_table'] = true;
    } catch (Throwable $e) {
        $checks['student_guardians_table'] = false;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM guardians");
    $guardianColumns = array_map(
        static fn(array $row): string => (string)$row['Field'],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    $required = [
        'id',
        'guardian_number',
        'full_name',
        'identity_number',
        'phone',
        'email',
        'phone_verified_at',
        'email_verified_at',
    ];

    $missing = array_values(
        array_diff($required, $guardianColumns)
    );

    echo json_encode([
        'success' => empty($missing),
        'checks' => $checks,
        'missing_guardian_columns' => $missing,
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
