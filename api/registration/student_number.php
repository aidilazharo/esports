<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/student_registration_service.php';

try {
    $schoolId = (int)($_GET['school_id'] ?? 0);

    if ($schoolId < 1) {
        studentRegistrationJson([
            'success' => false,
            'message' => 'Please select a school.',
        ], 422);
    }

    $numberData = studentRegistrationNextNumber(
        $pdo,
        $schoolId
    );

    if (!$numberData) {
        studentRegistrationJson([
            'success' => false,
            'message' => 'School not found.',
        ], 404);
    }

    studentRegistrationJson([
        'success' => true,
        'student_number' => $numberData['student_number'],
        'school_code' => $numberData['school_code'],
    ]);
} catch (Throwable $e) {
    studentRegistrationJson([
        'success' => false,
        'message' => 'Unable to generate the student number.',
    ], 500);
}
