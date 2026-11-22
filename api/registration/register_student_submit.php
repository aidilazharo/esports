<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_api_cache.php';
require_once __DIR__ . '/../../student_guardian_verification.php';
require_once __DIR__ . '/../../registration_contact_verification.php';
require_once __DIR__ . '/student_registration_service.php';

try {
    verifyCsrf();

    $errors = [];

    $schoolId = (int)($_POST['school_id'] ?? 0);

    $coachingTeacherId = (int)(
        $_POST['coaching_teacher_id'] ?? 0
    );

    $fullName = strtoupper(
        trim((string)($_POST['full_name'] ?? ''))
    );

    $identityNumber = normalizeIdentityNumber(
        (string)($_POST['identity_number'] ?? '')
    );

    $gender = trim((string)($_POST['gender'] ?? ''));
    $ethnicity = trim((string)($_POST['ethnicity'] ?? ''));

    $dateOfBirth = trim(
        (string)($_POST['date_of_birth'] ?? '')
    ) ?: null;

    $className = strtoupper(
        trim((string)($_POST['class_name'] ?? ''))
    );

    $jerseyName = strtoupper(
        trim((string)($_POST['jersey_name'] ?? ''))
    );

    $jerseyNumber = trim(
        (string)($_POST['jersey_number'] ?? '')
    );

    $couponCode = studentRegistrationNormalizeCouponCode(
        (string)($_POST['coupon_code'] ?? '')
    );

    $guardianFullName = strtoupper(
        trim((string)($_POST['guardian_full_name'] ?? ''))
    );

    $guardianIdentityNumber = normalizeIdentityNumber(
        (string)($_POST['guardian_identity_number'] ?? '')
    );

    $guardianRelationship = strtoupper(
        trim((string)($_POST['guardian_relationship'] ?? ''))
    );

    $guardianPhone = normalizeSmsNumber(
        (string)($_POST['guardian_phone'] ?? '')
    );

    $guardianEmail = trim(
        (string)($_POST['guardian_email'] ?? '')
    );

    $guardianConsent =
        isset($_POST['guardian_consent']) &&
        (string)$_POST['guardian_consent'] === '1';

    $existingGuardian = null;
    $existingGuardianPhoneVerified = false;
    $existingGuardianEmailVerified = false;

    if ($guardianIdentityNumber !== '') {
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
        $stmt->execute([$guardianIdentityNumber]);

        $existingGuardian = $stmt->fetch() ?: null;

        if ($existingGuardian) {
            $guardianFullName = strtoupper(
                trim(
                    (string)(
                        $existingGuardian['full_name']
                        ?? $guardianFullName
                    )
                )
            );

            $existingGuardianPhoneVerified =
                !empty($existingGuardian['phone_verified_at']);

            $existingGuardianEmailVerified =
                !empty($existingGuardian['email_verified_at']);

            if ($existingGuardianPhoneVerified) {
                $guardianPhone = normalizeSmsNumber(
                    (string)($existingGuardian['phone'] ?? '')
                );
            }

            if ($existingGuardianEmailVerified) {
                $guardianEmail = trim(
                    (string)($existingGuardian['email'] ?? '')
                );
            }
        }
    }

    $existingStudent = null;

    if ($identityNumber !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, student_number, membership_status
             FROM students
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
        $stmt->execute([$identityNumber]);
        $existingStudent = $stmt->fetch();

        if ($existingStudent) {
            $studentId = (int)$existingStudent['id'];
            $studentNumber = (string)(
                $existingStudent['student_number'] ?? ''
            );

            $membershipStatus = strtolower(
                trim(
                    (string)(
                        $existingStudent['membership_status']
                        ?? ''
                    )
                )
            );

            if ($membershipStatus === 'pending_activation') {
                setFlash(
                    'info',
                    'Student '
                    . $studentNumber
                    . ' is already registered and payment is still pending.'
                );

                studentRegistrationJson([
                    'success' => true,
                    'redirect' =>
                        'student_payment.php?student_id='
                        . $studentId,
                ]);
            }

            $errors[] =
                'This student NRIC / Passport is already registered under '
                . 'student number '
                . $studentNumber
                . '.';
        }
    }

    if (!$existingStudent) {
        if ($schoolId < 1) {
            $errors[] = 'Please select a school.';
        }

        if ($coachingTeacherId < 1) {
            $errors[] =
                'Please select a coaching teacher.';
        } elseif ($schoolId > 0) {
            $teacherStmt = $pdo->prepare(
                'SELECT id
                 FROM teachers
                 WHERE id = ?
                   AND school_id = ?
                 LIMIT 1'
            );
            $teacherStmt->execute([
                $coachingTeacherId,
                $schoolId,
            ]);

            if (!$teacherStmt->fetchColumn()) {
                $errors[] =
                    'The selected coaching teacher does not belong to the selected school.';
            }

            $assignmentTableStmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'student_coach_assignments'"
            );

            if (
                (int)$assignmentTableStmt->fetchColumn() < 1
            ) {
                $errors[] =
                    'Student coaching assignment setup is required.';
            }
        }

        if ($fullName === '') {
            $errors[] = 'Student name is required.';
        }

        if ($identityNumber === '') {
            $errors[] = 'Student NRIC / Passport is required.';
        }

        if (!in_array($gender, ['Male', 'Female'], true)) {
            $errors[] = 'Please select the student gender.';
        }

        $allowedEthnicities = [
            'Malay',
            'Chinese',
            'Indian',
            'Orang Asli',
            'Other',
        ];

        if (!in_array($ethnicity, $allowedEthnicities, true)) {
            $errors[] = 'Please select the student ethnicity.';
        }

        if ($jerseyName === '') {
            $errors[] = 'Jersey name is required.';
        } elseif (
            function_exists('mb_strlen')
                ? mb_strlen($jerseyName) > 30
                : strlen($jerseyName) > 30
        ) {
            $errors[] = 'Jersey name must not exceed 30 characters.';
        }

        if ($jerseyNumber === '') {
            $errors[] = 'Jersey number is required.';
        } elseif (!preg_match('/^\d{1,2}$/', $jerseyNumber)) {
            $errors[] = 'Jersey number must contain 1 or 2 digits.';
        }

        if ($guardianFullName === '') {
            $errors[] = 'Parent / guardian full name is required.';
        }

        if ($guardianIdentityNumber === '') {
            $errors[] =
                'Parent / guardian NRIC / Passport is required.';
        }

        if ($guardianRelationship === '') {
            $errors[] =
                'Please select the parent / guardian relationship.';
        }

        if ($guardianPhone === '' || strlen($guardianPhone) < 10) {
            $errors[] =
                'A valid parent / guardian mobile number is required.';
        }

        if (
            $guardianEmail === '' ||
            !filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)
        ) {
            $errors[] =
                'A valid parent / guardian email is required.';
        }

        if (!$guardianConsent) {
            $errors[] =
                'Parent / guardian consent is required.';
        }

        if ($couponCode !== '') {
            if (
                !preg_match(
                    '/^[A-Z0-9][A-Z0-9_-]{2,63}$/',
                    $couponCode
                )
            ) {
                $errors[] = 'Coupon code format is invalid.';
            } else {
                try {
                    $coupon = studentRegistrationGetCoupon(
                        $pdo,
                        $couponCode
                    );

                    $couponError =
                        studentRegistrationCouponError($coupon);

                    if ($couponError !== null) {
                        $errors[] = $couponError;
                    }
                } catch (PDOException $e) {
                    $errors[] =
                        'Coupon redemption is not available.';
                }
            }
        }
    }

    if ($errors) {
        studentRegistrationJson([
            'success' => false,
            'errors' => $errors,
        ], 422);
    }

    $redeemedCoupon = null;

    try {
        $pdo->beginTransaction();

        $numberData = studentRegistrationNextNumber(
            $pdo,
            $schoolId,
            true
        );

        if (!$numberData) {
            throw new RuntimeException(
                'The selected school was not found.'
            );
        }

        $studentNumber = $numberData['student_number'];
        $qrToken = bin2hex(random_bytes(32));

        $guardianStmt = $pdo->prepare(
            "SELECT *
             FROM guardians
             WHERE UPPER(
                REPLACE(
                    REPLACE(identity_number, '-', ''),
                    ' ',
                    ''
                )
             ) = ?
             LIMIT 1
             FOR UPDATE"
        );
        $guardianStmt->execute([$guardianIdentityNumber]);
        $guardian = $guardianStmt->fetch();

        if ($guardian) {
            $guardianId = (int)$guardian['id'];

            $pdo->prepare(
                'UPDATE guardians
                 SET
                    full_name = ?,
                    phone = CASE
                        WHEN phone_verified_at IS NOT NULL
                            THEN phone
                        ELSE ?
                    END,
                    email = CASE
                        WHEN email_verified_at IS NOT NULL
                            THEN email
                        ELSE ?
                    END,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([
                $guardianFullName,
                $guardianPhone,
                $guardianEmail,
                $guardianId,
            ]);

            $guardianNumber = (string)(
                $guardian['guardian_number'] ?? ''
            );

            if ($guardianNumber === '') {
                $guardianNumber =
                    'PG-'
                    . str_pad(
                        (string)$guardianId,
                        6,
                        '0',
                        STR_PAD_LEFT
                    );

                $pdo->prepare(
                    'UPDATE guardians
                     SET guardian_number = ?
                     WHERE id = ?'
                )->execute([
                    $guardianNumber,
                    $guardianId,
                ]);
            }
        } else {
            $pdo->prepare(
                'INSERT INTO guardians
                 (
                    guardian_number,
                    full_name,
                    identity_number,
                    phone,
                    email,
                    phone_verified_at
                 )
                 VALUES (NULL, ?, ?, ?, ?, NULL)'
            )->execute([
                $guardianFullName,
                $guardianIdentityNumber,
                $guardianPhone,
                $guardianEmail,
            ]);

            $guardianId = (int)$pdo->lastInsertId();

            $guardianNumber =
                'PG-'
                . str_pad(
                    (string)$guardianId,
                    6,
                    '0',
                    STR_PAD_LEFT
                );

            $pdo->prepare(
                'UPDATE guardians
                 SET guardian_number = ?
                 WHERE id = ?'
            )->execute([
                $guardianNumber,
                $guardianId,
            ]);
        }

        $pdo->prepare(
            'INSERT INTO students
             (
                school_id,
                student_number,
                full_name,
                identity_number,
                gender,
                ethnicity,
                date_of_birth,
                class_name,
                jersey_name,
                jersey_number,
                phone,
                email,
                qr_token,
                membership_status,
                account_verified_at
             )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        )->execute([
            $schoolId,
            $studentNumber,
            $fullName,
            $identityNumber,
            $gender,
            $ethnicity,
            $dateOfBirth,
            $className,
            $jerseyName,
            $jerseyNumber,
            $guardianPhone,
            $guardianEmail,
            $qrToken,
            'pending_activation',
        ]);

        $studentId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO student_coach_assignments
             (
                student_id,
                teacher_id,
                school_id,
                assigned_by_teacher_id,
                assigned_at,
                updated_at
             )
             VALUES (?, ?, ?, NULL, NOW(), NOW())'
        )->execute([
            $studentId,
            $coachingTeacherId,
            $schoolId,
        ]);

        $pdo->prepare(
            'INSERT INTO student_guardians
             (
                student_id,
                guardian_id,
                relationship,
                is_primary,
                consent_given_at,
                consent_version,
                consent_ip,
                consent_user_agent
             )
             VALUES (?, ?, ?, 1, NOW(), ?, ?, ?)'
        )->execute([
            $studentId,
            $guardianId,
            $guardianRelationship,
            GUARDIAN_CONSENT_VERSION,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            substr(
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ),
        ]);

        $emailVerification = createAccountVerification(
            $pdo,
            $studentId,
            $guardianId,
            false
        );

        $verifyUrl =
            studentAppBaseUrl()
            . '/register_student.php?verify='
            . rawurlencode(
                (string)$emailVerification['token']
            );

        if ($couponCode !== '') {
            $redeemedCoupon =
                studentRegistrationRedeemCoupon(
                    $pdo,
                    $couponCode,
                    $studentId
                );
        }

        $pdo->commit();

        registrationIdentityCacheInvalidate($identityNumber);
        registrationIdentityCacheInvalidate(
            $guardianIdentityNumber
        );

        $service =
            registrationVerificationServiceStatus();

        $emailResult = !empty($service['available'])
            ? registrationSendVerificationEmail(
                $guardianEmail,
                $guardianFullName,
                'Student',
                $fullName . ' (' . $studentNumber . ')',
                $verifyUrl
            )
            : [
                'success' => false,
                'message' =>
                    'Verification service configuration is unavailable.',
            ];

        if ($redeemedCoupon !== null) {
            $expiry = date(
                'd M Y',
                strtotime(
                    (string)$redeemedCoupon[
                        'membership_expires_at'
                    ]
                    . ' UTC'
                )
            );

            setFlash(
                !$emailResult['success']
                    ? 'warning'
                    : 'success',
                'Student '
                . $studentNumber
                . ' registered successfully. Coupon '
                . $redeemedCoupon['code']
                . ' activated membership until '
                . $expiry
                . '.'
            );
        } else {
            setFlash(
                $emailResult['success']
                    ? 'success'
                    : 'warning',
                'Student '
                . $studentNumber
                . ' registered successfully. Verify the parent / guardian email using the link sent after registration.'
            );
        }

        studentRegistrationJson([
            'success' => true,
            'student_id' => $studentId,
            'student_number' => $studentNumber,
            'redirect' =>
                'student_payment.php?student_id='
                . $studentId,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            $e instanceof PDOException &&
            $e->getCode() === '23000'
        ) {
            $errors[] =
                'A duplicate registration record was detected.';
        } elseif ($e instanceof RuntimeException) {
            $errors[] = $e->getMessage();
        } else {
            $errors[] =
                'Unable to register the student at this time.';

            error_log(
                'register_student_submit.php error: '
                . $e->getMessage()
            );
        }

        studentRegistrationJson([
            'success' => false,
            'errors' => $errors,
        ], 500);
    }
} catch (Throwable $e) {
    error_log(
        'register_student_submit.php outer error: '
        . $e->getMessage()
    );

    studentRegistrationJson([
        'success' => false,
        'errors' => [
            'Unable to process the registration request.',
        ],
    ], 500);
}
