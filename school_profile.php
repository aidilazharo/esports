<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/includes/profile_scoring.php';
require_once __DIR__ . '/includes/video_gallery.php';
require_once __DIR__ . '/includes/equipment_timeline.php';

$schoolId = (int)($_GET['school_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT *
     FROM schools
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    http_response_code(404);
    $pageTitle = 'School Profile';
    require 'includes/header.php';
    echo '<div class="alert alert-danger">School profile not found.</div>';
    require 'includes/footer.php';
    exit;
}

$scoreData = profileScoringData($pdo);
$studentIds = profileStudentIdsForSchool($pdo, $schoolId);
$scoreSummary = profileAggregateStudentScores(
    $scoreData,
    $studentIds
);
$activity = profileActivitySummary($pdo, $studentIds);

$schoolScore = $scoreData['schools'][$schoolId] ?? [
    'points' => 0,
    'rank' => null,
];

$activeStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM students
     WHERE school_id = ?
       AND membership_status = 'active'
       AND membership_next_due_at IS NOT NULL
       AND membership_next_due_at >= NOW()"
);
$activeStmt->execute([$schoolId]);
$activeStudents = (int)$activeStmt->fetchColumn();

$teacherStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM teachers
     WHERE school_id = ?'
);
$teacherStmt->execute([$schoolId]);
$teacherCount = (int)$teacherStmt->fetchColumn();

$schoolTournaments = profileTournamentRowsForStudents(
    $pdo,
    $studentIds,
    $scoreData
);

$schoolCertificates = profileCertificateRowsForStudents(
    $pdo,
    $studentIds,
    $scoreData
);

$topStudents = [];

if ($studentIds) {
    $studentStmt = $pdo->prepare(
        'SELECT
            id,
            student_number,
            full_name,
            class_name,
            membership_status,
            membership_next_due_at
         FROM students
         WHERE school_id = ?
         ORDER BY full_name'
    );
    $studentStmt->execute([$schoolId]);

    foreach ($studentStmt->fetchAll() as $student) {
        $studentId = (int)$student['id'];
        $studentScore = $scoreData['students'][$studentId] ?? null;

        $student['points'] =
            (int)($studentScore['points'] ?? 0);

        $student['rank'] =
            $studentScore['rank'] ?? null;

        $topStudents[] = $student;
    }

    usort(
        $topStudents,
        static function (array $a, array $b): int {
            $compare =
                ((int)$b['points'])
                <=>
                ((int)$a['points']);

            if ($compare !== 0) {
                return $compare;
            }

            return strcmp(
                (string)$a['full_name'],
                (string)$b['full_name']
            );
        }
    );

}

$schoolTeachers = [];

$teacherEmailVerifiedColumn = false;

try {
    $columnStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'teachers'
           AND COLUMN_NAME = 'email_verified_at'"
    );
    $columnStmt->execute();
    $teacherEmailVerifiedColumn =
        (int)$columnStmt->fetchColumn() > 0;
} catch (Throwable $e) {
    $teacherEmailVerifiedColumn = false;
}

$teacherVerifiedSelect =
    $teacherEmailVerifiedColumn
        ? 't.email_verified_at'
        : 'NULL AS email_verified_at';

$stmt = $pdo->prepare(
    "SELECT
        t.id,
        t.full_name,
        t.position,
        t.email,
        t.created_at,
        {$teacherVerifiedSelect}
     FROM teachers t
     WHERE t.school_id = ?
     ORDER BY
        t.full_name ASC"
);

$stmt->execute([
    $schoolId,
]);

$schoolTeachers =
    $stmt->fetchAll();


$pageTitle = 'School Profile';
require 'includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css"
>

