<?php
declare(strict_types=1);

$startedAt = microtime(true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/lightweight_registration_common.php';

try {
    $identity = lightweightNormalizeIdentity(
        (string)($_GET['identity_number'] ?? '')
    );

    if (strlen($identity) < 4) {
        lightweightRegistrationJson([
            'success' => false,
            'exists' => false,
            'message' =>
                'Enter at least 4 numbers / characters.',
        ], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, school_id, full_name, identity_number, email
         FROM teachers
         WHERE identity_number = ?
         LIMIT 1'
    );
    $stmt->execute([$identity]);
    $teacher = $stmt->fetch();
    $source = 'exact-index';

    if (!$teacher) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, school_id, full_name, identity_number, email
                 FROM teachers
                 WHERE identity_number_normalized = ?
                 LIMIT 1'
            );
            $stmt->execute([$identity]);
            $teacher = $stmt->fetch();
            $source = 'normalized-index';
        } catch (PDOException $e) {
            $stmt = $pdo->prepare(
                "SELECT id, school_id, full_name, identity_number, email
                 FROM teachers
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
            $teacher = $stmt->fetch();
            $source = 'legacy-scan';
        }
    }

    $durationMs = round(
        (microtime(true) - $startedAt) * 1000,
        2
    );

    lightweightRegistrationJson([
        'success' => true,
        'exists' => (bool)$teacher,
        'teacher_id' => $teacher
            ? (int)$teacher['id']
            : null,
        'message' => $teacher
            ? 'This NRIC / Passport is already registered.'
            : 'NRIC / Passport is available.',
        'source' => $source,
        'server_ms' => $durationMs,
    ]);
} catch (Throwable $e) {
    error_log(
        'teacher_identity.php error: '
        . $e->getMessage()
    );

    lightweightRegistrationJson([
        'success' => false,
        'exists' => false,
        'message' =>
            'Unable to check NRIC / Passport at this time.',
    ], 500);
}
