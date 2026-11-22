<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function teacherListJson(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {
    $schoolId = (int)($_GET['school_id'] ?? 0);

    if ($schoolId < 1) {
        teacherListJson(
            [
                'success' => false,
                'message' => 'Please select a school.',
            ],
            422
        );
    }

    $schoolStmt = $pdo->prepare(
        'SELECT id
         FROM schools
         WHERE id = ?
         LIMIT 1'
    );
    $schoolStmt->execute([$schoolId]);

    if (!$schoolStmt->fetchColumn()) {
        teacherListJson(
            [
                'success' => false,
                'message' => 'Selected school was not found.',
            ],
            404
        );
    }

    $stmt = $pdo->prepare(
        'SELECT
            id,
            full_name,
            position
         FROM teachers
         WHERE school_id = ?
         ORDER BY full_name ASC, id ASC'
    );

    $stmt->execute([$schoolId]);

    $teachers = [];

    foreach ($stmt->fetchAll() as $row) {
        $teachers[] = [
            'id' => (int)$row['id'],
            'full_name' => (string)$row['full_name'],
            'position' => trim(
                (string)($row['position'] ?? '')
            ),
        ];
    }

    teacherListJson([
        'success' => true,
        'school_id' => $schoolId,
        'count' => count($teachers),
        'teachers' => $teachers,
    ]);
} catch (Throwable $e) {
    error_log(
        'School teacher registration API error: '
        . $e->getMessage()
    );

    teacherListJson(
        [
            'success' => false,
            'message' => 'Unable to load school teachers.',
        ],
        500
    );
}
