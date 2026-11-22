<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $identity = strtoupper(
        preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            trim((string)($_GET['identity_number'] ?? ''))
        ) ?? ''
    );

    if (strlen($identity) < 4) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Provide identity_number with at least 4 characters.',
        ]);
        exit;
    }

    $started = microtime(true);

    $stmt = $pdo->prepare(
        'SELECT id, student_number
         FROM students
         WHERE identity_number = ?
         LIMIT 1'
    );
    $stmt->execute([$identity]);
    $row = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'exists' => (bool)$row,
        'student_number' => $row
            ? (string)($row['student_number'] ?? '')
            : null,
        'db_ms' => round((microtime(true) - $started) * 1000, 2),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
