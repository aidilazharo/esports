<?php
require_once 'config.php';

$schoolId = (int) ($_GET['school_id'] ?? 0);
$month = monthRange(null); // Always use the current month.
$studentSearch = trim((string) ($_GET['student_search'] ?? ''));
$membershipStatus = trim((string) ($_GET['membership_status'] ?? ''));
$classFilter = trim((string) ($_GET['class_name'] ?? ''));
$allowedMembershipStatuses = [
    'pending_registration',
    'pending_payment',
    'registered',
    'pending_activation',
    'active',
    'inactive',
    'cancelled',
];

function schoolReportColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function schoolReportTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function schoolReportIsActiveStudent(array $student): bool
{
    if (($student['membership_status'] ?? '') !== 'active') {
        return false;
    }

    if (empty($student['membership_next_due_at'])) {
        return false;
    }

    $expiryTimestamp = strtotime((string) $student['membership_next_due_at']);
    return $expiryTimestamp !== false && $expiryTimestamp >= time();
}

function schoolReportMaskName(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $length = mb_strlen($name, 'UTF-8');
        $visibleLength = max(1, (int) ceil($length / 2));
        return rtrim(mb_substr($name, 0, $visibleLength, 'UTF-8')) . ' xxx';
    }

    $length = strlen($name);
    $visibleLength = max(1, (int) ceil($length / 2));
    return rtrim(substr($name, 0, $visibleLength)) . ' xxx';
}

$schoolHasReferrer = schoolReportColumnExists($pdo, 'schools', 'referrer');
$schoolHasLogo = schoolReportColumnExists($pdo, 'schools', 'school_logo');

if (!in_array($membershipStatus, $allowedMembershipStatuses, true)) {
    $membershipStatus = '';
}
$monthDate = substr($month['start'], 0, 10);
$school = null;
$students = $teachers = [];
$displayTeachers = [];
$filteredStudents = [];
$pagedStudents = [];
$filteredStudentCount = 0;
$classOptions = [];
$attendanceMap = $progressMap = [];
$teacherAttendanceMap = $teacherProgressMap = [];
$teacherAttendanceTableReady = schoolReportTableExists($pdo, 'teacher_attendance_sessions');
$teacherModuleProgressTableReady = schoolReportTableExists($pdo, 'teacher_module_progress');
$learningModuleCount = schoolReportTableExists($pdo, 'learning_modules')
    ? (int) $pdo->query('SELECT COUNT(*) FROM learning_modules')->fetchColumn()
    : 20;
$learningModuleCount = max(1, $learningModuleCount);
$activeCount = 0;
$totalAttendanceMinutes = 0;
$totalModulesCompleted = 0;

$equipmentMinimumActiveStudents = 10;

// School Reference Eligibility:
// - the current/referrer school must have at least 5 registered students;
// - at least 2 schools must use this school as their referrer;
// - at least 2 of those referred schools must each have at least 30 active students.
$referenceMinimumSchools = 2;
$referenceMinimumActiveStudents = 30;
$referrerMinimumStudents = 5;

$referredSchools = [];
$qualifiedReferenceSchools = 0;
$schoolReferenceEligible = false;

