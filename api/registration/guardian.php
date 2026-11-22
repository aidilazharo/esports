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
            'role' => 'parent',
            'message' => 'ENTER A VALID PARENT / GUARDIAN NRIC / PASSPORT.',
        ], 422, 0);
    }

    $cacheKey = 'registration-guardian-v3:' . $identity;
    $result = registrationApiCacheGet($cacheKey, 60);

    if (!is_array($result)) {
        $stmt = $pdo->prepare(
            "SELECT id, guardian_number, full_name, phone, email,
                    phone_verified_at, email_verified_at
             FROM guardians
             WHERE identity_number=?
             LIMIT 1"
        );
        $stmt->execute([$identity]);
        $guardian = $stmt->fetch();

        if (!$guardian) {
            $stmt = $pdo->prepare(
                "SELECT id, guardian_number, full_name, phone, email,
                        phone_verified_at, email_verified_at
                 FROM guardians
                 WHERE UPPER(REPLACE(REPLACE(COALESCE(identity_number,''),'-',''),' ',''))=?
                 LIMIT 1"
            );
            $stmt->execute([$identity]);
            $guardian = $stmt->fetch();
        }

        if (!$guardian) {
            $result = [
                'success' => true,
                'exists' => false,
                'role' => 'parent',
                'message' => 'NO EXISTING PARENT / GUARDIAN ACCOUNT FOUND.',
            ];
        } else {
            $relationshipStmt = $pdo->prepare(
                "SELECT relationship
                 FROM student_guardians
                 WHERE guardian_id=?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $relationshipStmt->execute([(int)$guardian['id']]);

            $phoneVerified = !empty($guardian['phone_verified_at']);
            $emailVerified = !empty($guardian['email_verified_at']);

            $result = [
                'success' => true,
                'exists' => true,
                'role' => 'parent',
                'guardian_number' => registrationUppercaseInput(
                    (string)($guardian['guardian_number'] ?? '')
                ),
                'full_name' => registrationUppercaseInput(
                    (string)($guardian['full_name'] ?? '')
                ),
                'relationship' => registrationUppercaseInput(
                    (string)($relationshipStmt->fetchColumn() ?: '')
                ),
                'phone_verified' => $phoneVerified,
                'email_verified' => $emailVerified,

                // Verified contact values remain hidden from the browser.
                'phone' => $phoneVerified ? null : (string)($guardian['phone'] ?? ''),
                'email' => $emailVerified
                    ? null
                    : registrationUppercaseInput((string)($guardian['email'] ?? '')),

                'message' => 'EXISTING PARENT / GUARDIAN ACCOUNT FOUND.',
            ];
        }

        registrationApiCacheSet($cacheKey, $result);
    }

    // Response can contain unverified contact details: never browser-cache it.
    registrationJsonResponse($result, 200, 0);
} catch (Throwable $e) {
    error_log('Guardian identity API error: ' . $e->getMessage());

    registrationJsonResponse([
        'success' => false,
        'exists' => false,
        'role' => 'parent',
        'message' => 'UNABLE TO CHECK PARENT / GUARDIAN AT THIS TIME.',
    ], 500, 0);
}
