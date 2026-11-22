<?php
require_once 'config.php';
require_once __DIR__ . '/registration_api_cache.php';
require_once __DIR__ . '/registration_contact_verification.php';

$verificationView = null;
if (isset($_GET['verify_email'])) {
    if (staffContactVerificationSchemaReady($pdo, 'coach')) {
        $verificationView = handleStaffEmailVerification($pdo, 'coach', (string) $_GET['verify_email']);
    } else {
        $verificationView = [
            'success' => false,
            'title' => 'Database update required',
            'message' => 'Run add_staff_contact_verification.sql before using email verification.',
        ];
    }
}

// AJAX endpoint for the Send SMS Code button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_coach_sms_code') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        verifyCsrf();

        $serviceStatus = registrationVerificationServiceStatus();
        if (empty($serviceStatus['available'])) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'bypass_allowed' => true,
                'config_unavailable' => true,
                'message' => 'SMS/email verification is currently unavailable. You may continue registration and verify later.',
            ]);
            exit;
        }

        $result = registrationRequestSmsCodeWithEmailFallback(
            'coach_sms_verification',
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['full_name'] ?? ''),
            'coach-registration-verification',
            'Esports Coach'
        );
        http_response_code((int) ($result['status'] ?? 200));
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to send the verification code at this time.']);
    }
    exit;
}

