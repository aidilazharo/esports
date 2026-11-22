<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_api_cache.php';
require_once __DIR__ . '/lightweight_registration_common.php';

function schoolSubmitColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute(['schools', $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function schoolSubmitTextLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function schoolSubmitIsComplete(array $school): bool
{
    $areaType = strtoupper(
        trim((string)($school['school_area_type'] ?? ''))
    );

    return trim((string)($school['contact_name'] ?? '')) !== ''
        && trim((string)($school['contact_phone'] ?? '')) !== ''
        && trim((string)($school['contact_email'] ?? '')) !== ''
        && (int)($school['total_students'] ?? 0) > 0
        && in_array($areaType, ['URBAN', 'RURAL'], true);
}

function schoolSubmitStoreLogo(
    array $file,
    string $schoolCode,
    int $maxBytes = 5242880
): array {
    $result = [
        'path' => null,
        'uploaded' => false,
        'absolute_path' => null,
        'error' => null,
    ];

    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return $result;
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $result['error'] =
            'The school logo upload failed. Please select the image again.';
        return $result;
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $result['error'] = 'The school logo upload is invalid.';
        return $result;
    }

    if ($size < 1 || $size > $maxBytes) {
        $result['error'] = 'The school logo must not exceed 5 MB.';
        return $result;
    }

    if (!function_exists('finfo_open')) {
        $result['error'] =
            'PHP Fileinfo is required to upload the school logo.';
        return $result;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo
        ? (string)finfo_file($finfo, $tmpName)
        : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        $result['error'] =
            'The school logo must be JPG, PNG or WEBP.';
        return $result;
    }

    if (@getimagesize($tmpName) === false) {
        $result['error'] =
            'The uploaded school logo is not a valid image.';
        return $result;
    }

    $safeCode = preg_replace(
        '/[^A-Z0-9_-]+/',
        '-',
        lightweightUpper($schoolCode)
    );

    $safeCode = trim((string)$safeCode, '-');

    if ($safeCode === '') {
        $safeCode = 'SCHOOL';
    }

    // API lives in /api/registration, so project root is two directories up.
    $projectRoot = dirname(__DIR__, 2);
    $relativeDir = 'uploads/schools/' . $safeCode;
    $absoluteDir = $projectRoot . '/' . $relativeDir;

    if (
        !is_dir($absoluteDir) &&
        !mkdir($absoluteDir, 0755, true) &&
        !is_dir($absoluteDir)
    ) {
        $result['error'] =
            'Unable to create the school logo upload folder.';
        return $result;
    }

    $filename =
        'school-logo-'
        . date('Ymd-His')
        . '-'
        . bin2hex(random_bytes(5))
        . '.'
        . $allowed[$mime];

    $relativePath = $relativeDir . '/' . $filename;
    $absolutePath = $absoluteDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        $result['error'] = 'Unable to save the school logo.';
        return $result;
    }

    @chmod($absolutePath, 0644);

    $result['path'] = $relativePath;
    $result['uploaded'] = true;
    $result['absolute_path'] = $absolutePath;

    return $result;
}

