<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';
requirePortalRole('school');

$schoolId = (int)($_SESSION['school_id'] ?? 0);
if ($schoolId < 1) {
    http_response_code(403);
    exit('School session is required.');
}

/** Fetch and confirm ownership from the authenticated school session. */
function schoolProfileSchool(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($school)) {
        throw new RuntimeException('School profile not found.');
    }
    return $school;
}

function schoolProfileScalar(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('School profile count error: ' . $e->getMessage());
        return 0;
    }
}

/** Invalidate only this school's generated public profile page. */
function schoolProfileInvalidatePublicCache(int $schoolId): void
{
    $cachedHtml = __DIR__ . '/../html/school_profile_' . $schoolId . '.html';
    if (is_file($cachedHtml)) {
        @unlink($cachedHtml);
    }
}

/**
 * Store a verified JPG, PNG or WEBP image in the existing school media folder.
 * Returns [web-relative path, absolute path] for use in SQL and rollback.
 */
function schoolProfileStoreImage(array $file, string $schoolCode, string $slot): array
{
    $maxBytes = 5 * 1024 * 1024;
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Select an image before clicking Upload.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'The selected image exceeds the server upload limit.'
                : 'Image upload failed. Please select the image again.'
        );
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) {
        throw new RuntimeException('Images must not exceed 5 MB each.');
    }
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid uploaded image.');
    }
    if (!class_exists('finfo')) {
        throw new RuntimeException('PHP Fileinfo is required for image uploads.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $formats = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($formats[$mime])) {
        throw new RuntimeException('Only JPG, PNG or WEBP images are allowed.');
    }

    $imageInfo = @getimagesize($tmp);
    if (!is_array($imageInfo)
        || (int)($imageInfo[0] ?? 0) < 1
        || (int)($imageInfo[1] ?? 0) < 1
        || (string)($imageInfo['mime'] ?? '') !== $mime
    ) {
        throw new RuntimeException('The selected file is not a valid image.');
    }
    if ($imageInfo[0] > 8000 || $imageInfo[1] > 8000) {
        throw new RuntimeException('Image dimensions must not exceed 8000 × 8000 pixels.');
    }

    $safeCode = strtoupper(trim($schoolCode));
    $safeCode = trim((string)preg_replace('/[^A-Z0-9_-]+/', '-', $safeCode), '-');
    if ($safeCode === '') {
        $safeCode = 'SCHOOL';
    }

    // Matches register_school.php: uploads/schools/{SCHOOL_CODE}/...
    $relativeDirectory = 'uploads/schools/' . $safeCode;
    $absoluteDirectory = __DIR__ . '/../' . $relativeDirectory;
    if (!is_dir($absoluteDirectory)
        && !@mkdir($absoluteDirectory, 0755, true)
        && !is_dir($absoluteDirectory)
    ) {
        throw new RuntimeException('Could not create the school image folder.');
    }
    if (!is_writable($absoluteDirectory)) {
        throw new RuntimeException('School image folder is not writable.');
    }

    $prefixes = [
        'school_logo' => 'school-logo',
        'school_image_1' => 'school-image-1',
        'school_image_2' => 'school-image-2',
    ];
    $filename = $prefixes[$slot] . '-' . date('Ymd-His')
        . '-' . bin2hex(random_bytes(8)) . '.' . $formats[$mime];

    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = $absoluteDirectory . '/' . $filename;
    if (!move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Unable to save the uploaded image.');
    }
    @chmod($absolutePath, 0644);

    return [$relativePath, $absolutePath];
}

try {
    $school = schoolProfileSchool($pdo, $schoolId);
} catch (Throwable $e) {
    http_response_code(404);
    exit('School profile not found.');
}