$schoolCount = (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn();
$schoolsAvailable = $schoolCount > 0;

/**
 * Normalize NRIC / Passport so the same coach cannot bypass the
 * two-school rule by changing spaces, dashes or letter case.
 */
function normalizeCoachIdentityNumber(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[\s\-]+/', '', $value) ?? $value;
}

// Optional school code from URL, e.g. ?code=CEA4088.
$requestedSchoolCode = registrationUppercaseInput((string) ($_GET['code'] ?? ''));
$requestedSchoolId = (int) ($_POST['school_id_1'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'send_coach_sms_code') {
    verifyCsrf();

    $schoolId1 = (int) ($_POST['school_id_1'] ?? 0);
    $schoolId2 = (int) ($_POST['school_id_2'] ?? 0);
    $fullName = registrationUppercaseInput((string) ($_POST['full_name'] ?? ''));
    $identityNumber = normalizeCoachIdentityNumber((string) ($_POST['identity_number'] ?? ''));
    $organization = registrationUppercaseInput((string) ($_POST['organization'] ?? ''));
    $certification = registrationUppercaseInput((string) ($_POST['certification'] ?? ''));
    $phone = registrationNormalizeSmsNumber((string) ($_POST['phone'] ?? ''));
    $smsCode = preg_replace('/\D+/', '', (string) ($_POST['sms_code'] ?? '')) ?? '';
    $email = registrationUppercaseInput((string) ($_POST['email'] ?? ''));
    $verificationBypassRequested = isset($_POST['skip_phone_verification']) || (string) ($_POST['verification_bypass'] ?? '') === '1';
    $verificationBypassed = false;

    if (!$verificationSchemaReady) {
        $errors[] = 'Run add_staff_contact_verification.sql before registering a coach.';
    }

    if ($schoolId1 < 1) {
        $errors[] = 'Please select School 1.';
    }
    if ($schoolId2 > 0 && $schoolId2 === $schoolId1) {
        $errors[] = 'School 1 and School 2 must be different schools.';
    }
    if ($fullName === '') {
        $errors[] = 'Esports Coach name is required.';
    }
    if ($identityNumber === '') {
        $errors[] = 'NRIC / Passport is required to enforce the maximum of 2 school registrations per coach.';
    }
    if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 15) {
        $errors[] = 'A valid mobile number is required for contact verification.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required for email verification.';
    }

    $selectedSchoolIds = [];
    if ($schoolId1 > 0) {
        $selectedSchoolIds[] = $schoolId1;
    }
    if ($schoolId2 > 0) {
        $selectedSchoolIds[] = $schoolId2;
    }
    $selectedSchoolIds = array_values(array_unique($selectedSchoolIds));
    $selectedSchools = [];

    if (!$errors && $selectedSchoolIds) {
        $placeholders = implode(',', array_fill(0, count($selectedSchoolIds), '?'));
        $selectedSchoolStmt = $pdo->prepare(
            "SELECT id, school_code, school_name, school_type
             FROM schools
             WHERE id IN ($placeholders)"
        );
        $selectedSchoolStmt->execute($selectedSchoolIds);

        foreach ($selectedSchoolStmt->fetchAll() as $selectedSchool) {
            $selectedSchools[(int) $selectedSchool['id']] = $selectedSchool;
        }

        if (count($selectedSchools) !== count($selectedSchoolIds)) {
            $errors[] = 'One of the selected schools was not found.';
        }
    }

    if (!$errors) {
        foreach ($selectedSchoolIds as $selectedSchoolId) {
            $schoolType = trim((string) ($selectedSchools[$selectedSchoolId]['school_type'] ?? ''));
            if ($schoolType === '') {
                $errors[] = 'School ' . (string) ($selectedSchools[$selectedSchoolId]['school_name'] ?? $selectedSchoolId)
                    . ' does not have a school type. Update the school record before registering this coach.';
            }
        }
    }

    // If two schools are selected in the same form, they must be different school types.
    if (!$errors && count($selectedSchoolIds) === 2) {
        $type1 = trim((string) $selectedSchools[$selectedSchoolIds[0]]['school_type']);
        $type2 = trim((string) $selectedSchools[$selectedSchoolIds[1]]['school_type']);

        if (strcasecmp($type1, $type2) === 0) {
            $errors[] = 'School 1 and School 2 must be different school types. For example: one SK and one SMK.';
        }
    }

    // A school may only have one coach. Block any selected school that already has a coach.
    if (!$errors && $selectedSchoolIds) {
        $placeholders = implode(',', array_fill(0, count($selectedSchoolIds), '?'));
        $schoolCoachStmt = $pdo->prepare(
            "SELECT c.school_id, c.full_name, s.school_name, s.school_code
             FROM coaches c
             INNER JOIN schools s ON s.id = c.school_id
             WHERE c.school_id IN ($placeholders)
             ORDER BY c.id ASC"
        );
        $schoolCoachStmt->execute($selectedSchoolIds);
        $occupiedSchools = $schoolCoachStmt->fetchAll();

        foreach ($occupiedSchools as $occupiedSchool) {
            $errors[] = 'School ' . (string) $occupiedSchool['school_name']
                . ' (' . (string) $occupiedSchool['school_code'] . ') already has a registered coach: '
                . (string) $occupiedSchool['full_name'] . '.';
        }
    }

    // Enforce a maximum of two total school registrations for the same coach,
    // including any existing registration made previously.
    $existingCoachRegistrations = [];
    if (!$errors && $identityNumber !== '') {
        $existingCoachStmt = $pdo->prepare(
            "SELECT c.id, c.school_id, s.school_code, s.school_name, s.school_type
             FROM coaches c
             INNER JOIN schools s ON s.id = c.school_id
             WHERE UPPER(REPLACE(REPLACE(COALESCE(c.identity_number, ''), '-', ''), ' ', '')) = ?
             ORDER BY c.id ASC"
        );
        $existingCoachStmt->execute([$identityNumber]);
        $existingCoachRegistrations = $existingCoachStmt->fetchAll();

        if (count($existingCoachRegistrations) + count($selectedSchoolIds) > 2) {
            $errors[] = 'This coach can be registered with a maximum of 2 schools in total.';
        }
    }

    // All schools assigned to the same coach must have different school types,
    // whether registered now or during an earlier registration.
    if (!$errors && $existingCoachRegistrations) {
        $existingTypes = [];
        foreach ($existingCoachRegistrations as $existingCoachRegistration) {
            $existingType = trim((string) ($existingCoachRegistration['school_type'] ?? ''));
            if ($existingType !== '') {
                $existingTypes[] = strtolower($existingType);
            }
        }

        foreach ($selectedSchoolIds as $selectedSchoolId) {
            $selectedType = strtolower(trim((string) ($selectedSchools[$selectedSchoolId]['school_type'] ?? '')));
            if ($selectedType !== '' && in_array($selectedType, $existingTypes, true)) {
                $errors[] = 'The selected school type '
                    . (string) $selectedSchools[$selectedSchoolId]['school_type']
                    . ' is already used by this coach. The second school must be a different school type.';
                break;
            }
        }
    }

    if ($phone !== '' && $email !== '') {
        if (!$verificationServiceAvailable) {
            $verificationBypassed = true;
        } elseif ($verificationBypassRequested) {
            // Default option: skip phone verification and keep it pending.
            $verificationBypassed = true;
        } else {
            $smsCheck = registrationVerifyStaffCode('coach_sms_verification', $phone, $smsCode);
            if (!$smsCheck['success']) {
                $errors[] = (string) $smsCheck['message'];
            }
        }
    }

    $verificationChannel = $verificationBypassed
        ? 'bypass'
        : (string) ($_SESSION['coach_sms_verification']['delivery_channel'] ?? 'sms');

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock the selected school rows so two simultaneous registrations
            // cannot both assign a coach to the same school.
            $placeholders = implode(',', array_fill(0, count($selectedSchoolIds), '?'));
            $lockSchoolsStmt = $pdo->prepare(
                "SELECT id
                 FROM schools
                 WHERE id IN ($placeholders)
                 FOR UPDATE"
            );
            $lockSchoolsStmt->execute($selectedSchoolIds);
            $lockedSchoolIds = array_map('intval', $lockSchoolsStmt->fetchAll(PDO::FETCH_COLUMN));

            if (count($lockedSchoolIds) !== count($selectedSchoolIds)) {
                throw new RuntimeException('One of the selected schools is no longer available.');
            }

            // Re-check after obtaining the school locks.
            $schoolCoachStmt = $pdo->prepare(
                "SELECT c.school_id, c.full_name, s.school_name, s.school_code
                 FROM coaches c
                 INNER JOIN schools s ON s.id = c.school_id
                 WHERE c.school_id IN ($placeholders)
                 LIMIT 1"
            );
            $schoolCoachStmt->execute($selectedSchoolIds);
            $occupiedSchool = $schoolCoachStmt->fetch();

            if ($occupiedSchool) {
                throw new RuntimeException(
                    'School ' . (string) $occupiedSchool['school_name']
                    . ' (' . (string) $occupiedSchool['school_code']
                    . ') already has a registered coach.'
                );
            }

            $existingCoachStmt = $pdo->prepare(
                "SELECT c.id, c.school_id, s.school_type
                 FROM coaches c
                 INNER JOIN schools s ON s.id = c.school_id
                 WHERE UPPER(REPLACE(REPLACE(COALESCE(c.identity_number, ''), '-', ''), ' ', '')) = ?
                 FOR UPDATE"
            );
            $existingCoachStmt->execute([$identityNumber]);
            $existingRows = $existingCoachStmt->fetchAll();

            if (count($existingRows) + count($selectedSchoolIds) > 2) {
                throw new RuntimeException('This coach can be registered with a maximum of 2 schools in total.');
            }

            $allSchoolTypes = [];
            foreach ($existingRows as $existingRow) {
                $existingType = strtolower(trim((string) ($existingRow['school_type'] ?? '')));
                if ($existingType !== '') {
                    $allSchoolTypes[] = $existingType;
                }
            }
            foreach ($selectedSchoolIds as $selectedSchoolId) {
                $selectedType = strtolower(trim((string) ($selectedSchools[$selectedSchoolId]['school_type'] ?? '')));
                if ($selectedType !== '' && in_array($selectedType, $allSchoolTypes, true)) {
                    throw new RuntimeException('Every school assigned to the same coach must have a different school type.');
                }
                if ($selectedType !== '') {
                    $allSchoolTypes[] = $selectedType;
                }
            }

            // One verification link verifies all coach rows created by this submission.
            $emailVerification = createStaffEmailVerificationToken();

            $insertCoachStmt = $pdo->prepare(
                'INSERT INTO coaches
                 (school_id, full_name, identity_number, organization, certification, phone, email,
                  phone_verified_at, email_verified_at,
                  email_verification_token_hash, email_verification_expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)'
            );

            foreach ($selectedSchoolIds as $selectedSchoolId) {
                $insertCoachStmt->execute([
                    $selectedSchoolId,
                    $fullName,
                    $identityNumber,
                    $organization,
                    $certification,
                    $phone,
                    $email,
                    $verificationBypassed ? null : (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                    $emailVerification['token_hash'],
                    $emailVerification['expires_at'],
                ]);
            }

            $pdo->commit();
            registrationIdentityCacheInvalidate($identityNumber);
            registrationSchoolCacheInvalidate();
            unset($_SESSION['coach_sms_verification']);

            $verifyUrl = registrationAppBaseUrl()
                . '/register_coach.php?verify_email='
                . rawurlencode((string) $emailVerification['token']);

            $schoolNames = array_map(
                static fn (int $schoolId): string => (string) ($selectedSchools[$schoolId]['school_name'] ?? ''),
                $selectedSchoolIds
            );
            $schoolNames = array_values(array_filter($schoolNames, static fn (string $name): bool => $name !== ''));
            $accountName = $fullName . ($schoolNames ? ' — ' . implode(' & ', $schoolNames) : '');

            $emailResult = $verificationServiceAvailable
                ? registrationSendVerificationEmail(
                    $email,
                    $fullName,
                    'Esports Coach',
                    $accountName,
                    $verifyUrl
                )
                : [
                    'success' => false,
                    'message' => 'Verification service configuration is unavailable.',
                ];

            $schoolCount = count($selectedSchoolIds);
            $schoolWord = $schoolCount === 1 ? 'school' : 'schools';

            if ($verificationBypassed) {
                if ($emailResult['success']) {
                    setFlash(
                        'warning',
                        'Esports Coach registered successfully for ' . $schoolCount . ' ' . $schoolWord
                        . ' with contact verification pending because SMS and email code delivery failed. '
                        . 'A new email verification link was sent to ' . $email . '. Please complete verification later.'
                    );
                } else {
                    setFlash(
                        'warning',
                        'Esports Coach registered successfully for ' . $schoolCount . ' ' . $schoolWord
                        . ' with contact verification pending. SMS and email delivery are currently unavailable, so verification can be completed later.'
                    );
                }
            } else {
                $codeDeliveryText = $verificationChannel === 'email'
                    ? 'The verification code was confirmed using the email fallback'
                    : 'The verification code was confirmed by SMS';

                if ($emailResult['success']) {
                    setFlash(
                        'success',
                        'Esports Coach registered successfully for ' . $schoolCount . ' ' . $schoolWord
                        . '. ' . $codeDeliveryText . ' and an email verification link has been sent to '
                        . $email . '.'
                    );
                } else {
                    setFlash(
                        'warning',
                        'Esports Coach registered for ' . $schoolCount . ' ' . $schoolWord
                        . '. ' . $codeDeliveryText . ', but the final verification email could not be sent. Verification can be completed later.'
                    );
                }
            }

            if (!$emailResult['success']) {
                error_log('Esports Coach verification email failed for ' . $email . ': ' . (string) ($emailResult['message'] ?? 'Unknown error'));
            }

            redirect('register_coach.php' . ($requestedSchoolCode !== '' ? '?code=' . rawurlencode($requestedSchoolCode) : ''));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to register the esports coach at this time.';
            error_log('Esports Coach registration error: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Esports Coach Registration';
require 'includes/header.php';
?>

<?php if ($verificationView !== null): ?>
<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="card content-card">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="display-6 <?= $verificationView['success'] ? 'text-success' : 'text-danger' ?> mb-3">
                    <i class="bi <?= $verificationView['success'] ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
                </div>
                <h1 class="h3 mb-3"><?= e($verificationView['title']) ?></h1>
                <p class="text-secondary mb-4"><?= e($verificationView['message']) ?></p>
                <?php if (!empty($verificationView['email'])): ?>
                    <p><strong>Email:</strong> <?= e($verificationView['email']) ?></p>
                <?php endif; ?>
                <a class="btn btn-outline-primary" href="register_coach.php">Back to Registration</a>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="card content-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h3 mb-1">Esports Coach Registration</h1>
                <p class="text-secondary mb-3">
                    Register as the coach responsible for developing and guiding the school's esports team.
                </p>
                <p class="text-secondary mb-2">
                    Select one or two eligible schools, enter your email and mobile number, request a verification code
                    (SMS first with email fallback), complete the registration, then verify your email using the link sent after registration.
                </p>
                <div class="alert alert-info mb-4">
                    <strong>Coach school limit:</strong> One coach may register with a maximum of <strong>2 schools</strong>.
                    Both schools must be different and must have <strong>different school types</strong>
                    (for example, one SK and one SMK). A school that already has a coach cannot accept another coach.
                </div>

                <?php if (!$verificationSchemaReady): ?>
                    <div class="alert alert-warning">
                        Run <code>add_staff_contact_verification.sql</code> once before using SMS + email verification for coaches.
                    </div>
                <?php endif; ?>

                <?php if (!$verificationServiceAvailable): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Contact verification temporarily unavailable.</strong>
                        The <code>.env</code> file or required Brevo values could not be validated.
                        You can still register; verification will remain pending and can be completed later.
                    </div>
                <?php endif; ?>

                <?php if (!$schoolsAvailable): ?>
                    <div class="alert alert-warning">Please <a href="register_school.php">register a school</a> first.</div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <form method="post" class="row g-3 registration-uppercase" id="coachRegistrationForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="col-12">
                        <h2 class="h5 border-bottom pb-2 mb-1">School Assignment</h2>
                        <p class="small text-secondary mb-0">
                            Select School 1 and optionally School 2. Schools already assigned to a coach are disabled.
                        </p>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label" for="schoolSearch1">Search School 1</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="schoolSearch1" placeholder="School name or code" autocomplete="off" <?= !$schoolsAvailable ? 'disabled' : '' ?>>
                            <button class="btn btn-outline-secondary" type="button" id="clearSchoolSearch1" title="Clear school search" <?= !$schoolsAvailable ? 'disabled' : '' ?>><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="form-text" id="schoolSearchResult1">Type to filter School 1.</div>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label required" for="schoolId1">School 1</label>
                        <select
                            class="form-select"
                            name="school_id_1"
                            id="schoolId1" required
                            data-registration-school-select="1"
                            data-search-input="schoolSearch1"
                            data-status-target="schoolSearchResult1"
                            data-clear-button="clearSchoolSearch1"
                            data-role="coach"
                            data-disable-coached="1"
                            data-initial-code="<?= e($requestedSchoolCode) ?>"
                            data-initial-value="<?= (int) ($_POST['school_id_1'] ?? 0) ?>"
                            data-empty-label="SELECT SCHOOL 1"
                            <?= !$schoolsAvailable ? 'disabled' : '' ?>
                        >
                            <option value="">SELECT SCHOOL 1</option>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label" for="schoolSearch2">Search School 2</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="schoolSearch2" placeholder="Optional second school" autocomplete="off" <?= !$schoolsAvailable ? 'disabled' : '' ?>>
                            <button class="btn btn-outline-secondary" type="button" id="clearSchoolSearch2" title="Clear school search" <?= !$schoolsAvailable ? 'disabled' : '' ?>><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="form-text" id="schoolSearchResult2">Optional. Must be a different school type from School 1.</div>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label" for="schoolId2">School 2 <span class="text-secondary">(Optional)</span></label>
                        <select
                            class="form-select"
                            name="school_id_2"
                            id="schoolId2"
                            data-registration-school-select="1"
                            data-search-input="schoolSearch2"
                            data-status-target="schoolSearchResult2"
                            data-clear-button="clearSchoolSearch2"
                            data-role="coach"
                            data-disable-coached="1"
                            data-initial-code="<?= e('') ?>"
                            data-initial-value="<?= (int) ($_POST['school_id_2'] ?? 0) ?>"
                            data-empty-label="NO SECOND SCHOOL"
                            <?= !$schoolsAvailable ? 'disabled' : '' ?>
                        >
                            <option value="">NO SECOND SCHOOL</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <div class="form-text" id="schoolSelectionStatus">
                            You may register the coach for one school or two schools in a single submission.
                        </div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label required">Esports Coach Full Name</label>
                        <input class="form-control" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required">NRIC / Passport</label>
                        <input
                            class="form-control"
                            id="coachIdentityNumber"
                            name="identity_number"
                            data-registration-identity="1"
                            data-identity-role="coach"
                            data-status-target="coachIdentityStatus"
                            value="<?= e($_POST['identity_number'] ?? '') ?>"
                            required
                        >
                        <div class="form-text" id="coachIdentityStatus">USED TO ENFORCE THE 2-SCHOOL MAXIMUM. OTHER ROLES DO NOT BLOCK COACH REGISTRATION.</div>
                    </div>


                    <div class="col-md-6">
                        <label class="form-label">Organisation / Club</label>
                        <input class="form-control" name="organization" value="<?= e($_POST['organization'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Certification / Experience</label>
                        <input class="form-control" name="certification" value="<?= e($_POST['certification'] ?? '') ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label required" for="coachEmail">Email</label>
                        <input type="email" class="form-control" id="coachEmail" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                        <div class="form-text">Enter email before requesting a code. It is used automatically if SMS delivery fails.</div>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label required" for="coachPhone">Mobile Number</label>
                        <div class="input-group">
                            <input class="form-control" id="coachPhone" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="Example: 60123456789" inputmode="tel" required>
                            <button class="btn btn-outline-primary" type="button" id="sendSmsCodeButton" <?= !$verificationServiceAvailable ? 'disabled' : '' ?>>
                                <i class="bi bi-chat-dots me-1"></i>Send Code
                            </button>
                        </div>
                        <div class="form-text" id="smsCodeStatus">SMS is attempted first; email is used automatically if SMS fails.</div>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label required" for="smsCode" id="smsCodeLabel">Verification Code</label>
                        <input class="form-control" id="smsCode" name="sms_code" value="<?= e($_POST['sms_code'] ?? '') ?>" placeholder="<?= $verificationServiceAvailable ? '6-digit code' : 'Verify later' ?>" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" <?= $verificationServiceAvailable ? 'required' : 'disabled' ?>>
                        <div class="form-text" id="smsCodeHelp">Enter the code received by SMS or email. Code is valid for 10 minutes.</div>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" name="skip_phone_verification" id="skipPhoneVerification" <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['skip_phone_verification']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="skipPhoneVerification">SKIP PHONE NUMBER VERIFICATION</label>
                            <div class="form-text">CHECKED BY DEFAULT. UNCHECK TO VERIFY THE MOBILE NUMBER NOW.</div>
                        </div>
                    </div>

                    <input type="hidden" name="verification_bypass" id="verificationBypass" value="<?= e($_POST['verification_bypass'] ?? ($verificationServiceAvailable ? '0' : '1')) ?>">

                    <div class="col-12 <?= $verificationServiceAvailable ? 'd-none' : '' ?>" id="verificationBypassNotice">
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Verification service unavailable.</strong>
                            SMS and email code delivery both failed. You can continue registration now; contact verification will remain pending and can be completed later.
                        </div>
                    </div>

                    <div class="col-12 mt-4">
                        <button class="btn btn-danger" type="submit" <?= (!$schoolsAvailable || !$verificationSchemaReady) ? 'disabled' : '' ?>>
                            <i class="bi bi-headset me-2"></i>Register Coach
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/registration_common.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const schoolId1 = document.getElementById('schoolId1');
    const schoolId2 = document.getElementById('schoolId2');
    const schoolSelectionStatus = document.getElementById('schoolSelectionStatus');

    // School search is handled by the cached registration school API.

    const validateSchoolSelection = () => {
        if (!schoolId1 || !schoolId2 || !schoolSelectionStatus) return true;

        const first = schoolId1.selectedOptions[0];
        const second = schoolId2.selectedOptions[0];

        if (!first || !first.value) {
            schoolSelectionStatus.textContent = 'Select School 1.';
            schoolSelectionStatus.className = 'form-text text-secondary';
            return false;
        }

        if (!second || !second.value) {
            schoolSelectionStatus.textContent = 'One school selected. You may optionally add a second school of a different school type.';
            schoolSelectionStatus.className = 'form-text text-success';
            return true;
        }

        if (first.value === second.value) {
            schoolSelectionStatus.textContent = 'School 1 and School 2 must be different schools.';
            schoolSelectionStatus.className = 'form-text text-danger';
            return false;
        }

        const type1 = (first.dataset.schoolType || '').trim().toLowerCase();
        const type2 = (second.dataset.schoolType || '').trim().toLowerCase();

        if (!type1 || !type2) {
            schoolSelectionStatus.textContent = 'Both selected schools must have a school type.';
            schoolSelectionStatus.className = 'form-text text-danger';
            return false;
        }

        if (type1 === type2) {
            schoolSelectionStatus.textContent = 'School 2 must be a different school type from School 1.';
            schoolSelectionStatus.className = 'form-text text-danger';
            return false;
        }

        schoolSelectionStatus.textContent = 'Two valid schools selected with different school types.';
        schoolSelectionStatus.className = 'form-text text-success';
        return true;
    };
    schoolId1?.addEventListener('change', validateSchoolSelection);
    schoolId2?.addEventListener('change', validateSchoolSelection);

    const phoneInput = document.getElementById('coachPhone');
    const emailInput = document.getElementById('coachEmail');
    const nameInput = document.querySelector('#coachRegistrationForm input[name="full_name"]');
    const sendButton = document.getElementById('sendSmsCodeButton');
    const smsCode = document.getElementById('smsCode');
    const smsCodeLabel = document.getElementById('smsCodeLabel');
    const smsCodeHelp = document.getElementById('smsCodeHelp');
    const status = document.getElementById('smsCodeStatus');
    const bypassInput = document.getElementById('verificationBypass');
    const bypassNotice = document.getElementById('verificationBypassNotice');
    const csrfToken = document.querySelector('#coachRegistrationForm input[name="csrf_token"]');
    const verificationServiceAvailable = <?= $verificationServiceAvailable ? 'true' : 'false' ?>;
    const skipPhoneVerification = document.getElementById('skipPhoneVerification');
    let countdownTimer = null;

    const setBypassMode = (enabled) => {
        const effectiveBypass = enabled || !verificationServiceAvailable;

        if (bypassInput) bypassInput.value = effectiveBypass ? '1' : '0';
        bypassNotice?.classList.toggle('d-none', !effectiveBypass);

        if (sendButton) {
            sendButton.disabled = effectiveBypass || !verificationServiceAvailable;
            sendButton.innerHTML = effectiveBypass
                ? (verificationServiceAvailable
                    ? '<i class="bi bi-skip-forward me-1"></i>Verification Skipped'
                    : '<i class="bi bi-slash-circle me-1"></i>Verification Unavailable')
                : '<i class="bi bi-chat-dots me-1"></i>Send Code';
        }

        if (smsCode) {
            smsCode.required = !effectiveBypass;
            smsCode.disabled = !verificationServiceAvailable;

            if (effectiveBypass) {
                smsCode.value = '';
                smsCode.placeholder = 'Verify later';
            } else {
                smsCode.placeholder = '6-digit code';
            }
        }

        if (smsCodeLabel) {
            smsCodeLabel.classList.toggle('required', !effectiveBypass);
            smsCodeLabel.textContent = effectiveBypass ? 'Verification Code (Optional)' : 'Verification Code';
        }

        if (smsCodeHelp) {
            smsCodeHelp.textContent = !verificationServiceAvailable
                ? 'Verification code is disabled because the service configuration is unavailable.'
                : (effectiveBypass
                    ? 'Code is not required because both delivery methods failed.'
                    : 'Enter the code received by SMS or email. Code is valid for 10 minutes.');
        }
    };

    const setCountdown = (seconds) => {
        if (!sendButton) return;
        if (countdownTimer) clearInterval(countdownTimer);
        let remaining = Math.max(0, Number(seconds) || 0);

        const render = () => {
            if (remaining > 0) {
                sendButton.disabled = true;
                sendButton.innerHTML = `<i class="bi bi-clock me-1"></i>Resend in ${remaining}s`;
                remaining -= 1;
            } else {
                if (countdownTimer) clearInterval(countdownTimer);
                countdownTimer = null;
                sendButton.disabled = !verificationServiceAvailable;
                sendButton.innerHTML = verificationServiceAvailable
                    ? '<i class="bi bi-chat-dots me-1"></i>Send Code'
                    : '<i class="bi bi-slash-circle me-1"></i>Verification Unavailable';
            }
        };

        render();
        if (seconds > 0) countdownTimer = setInterval(render, 1000);
    };

    sendButton?.addEventListener('click', async () => {
        const phone = phoneInput?.value.trim() || '';
        const email = emailInput?.value.trim() || '';
        const fullName = nameInput?.value.trim() || '';

        if (!phone) {
            status.textContent = 'Enter the mobile number first.';
            status.className = 'form-text text-danger';
            phoneInput?.focus();
            return;
        }
        if (!email || !emailInput?.checkValidity()) {
            status.textContent = 'Enter a valid email address first. It is required as the backup delivery method if SMS fails.';
            status.className = 'form-text text-danger';
            emailInput?.focus();
            return;
        }

        sendButton.disabled = true;
        sendButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Sending...';
        status.textContent = 'Sending verification code...';
        status.className = 'form-text text-secondary';

        try {
            const body = new URLSearchParams();
            body.set('action', 'send_coach_sms_code');
            body.set('phone', phone);
            body.set('email', email);
            body.set('full_name', fullName);
            body.set('csrf_token', csrfToken?.value || '');

            const response = await fetch('register_coach.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                },
                body: body.toString(),
                cache: 'no-store',
            });

            const data = await response.json();

            if ((!response.ok || !data.success) && data.bypass_allowed) {
                setBypassMode(true);
                status.textContent = data.message || 'Verification services are unavailable. You may register now and verify later.';
                status.className = 'form-text text-warning';
                setCountdown(data.retry_after || 60);
                return;
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to send verification code.');
            }

            setBypassMode(false);
            if (smsCode) {
                smsCode.value = '';
                smsCode.focus();
            }
            status.textContent = data.message || 'Verification code sent.';
            status.className = 'form-text text-success';
            setCountdown(data.retry_after || 60);
        } catch (error) {
            status.textContent = error.message || 'Unable to send verification code.';
            status.className = 'form-text text-danger';
            sendButton.disabled = false;
            sendButton.innerHTML = '<i class="bi bi-chat-dots me-1"></i>Send Code';
        }
    });

    const resetVerificationAfterContactChange = () => {
        setBypassMode(Boolean(skipPhoneVerification?.checked) || !verificationServiceAvailable);

        if (status) {
            status.textContent = verificationServiceAvailable
                ? 'Contact details changed. Request a new 6-digit verification code.'
                : 'Verification service is unavailable. Registration can continue with verification pending.';
            status.className = verificationServiceAvailable
                ? 'form-text text-secondary'
                : 'form-text text-warning';
        }
    };

    phoneInput?.addEventListener('input', resetVerificationAfterContactChange);
    emailInput?.addEventListener('input', resetVerificationAfterContactChange);

    skipPhoneVerification?.addEventListener('change', () => {
        setBypassMode(skipPhoneVerification.checked || !verificationServiceAvailable);
        if (status) {
            status.textContent = skipPhoneVerification.checked
                ? 'PHONE VERIFICATION WILL BE SKIPPED. YOU CAN VERIFY LATER.'
                : 'PHONE VERIFICATION ENABLED. REQUEST A 6-DIGIT CODE.';
            status.className = skipPhoneVerification.checked ? 'form-text text-warning' : 'form-text text-secondary';
        }
    });

    setBypassMode(Boolean(skipPhoneVerification?.checked) || !verificationServiceAvailable);

    document.getElementById('coachRegistrationForm')?.addEventListener('submit', (event) => {
        if (!validateSchoolSelection()) {
            event.preventDefault();
            schoolSelectionStatus?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
});
</script>

<?php require 'includes/footer.php'; ?>
