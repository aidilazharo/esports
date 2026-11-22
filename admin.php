<?php

declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/includes/admin_auth.php';

if (isset($_GET['logout'])) {
    adminAccessLogout();
    redirect('login.php');
}
requireAdminAccess('Administrator');

const ADMIN_PAGE_SIZE = 10;

function adminUrl(array $changes = []): string
{
    $query = $_GET;
    unset($query['logout'], $query['edit_id']);

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'admin.php' . ($query ? '?' . http_build_query($query) : '');
}

function adminTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function adminNullable(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function adminColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function adminRecordExists(PDO $pdo, string $table, int $id): bool
{
    $allowed = ['schools', 'students', 'teachers', 'coaches', 'guardians'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return (bool) $stmt->fetchColumn();
}

$entities = [
    'schools' => 'Schools',
    'students' => 'Students',
    'guardians' => 'Parents / Guardians',
    'teachers' => 'Teachers',
    'coaches' => 'Coaches',
];
$entitySingular = [
    'schools' => 'School',
    'students' => 'Student',
    'guardians' => 'Parent / Guardian',
    'teachers' => 'Teacher',
    'coaches' => 'Coach',
];
$entity = (string) ($_GET['entity'] ?? $_POST['entity'] ?? 'schools');
if (!isset($entities[$entity])) {
    $entity = 'schools';
}

$schools = getSchools($pdo);

$schoolHasReferrer = adminColumnExists($pdo, 'schools', 'referrer');
$schoolHasLogo = adminColumnExists($pdo, 'schools', 'school_logo');
$schoolHasImage1 = adminColumnExists($pdo, 'schools', 'school_image_1');
$schoolHasImage2 = adminColumnExists($pdo, 'schools', 'school_image_2');

$studentHasEthnicity = adminColumnExists($pdo, 'students', 'ethnicity');
$studentHasMembershipExpiry = adminColumnExists($pdo, 'students', 'membership_next_due_at');
$studentHasAccountVerification = adminColumnExists($pdo, 'students', 'account_verified_at');

$guardianHasPhoneVerification = adminColumnExists($pdo, 'guardians', 'phone_verified_at');
$guardianHasEmailVerification = adminColumnExists($pdo, 'guardians', 'email_verified_at');

$teacherHasPhoneVerification = adminColumnExists($pdo, 'teachers', 'phone_verified_at');
$teacherHasEmailVerification = adminColumnExists($pdo, 'teachers', 'email_verified_at');

$coachHasPhoneVerification = adminColumnExists($pdo, 'coaches', 'phone_verified_at');
$coachHasEmailVerification = adminColumnExists($pdo, 'coaches', 'email_verified_at');

$allowedEthnicities = [
    'Malay',
    'Chinese',
    'Indian',
    'Orang Asli',
    'Other',
];

$allowedMembershipStatuses = [
    'pending_registration',
    'pending_payment',
    'registered',
    'pending_activation',
    'active',
    'inactive',
    'cancelled',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), ['create', 'save', 'delete'], true)) {
    verifyCsrf();
    $action = (string) $_POST['action'];
    $recordId = (int) ($_POST['record_id'] ?? 0);

    if ($action !== 'create' && ($recordId <= 0 || !adminRecordExists($pdo, $entity, $recordId))) {
        $errors[] = 'The selected record no longer exists.';
    }

    if (!$errors && $action === 'create') {
        if ($entity !== 'guardians') {
            $errors[] = 'Create from the Admin Manager is currently available for parents / guardians only.';
        } else {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $identityNumber = trim((string) ($_POST['identity_number'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($fullName === '') $errors[] = 'Parent / guardian name is required.';
            if ($identityNumber === '') $errors[] = 'NRIC / Passport is required.';
            if ($phone === '') $errors[] = 'Mobile number is required.';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
            if (adminTextLength($fullName) > 255) $errors[] = 'Parent / guardian name must not exceed 255 characters.';
            if (adminTextLength($identityNumber) > 80) $errors[] = 'NRIC / Passport must not exceed 80 characters.';
            if (adminTextLength($phone) > 30) $errors[] = 'Mobile number must not exceed 30 characters.';
            if (adminTextLength($email) > 255) $errors[] = 'Email must not exceed 255 characters.';

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'INSERT INTO guardians (guardian_number, full_name, identity_number, phone, email)
                         VALUES (NULL, :full_name, :identity_number, :phone, :email)'
                    );
                    $stmt->execute([
                        'full_name' => $fullName,
                        'identity_number' => $identityNumber,
                        'phone' => $phone,
                        'email' => $email,
                    ]);

                    $guardianId = (int) $pdo->lastInsertId();
                    $guardianNumber = 'PG-' . str_pad((string) $guardianId, 6, '0', STR_PAD_LEFT);
                    $pdo->prepare('UPDATE guardians SET guardian_number = :guardian_number WHERE id = :id')
                        ->execute(['guardian_number' => $guardianNumber, 'id' => $guardianId]);
                    $pdo->commit();

                    setFlash('success', 'Parent / guardian ' . $guardianNumber . ' created successfully.');
                    redirect(adminUrl(['entity' => 'guardians', 'edit_id' => null]));
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $exception->getCode() === '23000'
                        ? 'This parent / guardian NRIC / Passport is already registered.'
                        : 'Unable to create the parent / guardian record. ' . $exception->getMessage();
                }
            }
        }
    }

    if (!$errors && $action === 'save') {
        try {
            if ($entity === 'schools') {
                $schoolCode = strtoupper(trim((string) ($_POST['school_code'] ?? '')));
                $schoolName = trim((string) ($_POST['school_name'] ?? ''));
                $schoolType = trim((string) ($_POST['school_type'] ?? ''));
                $state = trim((string) ($_POST['state'] ?? ''));
                $district = trim((string) ($_POST['district'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));
                $contactName = trim((string) ($_POST['contact_name'] ?? ''));
                $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
                $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
                $referrer = $schoolHasReferrer
                    ? strtoupper(trim((string) ($_POST['referrer'] ?? '')))
                    : '';

                if ($schoolCode === '') $errors[] = 'School code is required.';
                if ($schoolName === '') $errors[] = 'School name is required.';
                if ($state === '') $errors[] = 'State is required.';
                if (adminTextLength($schoolCode) > 30) $errors[] = 'School code must not exceed 30 characters.';
                if (adminTextLength($schoolName) > 200) $errors[] = 'School name must not exceed 200 characters.';
                if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Contact email is invalid.';

                if ($schoolHasReferrer && $referrer !== '') {
                    if ($referrer === $schoolCode) {
                        $errors[] = 'A school cannot refer itself.';
                    } else {
                        $referrerStmt = $pdo->prepare(
                            'SELECT 1 FROM schools WHERE school_code = :school_code LIMIT 1'
                        );
                        $referrerStmt->execute(['school_code' => $referrer]);

                        if (!$referrerStmt->fetchColumn()) {
                            $errors[] = 'Referrer school code was not found.';
                        }
                    }
                }

                if (!$errors) {
                    $schoolUpdateSql =
                        'UPDATE schools SET school_code = :school_code, school_name = :school_name,
                         school_type = :school_type, state = :state, district = :district, address = :address,
                         contact_name = :contact_name, contact_phone = :contact_phone, contact_email = :contact_email';

                    if ($schoolHasReferrer) {
                        $schoolUpdateSql .= ', referrer = :referrer';
                    }

                    $schoolUpdateSql .= ' WHERE id = :id';

                    $schoolUpdateParams = [
                        'school_code' => $schoolCode,
                        'school_name' => $schoolName,
                        'school_type' => adminNullable($schoolType),
                        'state' => $state,
                        'district' => adminNullable($district),
                        'address' => adminNullable($address),
                        'contact_name' => adminNullable($contactName),
                        'contact_phone' => adminNullable($contactPhone),
                        'contact_email' => adminNullable($contactEmail),
                        'id' => $recordId,
                    ];

                    if ($schoolHasReferrer) {
                        $schoolUpdateParams['referrer'] = adminNullable($referrer);
                    }

                    $stmt = $pdo->prepare($schoolUpdateSql);
                    $stmt->execute($schoolUpdateParams);
                }
            } elseif ($entity === 'students') {
                $schoolId = (int) ($_POST['school_id'] ?? 0);
                $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $identityNumber = trim((string) ($_POST['identity_number'] ?? ''));
                $gender = trim((string) ($_POST['gender'] ?? ''));
                $ethnicity = $studentHasEthnicity
                    ? trim((string) ($_POST['ethnicity'] ?? ''))
                    : '';
                $dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? ''));
                $className = trim((string) ($_POST['class_name'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $membershipStatus = trim((string) ($_POST['membership_status'] ?? ''));

                if ($schoolId <= 0) $errors[] = 'School is required.';
                if ($studentNumber === '') $errors[] = 'Student number is required.';
                if ($fullName === '') $errors[] = 'Student name is required.';
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Student email is invalid.';
                if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) $errors[] = 'Gender is invalid.';
                if ($studentHasEthnicity && $ethnicity !== '' && !in_array($ethnicity, $allowedEthnicities, true)) {
                    $errors[] = 'Ethnicity is invalid.';
                }
                if (!in_array($membershipStatus, $allowedMembershipStatuses, true)) $errors[] = 'Membership status is invalid.';

                if (!$errors) {
                    $studentUpdateSql =
                        'UPDATE students SET school_id = :school_id, student_number = :student_number,
                         full_name = :full_name, identity_number = :identity_number, gender = :gender';

                    if ($studentHasEthnicity) {
                        $studentUpdateSql .= ', ethnicity = :ethnicity';
                    }

                    $studentUpdateSql .=
                        ', date_of_birth = :date_of_birth, class_name = :class_name, phone = :phone,
                         email = :email, membership_status = :membership_status WHERE id = :id';

                    $studentUpdateParams = [
                        'school_id' => $schoolId,
                        'student_number' => $studentNumber,
                        'full_name' => $fullName,
                        'identity_number' => adminNullable($identityNumber),
                        'gender' => adminNullable($gender),
                        'date_of_birth' => adminNullable($dateOfBirth),
                        'class_name' => adminNullable($className),
                        'phone' => adminNullable($phone),
                        'email' => adminNullable($email),
                        'membership_status' => $membershipStatus,
                        'id' => $recordId,
                    ];

                    if ($studentHasEthnicity) {
                        $studentUpdateParams['ethnicity'] = adminNullable($ethnicity);
                    }

                    $stmt = $pdo->prepare($studentUpdateSql);
                    $stmt->execute($studentUpdateParams);
                }
            } elseif ($entity === 'guardians') {
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $identityNumber = trim((string) ($_POST['identity_number'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));

                if ($fullName === '') $errors[] = 'Parent / guardian name is required.';
                if ($identityNumber === '') $errors[] = 'NRIC / Passport is required.';
                if ($phone === '') $errors[] = 'Mobile number is required.';
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
                if (adminTextLength($fullName) > 255) $errors[] = 'Parent / guardian name must not exceed 255 characters.';
                if (adminTextLength($identityNumber) > 80) $errors[] = 'NRIC / Passport must not exceed 80 characters.';
                if (adminTextLength($phone) > 30) $errors[] = 'Mobile number must not exceed 30 characters.';
                if (adminTextLength($email) > 255) $errors[] = 'Email must not exceed 255 characters.';

                if (!$errors) {
                    $currentStmt = $pdo->prepare('SELECT * FROM guardians WHERE id = :id LIMIT 1');
                    $currentStmt->execute(['id' => $recordId]);
                    $currentGuardian = $currentStmt->fetch();

                    if (!$currentGuardian) {
                        $errors[] = 'The selected parent / guardian no longer exists.';
                    } else {
                        $guardianUpdateSql =
                            'UPDATE guardians SET full_name = :full_name, identity_number = :identity_number,
                             phone = :phone, email = :email';
                        $guardianUpdateParams = [
                            'full_name' => $fullName,
                            'identity_number' => $identityNumber,
                            'phone' => $phone,
                            'email' => $email,
                            'id' => $recordId,
                        ];

                        if ($guardianHasPhoneVerification) {
                            $guardianUpdateSql .= ', phone_verified_at = :phone_verified_at';
                            $guardianUpdateParams['phone_verified_at'] =
                                trim((string) ($currentGuardian['phone'] ?? '')) === $phone
                                    ? ($currentGuardian['phone_verified_at'] ?? null)
                                    : null;
                        }

                        if ($guardianHasEmailVerification) {
                            $guardianUpdateSql .= ', email_verified_at = :email_verified_at';
                            $guardianUpdateParams['email_verified_at'] =
                                strtolower(trim((string) ($currentGuardian['email'] ?? ''))) === strtolower($email)
                                    ? ($currentGuardian['email_verified_at'] ?? null)
                                    : null;
                        }

                        $guardianUpdateSql .= ' WHERE id = :id';
                        $stmt = $pdo->prepare($guardianUpdateSql);
                        $stmt->execute($guardianUpdateParams);
                    }
                }
            } elseif ($entity === 'teachers') {
                $schoolId = (int) ($_POST['school_id'] ?? 0);
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $identityNumber = trim((string) ($_POST['identity_number'] ?? ''));
                $position = trim((string) ($_POST['position'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));

                if ($schoolId <= 0) $errors[] = 'School is required.';
                if ($fullName === '') $errors[] = 'Teacher name is required.';
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Teacher email is invalid.';

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'UPDATE teachers SET school_id = :school_id, full_name = :full_name,
                         identity_number = :identity_number, position = :position, phone = :phone,
                         email = :email WHERE id = :id'
                    );
                    $stmt->execute([
                        'school_id' => $schoolId,
                        'full_name' => $fullName,
                        'identity_number' => adminNullable($identityNumber),
                        'position' => adminNullable($position),
                        'phone' => adminNullable($phone),
                        'email' => adminNullable($email),
                        'id' => $recordId,
                    ]);
                }
            } elseif ($entity === 'coaches') {
                $schoolId = (int) ($_POST['school_id'] ?? 0);
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $identityNumber = trim((string) ($_POST['identity_number'] ?? ''));
                $organization = trim((string) ($_POST['organization'] ?? ''));
                $certification = trim((string) ($_POST['certification'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));

                if ($schoolId <= 0) $errors[] = 'School is required.';
                if ($fullName === '') $errors[] = 'Coach name is required.';
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Coach email is invalid.';

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'UPDATE coaches SET school_id = :school_id, full_name = :full_name,
                         identity_number = :identity_number, organization = :organization,
                         certification = :certification, phone = :phone, email = :email WHERE id = :id'
                    );
                    $stmt->execute([
                        'school_id' => $schoolId,
                        'full_name' => $fullName,
                        'identity_number' => adminNullable($identityNumber),
                        'organization' => adminNullable($organization),
                        'certification' => adminNullable($certification),
                        'phone' => adminNullable($phone),
                        'email' => adminNullable($email),
                        'id' => $recordId,
                    ]);
                }
            }

            if (!$errors) {
                setFlash('success', $entitySingular[$entity] . ' record updated successfully.');
                redirect(adminUrl(['entity' => $entity, 'edit_id' => null]));
            }
        } catch (PDOException $exception) {
            $errors[] = $exception->getCode() === '23000'
                ? ($entity === 'guardians'
                    ? 'The update conflicts with an existing parent / guardian NRIC / Passport.'
                    : 'The update conflicts with an existing school code or student number.')
                : 'Unable to update the record. ' . $exception->getMessage();
        }
    }

    if (!$errors && $action === 'delete') {
        try {
            if ($entity === 'students') {
                $stmt = $pdo->prepare('SELECT stripe_subscription_id, stripe_subscription_status FROM students WHERE id = :id');
                $stmt->execute(['id' => $recordId]);
                $stripeDetails = $stmt->fetch();
                $subscriptionId = trim((string) ($stripeDetails['stripe_subscription_id'] ?? ''));
                $subscriptionStatus = strtolower(trim((string) ($stripeDetails['stripe_subscription_status'] ?? '')));

                if ($subscriptionId !== '' && !in_array($subscriptionStatus, ['canceled', 'cancelled', 'incomplete_expired'], true)) {
                    $errors[] = 'This student still has a legacy Stripe subscription. Cancel it in Stripe before deleting the student.';
                }
            }

            if ($entity === 'schools') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM students
                     WHERE school_id = :school_id
                       AND stripe_subscription_id IS NOT NULL
                       AND stripe_subscription_id <> ''
                       AND COALESCE(stripe_subscription_status, '') NOT IN ('canceled', 'cancelled', 'incomplete_expired')"
                );
                $stmt->execute(['school_id' => $recordId]);
                $activeStripeStudents = (int) $stmt->fetchColumn();
                if ($activeStripeStudents > 0) {
                    $errors[] = 'This school contains ' . $activeStripeStudents . ' student(s) with legacy Stripe subscriptions. Cancel those subscriptions in Stripe before deleting the school.';
                }
            }

            if (!$errors) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("DELETE FROM {$entity} WHERE id = :id");
                $stmt->execute(['id' => $recordId]);
                $pdo->commit();

                setFlash('success', $entitySingular[$entity] . ' record deleted successfully.');
                redirect(adminUrl(['entity' => $entity, 'edit_id' => null]));
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to delete the record. ' . $exception->getMessage();
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$schoolFilter = (int) ($_GET['school_id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
$fromSql = '';
$selectSql = '';
$orderSql = '';

if ($entity === 'schools') {
    $fromSql = 'FROM schools s';
    $selectSql = 'SELECT s.*,
        (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id) AS student_count,
        (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count,
        (SELECT COUNT(*) FROM coaches c WHERE c.school_id = s.id) AS coach_count';
    $orderSql = 'ORDER BY s.school_name';
    if ($search !== '') {
        if ($schoolHasReferrer) {
            $where[] = '(s.school_name LIKE :q1 OR s.school_code LIKE :q2 OR s.state LIKE :q3 OR s.district LIKE :q4 OR s.referrer LIKE :q5)';
            $like = '%' . $search . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        } else {
            $where[] = '(s.school_name LIKE :q1 OR s.school_code LIKE :q2 OR s.state LIKE :q3 OR s.district LIKE :q4)';
            $like = '%' . $search . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
    }
} elseif ($entity === 'students') {
    $fromSql = 'FROM students r JOIN schools s ON s.id = r.school_id';
    $selectSql = "SELECT r.*, s.school_name, s.school_code,
        (SELECT MAX(p.paid_at)
         FROM payments p
         WHERE p.student_id = r.id
           AND p.payment_type = 'annual_membership'
           AND p.status = 'paid') AS last_annual_membership_paid_at";
    $orderSql = 'ORDER BY r.created_at DESC';
    if ($search !== '') {
        if ($studentHasEthnicity) {
            $where[] = '(r.full_name LIKE :q1 OR r.student_number LIKE :q2 OR r.email LIKE :q3 OR r.identity_number LIKE :q4 OR s.school_name LIKE :q5 OR s.school_code LIKE :q6 OR r.ethnicity LIKE :q7 OR r.gender LIKE :q8)';
            $like = '%' . $search . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like, 'q7' => $like, 'q8' => $like];
        } else {
            $where[] = '(r.full_name LIKE :q1 OR r.student_number LIKE :q2 OR r.email LIKE :q3 OR r.identity_number LIKE :q4 OR s.school_name LIKE :q5 OR s.school_code LIKE :q6)';
            $like = '%' . $search . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
        }
    }
    if ($schoolFilter > 0) {
        $where[] = 'r.school_id = :school_id';
        $params['school_id'] = $schoolFilter;
    }
    if (in_array($statusFilter, $allowedMembershipStatuses, true)) {
        $where[] = 'r.membership_status = :membership_status';
        $params['membership_status'] = $statusFilter;
    }
} elseif ($entity === 'guardians') {
    $fromSql = 'FROM guardians r';
    $selectSql = "SELECT r.*,
        (SELECT COUNT(*)
         FROM student_guardians sg
         WHERE sg.guardian_id = r.id) AS student_count,
        (SELECT GROUP_CONCAT(
                    CONCAT(st.student_number, ' — ', st.full_name, ' · ', sg.relationship,
                           ' · ', COALESCE(s.school_code, ''))
                    ORDER BY st.full_name SEPARATOR '||')
         FROM student_guardians sg
         JOIN students st ON st.id = sg.student_id
         LEFT JOIN schools s ON s.id = st.school_id
         WHERE sg.guardian_id = r.id) AS children_summary";
    $orderSql = 'ORDER BY r.created_at DESC';
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(r.full_name LIKE :q1
                     OR r.guardian_number LIKE :q2
                     OR r.identity_number LIKE :q3
                     OR r.phone LIKE :q4
                     OR r.email LIKE :q5
                     OR EXISTS (
                         SELECT 1
                         FROM student_guardians sg2
                         JOIN students st2 ON st2.id = sg2.student_id
                         LEFT JOIN schools s2 ON s2.id = st2.school_id
                         WHERE sg2.guardian_id = r.id
                           AND (st2.full_name LIKE :q6 OR st2.student_number LIKE :q7 OR s2.school_name LIKE :q8 OR s2.school_code LIKE :q9)
                     ))";
        $params += [
            'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like,
            'q6' => $like, 'q7' => $like, 'q8' => $like, 'q9' => $like,
        ];
    }
    if ($schoolFilter > 0) {
        $where[] = "EXISTS (
            SELECT 1
            FROM student_guardians sgf
            JOIN students stf ON stf.id = sgf.student_id
            WHERE sgf.guardian_id = r.id AND stf.school_id = :school_id
        )";
        $params['school_id'] = $schoolFilter;
    }
} elseif ($entity === 'teachers') {
    $fromSql = 'FROM teachers r JOIN schools s ON s.id = r.school_id';
    $selectSql = 'SELECT r.*, s.school_name, s.school_code';
    $orderSql = 'ORDER BY r.created_at DESC';
    if ($search !== '') {
        $where[] = '(r.full_name LIKE :q1 OR r.identity_number LIKE :q2 OR r.position LIKE :q3 OR r.email LIKE :q4 OR s.school_name LIKE :q5 OR s.school_code LIKE :q6)';
        $like = '%' . $search . '%';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
    }
    if ($schoolFilter > 0) {
        $where[] = 'r.school_id = :school_id';
        $params['school_id'] = $schoolFilter;
    }
} else {
    $fromSql = 'FROM coaches r JOIN schools s ON s.id = r.school_id';
    $selectSql = 'SELECT r.*, s.school_name, s.school_code';
    $orderSql = 'ORDER BY r.created_at DESC';
    if ($search !== '') {
        $where[] = '(r.full_name LIKE :q1 OR r.identity_number LIKE :q2 OR r.organization LIKE :q3 OR r.certification LIKE :q4 OR r.email LIKE :q5 OR s.school_name LIKE :q6 OR s.school_code LIKE :q7)';
        $like = '%' . $search . '%';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like, 'q7' => $like];
    }
    if ($schoolFilter > 0) {
        $where[] = 'r.school_id = :school_id';
        $params['school_id'] = $schoolFilter;
    }
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / ADMIN_PAGE_SIZE));
$page = min($page, $totalPages);
$offset = ($page - 1) * ADMIN_PAGE_SIZE;