<style>
.profile-page{max-width:1280px;margin:0 auto}
.profile-stat-card,.profile-section-card{border:0;box-shadow:0 .125rem .5rem rgba(0,0,0,.06)}
.profile-avatar{width:64px;height:64px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:var(--bs-primary-bg-subtle);color:var(--bs-primary);font-size:1.8rem;overflow:hidden}
.profile-school-logo{width:64px;height:64px;object-fit:contain;background:#fff;border:1px solid var(--bs-border-color);border-radius:16px;padding:6px}
.profile-score{font-size:1.9rem;font-weight:700;line-height:1}
.profile-head-card .card-body{padding:1.25rem 1.25rem}
.profile-stats-row{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.75rem;margin-bottom:.9rem}
.profile-stats-row > [class*="col-"]{padding:0;max-width:none;width:auto;flex:none}
.profile-stat-card .card-body{padding:1rem 1.05rem}
.profile-stat-card .h2{font-size:2rem;line-height:1.05;margin-bottom:0}
.profile-stat-card .small{line-height:1.25}
.profile-section-row{--bs-gutter-x:.9rem;--bs-gutter-y:.9rem}
.school-profile-main-row{align-items:stretch}
.school-profile-main-row > [class*="col-"]{display:flex}
.school-profile-main-row .profile-section-card{width:100%}
.school-side-stack{
  display:flex;
  flex-direction:column;
  gap:.9rem;
  width:100%;
  min-height:100%;
}
.school-side-stack > .profile-section-card{
  flex:1 1 0;
  min-height:0;
}
.school-side-stack .dataTables_wrapper{
  display:flex;
  flex-direction:column;
  height:100%;
}
.school-side-stack .dataTables_wrapper .dataTables_scroll,
.school-side-stack .table-responsive{
  flex:1 1 auto;
}
.school-side-stack .profile-section-card .table > :not(caption) > * > *{
  padding:.58rem .65rem;
  font-size:.86rem;
}
.school-side-stack .profile-section-card .table .ps-4{
  padding-left:.8rem !important;
}
.school-side-stack .profile-section-card .table .pe-4{
  padding-right:.8rem !important;
}
.school-side-stack .dataTables_wrapper > .row:first-child{
  padding:.65rem .8rem .4rem;
}
.school-side-stack .dataTables_wrapper > .row:last-child{
  padding:.5rem .8rem .65rem;
}
.school-side-stack .dataTables_wrapper .dataTables_filter input{
  min-width:120px;
  width:135px;
}
.profile-section-card .card-header,.profile-section-card .card-body.profile-padded{padding:1rem 1.1rem}
.profile-section-card .card-header h2{margin-bottom:.15rem}
.profile-section-card .table{margin-bottom:0}
.profile-section-card .table thead th{white-space:nowrap}
.profile-section-card .table > :not(caption) > * > *{padding:.72rem .9rem}
.school-teachers-full .dataTables_wrapper > .row:first-child{
  padding-top:.75rem;
}
.profile-section-card .table .ps-4{padding-left:1.15rem !important}
.profile-section-card .table .pe-4{padding-right:1.15rem !important}

/* DataTables spacing inside cards */
.dataTables_wrapper > .row{
  margin-left:0;
  margin-right:0;
}
.dataTables_wrapper > .row:first-child{
  padding:.85rem 1.1rem .55rem;
  align-items:center;
}
.dataTables_wrapper > .row:last-child{
  padding:.65rem 1.1rem .85rem;
  align-items:center;
}
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_length{
  margin:0;
}
.dataTables_wrapper .dataTables_filter label{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:.5rem;
  margin:0;
}
.dataTables_wrapper .dataTables_filter input{
  min-width:190px;
  margin-left:0 !important;
}
.dataTables_wrapper .dataTables_info{
  padding-top:.35rem !important;
  margin:0;
}
.dataTables_wrapper .dataTables_paginate{
  padding-top:0 !important;
  margin:0 !important;
}
.dataTables_wrapper .pagination{
  margin:0;
  justify-content:flex-end;
}
.dataTables_wrapper .page-link{
  padding:.4rem .7rem;
}
@media (max-width:1199.98px){
  .profile-stats-row{grid-template-columns:repeat(4,minmax(0,1fr))}
}
@media (max-width:991.98px){
  .profile-stats-row{grid-template-columns:repeat(3,minmax(0,1fr))}
  .school-side-stack{min-height:auto}
  .school-side-stack > .profile-section-card{flex:none}
}
@media (max-width:575.98px){
  .profile-stats-row{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width:767.98px){
  .dataTables_wrapper > .row:first-child,
  .dataTables_wrapper > .row:last-child{
    padding:.7rem .85rem;
  }
  .dataTables_wrapper .dataTables_filter label{
    justify-content:stretch;
    flex-wrap:wrap;
  }
  .dataTables_wrapper .dataTables_filter input{
    width:100%;
    min-width:0;
  }
  .dataTables_wrapper .dataTables_info{
    text-align:center;
    margin-bottom:.5rem;
  }
  .dataTables_wrapper .pagination{
    justify-content:center;
  }
}
@media (max-width: 767.98px){
  .profile-head-card .card-body{padding:1rem}
  .profile-score{font-size:1.6rem}
  .profile-stat-card .card-body{padding:.9rem}
  .profile-section-card .card-header,.profile-section-card .card-body.profile-padded{padding:.9rem}
}
</style>

<div class="profile-page">
<div class="card border-0 shadow-sm mb-3 profile-head-card">
    <div class="card-body p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center gap-4">
            <?php
            $schoolLogo = trim((string)($school['school_logo'] ?? ''));
            $schoolLogoUrl = $schoolLogo !== ''
                ? '/' . ltrim($schoolLogo, '/')
                : '';
            ?>
            <?php if ($schoolLogoUrl !== ''): ?>
                <img
                    src="<?= e($schoolLogoUrl) ?>"
                    alt="<?= e((string)$school['school_name']) ?> logo"
                    class="profile-school-logo"
                    width="72"
                    height="72"
                >
            <?php else: ?>
                <div class="profile-avatar">
                    <i class="bi bi-building"></i>
                </div>
            <?php endif; ?>

            <div class="flex-grow-1">
                <span class="badge text-bg-primary mb-2">
                    <?= e((string)$school['school_code']) ?>
                </span>

                <h1 class="h2 mb-1">
                    <?= e((string)$school['school_name']) ?>
                </h1>

                <div class="text-secondary">
                    <?= e((string)($school['district'] ?? '')) ?>
                    <?php if (!empty($school['state'])): ?>
                        · <?= e((string)$school['state']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-md-end">
                <div class="small text-secondary">School Ranking</div>
                <div class="h3 mb-1">
                    <?= e(profileRankLabel(
                        $schoolScore['rank'] ?? null,
                        (int)$schoolScore['points']
                    )) ?>
                </div>
                <div class="fw-semibold text-primary">
                    <?= number_format((int)$schoolScore['points']) ?> points
                </div>
            </div>
        </div>
    </div>
</div>

<?php
equipmentRenderTimeline($pdo, $schoolId);
portalRenderVideoGallery(
    portalVideosForSchool($pdo, $schoolId),
    'School Videos',
    '',
    'school-videos'
);
?>

<div class="profile-stats-row">
    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Students</div>
                <div class="h2 mb-0"><?= number_format(count($studentIds)) ?></div>
                <div class="small text-success"><?= number_format($activeStudents) ?> active</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Teachers</div>
                <div class="h2 mb-0"><?= number_format($teacherCount) ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Modules Completed</div>
                <div class="h2 mb-0"><?= number_format($scoreSummary['modules_completed']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Tournament Entries</div>
                <div class="h2 mb-0"><?= number_format($scoreSummary['tournaments_joined']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Attendance</div>
                <div class="h2 mb-0">
                    <?= e(profileFormatMinutes($activity['attendance_minutes'])) ?>
                </div>
                <div class="small text-secondary">
                    <?= number_format($activity['attendance_sessions']) ?> sessions
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Certificates</div>
                <div class="h2 mb-0"><?= number_format($activity['certificates']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Tournament Wins</div>
                <div class="h2 mb-0">
                    <?= number_format(
                        $scoreSummary['first_places']
                        + $scoreSummary['second_places']
                        + $scoreSummary['third_places']
                    ) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row profile-section-row school-profile-main-row">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100 profile-section-card">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1"><i class="bi bi-people me-2"></i>Registered Students</h2>
                <div class="small text-secondary">
                    All registered students with membership status, ranking points and profile access.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        id="schoolStudentsTable"
                        class="table table-hover align-middle w-100"
                    >
                        <thead>
                            <tr>
                                <th class="ps-4">Student</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Rank</th>
                                <th class="text-end">Points</th>
                                <th class="text-end pe-4">Profile</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topStudents as $student): ?>
                            <?php
                            $studentStatus =
                                strtolower(
                                    trim(
                                        (string)(
                                            $student['membership_status']
                                            ?? ''
                                        )
                                    )
                                );

                            $expiryTimestamp =
                                !empty(
                                    $student['membership_next_due_at']
                                )
                                    ? strtotime(
                                        (string)$student['membership_next_due_at']
                                    )
                                    : false;

                            $isCurrentActive =
                                $studentStatus === 'active'
                                && $expiryTimestamp !== false
                                && $expiryTimestamp >= time();

                            $isPending =
                                in_array(
                                    $studentStatus,
                                    [
                                        'pending_activation',
                                        'pending_payment',
                                    ],
                                    true
                                );

                            if ($isPending) {
                                $displayStatus =
                                    'Pending Payment';
                                $displayBadge =
                                    'warning';
                                // Default DataTables sort:
                                // Pending Payment first.
                                $studentStatusOrder = 0;
                            } elseif ($isCurrentActive) {
                                $displayStatus =
                                    'Active';
                                $displayBadge =
                                    'success';
                                $studentStatusOrder = 1;
                            } else {
                                $displayStatus =
                                    membershipLabel(
                                        $studentStatus
                                    );
                                $displayBadge =
                                    membershipBadge(
                                        $studentStatus
                                    );
                                $studentStatusOrder = 2;
                            }
                            ?>

                            <tr>
                                <td class="ps-4">
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)$student['full_name']
                                        ) ?>
                                    </div>

                                    <div class="small text-secondary">
                                        <?= e(
                                            (string)$student['student_number']
                                        ) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= e(
                                        (string)(
                                            $student['class_name']
                                            ?? '-'
                                        )
                                    ) ?>
                                </td>

                                <td data-order="<?= $studentStatusOrder ?>">
                                    <span
                                        class="badge text-bg-<?= e($displayBadge) ?>"
                                    >
                                        <?= e($displayStatus) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e(
                                        profileRankLabel(
                                            $student['rank'] ?? null,
                                            (int)$student['points']
                                        )
                                    ) ?>
                                </td>

                                <td
                                    class="text-end fw-semibold"
                                    data-order="<?= (int)$student['points'] ?>"
                                >
                                    <?= number_format(
                                        (int)$student['points']
                                    ) ?>
                                </td>

                                <td class="text-end pe-4">
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="student_profile.php?student_id=<?= (int)$student['id'] ?>"
                                        title="Student Profile"
                                        aria-label="Student Profile"
                                    >
                                        <i class="bi bi-person-vcard"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="school-side-stack">
            <div class="card border-0 shadow-sm profile-section-card">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-1">Tournaments</h2>
                    <div class="small text-secondary">
                        Tournaments joined by students from this school.
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table
                            id="schoolTournamentsTable"
                            class="table table-hover align-middle w-100"
                        >
                            <thead>
                                <tr>
                                    <th class="ps-4">Student</th>
                                    <th>Tournament</th>
                                    <th>Result</th>
                                    <th class="text-end pe-4">Pts</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($schoolTournaments as $event): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-semibold">
                                            <?= e((string)$event['student_name']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="fw-semibold">
                                            <?= e((string)$event['title']) ?>
                                        </div>
                                        <small class="text-secondary">
                                            <?= !empty($event['start_at'])
                                                ? e(date('d M Y', strtotime((string)$event['start_at'])))
                                                : '-' ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?= e(profileTournamentPlaceLabel(
                                            $event['place'] !== null
                                                ? (int)$event['place']
                                                : null
                                        )) ?>
                                    </td>

                                    <td
                                        class="text-end pe-4 fw-semibold"
                                        data-order="<?= (int)$event['points'] ?>"
                                    >
                                        <?= number_format((int)$event['points']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm profile-section-card">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-1">Certificates</h2>
                    <div class="small text-secondary">
                        Certificates obtained by students from this school.
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table
                            id="schoolCertificatesTable"
                            class="table table-hover align-middle w-100"
                        >
                            <thead>
                                <tr>
                                    <th class="ps-4">Student</th>
                                    <th>Certificate</th>
                                    <th class="pe-4">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($schoolCertificates as $certificate): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-semibold">
                                            <?= e((string)$certificate['student_name']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="fw-semibold">
                                            <?= e((string)$certificate['certificate_title']) ?>
                                        </div>
                                        <small class="text-secondary">
                                            <?= e((string)$certificate['certificate_number']) ?>
                                        </small>
                                    </td>

                                    <td
                                        class="pe-4"
                                        data-order="<?= e((string)($certificate['completion_date'] ?? $certificate['created_at'] ?? '')) ?>"
                                    >
                                        <?php
                                        $certificateDate =
                                            $certificate['completion_date']
                                            ?? $certificate['created_at']
                                            ?? null;
                                        ?>
                                        <?= $certificateDate
                                            ? e(date('d M Y', strtotime((string)$certificateDate)))
                                            : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<div class="row profile-section-row mt-3">
    <div class="col-12">
        <div class="card border-0 shadow-sm h-100 profile-section-card school-teachers-full">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1">
                    <i class="bi bi-person-badge me-2"></i>
                    School Team Teachers
                </h2>

                <div class="small text-secondary">
                    Registered teachers for this school.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        id="schoolTeachersTable"
                        class="table table-hover align-middle w-100"
                    >
                        <thead>
                            <tr>
                                <th class="ps-4">Teacher</th>
                                <th>Position</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th class="text-end pe-4">Profile</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($schoolTeachers as $teacher): ?>
                            <?php
                            $teacherRegisteredTimestamp =
                                !empty($teacher['created_at'])
                                    ? strtotime(
                                        (string)$teacher['created_at']
                                    )
                                    : false;

                            $teacherExpiryTimestamp =
                                $teacherRegisteredTimestamp !== false
                                    ? strtotime(
                                        '+1 year',
                                        $teacherRegisteredTimestamp
                                    )
                                    : false;

                            $teacherEmailVerified =
                                !$teacherEmailVerifiedColumn
                                || !empty(
                                    $teacher['email_verified_at']
                                );

                            $teacherActive =
                                $teacherEmailVerified
                                && $teacherExpiryTimestamp !== false
                                && $teacherExpiryTimestamp >= time();

                            $teacherExpired =
                                $teacherEmailVerified
                                && $teacherExpiryTimestamp !== false
                                && $teacherExpiryTimestamp < time();

                            if ($teacherActive) {
                                $teacherStatusLabel = 'Active';
                                $teacherStatusBadge = 'success';
                                $teacherStatusOrder = 1;
                            } elseif ($teacherExpired) {
                                $teacherStatusLabel = 'Expired';
                                $teacherStatusBadge = 'danger';
                                $teacherStatusOrder = 2;
                            } else {
                                $teacherStatusLabel =
                                    'Pending Verification';
                                $teacherStatusBadge = 'warning';
                                // Default DataTables sort:
                                // Pending Verification first.
                                $teacherStatusOrder = 0;
                            }
                            ?>

                            <tr>
                                <td class="ps-4">
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)$teacher['full_name']
                                        ) ?>
                                    </div>

                                    <?php if (!empty($teacher['email'])): ?>
                                        <div class="small text-secondary">
                                            <?= e(
                                                (string)$teacher['email']
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= e(
                                        (string)(
                                            $teacher['position']
                                            ?? '-'
                                        )
                                    ) ?>
                                </td>

                                <td data-order="<?= $teacherStatusOrder ?>">
                                    <span
                                        class="badge text-bg-<?= e($teacherStatusBadge) ?>"
                                    >
                                        <?= e($teacherStatusLabel) ?>
                                    </span>
                                </td>

                                <td
                                    data-order="<?= $teacherRegisteredTimestamp !== false ? (int)$teacherRegisteredTimestamp : 0 ?>"
                                >
                                    <?= $teacherRegisteredTimestamp !== false
                                        ? e(
                                            date(
                                                'd M Y',
                                                $teacherRegisteredTimestamp
                                            )
                                        )
                                        : '-' ?>
                                </td>

                                <td class="text-end pe-4">
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="teacher_profile.php?teacher_id=<?= (int)$teacher['id'] ?>"
                                        title="Teacher Profile"
                                        aria-label="Teacher Profile"
                                    >
                                        <i class="bi bi-person-vcard"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/dataTables.bootstrap5.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (
        !window.jQuery
        || !jQuery.fn
        || !jQuery.fn.DataTable
    ) {
        return;
    }

    jQuery('#schoolStudentsTable').DataTable({
        pageLength: 5,
        lengthChange: false,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        // Pending Payment (data-order 0) is shown first by default.
        order: [[2, 'asc'], [4, 'desc'], [0, 'asc']],
        columnDefs: [
            {
                orderable: false,
                searchable: false,
                targets: [5]
            }
        ],
        language: {
            search: 'Search:',
            emptyTable: 'No students registered.'
        }
    });

    jQuery('#schoolTournamentsTable').DataTable({
        pageLength: 5,
        lengthChange: false,
        pagingType: 'simple',
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        order: [[3, 'desc']],
        language: {
            search: 'Search:',
            emptyTable: 'No tournament participation recorded.'
        }
    });

    jQuery('#schoolCertificatesTable').DataTable({
        pageLength: 5,
        lengthChange: false,
        pagingType: 'simple',
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        order: [[2, 'desc']],
        language: {
            search: 'Search:',
            emptyTable: 'No certificates obtained yet.'
        }
    });

    jQuery('#schoolTeachersTable').DataTable({
        pageLength: 5,
        lengthChange: false,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        // Status first: Pending Verification, Active, Expired.
        // Within each status, Teacher name A-Z.
        order: [[2, 'asc'], [0, 'asc']],
        columnDefs: [
            {
                orderable: false,
                searchable: false,
                targets: [4]
            }
        ],
        language: {
            search: 'Search:',
            emptyTable: 'No teachers registered.'
        }
    });
});
</script>

</div>

<?php require 'includes/footer.php'; ?>