$uploadSlots = [
    'school_logo' => ['label' => 'School Logo', 'icon' => 'bi-image'],
    'school_image_1' => ['label' => 'School Image 1', 'icon' => 'bi-camera'],
    'school_image_2' => ['label' => 'School Image 2', 'icon' => 'bi-camera'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? 'save_profile');

        if ($action === 'upload_image') {
            $slot = (string)($_POST['image_slot'] ?? '');
            if (!array_key_exists($slot, $uploadSlots)) {
                throw new RuntimeException('Invalid image upload selection.');
            }

            $upload = $_FILES['school_image_upload'] ?? null;
            if (!is_array($upload) || is_array($upload['name'] ?? null)) {
                throw new RuntimeException('Please select one image to upload.');
            }

            $storedAbsolutePath = null;
            try {
                [$storedPath, $storedAbsolutePath] = schoolProfileStoreImage(
                    $upload,
                    (string)($school['school_code'] ?? ''),
                    $slot
                );

                // The column name is chosen only from the hardcoded whitelist.
                $stmt = $pdo->prepare('UPDATE schools SET `' . $slot . '` = ? WHERE id = ?');
                $stmt->execute([$storedPath, $schoolId]);
            } catch (Throwable $e) {
                if ($storedAbsolutePath !== null && is_file($storedAbsolutePath)) {
                    @unlink($storedAbsolutePath);
                }
                throw $e;
            }

            schoolProfileInvalidatePublicCache($schoolId);
            setFlash('success', $uploadSlots[$slot]['label'] . ' uploaded successfully.');
        } elseif ($action === 'save_profile') {
            $schoolType = trim((string)($_POST['school_type'] ?? ''));
            $district = trim((string)($_POST['district'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $contactName = trim((string)($_POST['contact_name'] ?? ''));
            $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
            $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
            $referrer = trim((string)($_POST['referrer'] ?? ''));

            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Contact email is invalid.');
            }

            // Do not touch existing image paths while editing profile details.
            $stmt = $pdo->prepare(
                'UPDATE schools SET school_type = ?, district = ?, address = ?,
                 contact_name = ?, contact_phone = ?, contact_email = ?, referrer = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $schoolType !== '' ? $schoolType : null,
                $district !== '' ? $district : null,
                $address !== '' ? $address : null,
                $contactName !== '' ? $contactName : null,
                $contactPhone !== '' ? $contactPhone : null,
                $contactEmail !== '' ? $contactEmail : null,
                $referrer !== '' ? $referrer : null,
                $schoolId,
            ]);

            schoolProfileInvalidatePublicCache($schoolId);
            setFlash('success', 'School profile updated successfully.');
        } else {
            throw new RuntimeException('Invalid school profile action.');
        }
    } catch (Throwable $e) {
        error_log('School profile update error: ' . $e->getMessage());
        setFlash('danger', $e->getMessage());
    }

    redirect('/school/profile.php');
}

$studentCount = schoolProfileScalar(
    $pdo, 'SELECT COUNT(*) FROM students WHERE school_id = ?', [$schoolId]
);
$teacherCount = schoolProfileScalar(
    $pdo, 'SELECT COUNT(*) FROM teachers WHERE school_id = ?', [$schoolId]
);
$parentCount = schoolProfileScalar(
    $pdo,
    'SELECT COUNT(DISTINCT sg.guardian_id) FROM student_guardians sg
     JOIN students st ON st.id = sg.student_id WHERE st.school_id = ?',
    [$schoolId]
);
$activeCount = schoolProfileScalar(
    $pdo,
    "SELECT COUNT(*) FROM students WHERE school_id = ?
     AND membership_status = 'active'
     AND membership_next_due_at IS NOT NULL
     AND membership_next_due_at >= NOW()",
    [$schoolId]
);

portalPageStart('school', 'profile', 'Profile');
?>