try {
    verifyCsrf();

    $errors = [];

    $schoolCode = lightweightUpper(
        (string)($_POST['school_code'] ?? '')
    );
    $schoolCode = substr($schoolCode, 0, 30);

    $schoolName = lightweightUpper(
        (string)($_POST['school_name'] ?? '')
    );

    $schoolType = lightweightUpper(
        (string)($_POST['school_type'] ?? '')
    );

    $state = lightweightUpper(
        (string)($_POST['state'] ?? '')
    );

    $district = lightweightUpper(
        (string)($_POST['district'] ?? '')
    );

    $totalStudentsRaw = trim(
        (string)($_POST['total_students'] ?? '')
    );

    $totalStudents = filter_var(
        $totalStudentsRaw,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 99999,
            ],
        ]
    );

    $schoolAreaType = lightweightUpper(
        (string)($_POST['school_area_type'] ?? '')
    );

    $address = lightweightUpper(
        (string)($_POST['address'] ?? '')
    );

    $contactName = lightweightUpper(
        (string)($_POST['contact_name'] ?? '')
    );

    $contactPhone = lightweightUpper(
        (string)($_POST['contact_phone'] ?? '')
    );

    $contactEmail = lightweightUpper(
        (string)($_POST['contact_email'] ?? '')
    );

    $referrerCode = lightweightUpper(
        (string)($_POST['referrer'] ?? '')
    );
    $referrerCode = substr($referrerCode, 0, 30);

    if ($schoolCode === '') $errors[] = 'School code is required.';
    if ($schoolName === '') $errors[] = 'School name is required.';
    if ($schoolType === '') $errors[] = 'School type is required.';
    if ($state === '') $errors[] = 'State is required.';
    if ($district === '') $errors[] = 'District is required.';

    if ($totalStudents === false) {
        $errors[] =
            'Total students is required and must be between 1 and 99,999.';
    }

    if (
        !in_array(
            $schoolAreaType,
            ['URBAN', 'RURAL'],
            true
        )
    ) {
        $errors[] = 'Select whether the school is Urban or Rural.';
    }

    if ($address === '') $errors[] = 'Address is required.';
    if ($contactName === '') $errors[] = 'Contact person is required.';
    if ($contactPhone === '') $errors[] = 'Contact phone is required.';

    if (
        $contactEmail === '' ||
        !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'A valid contact email is required.';
    }

    if (schoolSubmitTextLength($schoolName) > 200) {
        $errors[] = 'School name must not exceed 200 characters.';
    }

    if (schoolSubmitTextLength($district) > 100) {
        $errors[] = 'District must not exceed 100 characters.';
    }

    if (schoolSubmitTextLength($address) > 1000) {
        $errors[] = 'Address must not exceed 1,000 characters.';
    }

    if (schoolSubmitTextLength($contactName) > 150) {
        $errors[] = 'Contact person must not exceed 150 characters.';
    }

    if (schoolSubmitTextLength($contactPhone) > 30) {
        $errors[] = 'Contact phone must not exceed 30 characters.';
    }

    if (schoolSubmitTextLength($contactEmail) > 150) {
        $errors[] = 'Contact email must not exceed 150 characters.';
    }

    if (
        $contactPhone !== '' &&
        !preg_match('/^[0-9+()\-\s.]+$/', $contactPhone)
    ) {
        $errors[] = 'Contact phone contains unsupported characters.';
    }

    if (!schoolSubmitColumnExists($pdo, 'school_logo')) {
        $errors[] =
            'Run add_school_logo_column.sql before registering a school '
            . 'because a school logo is required.';
    }

    if (!schoolSubmitColumnExists($pdo, 'total_students')) {
        $errors[] =
            'Run add_school_population_area_columns.sql before registering a school.';
    }

    if (!schoolSubmitColumnExists($pdo, 'school_area_type')) {
        $errors[] =
            'Run add_school_population_area_columns.sql before registering a school.';
    }

    $lookup = null;

    if ($schoolCode !== '') {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM schools
             WHERE UPPER(school_code) = ?
             LIMIT 1'
        );
        $stmt->execute([$schoolCode]);
        $lookup = $stmt->fetch() ?: null;

        if ($lookup && schoolSubmitIsComplete($lookup)) {
            $errors[] =
                'This school is already registered. Please use the login page.';
        }
    }

    if ($referrerCode !== '') {
        if ($referrerCode === $schoolCode) {
            $errors[] = 'A school cannot refer itself.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT school_code
                 FROM schools
                 WHERE UPPER(school_code) = ?
                 LIMIT 1'
            );
            $stmt->execute([$referrerCode]);

            if (!$stmt->fetchColumn()) {
                $errors[] =
                    'The selected referrer school code is invalid.';
            }
        }
    }

    $currentLogo = trim(
        (string)($lookup['school_logo'] ?? '')
    );

    $newLogoPath = null;
    $newLogoAbsolutePath = null;

    if (!$errors && schoolSubmitColumnExists($pdo, 'school_logo')) {
        $upload = schoolSubmitStoreLogo(
            $_FILES['school_logo'] ?? [],
            $schoolCode
        );

        if ($upload['error'] !== null) {
            $errors[] = (string)$upload['error'];
        } elseif (!empty($upload['uploaded'])) {
            $newLogoPath = (string)$upload['path'];
            $newLogoAbsolutePath =
                (string)$upload['absolute_path'];
        } elseif ($currentLogo === '') {
            $errors[] = 'School logo is required.';
        }
    }

    if ($errors) {
        if (
            $newLogoAbsolutePath &&
            is_file($newLogoAbsolutePath)
        ) {
            @unlink($newLogoAbsolutePath);
        }

        lightweightRegistrationJson([
            'success' => false,
            'errors' => $errors,
        ], 422);
    }

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare(
            'SELECT *
             FROM schools
             WHERE UPPER(school_code) = ?
             LIMIT 1
             FOR UPDATE'
        );
        $lock->execute([$schoolCode]);
        $school = $lock->fetch() ?: null;

        if ($school && schoolSubmitIsComplete($school)) {
            throw new RuntimeException(
                'This school is already registered. Please use the login page.'
            );
        }

        $logoToSave =
            $newLogoPath
            ?: trim((string)($school['school_logo'] ?? ''));

        if ($logoToSave === '') {
            throw new RuntimeException('School logo is required.');
        }

        if ($school) {
            $stmt = $pdo->prepare(
                'UPDATE schools
                 SET school_name = ?,
                     school_type = ?,
                     state = ?,
                     district = ?,
                     total_students = ?,
                     school_area_type = ?,
                     address = ?,
                     contact_name = ?,
                     contact_phone = ?,
                     contact_email = ?,
                     referrer = ?,
                     school_logo = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $schoolName,
                $schoolType,
                $state,
                $district,
                (int)$totalStudents,
                $schoolAreaType,
                $address,
                $contactName,
                $contactPhone,
                $contactEmail,
                $referrerCode !== '' ? $referrerCode : null,
                $logoToSave,
                (int)$school['id'],
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO schools
                 (
                    school_code,
                    school_name,
                    school_type,
                    state,
                    district,
                    total_students,
                    school_area_type,
                    address,
                    contact_name,
                    contact_phone,
                    contact_email,
                    referrer,
                    school_logo
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $schoolCode,
                $schoolName,
                $schoolType,
                $state,
                $district,
                (int)$totalStudents,
                $schoolAreaType,
                $address,
                $contactName,
                $contactPhone,
                $contactEmail,
                $referrerCode !== '' ? $referrerCode : null,
                $logoToSave,
            ]);
        }

        $pdo->commit();

        registrationSchoolCacheInvalidate($schoolCode);

        // A directory-only school remains email-suppressed until an
        // authorized administrator explicitly activates its record.
        $directoryOnly = !empty($school['suppress_school_email']);
        setFlash(
            'success',
            $directoryOnly
                ? 'Your school profile was submitted. Email communication is disabled for this directory school until an administrator activates it.'
                : 'School registered successfully. You can now log in to the School Portal.'
        );

        lightweightRegistrationJson([
            'success' => true,
            'redirect' => 'login.php',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            $newLogoAbsolutePath &&
            is_file($newLogoAbsolutePath)
        ) {
            @unlink($newLogoAbsolutePath);
        }

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to register the school at this time.';

        error_log(
            'school_submit.php error: ' . $e->getMessage()
        );

        lightweightRegistrationJson([
            'success' => false,
            'errors' => [$message],
        ], 500);
    }
} catch (Throwable $e) {
    error_log(
        'school_submit.php outer error: '
        . $e->getMessage()
    );

    lightweightRegistrationJson([
        'success' => false,
        'errors' => [
            'Unable to process the school registration request.',
        ],
    ], 500);
}
