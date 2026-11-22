<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/includes/profile_scoring.php';
require_once __DIR__ . '/registration_contact_verification.php';

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


$teacherId = (int)($_GET['teacher_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT
        t.*,
        s.school_name,
        s.school_code,
        s.school_logo,
        s.state,
        s.district
     FROM teachers t
     JOIN schools s
       ON s.id = t.school_id
     WHERE t.id = ?
     LIMIT 1'
);
$stmt->execute([$teacherId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    http_response_code(404);
    $pageTitle = 'Teacher Profile';
    require 'includes/header.php';
    echo '<div class="alert alert-danger">Teacher profile not found.</div>';
    require 'includes/footer.php';
    exit;
}

$teacherVerificationSchemaReady =
    staffContactVerificationSchemaReady(
        $pdo,
        'teacher'
    );

$teacherEmail =
    trim(
        (string)($teacher['email'] ?? '')
    );

$teacherEmailPending =
    $teacherVerificationSchemaReady
    && empty($teacher['email_verified_at']);

$teacherEmailCanVerify =
    $teacherEmailPending
    && filter_var(
        $teacherEmail,
        FILTER_VALIDATE_EMAIL
    );

/*
 * Pending-verification profiles contain a CSRF-protected action.
 * Do not generate a public static HTML copy while verification is pending.
 */
if ($teacherEmailPending) {
    $disableStaticHtmlGeneration = true;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '')
        === 'send_teacher_email_verification'
) {
    try {
        verifyCsrf();

        $postedTeacherId =
            (int)($_POST['teacher_id'] ?? 0);

        if ($postedTeacherId !== $teacherId) {
            throw new RuntimeException(
                'Invalid teacher verification request.'
            );
        }

        if (!$teacherVerificationSchemaReady) {
            throw new RuntimeException(
                'Email verification is not available until the verification database update is installed.'
            );
        }

        if (!$teacherEmailPending) {
            throw new RuntimeException(
                'This teacher email address is already verified.'
            );
        }

        if (!$teacherEmailCanVerify) {
            throw new RuntimeException(
                'A valid registered email address is required before verification can be sent.'
            );
        }

        $rateKey =
            'teacher_email_verification_sent_'
            . $teacherId;

        $lastSentAt =
            (int)($_SESSION[$rateKey] ?? 0);

        $cooldownSeconds = 60;

        if (
            $lastSentAt > 0
            && (time() - $lastSentAt)
                < $cooldownSeconds
        ) {
            $remaining =
                $cooldownSeconds
                - (time() - $lastSentAt);

            throw new RuntimeException(
                'A verification email was recently sent. Please wait '
                . max(1, $remaining)
                . ' seconds before trying again.'
            );
        }

        $emailVerification =
            createStaffEmailVerificationToken();

        $stmt = $pdo->prepare(
            "UPDATE teachers
             SET
                email_verification_token_hash = ?,
                email_verification_expires_at = ?
             WHERE id = ?
               AND email_verified_at IS NULL"
        );

        $stmt->execute([
            $emailVerification['token_hash'],
            $emailVerification['expires_at'],
            $teacherId,
        ]);

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException(
                'The teacher email is already verified or the teacher record could not be updated.'
            );
        }

        $verifyUrl =
            registrationAppBaseUrl()
            . '/register_teacher.php?verify_email='
            . rawurlencode(
                (string)$emailVerification['token']
            );

        $emailResult =
            registrationSendVerificationEmail(
                $teacherEmail,
                (string)$teacher['full_name'],
                'School Team Teacher',
                (string)$teacher['full_name'],
                $verifyUrl
            );

        if (empty($emailResult['success'])) {
            throw new RuntimeException(
                (string)(
                    $emailResult['message']
                    ?? 'Unable to send the verification email.'
                )
            );
        }

        $_SESSION[$rateKey] = time();

        setFlash(
            'success',
            'A verification link has been sent to the teacher\'s registered email address.'
        );
    } catch (Throwable $e) {
        error_log(
            'Teacher profile email verification error: '
            . $e->getMessage()
        );

        setFlash(
            'danger',
            $e->getMessage()
        );
    }

    redirect(
        'teacher_profile.php?teacher_id='
        . $teacherId
    );
}

$scoreData = profileScoringData($pdo);
$studentIds = profileStudentIdsForTeacher($pdo, $teacherId);
$scoreSummary = profileAggregateStudentScores(
    $scoreData,
    $studentIds
);
$activity = profileActivitySummary($pdo, $studentIds);