if ($schoolId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();

    if ($school) {
        $stmt = $pdo->prepare(
            "SELECT st.*,
                (
                    SELECT MAX(p.paid_at)
                    FROM payments p
                    WHERE p.student_id = st.id
                      AND p.payment_type = 'annual_membership'
                      AND p.status = 'paid'
                ) AS last_annual_membership_paid_at
             FROM students st
             WHERE st.school_id = ?
             ORDER BY st.full_name"
        );
        $stmt->execute([$schoolId]);
        $students = $stmt->fetchAll();

        // Equipment eligibility counts every active student with a valid membership period,
        // including both RM100-paid and coupon-activated memberships.
        $activeCount = count(array_filter($students, 'schoolReportIsActiveStudent'));

        $stmt = $pdo->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY full_name');
        $stmt->execute([$schoolId]);
        $teachers = $stmt->fetchAll();
        $displayTeachers = $teachers;

        if ($teacherAttendanceTableReady) {
            $teacherAttendanceStmt = $pdo->prepare(
                "SELECT ta.teacher_id,
                        SUM(CASE WHEN ta.status = 'completed' THEN ta.credited_minutes ELSE 0 END) AS credited_minutes,
                        SUM(CASE WHEN ta.status = 'completed' THEN 1 ELSE 0 END) AS session_count,
                        MAX(CASE WHEN ta.status = 'open' THEN ta.check_in_at END) AS open_check_in
                 FROM teacher_attendance_sessions ta
                 JOIN teachers t ON t.id = ta.teacher_id
                 WHERE t.school_id = ? AND ta.attendance_month = ?
                 GROUP BY ta.teacher_id"
            );
            $teacherAttendanceStmt->execute([$schoolId, $monthDate]);
            foreach ($teacherAttendanceStmt->fetchAll() as $row) {
                $teacherAttendanceMap[(int) $row['teacher_id']] = $row;
            }
        }

        if ($teacherModuleProgressTableReady) {
            $teacherProgressStmt = $pdo->prepare(
                "SELECT tmp.teacher_id,
                        SUM(CASE WHEN tmp.progress_status = 'completed' THEN 1 ELSE 0 END) AS completed_modules,
                        AVG(tmp.total_score) AS average_score
                 FROM teacher_module_progress tmp
                 JOIN teachers t ON t.id = tmp.teacher_id
                 WHERE t.school_id = ?
                 GROUP BY tmp.teacher_id"
            );
            $teacherProgressStmt->execute([$schoolId]);
            foreach ($teacherProgressStmt->fetchAll() as $row) {
                $teacherProgressMap[(int) $row['teacher_id']] = $row;
            }
        }

        // Schools that use the current school as their referrer.
        // Each referred school qualifies when it has at least 30 active students.
        // Overall reference eligibility requires at least 2 qualified referred schools
        // and at least 5 registered students in the current/referrer school.
        if ($schoolHasReferrer) {
            $referenceStmt = $pdo->prepare(
                "SELECT rs.*,
                    (SELECT COUNT(*)
                     FROM students rst
                     WHERE rst.school_id = rs.id
                       AND rst.membership_status = 'active'
                       AND rst.membership_next_due_at IS NOT NULL
                       AND rst.membership_next_due_at >= NOW()) AS active_student_count,
                    (SELECT rst.full_name
                     FROM students rst
                     WHERE rst.school_id = rs.id
                       AND rst.membership_status = 'active'
                       AND rst.membership_next_due_at IS NOT NULL
                       AND rst.membership_next_due_at >= NOW()
                     ORDER BY COALESCE(rst.membership_activation_paid_at, DATE_SUB(rst.membership_next_due_at, INTERVAL 1 YEAR), rst.created_at) DESC, rst.id DESC
                     LIMIT 1) AS latest_active_student_name,
                    (SELECT rst.student_number
                     FROM students rst
                     WHERE rst.school_id = rs.id
                       AND rst.membership_status = 'active'
                       AND rst.membership_next_due_at IS NOT NULL
                       AND rst.membership_next_due_at >= NOW()
                     ORDER BY COALESCE(rst.membership_activation_paid_at, DATE_SUB(rst.membership_next_due_at, INTERVAL 1 YEAR), rst.created_at) DESC, rst.id DESC
                     LIMIT 1) AS latest_active_student_number,
                    (SELECT COALESCE(
                                rst.membership_activation_paid_at,
                                DATE_SUB(rst.membership_next_due_at, INTERVAL 1 YEAR),
                                rst.created_at
                            )
                     FROM students rst
                     WHERE rst.school_id = rs.id
                       AND rst.membership_status = 'active'
                       AND rst.membership_next_due_at IS NOT NULL
                       AND rst.membership_next_due_at >= NOW()
                     ORDER BY COALESCE(
                                rst.membership_activation_paid_at,
                                DATE_SUB(rst.membership_next_due_at, INTERVAL 1 YEAR),
                                rst.created_at
                              ) DESC, rst.id DESC
                     LIMIT 1) AS latest_active_student_date
                 FROM schools rs
                 WHERE UPPER(TRIM(COALESCE(rs.referrer, ''))) = ?
                   AND rs.id <> ?
                 ORDER BY rs.school_name"
            );
            $referenceStmt->execute([
                strtoupper(trim((string) ($school['school_code'] ?? ''))),
                $schoolId,
            ]);
            $referredSchools = $referenceStmt->fetchAll();

            foreach ($referredSchools as &$referredSchool) {
                $referredActiveCount = (int) ($referredSchool['active_student_count'] ?? 0);
                $referredSchoolQualified = $referredActiveCount >= $referenceMinimumActiveStudents;

                $referredSchool['reference_school_qualified'] = $referredSchoolQualified ? 1 : 0;

                if ($referredSchoolQualified) {
                    $qualifiedReferenceSchools++;
                }
            }
            unset($referredSchool);

            $schoolReferenceEligible =
                count($students) >= $referrerMinimumStudents
                && count($referredSchools) >= $referenceMinimumSchools
                && $qualifiedReferenceSchools >= $referenceMinimumSchools;
        }

        $stmt = $pdo->prepare(
            "SELECT a.student_id,
                    SUM(CASE WHEN a.status = 'completed' THEN a.credited_minutes ELSE 0 END) AS credited_minutes,
                    SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS session_count,
                    MAX(CASE WHEN a.status = 'open' THEN a.check_in_at END) AS open_check_in
             FROM attendance_sessions a
             JOIN students st ON st.id = a.student_id
             WHERE st.school_id = ? AND a.attendance_month = ?
             GROUP BY a.student_id"
        );
        $stmt->execute([$schoolId, $monthDate]);
        foreach ($stmt->fetchAll() as $row) {
            $attendanceMap[(int) $row['student_id']] = $row;
            $totalAttendanceMinutes += (int) $row['credited_minutes'];
        }

        $stmt = $pdo->prepare(
            "SELECT smp.student_id,
                    SUM(CASE WHEN smp.progress_status = 'completed' THEN 1 ELSE 0 END) AS completed_modules,
                    AVG(smp.total_score) AS average_score
             FROM student_module_progress smp
             JOIN students st ON st.id = smp.student_id
             WHERE st.school_id = ?
             GROUP BY smp.student_id"
        );
        $stmt->execute([$schoolId]);
        foreach ($stmt->fetchAll() as $row) {
            $progressMap[(int) $row['student_id']] = $row;
            $totalModulesCompleted += (int) $row['completed_modules'];
        }

        $classOptions = array_values(array_unique(array_filter(array_map(
            static fn(array $student): string => trim((string) ($student['class_name'] ?? '')),
            $students
        ))));
        natcasesort($classOptions);
        $classOptions = array_values($classOptions);

        $filteredStudents = array_values(array_filter(
            $students,
            static function (array $student) use ($studentSearch, $membershipStatus, $classFilter): bool {
                if ($membershipStatus !== '' && (string) $student['membership_status'] !== $membershipStatus) {
                    return false;
                }

                if ($classFilter !== '' && (string) ($student['class_name'] ?? '') !== $classFilter) {
                    return false;
                }

                if ($studentSearch === '') {
                    return true;
                }

                $haystack = implode(' ', [
                    (string) ($student['full_name'] ?? ''),
                    (string) ($student['student_number'] ?? ''),
                    (string) ($student['class_name'] ?? ''),
                ]);

                return stripos($haystack, $studentSearch) !== false;
            }
        ));

        $filteredStudentCount = count($filteredStudents);
        $pagedStudents = $filteredStudents;
    }
}

