<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_contact_verification.php';
require_once __DIR__ . '/lightweight_registration_common.php';

function teacherSubmitFindByIdentity(
    PDO $pdo,
    string $identity,
    bool $forUpdate = false
): ?array {
    $identity = lightweightNormalizeIdentity($identity);

    if ($identity === '') {
        return null;
    }

    $suffix = $forUpdate ? ' FOR UPDATE' : '';

    $stmt = $pdo->prepare(
        'SELECT id, school_id, full_name, identity_number, email
         FROM teachers
         WHERE identity_number = ?
         LIMIT 1' . $suffix
    );
    $stmt->execute([$identity]);
    $teacher = $stmt->fetch();

    if ($teacher) {
        return $teacher;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, school_id, full_name, identity_number, email
             FROM teachers
             WHERE identity_number_normalized = ?
             LIMIT 1' . $suffix
        );
        $stmt->execute([$identity]);
        $teacher = $stmt->fetch();

        if ($teacher) {
            return $teacher;
        }
    } catch (PDOException $e) {
        // Generated normalized column is optional.
    }

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
         LIMIT 1" . $suffix
    );
    $stmt->execute([$identity]);
    $teacher = $stmt->fetch();

    return $teacher ?: null;
}

try {
    verifyCsrf();

    $errors = [];

    $schoolId = (int)($_POST['school_id'] ?? 0);

    $fullName = trim(
        (string)($_POST['full_name'] ?? '')
    );

    $identity = lightweightNormalizeIdentity(
        (string)($_POST['identity_number'] ?? '')
    );

    $position = trim(
        (string)($_POST['position'] ?? '')
    );

    $phone = registrationNormalizeSmsNumber(
        (string)($_POST['phone'] ?? '')
    );

    $email = trim((string)($_POST['email'] ?? ''));

    if (!staffContactVerificationSchemaReady($pdo, 'teacher')) {
        $errors[] =
            'Run add_staff_contact_verification.sql before registering a teacher.';
    }

    if ($schoolId < 1) {
        $errors[] = 'Please select a school.';
    } else {
        $schoolStmt = $pdo->prepare(
            'SELECT id
             FROM schools
             WHERE id = ?
             LIMIT 1'
        );
        $schoolStmt->execute([$schoolId]);

        if (!$schoolStmt->fetchColumn()) {
            $errors[] = 'Selected school was not found.';
        }
    }

    if ($fullName === '') {
        $errors[] =
            'School Team Teacher name is required.';
    }

    if ($identity === '') {
        $errors[] = 'NRIC / Passport is required.';
    }

    if ($identity !== '') {
        $existing = teacherSubmitFindByIdentity(
            $pdo,
            $identity
        );

        if ($existing) {
            $errors[] =
                'This NRIC / Passport is already registered to '
                . (string)$existing['full_name']
                . '.';
        }
    }

    if (
        $phone === ''
        || strlen($phone) < 10
        || strlen($phone) > 15
    ) {
        $errors[] =
            'A valid mobile number is required.';
    }

    if (
        $email === ''
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] =
            'A valid email address is required.';
    }

    $service = registrationVerificationServiceStatus();
    if (empty($service['available'])) {
        $errors[] = 'Email verification is currently unavailable. Please try again later.';
    }

    if ($errors) {
        lightweightRegistrationJson([
            'success' => false,
            'errors' => $errors,
        ], 422);
    }

    try {
        $pdo->beginTransaction();

        $existing = teacherSubmitFindByIdentity(
            $pdo,
            $identity,
            true
        );

        if ($existing) {
            throw new RuntimeException(
                'This NRIC / Passport is already registered to '
                . (string)$existing['full_name']
                . '.'
            );
        }

        $emailVerification =
            createStaffEmailVerificationToken();

        $stmt = $pdo->prepare(
            'INSERT INTO teachers
             (
                school_id,
                full_name,
                identity_number,
                position,
                phone,
                email,
                phone_verified_at,
                email_verified_at,
                email_verification_token_hash,
                email_verification_expires_at
             )
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)'
        );

        $stmt->execute([
            $schoolId,
            $fullName,
            $identity,
            $position,
            $phone,
            $email,
            $emailVerification['token_hash'],
            $emailVerification['expires_at'],
        ]);

        $pdo->commit();

        $verifyUrl =
            registrationAppBaseUrl()
            . '/register_teacher.php?verify_email='
            . rawurlencode(
                (string)$emailVerification['token']
            );

        $emailResult = !empty($service['available'])
            ? registrationSendVerificationEmail(
                $email,
                $fullName,
                'School Team Teacher',
                $fullName,
                $verifyUrl
            )
            : [
                'success' => false,
                'message' =>
                    'Verification service configuration is unavailable.',
            ];

        setFlash(
            $emailResult['success'] ? 'success' : 'warning',
            $emailResult['success']
                ? 'Teacher registered. Please check your email to verify the account.'
                : 'Teacher registered, but the verification email could not be delivered. Contact support to request another link.'
        );

        lightweightRegistrationJson([
            'success' => true,
            'redirect' => 'register_teacher.php',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to register the school team teacher at this time.';

        error_log(
            'teacher_submit.php error: '
            . $e->getMessage()
        );

        lightweightRegistrationJson([
            'success' => false,
            'errors' => [$message],
        ], 500);
    }
} catch (Throwable $e) {
    error_log(
        'teacher_submit.php outer error: '
        . $e->getMessage()
    );

    lightweightRegistrationJson([
        'success' => false,
        'errors' => [
            'Unable to process the teacher registration request.',
        ],
    ], 500);
}