$teacherTournaments = profileTournamentRowsForStudents(
    $pdo,
    $studentIds,
    $scoreData
);

$teacherCertificates = profileCertificateRowsForStudents(
    $pdo,
    $studentIds,
    $scoreData
);

$teacherScore = $scoreData['teachers'][$teacherId] ?? [
    'students' => 0,
    'points' => 0,
    'rank' => null,
];

$teamStudents = [];

if (
    $studentIds
    && profileTableExists($pdo, 'student_coach_assignments')
) {
    $stmt = $pdo->prepare(
        'SELECT
            st.id,
            st.student_number,
            st.full_name,
            st.class_name
         FROM student_coach_assignments a
         JOIN students st
           ON st.id = a.student_id
         WHERE a.teacher_id = ?
         ORDER BY st.full_name'
    );
    $stmt->execute([$teacherId]);

    foreach ($stmt->fetchAll() as $student) {
        $studentId = (int)$student['id'];
        $studentScore = $scoreData['students'][$studentId] ?? null;

        $student['points'] =
            (int)($studentScore['points'] ?? 0);

        $student['rank'] =
            $studentScore['rank'] ?? null;

        $teamStudents[] = $student;
    }

    usort(
        $teamStudents,
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

$teacherModuleCompleted = 0;
$moduleVideos = [];

if (
    profileTableExists(
        $pdo,
        'teacher_module_progress'
    )
) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM teacher_module_progress
         WHERE teacher_id = ?
           AND progress_status = 'completed'"
    );

    $stmt->execute([
        $teacherId,
    ]);

    $teacherModuleCompleted =
        (int)$stmt->fetchColumn();
}