<style>
.school-media-preview{
    height:170px;
    border:1px solid var(--bs-border-color);
    border-radius:.6rem;
    display:flex;
    align-items:center;
    justify-content:center;
    background:var(--bs-tertiary-bg,#f8f9fa);
    overflow:hidden;
}
.school-media-preview img{
    width:100%;height:100%;object-fit:contain;
}
.school-media-preview .bi{font-size:2rem;color:var(--bs-secondary-color)}
.school-media-card .form-control{font-size:.85rem}
.school-media-card .btn{white-space:nowrap}
@media(max-width:767.98px){.school-media-preview{height:150px}}
</style>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Students', $studentCount, 'bi-people'],
        ['Active Students', $activeCount, 'bi-person-check'],
        ['Teachers', $teacherCount, 'bi-person-badge'],
        ['Parents', $parentCount, 'bi-person-hearts'],
    ] as [$label, $value, $icon]): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-secondary"><?= e($label) ?></div>
                        <div class="h3 mb-0"><?= number_format($value) ?></div>
                    </div>
                    <i class="bi <?= e($icon) ?> fs-2 text-primary"></i>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card stat-card mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">School Photos &amp; Logo</h2>
        <div class="small text-secondary mt-1">
            JPG, PNG or WEBP, maximum 5 MB per image. Upload each image separately.
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($uploadSlots as $slot => $meta): ?>
                <?php
                    $currentPath = trim((string)($school[$slot] ?? ''));
                    // Existing database values are stored as site-relative paths.
                    $currentUrl = $currentPath !== ''
                        ? '/' . ltrim(str_replace('\\', '/', $currentPath), '/')
                        : '';
                ?>
                <div class="col-md-4">
                    <div class="card h-100 school-media-card">
                        <div class="card-body d-flex flex-column gap-2">
                            <div class="fw-semibold"><?= e($meta['label']) ?></div>
                            <div class="school-media-preview" data-image-preview>
                                <?php if ($currentUrl !== ''): ?>
                                    <img src="<?= e($currentUrl) ?>"
                                         alt="<?= e($meta['label']) ?> preview"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="text-center text-secondary">
                                        <i class="bi <?= e($meta['icon']) ?> d-block"></i>
                                        <small>No image uploaded</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <form method="post" enctype="multipart/form-data"
                                  class="mt-auto d-grid gap-2" data-image-upload-form>
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="upload_image">
                                <input type="hidden" name="image_slot" value="<?= e($slot) ?>">
                                <input type="file" class="form-control form-control-sm"
                                       name="school_image_upload" accept="image/jpeg,image/png,image/webp"
                                       aria-label="Select <?= e($meta['label']) ?> image" required>
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-cloud-arrow-up me-1"></i>
                                    Upload <?= e($meta['label']) ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">Edit School Profile</h2>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_profile">
            <div class="col-md-3">
                <label class="form-label">School Code</label>
                <input class="form-control" value="<?= e((string)($school['school_code'] ?? '')) ?>" disabled>
            </div>
            <div class="col-md-6">
                <label class="form-label">School Name</label>
                <input class="form-control" value="<?= e((string)($school['school_name'] ?? '')) ?>" disabled>
            </div>
            <div class="col-md-3">
                <label class="form-label">State</label>
                <input class="form-control" value="<?= e((string)($school['state'] ?? '')) ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label">School Type</label>
                <input class="form-control" name="school_type"
                       value="<?= e((string)($school['school_type'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">District</label>
                <input class="form-control" name="district"
                       value="<?= e((string)($school['district'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Referrer School Code</label>
                <input class="form-control" name="referrer"
                       value="<?= e((string)($school['referrer'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"><?= e((string)($school['address'] ?? '')) ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact Name</label>
                <input class="form-control" name="contact_name"
                       value="<?= e((string)($school['contact_name'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact Phone</label>
                <input class="form-control" name="contact_phone"
                       value="<?= e((string)($school['contact_phone'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact Email</label>
                <input type="email" class="form-control" name="contact_email"
                       value="<?= e((string)($school['contact_email'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-save me-1"></i>Save Profile
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-image-upload-form]').forEach(function (form) {
    var input = form.querySelector('input[type=file]');
    var preview = form.closest('.school-media-card').querySelector('[data-image-preview]');
    var lastObjectUrl = null;
    if (!input || !preview) return;
    input.addEventListener('change', function () {
        if (lastObjectUrl) {
            URL.revokeObjectURL(lastObjectUrl);
            lastObjectUrl = null;
        }
        var file = input.files && input.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
            || file.size > 5 * 1024 * 1024) {
            input.setCustomValidity('Choose a JPG, PNG or WEBP image up to 5 MB.');
            input.reportValidity();
            return;
        }
        input.setCustomValidity('');
        lastObjectUrl = URL.createObjectURL(file);
        var img = document.createElement('img');
        img.src = lastObjectUrl;
        img.alt = 'Selected image preview';
        preview.replaceChildren(img);
    });
});
</script>

<?php portalPageEnd(); ?>
