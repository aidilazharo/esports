<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_api_cache.php';

try {
    $identity = registrationNormalizeApiIdentity(
        (string)($_GET['nric'] ?? $_GET['identity_number'] ?? '')
    );

    if ($identity === '' || strlen($identity) < 4) {
        registrationJsonResponse([
            'success' => false,
            'exists' => false,
            'role' => 'student',
            'message' => 'ENTER A VALID STUDENT NRIC / PASSPORT.',
        ], 422, 0);
    }

    $cacheKey = 'registration-student-v3:' . $identity;
    $result = registrationApiCacheGet($cacheKey, 60);

    if (!is_array($result)) {
        // Fast path uses identity_number index.
        $stmt = $pdo->prepare(
            'SELECT id FROM students WHERE identity_number=? LIMIT 1'
        );
        $stmt->execute([$identity]);
        $exists = (bool)$stmt->fetchColumn();

        // Legacy compatibility for older rows containing spaces/hyphens.
        if (!$exists) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM students
                 WHERE UPPER(REPLACE(REPLACE(COALESCE(identity_number,''),'-',''),' ',''))=?
                 LIMIT 1"
            );
            $stmt->execute([$identity]);
            $exists = (bool)$stmt->fetchColumn();
        }

        $result = [
            'success' => true,
            'exists' => $exists,
            'role' => 'student',
            'message' => $exists
                ? 'THIS NRIC / PASSPORT IS ALREADY REGISTERED AS A STUDENT.'
                : 'NRIC / PASSPORT IS AVAILABLE FOR THIS ROLE.',
        ];

        registrationApiCacheSet($cacheKey, $result);
    }

    registrationJsonResponse($result, 200, 20);
} catch (Throwable $e) {
    error_log('Student identity API error: ' . $e->getMessage());

    registrationJsonResponse([
        'success' => false,
        'exists' => false,
        'role' => 'student',
        'message' => 'UNABLE TO CHECK STUDENT NRIC / PASSPORT AT THIS TIME.',
    ], 500, 0);
}