$equipmentStudentsNeeded = max(0, $equipmentMinimumActiveStudents - $activeCount);
$equipmentReceivedSets = ($activeCount >= $equipmentMinimumActiveStudents ? 1 : 0)
    + ($schoolReferenceEligible ? 1 : 0);

if ($activeCount >= $equipmentMinimumActiveStudents) {
    $reward = [
        'level' => 'Eligible',
        'description' => 'This school has reached the minimum of '
            . $equipmentMinimumActiveStudents
            . ' active students and is eligible for programme equipment.',
        'badge' => 'success',
    ];
} else {
    $reward = [
        'level' => 'Not Yet Eligible',
        'description' => 'A minimum of '
            . $equipmentMinimumActiveStudents
            . ' active students is required. '
            . $equipmentStudentsNeeded
            . ' more active student'
            . ($equipmentStudentsNeeded === 1 ? '' : 's')
            . ' needed.',
        'badge' => 'secondary',
    ];
}

$pageTitle = 'School Report';
require 'includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">

<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div><h1 class="h2 mb-1">School Report</h1><p class="text-secondary mb-0">Teachers, memberships, equipment, attendance, learning progress and school reference eligibility.</p></div>
    <?php if ($school): ?><button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()"><i class="bi bi-printer me-2"></i>Print Report</button><?php endif; ?>