if (
    profileColumnExistsLocal(
        $pdo,
        'teacher_module_progress',
        'youtube_url'
    )
) {
    $stmt = $pdo->prepare(
        "SELECT
            lm.id,
            lm.module_code,
            lm.sequence_no,
            lm.week_number,
            lm.module_name,
            lm.game_title,
            COALESCE(
                tmp.progress_status,
                'not_started'
            ) AS progress_status,
            tmp.youtube_url,
            tmp.completed_at
         FROM learning_modules lm
         LEFT JOIN teacher_module_progress tmp
           ON tmp.module_id = lm.id
          AND tmp.teacher_id = ?
         ORDER BY
            lm.sequence_no ASC"
    );

    $stmt->execute([
        $teacherId,
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
} else {
    /*
     * Keep the module grid visible even before the YouTube migration
     * is applied.
     */
    $stmt = $pdo->query(
        "SELECT
            id,
            module_code,
            sequence_no,
            week_number,
            module_name,
            game_title,
            'not_started' AS progress_status,
            NULL AS youtube_url,
            NULL AS completed_at
         FROM learning_modules
         ORDER BY sequence_no ASC"
    );

    $moduleVideos =
        $stmt->fetchAll();
}

$pageTitle = 'Teacher Profile';
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
.profile-stats-row{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.75rem;margin-bottom:.9rem}
.profile-stats-row > [class*="col-"]{padding:0;max-width:none;width:auto;flex:none}
.profile-stat-card .card-body{padding:1rem 1.05rem}
.profile-stat-card .h2{font-size:2rem;line-height:1.05;margin-bottom:0}
.profile-stat-card .small{line-height:1.25}
.profile-section-row{--bs-gutter-x:.9rem;--bs-gutter-y:.9rem}
.teacher-main-grid{
  display:grid;
  grid-template-columns:minmax(0,2fr) minmax(360px,1fr);
  gap:.85rem;
  align-items:stretch;
}
.teacher-left-section,
.teacher-right-stack{
  min-width:0;
}
.teacher-left-section{
  display:flex;
}
.teacher-left-section > .teacher-section-card{
  width:100%;
}
.teacher-right-stack{
  display:grid;
  grid-template-rows:1fr 1fr;
  gap:.85rem;
}
.teacher-section-card{
  min-width:0;
  height:100%;
  display:flex;
  flex-direction:column;
}
.teacher-section-card .card-header{
  min-height:72px;
  display:flex;
  flex-direction:column;
  justify-content:center;
}
.teacher-section-card .card-body{
  flex:1 1 auto;
  min-height:0;
}
.teacher-section-card .table-responsive{
  width:100%;
}
.teacher-section-card .table > :not(caption) > * > *{
  padding:.58rem .65rem;
  font-size:.86rem;
}
.teacher-section-card .table .ps-4{
  padding-left:.8rem !important;
}
.teacher-section-card .table .pe-4{
  padding-right:.8rem !important;
}
.teacher-section-card .dataTables_wrapper > .row:first-child{
  padding:.65rem .8rem .4rem;
}
.teacher-section-card .dataTables_wrapper > .row:last-child{
  padding:.5rem .8rem .65rem;
}
.teacher-section-card .dataTables_wrapper .dataTables_filter input{
  min-width:120px;
  width:145px;
}
.teacher-section-card .dataTables_wrapper .dataTables_filter label{
  font-size:.84rem;
}
.teacher-section-card .dataTables_wrapper .dataTables_info{
  font-size:.8rem;
}
.teacher-section-card .dataTables_wrapper .page-link{
  padding:.32rem .58rem;
  font-size:.82rem;
}
.teacher-right-stack .teacher-section-card .table > :not(caption) > * > *{
  padding:.5rem .55rem;
  font-size:.82rem;
}
.teacher-right-stack .teacher-section-card .dataTables_wrapper > .row:first-child{
  padding:.55rem .65rem .35rem;
}
.teacher-right-stack .teacher-section-card .dataTables_wrapper > .row:last-child{
  padding:.45rem .65rem .55rem;
}
.teacher-right-stack .teacher-section-card .dataTables_wrapper .dataTables_filter input{
  width:125px;
  min-width:110px;
}
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
@media (max-width:1199.98px){
  .profile-stats-row{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media (max-width:991.98px){
  .profile-stats-row{grid-template-columns:repeat(3,minmax(0,1fr))}
  .teacher-main-grid{grid-template-columns:1fr}
  .teacher-right-stack{grid-template-rows:auto;grid-template-columns:1fr}
  .teacher-section-card .card-header{min-height:auto}
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
.module-video-student{
  font-size:.7rem;
  font-weight:700;
  color:var(--bs-primary);
  margin-bottom:.2rem;
}
.module-video-status{
  font-size:.64rem;
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

</style>

<div class="profile-page">
<?php if ($teacherEmailPending): ?>
    <div
        class="alert alert-warning border-warning shadow-sm mb-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3"
        role="alert"
    >
        <div>
            <div class="fw-bold">
                <i class="bi bi-envelope-exclamation-fill me-2"></i>
                Pending Email Verification
            </div>

            <div class="small mt-1">
                The teacher's registered email address has not been verified yet.
                Send a verification link to complete the teacher account activation.
            </div>
        </div>

        <?php if ($teacherEmailCanVerify): ?>
            <form method="post" class="m-0 flex-shrink-0">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="send_teacher_email_verification"
                >

                <input
                    type="hidden"
                    name="teacher_id"
                    value="<?= $teacherId ?>"
                >

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    <i class="bi bi-envelope-check me-2"></i>
                    Verify Email
                </button>
            </form>
        <?php else: ?>
            <button
                class="btn btn-secondary flex-shrink-0"
                type="button"
                disabled
            >
                <i class="bi bi-envelope-x me-2"></i>
                Email Unavailable
            </button>
        <?php endif; ?>
    </div>
<?php endif; ?>
<div class="card border-0 shadow-sm mb-3 profile-head-card">
    <div class="card-body p-4 p-lg-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center gap-4">
            <?php
            $schoolLogo = trim((string)($teacher['school_logo'] ?? ''));
            $schoolLogoUrl = $schoolLogo !== ''
                ? '/' . ltrim($schoolLogo, '/')
                : '';
            ?>
            <?php if ($schoolLogoUrl !== ''): ?>
                <img
                    src="<?= e($schoolLogoUrl) ?>"
                    alt="<?= e((string)$teacher['school_name']) ?> logo"
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
                        School Team Teacher
                    </span>

                    <?php if ($teacherEmailPending): ?>
                        <span class="badge text-bg-warning">
                            Pending Email Verification
                        </span>
                    <?php elseif ($teacherVerificationSchemaReady): ?>
                        <span class="badge text-bg-success">
                            Email Verified
                        </span>
                    <?php endif; ?>
                </div>

                <h1 class="h2 mb-1">
                    <?= e((string)$teacher['full_name']) ?>
                </h1>

                <div class="text-secondary mb-1">
                    <?= e((string)($teacher['position'] ?? 'Teacher')) ?>
                </div>

                <a
                    class="text-decoration-none"
                    href="school_profile.php?school_id=<?= (int)$teacher['school_id'] ?>"
                >
                    <?= e((string)$teacher['school_name']) ?>
                    · <?= e((string)$teacher['school_code']) ?>
                </a>
            </div>

            <div class="text-md-end">
                <div class="small text-secondary">Teacher Ranking</div>
                <div class="h3 mb-1">
                    <?= e(profileRankLabel(
                        $teacherScore['rank'] ?? null,
                        (int)$teacherScore['points']
                    )) ?>
                </div>
                <div class="fw-semibold text-primary">
                    <?= number_format((int)$teacherScore['points']) ?> points
                </div>
            </div>
        </div>
    </div>
</div>

<div class="profile-stats-row">
    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Students Coached</div>
                <div class="h2 mb-0"><?= number_format(count($studentIds)) ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Modules Completed</div>
                <div class="h2 mb-0"><?= number_format($teacherModuleCompleted) ?></div>
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

    <div class="col-6 col-xl-4">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Team Attendance</div>
                <div class="h2 mb-0">
                    <?= e(profileFormatMinutes($activity['attendance_minutes'])) ?>
                </div>
                <div class="small text-secondary">
                    <?= number_format($activity['attendance_sessions']) ?> sessions
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-4">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Team Certificates</div>
                <div class="h2 mb-0"><?= number_format($activity['certificates']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card profile-stat-card h-100">
            <div class="card-body profile-padded">
                <div class="small text-secondary">Tournament Placements</div>
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

<div class="card border-0 shadow-sm mb-3 profile-section-card">
    <div class="card-header bg-white">
        <h2 class="h5 mb-1">
            <i class="bi bi-youtube text-danger me-2"></i>
            Teacher Learning Modules & YouTube Videos
        </h2>

        <div class="small text-secondary">
            The teacher completes the same esports modules as students and uploads their own gameplay evidence to YouTube.
        </div>
    </div>

    <div class="card-body profile-padded">
        <?php if (!$moduleVideos): ?>
            <div class="text-secondary">
                No teacher module videos have been uploaded yet.
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

<div class="teacher-main-grid">
    <div class="teacher-left-section">
        <div class="card border-0 shadow-sm profile-section-card teacher-section-card">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1">Coached Students</h2>
                <div class="small text-secondary">
                    Students assigned to this teacher.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        id="teacherStudentsTable"
                        class="table table-hover align-middle w-100"
                    >
                        <thead>
                            <tr>
                                <th class="ps-4">Student</th>
                                <th>Class</th>
                                <th>Rank</th>
                                <th class="text-end pe-4">Points</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($teamStudents as $student): ?>
                            <tr>
                                <td class="ps-4">
                                    <a
                                        class="text-decoration-none fw-semibold"
                                        href="student_profile.php?student_id=<?= (int)$student['id'] ?>"
                                    >
                                        <?= e((string)$student['full_name']) ?>
                                    </a>

                                    <div class="small text-secondary">
                                        <?= e((string)$student['student_number']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= e((string)($student['class_name'] ?? '-')) ?>
                                </td>

                                <td>
                                    <?= e(profileRankLabel(
                                        $student['rank'] ?? null,
                                        (int)$student['points']
                                    )) ?>
                                </td>

                                <td
                                    class="text-end pe-4 fw-semibold"
                                    data-order="<?= (int)$student['points'] ?>"
                                >
                                    <?= number_format((int)$student['points']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="teacher-right-stack">
        <div class="card border-0 shadow-sm profile-section-card teacher-section-card">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1">Tournaments</h2>
                <div class="small text-secondary">
                    Tournaments joined by coached students.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        id="teacherTournamentsTable"
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
                        <?php foreach ($teacherTournaments as $event): ?>
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

        <div class="card border-0 shadow-sm profile-section-card teacher-section-card">
            <div class="card-header bg-white">
                <h2 class="h5 mb-1">Certificates</h2>
                <div class="small text-secondary">
                    Certificates obtained by coached students.
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table
                        id="teacherCertificatesTable"
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
                        <?php foreach ($teacherCertificates as $certificate): ?>
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

    jQuery('#teacherStudentsTable').DataTable({
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
            emptyTable: 'No students are assigned to this teacher yet.'
        }
    });

    jQuery('#teacherTournamentsTable').DataTable({
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

    jQuery('#teacherCertificatesTable').DataTable({
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
});
</script>

</div>

<?php require 'includes/footer.php'; ?>
