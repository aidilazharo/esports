<?php
require_once 'config.php';

$schoolId = (int) ($_GET['school_id'] ?? $_POST['school_id'] ?? 0);

if ($schoolId <= 0) {
    setFlash('danger', 'Invalid school selected.');
    redirect('schools.php');
}

/**
 * Return the character length without requiring the mbstring extension.
 */
function schoolEditTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function schoolEditColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute(['schools', $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function schoolEditMediaSchemaReady(PDO $pdo): bool
{
    foreach (['school_logo', 'school_image_1', 'school_image_2'] as $column) {
        if (!schoolEditColumnExists($pdo, $column)) {
            return false;
        }
    }
    return true;
}

function schoolEditStoreImage(
    array $file,
    string $schoolCode,
    string $slot,
    int $maxBytes = 5242880
): array {
    $result = [
        'path' => null,
        'uploaded' => false,
        'absolute_path' => null,
        'error' => null,
    ];

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return $result;
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $result['error'] = 'The ' . $slot . ' upload failed. Please select the image again.';
        return $result;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $result['error'] = 'The ' . $slot . ' upload is invalid.';
        return $result;
    }

    if ($size < 1 || $size > $maxBytes) {
        $result['error'] = 'The ' . $slot . ' image must not exceed 5 MB.';
        return $result;
    }

    if (!function_exists('finfo_open')) {
        $result['error'] = 'PHP Fileinfo is required to upload school images.';
        return $result;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        $result['error'] = 'The ' . $slot . ' image must be JPG, PNG or WEBP.';
        return $result;
    }

    if (@getimagesize($tmpName) === false) {
        $result['error'] = 'The ' . $slot . ' file is not a valid image.';
        return $result;
    }

    $safeSchoolCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtoupper(trim($schoolCode)));
    $safeSchoolCode = trim((string) $safeSchoolCode, '-');
    if ($safeSchoolCode === '') {
        $safeSchoolCode = 'school';
    }

    $safeSlot = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower($slot));
    $safeSlot = trim((string) $safeSlot, '-');
    if ($safeSlot === '') {
        $safeSlot = 'image';
    }

    $relativeDir = 'uploads/schools/' . $safeSchoolCode;
    $absoluteDir = __DIR__ . '/' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        $result['error'] = 'Unable to create the school image upload folder.';
        return $result;
    }

    try {
        $random = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $random = str_replace('.', '', uniqid('', true));
    }

    $filename = $safeSlot . '-' . date('Ymd-His') . '-' . $random . '.' . $allowed[$mime];
    $relativePath = $relativeDir . '/' . $filename;
    $absolutePath = $absoluteDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        $result['error'] = 'Unable to save the ' . $slot . ' image.';
        return $result;
    }

    @chmod($absolutePath, 0644);

    $result['path'] = $relativePath;
    $result['uploaded'] = true;
    $result['absolute_path'] = $absolutePath;

    return $result;
}

function schoolEditDeleteReplacedImage(?string $oldPath, ?string $newPath): void
{
    $oldPath = trim((string) $oldPath);
    $newPath = trim((string) $newPath);

    if ($oldPath === '' || $oldPath === $newPath) {
        return;
    }

    $normalized = ltrim(str_replace('\\', '/', $oldPath), '/');
    if (!str_starts_with($normalized, 'uploads/schools/')) {
        return;
    }

    $absolute = __DIR__ . '/' . $normalized;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

$schoolMediaSchemaReady = schoolEditMediaSchemaReady($pdo);

$stmt = $pdo->prepare(
    'SELECT
        s.*,
        r.school_code AS referrer_school_code,
        r.school_name AS referrer_school_name
     FROM schools AS s
     LEFT JOIN schools AS r ON r.school_code = s.referrer
     WHERE s.id = ?'
);
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    setFlash('danger', 'School record was not found.');
    redirect('schools.php');
}

// Once these values have been set, they are permanently locked from this page.
$referrerLocked = trim((string) ($school['referrer'] ?? '')) !== '';
$emailLocked = trim((string) ($school['contact_email'] ?? '')) !== '';

