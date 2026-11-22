<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/includes/profile_scoring.php';

function profileYoutubeVideoIdFromUrl(?string $url): ?string
{
    $url = trim((string)$url);

    if ($url === '') {
        return null;
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower(
        (string)($parts['host'] ?? '')
    );

    $path =
        trim(
            (string)($parts['path'] ?? ''),
            '/'
        );

    if (
        $host === 'youtu.be'
        || $host === 'www.youtu.be'
    ) {
        $id = explode('/', $path)[0] ?? '';

        return preg_match(
            '/^[A-Za-z0-9_-]{6,}$/',
            $id
        )
            ? $id
            : null;
    }

    if (
        in_array(
            $host,
            [
                'youtube.com',
                'www.youtube.com',
                'm.youtube.com',
            ],
            true
        )
    ) {
        parse_str(
            (string)($parts['query'] ?? ''),
            $query
        );

        if (!empty($query['v'])) {
            $id = (string)$query['v'];

            if (
                preg_match(
                    '/^[A-Za-z0-9_-]{6,}$/',
                    $id
                )
            ) {
                return $id;
            }
        }

        if (
            preg_match(
                '#^(?:embed|shorts)/([A-Za-z0-9_-]{6,})#',
                $path,
                $match
            )
        ) {
            return $match[1];
        }
    }

    return null;
}

function profileYoutubeEmbedUrl(?string $url): ?string
{
    $videoId =
        profileYoutubeVideoIdFromUrl($url);

    if ($videoId === null) {
        return null;
    }

    return
        'https://www.youtube-nocookie.com/embed/'
        . rawurlencode($videoId);
}

function profileColumnExistsLocal(
    PDO $pdo,
    string $table,
    string $column
): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );

    $stmt->execute([
        $table,
        $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}


$studentId = (int)($_GET['student_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT
        st.*,
        s.school_name,
        s.school_code,
        s.school_logo,
        s.state,
        s.district
     FROM students st
     JOIN schools s
       ON s.id = st.school_id
     WHERE st.id = ?
     LIMIT 1'
);
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    $pageTitle = 'Student Profile';
    require 'includes/header.php';
    echo '<div class="alert alert-danger">Student profile not found.</div>';
    require 'includes/footer.php';
    exit;
}

$scoreData = profileScoringData($pdo);
$score = $scoreData['students'][$studentId] ?? [
    'modules_completed' => 0,
    'module_points' => 0,
    'tournaments_joined' => 0,
    'tournament_points' => 0,
    'first_places' => 0,
    'second_places' => 0,
    'third_places' => 0,
    'points' => 0,
    'rank' => null,
];

$activity = profileActivitySummary(
    $pdo,
    [$studentId]
);

$teacher = profileAssignedTeacher(
    $pdo,
    $studentId
);

$tournaments = profileStudentTournamentRows(
    $pdo,
    $studentId
);

$certificates = profileCertificateRowsForStudents(
    $pdo,
    [$studentId],
    $scoreData
);

$status = strtolower(
    trim(
        (string)($student['membership_status'] ?? '')
    )
);

$expiry = !empty($student['membership_next_due_at'])
    ? strtotime((string)$student['membership_next_due_at'])
    : false;

$isActive =
    $status === 'active'
    && $expiry !== false
    && $expiry >= time();

$isPendingPayment = in_array(
    $status,
    [
        'pending_payment',
        'pending_activation',
        'pending_registration',
        'registered',
    ],
    true
);

$assignReturn =
    '/teacher/students.php?student_id='
    . $studentId;

$assignTeacherUrl =
    'login.php?role=teacher&return='
    . rawurlencode($assignReturn);

$moduleVideos = [];

$youtubeColumnReady =
    profileColumnExistsLocal(
        $pdo,
        'student_module_progress',
        'youtube_url'
    );

$youtubeSelect =
    $youtubeColumnReady
        ? 'smp.youtube_url'
        : 'NULL AS youtube_url';

$stmt = $pdo->prepare(
    "SELECT
        lm.id,
        lm.module_code,
        lm.sequence_no,
        lm.week_number,
        lm.module_name,
        lm.game_title,
        COALESCE(
            smp.progress_status,
            'not_started'
        ) AS progress_status,
        {$youtubeSelect},
        smp.completed_at
     FROM learning_modules lm
     LEFT JOIN student_module_progress smp
       ON smp.module_id = lm.id
      AND smp.student_id = ?
     ORDER BY
        lm.sequence_no ASC"
);

$stmt->execute([
    $studentId,
]);

foreach ($stmt->fetchAll() as $row) {
    $row['embed_url'] =
        profileYoutubeEmbedUrl(
            (string)(
                $row['youtube_url']
                ?? ''
            )
        );

    $moduleVideos[] = $row;
}

$pageTitle = 'Student Profile';
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
.profile-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.85rem;margin-bottom:1rem}
.profile-stats-row > [class*="col-"]{padding:0;max-width:none;width:auto;flex:none}
.profile-stat-card .card-body{padding:1rem 1.05rem}
.profile-stat-card .h2{font-size:2rem;line-height:1.05;margin-bottom:0}
.profile-stat-card .small{line-height:1.25}
.profile-section-row{--bs-gutter-x:.9rem;--bs-gutter-y:.9rem}
.profile-section-card .card-header,.profile-section-card .card-body.profile-padded{padding:1rem 1.1rem}
.profile-section-card .card-header h2{margin-bottom:.15rem}
.profile-section-card .table{margin-bottom:0}
.profile-section-card .table thead th{white-space:nowrap}
.profile-section-card .table > :not(caption) > * > *{padding:.72rem .9rem}
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