</div>

<div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <div>
        <i class="bi bi-calendar3 me-2"></i>
        <strong>Report Month:</strong> <?= e($month['label']) ?>
    </div>
    <div class="small text-secondary">Automatically uses the current month.</div>
</div>

<?php if ($school): ?>
<div class="card content-card mb-4"><div class="card-body p-4">
    <div class="row g-3 align-items-center">
        <div class="col-md-8">
            <span class="badge text-bg-primary mb-2"><?= e($school['school_code']) ?></span>
            <div class="d-flex align-items-center gap-3 mb-1">
                <?php $currentSchoolLogo = $schoolHasLogo ? trim((string) ($school['school_logo'] ?? '')) : ''; ?>
                <?php if ($currentSchoolLogo !== ''): ?>
                    <img src="<?= e('/' . ltrim($currentSchoolLogo, '/')) ?>" alt="<?= e($school['school_name']) ?> logo" width="48" height="48" loading="lazy" style="width:48px;height:48px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;background:#fff;padding:3px;">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center border rounded bg-light text-secondary flex-shrink-0" style="width:48px;height:48px;" title="No school logo uploaded"><i class="bi bi-building"></i></div>
                <?php endif; ?>
                <h2 class="h3 mb-0"><?= e($school['school_name']) ?></h2>
            </div>
            <div class="text-secondary"><?= e($school['school_type']) ?> · <?= e($school['district']) ?>, <?= e($school['state']) ?></div>
        </div>
        <div class="col-md-4"><div class="small text-secondary">School Contact</div><div class="fw-semibold"><?= e($school['contact_name'] ?: '-') ?></div><div><?= e($school['contact_phone']) ?></div><div><?= e($school['contact_email']) ?></div></div>
    </div>
