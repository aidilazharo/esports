<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/student_registration_service.php';

/**
 * Keep the Guardian lookup endpoint lightweight.
 *
 * Do not load student_guardian_verification.php here because this endpoint only
 * needs identity normalization and database lookup. Loading the full
 * verification helper can introduce unrelated dependencies/failures.
 */
function guardianLookupNormalizeIdentity(string $value): string
{
    return strtoupper(
        preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? ''
    );
}

try {
    verifyCsrf();

    $identity = guardianLookupNormalizeIdentity(
        (string)($_POST['guardian_identity_number'] ?? '')
    );

    if (strlen($identity) < 4) {
        studentRegistrationJson([
            'success' => false,
            'exists' => false,
            'message' => 'Enter at least 4 numbers / characters for the parent / guardian NRIC / Passport.',
        ], 422);
    }

    /*
     * Fast path: current records are stored normalized and can use a normal
     * index on guardians.identity_number when available.
     */
    $stmt = $pdo->prepare(
        'SELECT
            id,
            guardian_number,
            full_name,
            phone,
            email,
            phone_verified_at,
            email_verified_at
         FROM guardians
         WHERE identity_number = ?
         LIMIT 1'
    );
    $stmt->execute([$identity]);
    $guardian = $stmt->fetch();

    /*
     * Compatibility fallback for older records containing spaces/hyphens.
     * This preserves the behavior of the previous working register_student.php.
     */
    if (!$guardian) {
        $stmt = $pdo->prepare(
            "SELECT
                id,
                guardian_number,
                full_name,
                phone,
                email,
                phone_verified_at,
                email_verified_at
             FROM guardians
             WHERE UPPER(
                REPLACE(
                    REPLACE(
                        COALESCE(identity_number, ''),
                        '-',
                        ''
                    ),
                    ' ',
                    ''
                )
             ) = ?
             LIMIT 1"
        );
        $stmt->execute([$identity]);
        $guardian = $stmt->fetch();
    }

    if (!$guardian) {
        studentRegistrationJson([
            'success' => true,
            'exists' => false,
            'message' => 'No existing parent / guardian account was found. Enter the new parent details.',
        ]);
    }

    $relationship = '';

    /*
     * Keep relationship lookup separate so a missing/older student_guardians
     * structure does not break the primary Guardian lookup.
     */
    try {
        $relationshipStmt = $pdo->prepare(
            'SELECT relationship
             FROM student_guardians
             WHERE guardian_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $relationshipStmt->execute([(int)$guardian['id']]);
        $relationship = (string)($relationshipStmt->fetchColumn() ?: '');
    } catch (Throwable $relationshipError) {
        error_log(
            'guardian_lookup.php relationship lookup warning: '
            . $relationshipError->getMessage()
        );
        $relationship = '';
    }

    $phoneVerified = !empty($guardian['phone_verified_at']);
    $emailVerified = !empty($guardian['email_verified_at']);

    studentRegistrationJson([
        'success' => true,
        'exists' => true,
        'guardian_number' => (string)($guardian['guardian_number'] ?? ''),
        'full_name' => strtoupper(
            trim((string)($guardian['full_name'] ?? ''))
        ),
        'relationship' => strtoupper(trim($relationship)),
        'phone_verified' => $phoneVerified,
        'email_verified' => $emailVerified,

        // Verified contact values are intentionally not returned to the browser.
        'phone' => $phoneVerified
            ? null
            : (string)($guardian['phone'] ?? ''),

        'email' => $emailVerified
            ? null
            : strtoupper(trim((string)($guardian['email'] ?? ''))),

        'message' => 'Existing parent / guardian account found. Existing details have been loaded.',
    ]);
} catch (Throwable $e) {
    error_log(
        'guardian_lookup.php error: '
        . get_class($e)
        . ': '
        . $e->getMessage()
    );

    studentRegistrationJson([
        'success' => false,
        'exists' => false,
        'message' => 'Unable to check the parent / guardian account.',
        'error_type' => get_class($e),
    ], 500);
}