$listStmt = $pdo->prepare($selectSql . ' ' . $fromSql . $whereSql . ' ' . $orderSql . ' LIMIT :limit OFFSET :offset');
foreach ($params as $name => $value) {
    $listStmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', ADMIN_PAGE_SIZE, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$records = $listStmt->fetchAll();

$editId = max(0, (int) ($_GET['edit_id'] ?? 0));
$editRecord = null;
$guardianChildren = [];
if ($editId > 0) {
    if ($entity === 'schools') {
        $stmt = $pdo->prepare('SELECT * FROM schools WHERE id = :id');
    } elseif ($entity === 'guardians') {
        $stmt = $pdo->prepare('SELECT * FROM guardians WHERE id = :id');
    } else {
        $stmt = $pdo->prepare("SELECT r.*, s.school_name, s.school_code FROM {$entity} r JOIN schools s ON s.id = r.school_id WHERE r.id = :id");
    }
    $stmt->execute(['id' => $editId]);
    $editRecord = $stmt->fetch() ?: null;

    if ($editRecord && $entity === 'guardians') {
        $childStmt = $pdo->prepare(
            'SELECT st.id, st.student_number, st.full_name, sg.relationship, sg.is_primary,
                    s.school_name, s.school_code
             FROM student_guardians sg
             JOIN students st ON st.id = sg.student_id
             LEFT JOIN schools s ON s.id = st.school_id
             WHERE sg.guardian_id = :guardian_id
             ORDER BY st.full_name'
        );
        $childStmt->execute(['guardian_id' => $editId]);
        $guardianChildren = $childStmt->fetchAll();
    }
}

$showingFrom = $totalRecords > 0 ? $offset + 1 : 0;
$showingTo = min($offset + ADMIN_PAGE_SIZE, $totalRecords);
$pageTitle = 'Admin Manager';
require 'includes/header.php';
?>

<div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1">Admin Manager</h1>
        <p class="text-secondary mb-0">Manage schools, annual student memberships, parents / guardians, teachers and coaches, including verification and JPN reporting data.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="programme_report" class="btn btn-outline-primary">
            <i class="bi bi-file-earmark-bar-graph me-2"></i>Programme Report
        </a>
        <a href="admin.php?logout=1" class="btn btn-outline-danger">
            <i class="bi bi-box-arrow-right me-2"></i>Admin Logout
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-pills gap-2 mb-4">
    <?php foreach ($entities as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $entity === $key ? 'active' : '' ?>" href="admin.php?entity=<?= e($key) ?>">
                <?= e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($entity === 'guardians' && !$editRecord): ?>
<div class="card content-card mb-4 border-success">
    <div class="card-header bg-success-subtle border-0 p-4">
        <h2 class="h5 mb-1">Add Parent / Guardian</h2>
        <div class="small text-secondary">Create a guardian account. The guardian number is generated automatically.</div>
    </div>
    <div class="card-body p-4">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="entity" value="guardians">

            <div class="col-md-6">
                <label class="form-label required">Full Name</label>
                <input class="form-control" name="full_name" maxlength="255" value="<?= e($_POST['action'] ?? '') === 'create' ? e($_POST['full_name'] ?? '') : '' ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label required">NRIC / Passport</label>
                <input class="form-control" name="identity_number" maxlength="80" value="<?= e($_POST['action'] ?? '') === 'create' ? e($_POST['identity_number'] ?? '') : '' ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label required">Mobile Number</label>
                <input class="form-control" name="phone" maxlength="30" inputmode="tel" value="<?= e($_POST['action'] ?? '') === 'create' ? e($_POST['phone'] ?? '') : '' ?>" required>
                <div class="form-text">New records start as unverified until verified through the registration flow.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label required">Email</label>
                <input type="email" class="form-control" name="email" maxlength="255" value="<?= e($_POST['action'] ?? '') === 'create' ? e($_POST['email'] ?? '') : '' ?>" required>
            </div>
            <div class="col-12 mt-3">
                <button class="btn btn-success" type="submit"><i class="bi bi-person-plus me-2"></i>Create Parent / Guardian</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($editRecord): ?>
<div class="card content-card mb-4 border-primary">
    <div class="card-header bg-primary-subtle border-0 p-4 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h5 mb-1">Edit <?= e($entitySingular[$entity]) ?></h2>
            <div class="small text-secondary">Record ID: <?= (int) $editRecord['id'] ?></div>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(adminUrl(['edit_id' => null])) ?>"><i class="bi bi-x-lg me-1"></i>Close</a>
    </div>
    <div class="card-body p-4">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="entity" value="<?= e($entity) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $editRecord['id'] ?>">

            <?php if ($entity === 'schools'): ?>
                <div class="col-md-4"><label class="form-label required">School Code</label><input class="form-control" name="school_code" maxlength="30" value="<?= e($editRecord['school_code']) ?>" required></div>
                <div class="col-md-8"><label class="form-label required">School Name</label><input class="form-control" name="school_name" maxlength="200" value="<?= e($editRecord['school_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">School Type</label><input class="form-control" name="school_type" maxlength="100" value="<?= e($editRecord['school_type']) ?>"></div>
                <div class="col-md-4"><label class="form-label required">State</label><input class="form-control" name="state" maxlength="100" value="<?= e($editRecord['state']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">District</label><input class="form-control" name="district" maxlength="100" value="<?= e($editRecord['district']) ?>"></div>
                <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="3"><?= e($editRecord['address']) ?></textarea></div>
                <div class="col-md-4"><label class="form-label">Contact Person</label><input class="form-control" name="contact_name" maxlength="150" value="<?= e($editRecord['contact_name']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Contact Phone</label><input class="form-control" name="contact_phone" maxlength="30" value="<?= e($editRecord['contact_phone']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Contact Email</label><input type="email" class="form-control" name="contact_email" maxlength="150" value="<?= e($editRecord['contact_email']) ?>"></div>
                <?php if ($schoolHasReferrer): ?>
                    <div class="col-md-6">
                        <label class="form-label">Referrer School</label>
                        <select class="form-select" name="referrer">
                            <option value="">No referrer</option>
                            <?php foreach ($schools as $referrerSchool): ?>
                                <?php if ((int) $referrerSchool['id'] === (int) $editRecord['id']) continue; ?>
                                <option
                                    value="<?= e($referrerSchool['school_code']) ?>"
                                    <?= strtoupper((string) ($editRecord['referrer'] ?? '')) === strtoupper((string) $referrerSchool['school_code']) ? 'selected' : '' ?>
                                >
                                    <?= e($referrerSchool['school_name']) ?> (<?= e($referrerSchool['school_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Stored using the referring school's school code.</div>
                    </div>
                <?php endif; ?>
                <?php if ($schoolHasLogo || $schoolHasImage1 || $schoolHasImage2): ?>
                    <div class="col-12">
                        <div class="border rounded p-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div>
                                    <div class="fw-semibold">School Media</div>
                                    <div class="small text-secondary">Logo and 2 school profile images are managed from the School Edit page.</div>
                                </div>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="edit_school.php?school_id=<?= (int) $editRecord['id'] ?>"
                                >
                                    <i class="bi bi-images me-1"></i>Manage School Images
                                </a>
                            </div>

                            <div class="d-flex flex-wrap gap-3 align-items-start">
                                <?php if ($schoolHasLogo): ?>
                                    <?php $adminSchoolLogo = trim((string) ($editRecord['school_logo'] ?? '')); ?>
                                    <div>
                                        <div class="small text-secondary mb-1">Logo</div>
                                        <?php if ($adminSchoolLogo !== ''): ?>
                                            <img
                                                src="<?= e('/' . ltrim($adminSchoolLogo, '/')) ?>"
                                                alt="School logo"
                                                width="72"
                                                height="72"
                                                style="width:72px;height:72px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;background:#fff;padding:4px;"
                                            >
                                        <?php else: ?>
                                            <div class="border rounded bg-light d-flex align-items-center justify-content-center text-secondary" style="width:72px;height:72px;">
                                                <i class="bi bi-building"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php foreach ([
                                    1 => $schoolHasImage1 ? trim((string) ($editRecord['school_image_1'] ?? '')) : '',
                                    2 => $schoolHasImage2 ? trim((string) ($editRecord['school_image_2'] ?? '')) : '',
                                ] as $adminImageNo => $adminImagePath): ?>
                                    <?php if (($adminImageNo === 1 && $schoolHasImage1) || ($adminImageNo === 2 && $schoolHasImage2)): ?>
                                        <div>
                                            <div class="small text-secondary mb-1">Image <?= $adminImageNo ?></div>
                                            <?php if ($adminImagePath !== ''): ?>
                                                <img
                                                    src="<?= e('/' . ltrim($adminImagePath, '/')) ?>"
                                                    alt="School image <?= $adminImageNo ?>"
                                                    width="120"
                                                    height="72"
                                                    style="width:120px;height:72px;object-fit:cover;border:1px solid #dee2e6;border-radius:8px;"
                                                >
                                            <?php else: ?>
                                                <div class="border rounded bg-light d-flex align-items-center justify-content-center text-secondary" style="width:120px;height:72px;">
                                                    <i class="bi bi-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php elseif ($entity === 'students'): ?>
                <div class="col-md-6"><label class="form-label required">School</label><select class="form-select" name="school_id" required><?php foreach ($schools as $school): ?><option value="<?= (int) $school['id'] ?>" <?= (int) $editRecord['school_id'] === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['school_name']) ?> (<?= e($school['school_code']) ?>)</option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label required">Student Number</label><input class="form-control" name="student_number" maxlength="50" value="<?= e($editRecord['student_number']) ?>" required></div>
                <div class="col-md-3"><label class="form-label">Membership Status</label><select class="form-select" name="membership_status"><?php foreach ($allowedMembershipStatuses as $status): ?><option value="<?= e($status) ?>" <?= $editRecord['membership_status'] === $status ? 'selected' : '' ?>><?= e(membershipLabel($status)) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-8"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" maxlength="200" value="<?= e($editRecord['full_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">NRIC / Passport</label><input class="form-control" name="identity_number" maxlength="50" value="<?= e($editRecord['identity_number']) ?>"></div>
                <div class="col-md-4">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                        <option value="">Select</option>
                        <?php foreach (['Male', 'Female'] as $gender): ?>
                            <option value="<?= e($gender) ?>" <?= ($editRecord['gender'] ?? '') === $gender ? 'selected' : '' ?>><?= e($gender) ?></option>
                        <?php endforeach; ?>
                        <?php if (($editRecord['gender'] ?? '') === 'Other'): ?>
                            <option value="Other" selected>Other (legacy)</option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">Use Male/Female for the JPN L/P report.</div>
                </div>
                <?php if ($studentHasEthnicity): ?>
                    <div class="col-md-4">
                        <label class="form-label">Ethnicity / Race</label>
                        <select class="form-select" name="ethnicity">
                            <option value="">Select ethnicity</option>
                            <?php foreach ($allowedEthnicities as $ethnicityOption): ?>
                                <option value="<?= e($ethnicityOption) ?>" <?= ($editRecord['ethnicity'] ?? '') === $ethnicityOption ? 'selected' : '' ?>>
                                    <?= e($ethnicityOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Used for the JPN programme report.</div>
                    </div>
                <?php endif; ?>
                <div class="col-md-4"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth" value="<?= e($editRecord['date_of_birth']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Class / Form</label><input class="form-control" name="class_name" maxlength="100" value="<?= e($editRecord['class_name']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="30" value="<?= e($editRecord['phone']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" maxlength="150" value="<?= e($editRecord['email']) ?>"></div>
                <div class="col-12">
                    <div class="alert alert-light border mb-0">
                        <div class="fw-semibold mb-2">RM100 / 1 Year Membership</div>
                        <div class="row g-2 small">
                            <div class="col-md-4">
                                <span class="text-secondary">Status:</span>
                                <strong><?= e(membershipLabel($editRecord['membership_status'])) ?></strong>
                            </div>
                            <div class="col-md-4">
                                <span class="text-secondary">Expiry:</span>
                                <strong>
                                    <?= $studentHasMembershipExpiry && !empty($editRecord['membership_next_due_at'])
                                        ? e(date('d M Y', strtotime((string) $editRecord['membership_next_due_at'])))
                                        : '-' ?>
                                </strong>
                            </div>
                            <div class="col-md-4">
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="student_payment.php?student_id=<?= (int) $editRecord['id'] ?>"
                                >
                                    <i class="bi bi-credit-card me-1"></i>Open Membership
                                </a>
                            </div>
                        </div>

                        <?php if (!empty($editRecord['stripe_subscription_id'])): ?>
                            <div class="small text-warning mt-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Legacy Stripe subscription detected:
                                <?= e($editRecord['stripe_subscription_status'] ?: 'unknown') ?>
                                · <?= e($editRecord['stripe_subscription_id']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($entity === 'guardians'): ?>
                <div class="col-md-3">
                    <label class="form-label">Guardian Number</label>
                    <input class="form-control fw-semibold" value="<?= e($editRecord['guardian_number'] ?: ('PG-' . str_pad((string) $editRecord['id'], 6, '0', STR_PAD_LEFT))) ?>" readonly>
                </div>
                <div class="col-md-5"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" maxlength="255" value="<?= e($editRecord['full_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label required">NRIC / Passport</label><input class="form-control" name="identity_number" maxlength="80" value="<?= e($editRecord['identity_number']) ?>" required></div>
                <div class="col-md-6">
                    <label class="form-label required">Mobile Number</label>
                    <input class="form-control" name="phone" maxlength="30" inputmode="tel" value="<?= e($editRecord['phone']) ?>" required>
                    <?php if ($guardianHasPhoneVerification): ?>
                        <div class="form-text">Changing this number resets phone verification.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Email</label>
                    <input type="email" class="form-control" name="email" maxlength="255" value="<?= e($editRecord['email']) ?>" required>
                    <?php if ($guardianHasEmailVerification): ?>
                        <div class="form-text">Changing this email resets email verification.</div>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <div class="alert alert-light border mb-0">
                        <div class="fw-semibold mb-2">Verification Status</div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($guardianHasPhoneVerification): ?>
                                <span class="badge text-bg-<?= !empty($editRecord['phone_verified_at']) ? 'success' : 'secondary' ?>">
                                    SMS <?= !empty($editRecord['phone_verified_at']) ? 'Verified' : 'Not Verified' ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($guardianHasEmailVerification): ?>
                                <span class="badge text-bg-<?= !empty($editRecord['email_verified_at']) ? 'success' : 'secondary' ?>">
                                    Email <?= !empty($editRecord['email_verified_at']) ? 'Verified' : 'Not Verified' ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!$guardianHasPhoneVerification && !$guardianHasEmailVerification): ?>
                                <span class="text-secondary small">Verification fields are not available in the current database schema.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">Linked Student(s) — <?= count($guardianChildren) ?></div>
                        <?php if (!$guardianChildren): ?>
                            <div class="text-secondary small">No student is currently linked to this parent / guardian.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Student</th><th>School</th><th>Relationship</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($guardianChildren as $child): ?>
                                        <tr>
                                            <td><div class="fw-semibold"><?= e($child['full_name']) ?></div><small class="text-secondary"><?= e($child['student_number']) ?></small></td>
                                            <td><?= e($child['school_name'] ?: '-') ?><br><small class="text-secondary"><?= e($child['school_code']) ?></small></td>
                                            <td><?= e($child['relationship']) ?><?= (int) $child['is_primary'] === 1 ? ' · Primary' : '' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($entity === 'teachers'): ?>
                <div class="col-md-6"><label class="form-label required">School</label><select class="form-select" name="school_id" required><?php foreach ($schools as $school): ?><option value="<?= (int) $school['id'] ?>" <?= (int) $editRecord['school_id'] === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['school_name']) ?> (<?= e($school['school_code']) ?>)</option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label required">Teacher Name</label><input class="form-control" name="full_name" maxlength="200" value="<?= e($editRecord['full_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">NRIC / Passport</label><input class="form-control" name="identity_number" maxlength="50" value="<?= e($editRecord['identity_number']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Position</label><input class="form-control" name="position" maxlength="100" value="<?= e($editRecord['position']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="30" value="<?= e($editRecord['phone']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" maxlength="150" value="<?= e($editRecord['email']) ?>"></div>
                <?php if ($teacherHasPhoneVerification || $teacherHasEmailVerification): ?>
                    <div class="col-12">
                        <div class="alert alert-light border mb-0">
                            <div class="fw-semibold mb-2">Verification Status</div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($teacherHasPhoneVerification): ?>
                                    <span class="badge text-bg-<?= !empty($editRecord['phone_verified_at']) ? 'success' : 'secondary' ?>">
                                        Phone <?= !empty($editRecord['phone_verified_at']) ? 'Verified' : 'Pending' ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($teacherHasEmailVerification): ?>
                                    <span class="badge text-bg-<?= !empty($editRecord['email_verified_at']) ? 'success' : 'secondary' ?>">
                                        Email <?= !empty($editRecord['email_verified_at']) ? 'Verified' : 'Pending' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="col-md-6"><label class="form-label required">School</label><select class="form-select" name="school_id" required><?php foreach ($schools as $school): ?><option value="<?= (int) $school['id'] ?>" <?= (int) $editRecord['school_id'] === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['school_name']) ?> (<?= e($school['school_code']) ?>)</option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label required">Coach Name</label><input class="form-control" name="full_name" maxlength="200" value="<?= e($editRecord['full_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">NRIC / Passport</label><input class="form-control" name="identity_number" maxlength="50" value="<?= e($editRecord['identity_number']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Organisation / Club</label><input class="form-control" name="organization" maxlength="150" value="<?= e($editRecord['organization']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Certification / Experience</label><input class="form-control" name="certification" maxlength="255" value="<?= e($editRecord['certification']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="30" value="<?= e($editRecord['phone']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" maxlength="150" value="<?= e($editRecord['email']) ?>"></div>
                <?php if ($coachHasPhoneVerification || $coachHasEmailVerification): ?>
                    <div class="col-12">
                        <div class="alert alert-light border mb-0">
                            <div class="fw-semibold mb-2">Verification Status</div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($coachHasPhoneVerification): ?>
                                    <span class="badge text-bg-<?= !empty($editRecord['phone_verified_at']) ? 'success' : 'secondary' ?>">
                                        Phone <?= !empty($editRecord['phone_verified_at']) ? 'Verified' : 'Pending' ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($coachHasEmailVerification): ?>
                                    <span class="badge text-bg-<?= !empty($editRecord['email_verified_at']) ? 'success' : 'secondary' ?>">
                                        Email <?= !empty($editRecord['email_verified_at']) ? 'Verified' : 'Pending' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="col-12 mt-4">
                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-2"></i>Save Changes</button>
                <a class="btn btn-outline-secondary" href="<?= e(adminUrl(['edit_id' => null])) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card content-card">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end mb-4">
            <input type="hidden" name="entity" value="<?= e($entity) ?>">
            <div class="<?= $entity === 'schools' ? 'col-lg-9' : ($entity === 'students' ? 'col-lg-5' : 'col-lg-6') ?>">
                <label class="form-label">Search</label>
                <input class="form-control" name="search" value="<?= e($search) ?>" placeholder="<?= $entity === 'guardians' ? 'Search guardian, NRIC, phone, email, student or school' : 'Search name, code, ID, email or school' ?>">
            </div>
            <?php if ($entity !== 'schools'): ?>
                <div class="col-lg-3">
                    <label class="form-label">School</label>
                    <select class="form-select" name="school_id">
                        <option value="">All schools</option>
                        <?php foreach ($schools as $school): ?><option value="<?= (int) $school['id'] ?>" <?= $schoolFilter === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['school_name']) ?> (<?= e($school['school_code']) ?>)</option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($entity === 'students'): ?>
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status"><option value="">All statuses</option><?php foreach ($allowedMembershipStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(membershipLabel($status)) ?></option><?php endforeach; ?></select>
                </div>
            <?php endif; ?>
            <div class="col-lg-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search me-2"></i>Filter</button></div>
            <div class="col-lg-1 d-grid"><a class="btn btn-outline-secondary" href="admin.php?entity=<?= e($entity) ?>" title="Clear filters"><i class="bi bi-x-lg"></i></a></div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <?php if ($entity === 'schools'): ?>
                    <tr><th>School</th><th>Location</th><th>Contact</th><?php if ($schoolHasReferrer): ?><th>Referrer</th><?php endif; ?><th class="text-center">Students</th><th class="text-center">Teachers</th><th class="text-end">Actions</th></tr>
                <?php elseif ($entity === 'students'): ?>
                    <tr><th>Student</th><th>School</th><th>Class</th><?php if ($studentHasEthnicity): ?><th>Gender / Ethnicity</th><?php endif; ?><th>Status</th><th>Contact</th><th class="text-end">Actions</th></tr>
                <?php elseif ($entity === 'guardians'): ?>
                    <tr><th>Parent / Guardian</th><th>NRIC / Passport</th><th>Contact</th><th>Verification</th><th>Linked Students</th><th class="text-end">Actions</th></tr>
                <?php elseif ($entity === 'teachers'): ?>
                    <tr><th>Teacher</th><th>School</th><th>Position</th><th>Contact</th><th>Verification</th><th class="text-end">Actions</th></tr>
                <?php else: ?>
                    <tr><th>Coach</th><th>School</th><th>Organisation</th><th>Certification</th><th>Contact</th><th>Verification</th><th class="text-end">Actions</th></tr>
                <?php endif; ?>
                </thead>
                <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="<?= $entity === 'schools'
                            ? ($schoolHasReferrer ? 7 : 6)
                            : ($entity === 'students'
                                ? ($studentHasEthnicity ? 7 : 6)
                                : ($entity === 'guardians'
                                    ? 6
                                    : ($entity === 'teachers' ? 6 : 7))) ?>"
                            class="text-center text-secondary py-5">
                            No matching records found.
                        </td>
                    </tr>
                <?php else: foreach ($records as $record): ?>
                    <?php if ($entity === 'schools'): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php
                                        $adminListLogo = $schoolHasLogo
                                            ? trim((string) ($record['school_logo'] ?? ''))
                                            : '';
                                    ?>
                                    <div class="flex-shrink-0">
                                        <?php if ($adminListLogo !== ''): ?>
                                            <img
                                                src="<?= e('/' . ltrim($adminListLogo, '/')) ?>"
                                                alt="<?= e($record['school_name']) ?> logo"
                                                width="40"
                                                height="40"
                                                loading="lazy"
                                                style="width:40px;height:40px;object-fit:contain;border:1px solid #dee2e6;border-radius:7px;background:#fff;padding:3px;"
                                            >
                                        <?php else: ?>
                                            <div class="border rounded bg-light d-flex align-items-center justify-content-center text-secondary" style="width:40px;height:40px;">
                                                <i class="bi bi-building"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="badge text-bg-primary mb-1"><?= e($record['school_code']) ?></span>
                                        <div class="fw-semibold"><?= e($record['school_name']) ?></div>
                                        <small class="text-secondary"><?= e($record['school_type']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($record['district']) ?><br><small class="text-secondary"><?= e($record['state']) ?></small></td>
                            <td><?= e($record['contact_name'] ?: '-') ?><br><small class="text-secondary"><?= e($record['contact_phone']) ?></small></td>
                            <?php if ($schoolHasReferrer): ?>
                                <td><?= e($record['referrer'] ?: '-') ?></td>
                            <?php endif; ?>
                            <td class="text-center"><?= (int) $record['student_count'] ?></td>
                            <td class="text-center"><?= (int) $record['teacher_count'] ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(adminUrl(['edit_id' => (int) $record['id']])) ?>" title="Edit School"><i class="bi bi-pencil-square"></i></a>
                                <a class="btn btn-sm btn-outline-secondary" href="edit_school.php?school_id=<?= (int) $record['id'] ?>" title="School Logo & Images"><i class="bi bi-images"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this school and all of its students, teachers, coaches, payments, attendance and learning records? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="entity" value="schools"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php elseif ($entity === 'students'): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($record['full_name']) ?></div><small class="text-secondary"><?= e($record['student_number']) ?><?= $record['identity_number'] ? ' · ' . e($record['identity_number']) : '' ?></small></td>
                            <td><?= e($record['school_name']) ?><br><small class="text-secondary"><?= e($record['school_code']) ?></small></td>
                            <td><?= e($record['class_name'] ?: '-') ?></td>
                            <?php if ($studentHasEthnicity): ?>
                                <td>
                                    <?= e($record['gender'] ?: '-') ?><br>
                                    <small class="text-secondary"><?= e($record['ethnicity'] ?: 'Not classified') ?></small>
                                </td>
                            <?php endif; ?>
                            <td>
                                <span class="badge text-bg-<?= e(membershipBadge($record['membership_status'])) ?>">
                                    <?= e(membershipLabel($record['membership_status'])) ?>
                                </span>
                                <div class="small text-secondary mt-1">RM100 / 1 Year</div>
                                <?php if ($studentHasMembershipExpiry && !empty($record['membership_next_due_at'])): ?>
                                    <div class="small text-secondary">
                                        Until <?= e(date('d M Y', strtotime((string) $record['membership_next_due_at']))) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($record['last_annual_membership_paid_at'])): ?>
                                    <div class="small text-success">
                                        Paid <?= e(date('d M Y', strtotime((string) $record['last_annual_membership_paid_at']))) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($studentHasAccountVerification): ?>
                                    <span class="badge text-bg-<?= !empty($record['account_verified_at']) ? 'success' : 'secondary' ?> mt-1">
                                        Account <?= !empty($record['account_verified_at']) ? 'Verified' : 'Pending' ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($record['stripe_subscription_id'])): ?>
                                    <div class="small text-warning mt-1">Legacy Stripe subscription</div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($record['phone'] ?: '-') ?><br><small class="text-secondary"><?= e($record['email']) ?></small></td>
                            <td>
                                <div class="d-flex flex-column align-items-start gap-1">
                                    <?php if ($teacherHasPhoneVerification): ?>
                                        <span class="badge text-bg-<?= !empty($record['phone_verified_at']) ? 'success' : 'secondary' ?>">
                                            Phone <?= !empty($record['phone_verified_at']) ? 'Verified' : 'Pending' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($teacherHasEmailVerification): ?>
                                        <span class="badge text-bg-<?= !empty($record['email_verified_at']) ? 'success' : 'secondary' ?>">
                                            Email <?= !empty($record['email_verified_at']) ? 'Verified' : 'Pending' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!$teacherHasPhoneVerification && !$teacherHasEmailVerification): ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(adminUrl(['edit_id' => (int) $record['id']])) ?>" title="Edit Student"><i class="bi bi-pencil-square"></i></a>
                                <a class="btn btn-sm btn-outline-success" href="student_payment.php?student_id=<?= (int) $record['id'] ?>" title="Membership"><i class="bi bi-credit-card"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this student, including payment history, attendance and learning progress? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="entity" value="students"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php elseif ($entity === 'guardians'): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($record['full_name']) ?></div>
                                <small class="text-secondary"><?= e($record['guardian_number'] ?: ('PG-' . str_pad((string) $record['id'], 6, '0', STR_PAD_LEFT))) ?></small>
                            </td>
                            <td><?= e($record['identity_number']) ?></td>
                            <td><?= e($record['phone'] ?: '-') ?><br><small class="text-secondary"><?= e($record['email']) ?></small></td>
                            <td>
                                <div class="d-flex flex-column align-items-start gap-1">
                                    <?php if ($guardianHasPhoneVerification): ?>
                                        <span class="badge text-bg-<?= !empty($record['phone_verified_at']) ? 'success' : 'secondary' ?>">SMS <?= !empty($record['phone_verified_at']) ? 'Verified' : 'Not Verified' ?></span>
                                    <?php endif; ?>
                                    <?php if ($guardianHasEmailVerification): ?>
                                        <span class="badge text-bg-<?= !empty($record['email_verified_at']) ? 'success' : 'secondary' ?>">Email <?= !empty($record['email_verified_at']) ? 'Verified' : 'Not Verified' ?></span>
                                    <?php endif; ?>
                                    <?php if (!$guardianHasPhoneVerification && !$guardianHasEmailVerification): ?><span class="text-secondary">-</span><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge text-bg-primary mb-1"><?= (int) $record['student_count'] ?> student<?= (int) $record['student_count'] === 1 ? '' : 's' ?></span>
                                <?php if (!empty($record['children_summary'])): ?>
                                    <?php $children = explode('||', (string) $record['children_summary']); ?>
                                    <?php foreach (array_slice($children, 0, 3) as $childSummary): ?>
                                        <div class="small text-secondary"><?= e($childSummary) ?></div>
                                    <?php endforeach; ?>
                                    <?php if (count($children) > 3): ?><div class="small text-secondary">+<?= count($children) - 3 ?> more</div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(adminUrl(['edit_id' => (int) $record['id']])) ?>"><i class="bi bi-pencil-square"></i> Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this parent / guardian record? Links to <?= (int) $record['student_count'] ?> student(s) and pending verification records will be removed. The student records themselves will NOT be deleted. This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="entity" value="guardians"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php elseif ($entity === 'teachers'): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($record['full_name']) ?></div><small class="text-secondary"><?= e($record['identity_number']) ?></small></td>
                            <td><?= e($record['school_name']) ?><br><small class="text-secondary"><?= e($record['school_code']) ?></small></td>
                            <td><?= e($record['position'] ?: '-') ?></td>
                            <td><?= e($record['phone'] ?: '-') ?><br><small class="text-secondary"><?= e($record['email']) ?></small></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(adminUrl(['edit_id' => (int) $record['id']])) ?>"><i class="bi bi-pencil-square"></i> Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this teacher record? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="entity" value="teachers"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></form>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($record['full_name']) ?></div><small class="text-secondary"><?= e($record['identity_number']) ?></small></td>
                            <td><?= e($record['school_name']) ?><br><small class="text-secondary"><?= e($record['school_code']) ?></small></td>
                            <td><?= e($record['organization'] ?: '-') ?></td>
                            <td><?= e($record['certification'] ?: '-') ?></td>
                            <td><?= e($record['phone'] ?: '-') ?><br><small class="text-secondary"><?= e($record['email']) ?></small></td>
                            <td>
                                <div class="d-flex flex-column align-items-start gap-1">
                                    <?php if ($coachHasPhoneVerification): ?>
                                        <span class="badge text-bg-<?= !empty($record['phone_verified_at']) ? 'success' : 'secondary' ?>">
                                            Phone <?= !empty($record['phone_verified_at']) ? 'Verified' : 'Pending' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($coachHasEmailVerification): ?>
                                        <span class="badge text-bg-<?= !empty($record['email_verified_at']) ? 'success' : 'secondary' ?>">
                                            Email <?= !empty($record['email_verified_at']) ? 'Verified' : 'Pending' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!$coachHasPhoneVerification && !$coachHasEmailVerification): ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(adminUrl(['edit_id' => (int) $record['id']])) ?>"><i class="bi bi-pencil-square"></i> Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this coach record? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="entity" value="coaches"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button></form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
            <div class="small text-secondary">Showing <?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?> of <?= number_format($totalRecords) ?> records</div>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Admin record pagination"><ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(adminUrl(['page' => max(1, $page - 1)])) ?>">Previous</a></li>
                    <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?><li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(adminUrl(['page' => $pageNumber])) ?>"><?= $pageNumber ?></a></li><?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(adminUrl(['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
                </ul></nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