</div></div>

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body p-4"><div class="text-secondary">Total Students</div><div class="display-6 fw-semibold"><?= count($students) ?></div></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body p-4"><div class="text-secondary">Active Students</div><div class="display-6 fw-semibold text-success"><?= $activeCount ?></div></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body p-4"><div class="text-secondary">School Team Teachers</div><div class="display-6 fw-semibold"><?= count($teachers) ?></div></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body p-4"><div class="text-secondary">Equipment Received</div><div class="h2 fw-semibold mb-0"><?= (int) $equipmentReceivedSets ?> <?= $equipmentReceivedSets === 1 ? 'Set' : 'Sets' ?></div></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body p-4"><div class="text-secondary">Attendance — <?= e($month['label']) ?></div><div class="h2 fw-semibold mb-0"><?= e(formatMinutes($totalAttendanceMinutes)) ?></div></div></div></div>
    <div class="col-md-6 col-xl-4"><div class="card stat-card h-100"><div class="card-body p-4"><div class="text-secondary">Modules Completed</div><div class="h2 fw-semibold mb-0"><?= number_format($totalModulesCompleted) ?></div></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card content-card h-100 border border-<?= e($reward['badge']) ?>">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                        <div class="small text-uppercase fw-semibold text-secondary">Equipment Eligibility</div>
                        <div class="h5 mb-0"><?= e($reward['level']) ?></div>
                    </div>
                    <span class="badge text-bg-<?= e($reward['badge']) ?>"><?= $activeCount ?>/<?= (int) $equipmentMinimumActiveStudents ?></span>
                </div>
                <div class="small text-secondary">
                    <?= $activeCount >= $equipmentMinimumActiveStudents
                        ? 'Qualified for 1 equipment set.'
                        : e($equipmentStudentsNeeded . ' more active student' . ($equipmentStudentsNeeded === 1 ? '' : 's') . ' needed.') ?>
                </div>
                <div class="small fw-semibold mt-2">PS5 + 2 controllers + PS Camera + 43-inch TV</div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card content-card h-100 border border-<?= $schoolReferenceEligible ? 'success' : 'secondary' ?>">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                        <div class="small text-uppercase fw-semibold text-secondary">School Reference Eligibility</div>
                        <div class="h5 mb-0"><?= $schoolReferenceEligible ? 'Eligible' : 'Not Yet Eligible' ?></div>
                    </div>
                    <span class="badge text-bg-<?= $schoolReferenceEligible ? 'success' : 'secondary' ?>">
                        <?= $qualifiedReferenceSchools ?>/<?= (int) $referenceMinimumSchools ?> Qualified
                    </span>
                </div>

                <?php if (!$schoolHasReferrer): ?>
                    <div class="small text-warning">School referral information is not available.</div>
                <?php else: ?>
                    <div class="small text-secondary">
                        Requires <?= (int) $referrerMinimumStudents ?> students in this school and
                        <?= (int) $referenceMinimumSchools ?> referred schools with
                        <?= (int) $referenceMinimumActiveStudents ?> active students each.
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <span class="badge text-bg-light border text-dark"><?= count($students) ?> own students</span>
                        <span class="badge text-bg-light border text-dark"><?= count($referredSchools) ?> referred schools</span>
                        <span class="badge text-bg-light border text-dark"><?= $qualifiedReferenceSchools ?> qualified</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card content-card mb-4">
    <div class="card-header bg-white p-4 d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-center">
        <h3 class="h5 mb-0">School Team Teachers</h3>
        <span class="small text-secondary"><?= count($teachers) ?> teachers · 5 per page</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="teacherTable" class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Teacher</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Expired Date</th>
                        <th><?= e($month['label']) ?> Hours</th>
                        <th>Learning</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($displayTeachers as $teacher):
                    $teacherAttendance = $teacherAttendanceMap[(int) $teacher['id']] ?? [
                        'credited_minutes' => 0,
                        'session_count' => 0,
                        'open_check_in' => null,
                    ];
                    $teacherProgress = $teacherProgressMap[(int) $teacher['id']] ?? [
                        'completed_modules' => 0,
                        'average_score' => null,
                    ];

                    $teacherRegisteredTimestamp = !empty($teacher['created_at'])
                        ? strtotime((string) $teacher['created_at'])
                        : false;
                    $teacherExpiryTimestamp = $teacherRegisteredTimestamp !== false
                        ? strtotime('+1 year', $teacherRegisteredTimestamp)
                        : false;
                    $teacherEmailVerified = !empty($teacher['email_verified_at']);
                    $teacherActive = $teacherEmailVerified
                        && $teacherExpiryTimestamp !== false
                        && $teacherExpiryTimestamp >= time();
                    $teacherExpired = $teacherEmailVerified
                        && $teacherExpiryTimestamp !== false
                        && $teacherExpiryTimestamp < time();

                    if ($teacherActive) {
                        $teacherStatusLabel = 'Active';
                        $teacherStatusBadge = 'success';
                    } elseif ($teacherExpired) {
                        $teacherStatusLabel = 'Expired';
                        $teacherStatusBadge = 'danger';
                    } else {
                        $teacherStatusLabel = 'Pending Verification';
                        $teacherStatusBadge = 'warning';
                    }
                ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-semibold"><?= e(schoolReportMaskName((string) ($teacher['full_name'] ?? ''))) ?></div>
                        </td>
                        <td><?= e((string) ($teacher['position'] ?? '')) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e($teacherStatusBadge) ?>"><?= e($teacherStatusLabel) ?></span>
                            <?php if ($teacherAttendance['open_check_in']): ?>
                                <div><span class="badge text-bg-warning mt-1">Checked In</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($teacherRegisteredTimestamp !== false): ?>
                                <span class="fw-semibold"><?= e(date('d M Y', $teacherRegisteredTimestamp)) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($teacherExpiryTimestamp !== false): ?>
                                <span class="<?= $teacherExpired ? 'text-danger fw-semibold' : 'fw-semibold' ?>">
                                    <?= e(date('d M Y', $teacherExpiryTimestamp)) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($teacherAttendanceTableReady): ?>
                                <div class="fw-semibold"><?= e(formatMinutes((int) $teacherAttendance['credited_minutes'])) ?> / 5h</div>
                                <small class="text-secondary"><?= (int) $teacherAttendance['session_count'] ?> sessions</small>
                            <?php else: ?>
                                <span class="text-secondary">Run teacher migration</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($teacherModuleProgressTableReady): ?>
                                <div class="fw-semibold"><?= (int) $teacherProgress['completed_modules'] ?>/<?= (int) $learningModuleCount ?> modules</div>
                                <small class="text-secondary">
                                    <?= $teacherProgress['average_score'] !== null
                                        ? e(number_format((float) $teacherProgress['average_score'], 2)) . '% average'
                                        : 'No score' ?>
                                </small>
                            <?php else: ?>
                                <span class="text-secondary">Run teacher migration</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end no-print text-nowrap">
                            <?php if ($teacherActive): ?>
                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="teacher_qr.php?teacher_id=<?= (int) $teacher['id'] ?>"
                                    title="Teacher Attendance QR"
                                    aria-label="Teacher Attendance QR"
                                >
                                    <i class="bi bi-qr-code"></i>
                                </a>
                                <a
                                    class="btn btn-sm btn-outline-info"
                                    href="teacher_learning_modules.php?teacher_id=<?= (int) $teacher['id'] ?>"
                                    title="Teacher Learning Modules"
                                    aria-label="Teacher Learning Modules"
                                >
                                    <i class="bi bi-journal-text"></i>
                                </a>
                            <?php endif; ?>
                            <a
                                class="btn btn-sm btn-outline-success"
                                href="teacher_certificate.php?teacher_id=<?= (int) $teacher['id'] ?>"
                                title="Teacher Certificate"
                                aria-label="Teacher Certificate"
                            >
                                <i class="bi bi-award"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card content-card">
    <div class="card-header bg-white p-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
            <div>
                <h3 class="h5 mb-0">Registered Students</h3>
                <div class="small text-secondary mt-1">Membership: RM100 per year</div>
            </div>
            <span class="small text-secondary">
                <?= number_format($filteredStudentCount) ?> students · <?= $activeCount ?> active · 5 per page
            </span>
        </div>
    </div>

    <div class="card-body border-bottom p-4 no-print">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="school_id" value="<?= (int) $schoolId ?>">
            <input type="hidden" name="month" value="<?= e($month['key']) ?>">

            <div class="col-lg-5">
                <label class="form-label" for="student_search">Search Student</label>
                <input
                    type="search"
                    class="form-control"
                    id="student_search"
                    name="student_search"
                    value="<?= e($studentSearch) ?>"
                    placeholder="Name, student number or class"
                >
            </div>

            <div class="col-lg-3">
                <label class="form-label" for="membership_status">Membership Status</label>
                <select class="form-select" id="membership_status" name="membership_status">
                    <option value="">All statuses</option>
                    <option value="pending_activation" <?= $membershipStatus === 'pending_activation' ? 'selected' : '' ?>>Pending Membership Payment</option>
                    <option value="active" <?= $membershipStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $membershipStatus === 'inactive' ? 'selected' : '' ?>>Expired / Inactive</option>
                    <option value="cancelled" <?= $membershipStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="pending_registration" <?= $membershipStatus === 'pending_registration' ? 'selected' : '' ?>>Pending Registration (Legacy)</option>
                    <option value="pending_payment" <?= $membershipStatus === 'pending_payment' ? 'selected' : '' ?>>Pending Payment (Legacy)</option>
                    <option value="registered" <?= $membershipStatus === 'registered' ? 'selected' : '' ?>>Registered (Legacy)</option>
                </select>
            </div>

            <div class="col-lg-2">
                <label class="form-label" for="class_name">Class</label>
                <select class="form-select" id="class_name" name="class_name">
                    <option value="">All classes</option>
                    <?php foreach ($classOptions as $className): ?>
                        <option value="<?= e($className) ?>" <?= $classFilter === $className ? 'selected' : '' ?>><?= e($className) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 d-grid gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="school_report.php?school_id=<?= (int) $schoolId ?>">Clear Filters</a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="studentTable" class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Student</th>
                        <th>Class</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Expired Date</th>
                        <th><?= e($month['label']) ?> Hours</th>
                        <th>Learning</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pagedStudents as $student):
                    $attendance = $attendanceMap[(int) $student['id']] ?? [
                        'credited_minutes' => 0,
                        'session_count' => 0,
                        'open_check_in' => null,
                    ];
                    $progress = $progressMap[(int) $student['id']] ?? [
                        'completed_modules' => 0,
                        'average_score' => null,
                    ];

                    $membershipStartAt = $student['last_annual_membership_paid_at'] ?? null;

                    // Backward compatibility with membership records created before
                    // annual_membership payment_type was introduced.
                    if (!$membershipStartAt && !empty($student['membership_activation_paid_at'])) {
                        $membershipStartAt = $student['membership_activation_paid_at'];
                    }

                    // Coupon memberships do not have a payment timestamp. For annual
                    // memberships, derive the start date from the one-year expiry.
                    if (
                        !$membershipStartAt
                        && ($student['membership_status'] ?? '') === 'active'
                        && !empty($student['membership_next_due_at'])
                    ) {
                        $derivedStart = strtotime('-1 year', strtotime((string) $student['membership_next_due_at']));
                        if ($derivedStart !== false) {
                            $membershipStartAt = date('Y-m-d H:i:s', $derivedStart);
                        }
                    }

                    $membershipExpiryTimestamp = !empty($student['membership_next_due_at'])
                        ? strtotime((string) $student['membership_next_due_at'])
                        : false;

                    $membershipExpired = $membershipExpiryTimestamp !== false
                        && $membershipExpiryTimestamp < time();
                ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-semibold"><?= e(schoolReportMaskName((string) ($student['full_name'] ?? ''))) ?></div>
                            <small class="text-secondary"><?= e($student['student_number']) ?></small>
                        </td>

                        <td><?= e($student['class_name']) ?></td>

                        <td>
                            <span class="badge text-bg-<?= e(membershipBadge($student['membership_status'])) ?>">
                                <?= e(membershipLabel($student['membership_status'])) ?>
                            </span>
                            <div class="small text-secondary mt-1">RM100 / 1 year</div>

                            <?php if ($attendance['open_check_in']): ?>
                                <div><span class="badge text-bg-warning mt-1">Checked In</span></div>
                            <?php endif; ?>

                            <?php if (!empty($student['stripe_subscription_id'])): ?>
                                <div class="small text-warning mt-1">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Legacy Stripe subscription
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($membershipStartAt): ?>
                                <span class="fw-semibold">
                                    <?= e(date('d M Y', strtotime((string) $membershipStartAt))) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($membershipExpiryTimestamp !== false): ?>
                                <span class="<?= $membershipExpired ? 'text-danger fw-semibold' : 'fw-semibold' ?>">
                                    <?= e(date('d M Y', $membershipExpiryTimestamp)) ?>
                                </span>
                                <?php if ($membershipExpired): ?>
                                    <div class="small text-danger">Expired</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="fw-semibold"><?= e(formatMinutes((int) $attendance['credited_minutes'])) ?> / 5h</div>
                            <small class="text-secondary"><?= (int) $attendance['session_count'] ?> sessions</small>
                        </td>

                        <td>
                            <div class="fw-semibold"><?= (int) $progress['completed_modules'] ?>/<?= (int) $learningModuleCount ?> modules</div>
                            <small class="text-secondary">
                                <?= $progress['average_score'] !== null
                                    ? e(number_format((float) $progress['average_score'], 2)) . '% average'
                                    : 'No score' ?>
                            </small>
                        </td>

                        <td class="text-end no-print text-nowrap">
                            <?php
                                $studentMembershipActive = ($student['membership_status'] ?? '') === 'active'
                                    && $membershipExpiryTimestamp !== false
                                    && $membershipExpiryTimestamp >= time();
                            ?>

                            <?php if ($studentMembershipActive): ?>
                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="student_qr.php?student_id=<?= (int) $student['id'] ?>"
                                    title="Attendance QR"
                                    aria-label="Attendance QR"
                                >
                                    <i class="bi bi-qr-code"></i>
                                </a>
                            <?php endif; ?>

                            <a
                                class="btn btn-sm btn-outline-success"
                                href="student_certificate.php?student_id=<?= (int) $student['id'] ?>"
                                title="Student Certificate"
                                aria-label="Student Certificate"
                            >
                                <i class="bi bi-award"></i>
                            </a>

                            <?php if (!$studentMembershipActive): ?>
                                <a
                                    class="btn btn-sm btn-outline-warning"
                                    href="redeem_coupon.php?student_id=<?= (int) $student['id'] ?>"
                                    title="Redeem Membership Coupon"
                                    aria-label="Redeem Membership Coupon"
                                >
                                    <i class="bi bi-ticket-perforated"></i>
                                </a>

                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="student_payment.php?student_id=<?= (int) $student['id'] ?>"
                                    title="Pay RM100 Membership"
                                    aria-label="Pay RM100 Membership"
                                >
                                    <i class="bi bi-credit-card"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