.module-video-grid{
  display:grid;
  grid-template-columns:repeat(5,minmax(0,1fr));
  gap:.75rem;
}
.module-video-card{
  min-width:0;
  overflow:hidden;
  border:1px solid var(--bs-border-color);
  border-radius:.75rem;
  background:#fff;
}
.module-video-frame{
  width:100%;
  aspect-ratio:16/9;
  border:0;
  display:block;
  background:#000;
}
.module-video-body{
  padding:.65rem .7rem .75rem;
}
.module-video-title{
  font-size:.82rem;
  font-weight:700;
  line-height:1.25;
  margin-bottom:.25rem;
}
.module-video-meta{
  font-size:.72rem;
  color:var(--bs-secondary-color);
  line-height:1.3;
}
.module-video-placeholder{
  width:100%;
  aspect-ratio:16/9;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#f8f9fa;
  color:#6c757d;
  border-bottom:1px solid var(--bs-border-color);
  text-align:center;
  font-size:.72rem;
}
.module-video-status{
  font-size:.64rem;
}
@media (max-width:991.98px){
  .module-video-grid{
    grid-template-columns:repeat(3,minmax(0,1fr));
  }
}
@media (max-width:767.98px){
  .module-video-grid{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
}
@media (max-width:575.98px){
  .module-video-grid{
    grid-template-columns:1fr;
  }
}


/* Keep the two membership activation options alongside each other. */
.profile-pending-actions{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:.6rem;
  min-width:min(100%,390px);
}
.profile-pending-actions .btn{
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  white-space:normal;
  line-height:1.25;
  min-width:0;
}
@media(max-width:767.98px){
  .profile-pending-actions{
    width:100%;
    min-width:0;
  }
  .profile-pending-actions .btn{
    font-size:.83rem;
    padding:.7rem .3rem;
  }
}

</style>

<div class="profile-page">
<?php if ($isPendingPayment): ?>
    <div class="alert alert-warning border-warning shadow-sm mb-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3" role="alert">
        <div>
            <div class="fw-bold">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Pending Payment
            </div>
            <div class="small mt-1">
                Membership payment is pending. Complete payment to activate the student's membership.
            </div>
        </div>

        <div class="profile-pending-actions">
            <a
                class="btn btn-primary"
                href="student_payment.php?student_id=<?= (int)$studentId ?>"
            >
                <i class="bi bi-credit-card me-1"></i>
                Continue to Payment
            </a>
            <a
                class="btn btn-success"
                href="redeem_coupon.php?student_id=<?= (int)$studentId ?>"
            >
                <i class="bi bi-ticket-perforated me-1"></i>
                Redeem Coupon
            </a>
        </div>
    </div>
<?php endif; ?>
<div class="card border-0 shadow-sm mb-3 profile-head-card">
    <div class="card-body p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center gap-4">
            <?php
            $schoolLogo = trim((string)($student['school_logo'] ?? ''));
            $schoolLogoUrl = $schoolLogo !== ''
                ? '/' . ltrim($schoolLogo, '/')
                : '';
            ?>
            <?php if ($schoolLogoUrl !== ''): ?>
                <img
                    src="<?= e($schoolLogoUrl) ?>"
                    alt="<?= e((string)$student['school_name']) ?> logo"
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
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge text-bg-primary">
                        <?= e((string)$student['student_number']) ?>
                    </span>

                    <span class="badge text-bg-<?= $isActive ? 'success' : ($isPendingPayment ? 'warning' : 'secondary') ?>">
                        <?= $isActive ? 'Active' : ($isPendingPayment ? 'Pending Payment' : e(ucwords(str_replace('_', ' ', $status)))) ?>
                    </span>
                </div>

                <h1 class="h2 mb-1">
                    <?= e((string)$student['full_name']) ?>
                </h1>

                <div class="text-secondary mb-1">
                    <?= e((string)($student['class_name'] ?? '-')) ?>
                </div>

                <div>
                    <a
                        class="text-decoration-none"
                        href="school_profile.php?school_id=<?= (int)$student['school_id'] ?>"
                    >
                        <?= e((string)$student['school_name']) ?>
                        · <?= e((string)$student['school_code']) ?>
                    </a>
                </div>

                <div class="small mt-1">
                    <?php if ($teacher): ?>
                        <i class="bi bi-person-badge me-1 text-secondary"></i>
                        <a
                            class="text-decoration-none"
                            href="teacher_profile.php?teacher_id=<?= (int)$teacher['id'] ?>"
                        >
                            <?= e((string)$teacher['full_name']) ?>
                        </a>
                        <?php if (!empty($teacher['position'])): ?>
                            <span class="text-secondary">
                                · <?= e((string)$teacher['position']) ?>
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <i class="bi bi-person-plus me-1 text-secondary"></i>
                        <a
                            class="text-decoration-none"
                            href="<?= e($assignTeacherUrl) ?>"
                        >
                            Assign Teacher
                        </a>
                        <span class="text-secondary">
                            · Teacher login required
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-md-end">
                <div class="small text-secondary">Student Ranking</div>
                <div class="h3 mb-1">
                    <?= e(profileRankLabel(
                        $score['rank'] ?? null,
                        (int)$score['points']
                    )) ?>
                </div>
                <div class="fw-semibold text-primary">
                    <?= number_format((int)$score['points']) ?> points
                </div>
            </div>
        </div>
    </div>
</div>


<div class="profile-stats-row">
    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Modules Completed</div>
                <div class="h2 mb-0"><?= number_format((int)$score['modules_completed']) ?></div>
                <div class="small text-primary">
                    <?= number_format((int)$score['module_points']) ?> points
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Tournaments</div>
                <div class="h2 mb-0"><?= number_format((int)$score['tournaments_joined']) ?></div>
                <div class="small text-primary">
                    <?= number_format((int)$score['tournament_points']) ?> points
                </div>
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
</div>


<div class="card border-0 shadow-sm mb-3 profile-section-card">
    <div class="card-header bg-white">
        <h2 class="h5 mb-1">
            <i class="bi bi-journal-play me-2"></i>
            Learning Modules & YouTube Videos
        </h2>

        <div class="small text-secondary">
            All programme modules are shown below. Uploaded gameplay videos appear directly in the matching module.
        </div>
    </div>

    <div class="card-body profile-padded">
        <?php if (!$moduleVideos): ?>
            <div class="text-secondary">
                No learning modules are available.
            </div>
        <?php else: ?>
            <div class="module-video-grid">
                <?php foreach ($moduleVideos as $video): ?>
                    <?php
                    $moduleStatus =
                        (string)($video['progress_status'] ?? 'not_started');

                    $statusClass = match ($moduleStatus) {
                        'completed' => 'success',
                        'in_progress' => 'warning',
                        default => 'secondary',
                    };
                    ?>

                    <div class="module-video-card">
                        <?php if (!empty($video['embed_url'])): ?>
                            <iframe
                                class="module-video-frame"
                                src="<?= e((string)$video['embed_url']) ?>"
                                title="<?= e((string)$video['module_name']) ?>"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen
                            ></iframe>
                        <?php else: ?>
                            <div class="module-video-placeholder">
                                <div>
                                    <i class="bi bi-youtube fs-4 d-block mb-1"></i>
                                    No video uploaded
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="module-video-body">
                            <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                <div class="module-video-title mb-0">
                                    <?= e((string)$video['module_code']) ?>
                                    · <?= e((string)$video['module_name']) ?>
                                </div>

                                <span class="badge text-bg-<?= e($statusClass) ?> module-video-status">
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $moduleStatus
                                            )
                                        )
                                    ) ?>
                                </span>
                            </div>

                            <div class="module-video-meta">
                                <?= e((string)$video['game_title']) ?>

                                <?php if (!empty($video['completed_at'])): ?>
                                    · <?= e(
                                        date(
                                            'd M Y',
                                            strtotime(
                                                (string)$video['completed_at']
                                            )
                                        )
                                    ) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row profile-section-row mb-3">
    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100 profile-section-card">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1">Tournaments</h2>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="studentTournamentsTable" class="table table-hover align-middle w-100">
                        <thead>
                            <tr>
                                <th class="ps-4">Tournament</th>
                                <th>Result</th>
                                <th class="text-end pe-4">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tournaments as $event): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-semibold">
                                            <?= e((string)$event['title']) ?>
                                        </div>
                                        <div class="small text-secondary">
                                            <?= !empty($event['start_at'])
                                                ? e(date('d M Y', strtotime((string)$event['start_at'])))
                                                : '-' ?>
                                            <?php if (!empty($event['event_level'])): ?>
                                                · <?= e((string)$event['event_level']) ?>
                                            <?php endif; ?>
                                        </div>
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
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100 profile-section-card">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1">Certificates</h2>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        id="studentCertificatesTable"
                        class="table table-hover align-middle w-100"
                    >
                        <thead>
                            <tr>
                                <th class="ps-4">Certificate Obtained</th>
                                <th>Date</th>
                                <th class="pe-4">Certificate No.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($certificates as $certificate): ?>
                            <tr>
                                <td class="ps-4 fw-semibold">
                                    <?= e((string)$certificate['certificate_title']) ?>
                                </td>
                                <td
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
                                <td class="pe-4">
                                    <?= e((string)$certificate['certificate_number']) ?>
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

    jQuery('#studentTournamentsTable').DataTable({
        pageLength: 5,
        lengthChange: false,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        order: [[2, 'desc']],
        language: {
            search: 'Search:',
            emptyTable: 'No tournament participation recorded.'
        }
    });

    jQuery('#studentCertificatesTable').DataTable({
        pageLength: 5,
        lengthChange: false,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        order: [[1, 'desc']],
        language: {
            search: 'Search:',
            emptyTable: 'No certificates obtained yet.'
        }
    });
});
</script>

</div>

<?php require 'includes/footer.php'; ?>