$referrerSchoolsStmt = $pdo->prepare(
    'SELECT id, school_code, school_name
     FROM schools
     WHERE id <> ?
     ORDER BY school_name ASC, school_code ASC'
);
$referrerSchoolsStmt->execute([$schoolId]);
$referrerSchools = $referrerSchoolsStmt->fetchAll();

$errors = [];
$selectedReferrerCode = strtoupper(trim((string) ($school['referrer'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $address = trim((string) ($_POST['address'] ?? ''));
    $contactName = trim((string) ($_POST['contact_name'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));

    // A non-empty email cannot be changed. Ignore any manipulated POST value.
    if ($emailLocked) {
        $contactEmail = trim((string) $school['contact_email']);
    } else {
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    }

    // A non-empty referrer cannot be changed. Ignore any manipulated POST value.
    if ($referrerLocked) {
        $referrerCode = strtoupper(trim((string) $school['referrer']));
    } else {
        $referrerCode = strtoupper(trim((string) ($_POST['referrer'] ?? '')));
        $referrerCode = substr($referrerCode, 0, 30);
        $selectedReferrerCode = $referrerCode;
    }

    if (schoolEditTextLength($address) > 1000) {
        $errors[] = 'Address must not exceed 1,000 characters.';
    }

    if (schoolEditTextLength($contactName) > 150) {
        $errors[] = 'Contact person must not exceed 150 characters.';
    }

    if (schoolEditTextLength($contactPhone) > 30) {
        $errors[] = 'Contact phone must not exceed 30 characters.';
    } elseif ($contactPhone !== '' && !preg_match('/^[0-9+()\-\s.]+$/', $contactPhone)) {
        $errors[] = 'Contact phone contains unsupported characters.';
    }

    if (!$emailLocked) {
        if (schoolEditTextLength($contactEmail) > 150) {
            $errors[] = 'Contact email must not exceed 150 characters.';
        } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid contact email address.';
        }
    }

    if (!$referrerLocked && $referrerCode !== '') {
        if ($referrerCode === strtoupper((string) $school['school_code'])) {
            $errors[] = 'A school cannot refer itself.';
        } else {
            $referrerCheck = $pdo->prepare(
                'SELECT school_code
                 FROM schools
                 WHERE school_code = ?
                 LIMIT 1'
            );
            $referrerCheck->execute([$referrerCode]);

            if (!$referrerCheck->fetchColumn()) {
                $errors[] = 'The selected referrer school code is invalid.';
            }
        }
    }

    $oldSchoolLogo = trim((string) ($school['school_logo'] ?? ''));
    $oldSchoolImage1 = trim((string) ($school['school_image_1'] ?? ''));
    $oldSchoolImage2 = trim((string) ($school['school_image_2'] ?? ''));

    $schoolLogo = $oldSchoolLogo;
    $schoolImage1 = $oldSchoolImage1;
    $schoolImage2 = $oldSchoolImage2;
    $newlyUploadedFiles = [];

    if (!$errors && $schoolMediaSchemaReady) {
        $uploadDefinitions = [
            'school_logo' => 'school logo',
            'school_image_1' => 'school image 1',
            'school_image_2' => 'school image 2',
        ];

        foreach ($uploadDefinitions as $field => $label) {
            $upload = schoolEditStoreImage(
                $_FILES[$field] ?? [],
                (string) $school['school_code'],
                $label
            );

            if ($upload['error'] !== null) {
                $errors[] = (string) $upload['error'];
                continue;
            }

            if (!empty($upload['uploaded']) && !empty($upload['path'])) {
                if ($field === 'school_logo') {
                    $schoolLogo = (string) $upload['path'];
                } elseif ($field === 'school_image_1') {
                    $schoolImage1 = (string) $upload['path'];
                } else {
                    $schoolImage2 = (string) $upload['path'];
                }

                if (!empty($upload['absolute_path'])) {
                    $newlyUploadedFiles[] = (string) $upload['absolute_path'];
                }
            }
        }
    } elseif (!$errors && !$schoolMediaSchemaReady) {
        foreach (['school_logo', 'school_image_1', 'school_image_2'] as $field) {
            if ((int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Run add_school_media_columns.sql before uploading school images.';
                break;
            }
        }
    }

    if (!$errors) {
        try {
            if ($schoolMediaSchemaReady) {
                $update = $pdo->prepare(
                    'UPDATE schools
                     SET address = ?,
                         contact_name = ?,
                         contact_phone = ?,
                         contact_email = ?,
                         referrer = ?,
                         school_logo = ?,
                         school_image_1 = ?,
                         school_image_2 = ?
                     WHERE id = ?'
                );
                $update->execute([
                    $address !== '' ? $address : null,
                    $contactName !== '' ? $contactName : null,
                    $contactPhone !== '' ? $contactPhone : null,
                    $contactEmail !== '' ? $contactEmail : null,
                    $referrerCode !== '' ? $referrerCode : null,
                    $schoolLogo !== '' ? $schoolLogo : null,
                    $schoolImage1 !== '' ? $schoolImage1 : null,
                    $schoolImage2 !== '' ? $schoolImage2 : null,
                    $schoolId,
                ]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE schools
                     SET address = ?,
                         contact_name = ?,
                         contact_phone = ?,
                         contact_email = ?,
                         referrer = ?
                     WHERE id = ?'
                );
                $update->execute([
                    $address !== '' ? $address : null,
                    $contactName !== '' ? $contactName : null,
                    $contactPhone !== '' ? $contactPhone : null,
                    $contactEmail !== '' ? $contactEmail : null,
                    $referrerCode !== '' ? $referrerCode : null,
                    $schoolId,
                ]);
            }

            if ($schoolMediaSchemaReady) {
                schoolEditDeleteReplacedImage($oldSchoolLogo, $schoolLogo);
                schoolEditDeleteReplacedImage($oldSchoolImage1, $schoolImage1);
                schoolEditDeleteReplacedImage($oldSchoolImage2, $schoolImage2);
            }

            setFlash('success', 'School information updated successfully.');
            redirect('edit_school.php?school_id=' . $schoolId);
        } catch (Throwable $e) {
            foreach ($newlyUploadedFiles as $newFile) {
                if (is_file($newFile)) {
                    @unlink($newFile);
                }
            }

            $errors[] = 'Unable to update the school information at this time.';
            error_log('School update error for ID ' . $schoolId . ': ' . $e->getMessage());
        }
    }

    if ($schoolMediaSchemaReady) {
        $school['school_logo'] = $schoolLogo !== '' ? $schoolLogo : null;
        $school['school_image_1'] = $schoolImage1 !== '' ? $schoolImage1 : null;
        $school['school_image_2'] = $schoolImage2 !== '' ? $schoolImage2 : null;
    }

    // Keep submitted editable values visible if validation fails.
    $school['address'] = $address;
    $school['contact_name'] = $contactName;
    $school['contact_phone'] = $contactPhone;

    if (!$emailLocked) {
        $school['contact_email'] = $contactEmail;
    }

    if (!$referrerLocked) {
        $school['referrer'] = $referrerCode !== '' ? $referrerCode : null;
    }
}

$pageTitle = 'Update School Information';
require 'includes/header.php';
?>
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div>
        <h1 class="h2 mb-1">Update School Information</h1>
        <p class="text-secondary mb-0">Address and contact details can be updated. Email and referrer become locked once a value has been saved.</p>
    </div>
    <a href="schools.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-2"></i>Back to Schools
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger" role="alert">
        <div class="fw-semibold mb-1">Please correct the following:</div>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card content-card mb-4">
    <div class="card-body p-4">
        <div class="row g-4">
            <div class="col-md-3">
                <div class="small text-secondary">School Code</div>
                <div class="fw-semibold"><?= e($school['school_code']) ?></div>
            </div>
            <div class="col-md-5">
                <div class="small text-secondary">School Name</div>
                <div class="fw-semibold"><?= e($school['school_name']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-secondary">School Type</div>
                <div class="fw-semibold"><?= e($school['school_type'] ?: '-') ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-secondary">State</div>
                <div class="fw-semibold"><?= e($school['state'] ?: '-') ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-secondary">District</div>
                <div class="fw-semibold"><?= e($school['district'] ?: '-') ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (!$schoolMediaSchemaReady): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Run <code>add_school_media_columns.sql</code> once to enable school logo and image uploads.
        Address and contact details can still be updated normally.
    </div>
<?php endif; ?>

<div class="card content-card">
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="school_id" value="<?= (int) $school['id'] ?>">

            <div class="mb-4">
                <h2 class="h5 border-bottom pb-2 mb-3">School Logo</h2>
                <div class="row g-3 align-items-center">
                    <div class="col-auto">
                        <?php $currentSchoolLogo = trim((string) ($school['school_logo'] ?? '')); ?>
                        <?php if ($currentSchoolLogo !== ''): ?>
                            <img
                                src="<?= e('/' . ltrim($currentSchoolLogo, '/')) ?>"
                                alt="<?= e($school['school_name']) ?> logo"
                                width="96"
                                height="96"
                                style="width:96px;height:96px;object-fit:contain;border:1px solid #dee2e6;border-radius:12px;background:#fff;padding:6px;"
                            >
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center border rounded bg-light text-secondary" style="width:96px;height:96px;">
                                <i class="bi bi-building fs-1"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col">
                        <label class="form-label" for="school_logo">Upload School Logo</label>
                        <input
                            type="file"
                            class="form-control"
                            id="school_logo"
                            name="school_logo"
                            accept="image/jpeg,image/png,image/webp"
                            <?= !$schoolMediaSchemaReady ? 'disabled' : '' ?>
                        >
                        <div class="form-text">
                            JPG, PNG or WEBP, maximum 5 MB. A square or transparent logo works best.
                            Uploading a new logo replaces the current logo.
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="address">Address</label>
                <textarea
                    class="form-control"
                    id="address"
                    name="address"
                    rows="4"
                    maxlength="1000"
                    placeholder="Enter the school address"
                ><?= e($school['address'] ?? '') ?></textarea>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="contact_name">Contact Person</label>
                    <input
                        type="text"
                        class="form-control"
                        id="contact_name"
                        name="contact_name"
                        maxlength="150"
                        value="<?= e($school['contact_name'] ?? '') ?>"
                        placeholder="Name of school contact"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="contact_phone">Contact Person Phone</label>
                    <input
                        type="tel"
                        class="form-control"
                        id="contact_phone"
                        name="contact_phone"
                        maxlength="30"
                        value="<?= e($school['contact_phone'] ?? '') ?>"
                        placeholder="Example: 09-123 4567"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="contact_email">
                        Contact Email
                        <?php if ($emailLocked): ?>
                            <span class="badge text-bg-secondary ms-1">Locked</span>
                        <?php endif; ?>
                    </label>
                    <input
                        type="email"
                        class="form-control"
                        id="contact_email"
                        name="contact_email"
                        maxlength="150"
                        value="<?= e($school['contact_email'] ?? '') ?>"
                        placeholder="Example: school@example.com"
                        <?= $emailLocked ? 'readonly aria-readonly="true"' : '' ?>
                    >
                    <?php if ($emailLocked): ?>
                        <div class="form-text">This email has already been saved and cannot be changed from this page.</div>
                    <?php else: ?>
                        <div class="form-text">Once saved with a value, this email will be locked.</div>
                    <?php endif; ?>
                </div>

                <?php if ($referrerLocked): ?>
                    <div class="col-12">
                        <label class="form-label">
                            Referrer
                            <span class="badge text-bg-secondary ms-1">Locked</span>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            value="<?= e(
                                ($school['referrer_school_code'] ?: $school['referrer']) .
                                ($school['referrer_school_name'] ? ' — ' . $school['referrer_school_name'] : '')
                            ) ?>"
                            readonly
                            aria-readonly="true"
                        >
                        <div class="form-text">The referrer has already been saved and cannot be changed.</div>
                    </div>
                <?php else: ?>
                    <div class="col-md-5">
                        <label class="form-label" for="referrerSearch">Search Referrer</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input
                                type="search"
                                class="form-control"
                                id="referrerSearch"
                                placeholder="School name or code"
                                autocomplete="off"
                            >
                            <button
                                class="btn btn-outline-secondary"
                                type="button"
                                id="clearReferrerSearch"
                                title="Clear referrer search"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="form-text" id="referrerSearchResult">Type to filter the school list.</div>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label" for="referrerSchoolCode">Referrer</label>
                        <select class="form-select" name="referrer" id="referrerSchoolCode">
                            <option value="">No referrer</option>
                            <?php foreach ($referrerSchools as $referrerSchool): ?>
                                <option
                                    value="<?= e($referrerSchool['school_code']) ?>"
                                    <?= $selectedReferrerCode === strtoupper((string) $referrerSchool['school_code']) ? 'selected' : '' ?>
                                >
                                    <?= e($referrerSchool['school_name']) ?> (<?= e($referrerSchool['school_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The referrer is saved using its school code. Once saved, it cannot be changed.</div>
                    </div>
                <?php endif; ?>

                <div class="col-12 mt-3">
                    <h2 class="h5 border-bottom pb-2 mb-1">School Images</h2>
                    <p class="text-secondary small mb-0">
                        Upload up to 2 school images for the school profile, facilities, activities or programme participation.
                    </p>
                </div>

                <?php
                    $schoolGalleryImages = [
                        1 => trim((string) ($school['school_image_1'] ?? '')),
                        2 => trim((string) ($school['school_image_2'] ?? '')),
                    ];
                ?>

                <?php foreach ($schoolGalleryImages as $imageNumber => $schoolImagePath): ?>
                    <div class="col-md-6">
                        <div class="card h-100 border">
                            <div class="card-body">
                                <div class="fw-semibold mb-3">School Image <?= $imageNumber ?></div>

                                <?php if ($schoolImagePath !== ''): ?>
                                    <div class="ratio ratio-16x9 mb-3 bg-light rounded overflow-hidden border">
                                        <img
                                            src="<?= e('/' . ltrim($schoolImagePath, '/')) ?>"
                                            alt="<?= e($school['school_name']) ?> image <?= $imageNumber ?>"
                                            loading="lazy"
                                            style="width:100%;height:100%;object-fit:cover;"
                                        >
                                    </div>
                                <?php else: ?>
                                    <div class="ratio ratio-16x9 mb-3 bg-light rounded border">
                                        <div class="d-flex align-items-center justify-content-center text-secondary">
                                            <div class="text-center">
                                                <i class="bi bi-image fs-2 d-block mb-1"></i>
                                                No image uploaded
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <label class="form-label" for="school_image_<?= $imageNumber ?>">
                                    <?= $schoolImagePath !== '' ? 'Replace Image' : 'Upload Image' ?>
                                </label>
                                <input
                                    type="file"
                                    class="form-control"
                                    id="school_image_<?= $imageNumber ?>"
                                    name="school_image_<?= $imageNumber ?>"
                                    accept="image/jpeg,image/png,image/webp"
                                    <?= !$schoolMediaSchemaReady ? 'disabled' : '' ?>
                                >
                                <div class="form-text">JPG, PNG or WEBP, maximum 5 MB.</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Save Changes
                </button>
                <a href="schools.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php if (!$referrerLocked): ?>
<script>
(function () {
    var searchInput = document.getElementById('referrerSearch');
    var clearButton = document.getElementById('clearReferrerSearch');
    var select = document.getElementById('referrerSchoolCode');
    var result = document.getElementById('referrerSearchResult');

    if (!searchInput || !clearButton || !select || !result) {
        return;
    }

    function filterReferrers() {
        var query = searchInput.value.trim().toLowerCase();
        var visibleCount = 0;

        Array.prototype.forEach.call(select.options, function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            var matches = query === '' || option.text.toLowerCase().indexOf(query) !== -1;
            option.hidden = !matches;

            if (matches) {
                visibleCount++;
            }
        });

        result.textContent = query === ''
            ? 'Type to filter the school list.'
            : visibleCount + ' school(s) found.';
    }

    searchInput.addEventListener('input', filterReferrers);

    clearButton.addEventListener('click', function () {
        searchInput.value = '';
        filterReferrers();
        searchInput.focus();
    });
})();
</script>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