<?php elseif ($schoolId > 0): ?>
<div class="alert alert-danger">School not found.</div>
<?php else: ?>
<div class="text-center py-5 text-secondary"><i class="bi bi-building display-3 d-block mb-3"></i>Open this report from the Schools directory.</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.jQuery === 'undefined' || !jQuery.fn.DataTable) {
        return;
    }

    const commonOptions = {
        pageLength: 5,
        lengthChange: false,
        paging: true,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        language: {
            search: 'Search:',
            emptyTable: 'No records found.'
        }
    };

    if (document.getElementById('teacherTable')) {
        $('#teacherTable').DataTable({
            ...commonOptions,
            order: [[0, 'asc']],
            language: {
                ...commonOptions.language,
                emptyTable: 'No teachers registered.'
            },
            columnDefs: [
                { orderable: false, searchable: false, targets: -1 }
            ]
        });
    }

    if (document.getElementById('studentTable')) {
        $('#studentTable').DataTable({
            ...commonOptions,
            order: [[0, 'asc']],
            language: {
                ...commonOptions.language,
                emptyTable: <?= json_encode(
                    !$students
                        ? 'No students registered.'
                        : (!$filteredStudents
                            ? 'No students match the selected search and filters.'
                            : 'No records found.'),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) ?>
            },
            columnDefs: [
                { orderable: false, searchable: false, targets: -1 }
            ]
        });
    }
});
</script>

<?php require 'includes/footer.php'; ?>
