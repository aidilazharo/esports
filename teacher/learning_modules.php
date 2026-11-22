<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';

requirePortalRole('teacher');

$teacherId = (int)($_SESSION['teacher_id'] ?? 0);
$schoolId = (int)($_SESSION['school_id'] ?? 0);

if ($teacherId < 1 || $schoolId < 1) {
    http_response_code(403);
    exit('Teacher and school context are required.');
}

function teacherLearningTableExists(
    PDO $pdo,
    string $table
): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );

    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function teacherLearningColumnExists(
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

function teacherLearningYoutubeUrl(
    mixed $value
): ?string {
    $url = trim((string)$value);

    if ($url === '') {
        return null;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException(
            'YouTube URL is not valid.'
        );
    }

    $host = strtolower(
        (string)(parse_url($url, PHP_URL_HOST) ?? '')
    );

    $allowedHosts = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'www.youtu.be',
    ];

    if (!in_array($host, $allowedHosts, true)) {
        throw new RuntimeException(
            'Only YouTube or youtu.be links are allowed.'
        );
    }

    return $url;
}

function teacherLearningYoutubeVideoId(
    ?string $url
): ?string {
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

    $path = trim(
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

function teacherLearningEmbedUrl(
    ?string $url
): ?string {
    $videoId =
        teacherLearningYoutubeVideoId($url);

    if ($videoId === null) {
        return null;
    }

    return
        'https://www.youtube-nocookie.com/embed/'
        . rawurlencode($videoId);
}

$mode = strtolower(
    trim(
        (string)(
            $_GET['mode']
            ?? $_POST['mode']
            ?? 'teacher'
        )
    )
);

if (!in_array($mode, ['teacher', 'student'], true)) {
    $mode = 'teacher';
}

$teacherYoutubeReady =
    teacherLearningColumnExists(
        $pdo,
        'teacher_module_progress',
        'youtube_url'
    );

$studentYoutubeReady =
    teacherLearningColumnExists(
        $pdo,
        'student_module_progress',
        'youtube_url'
    );

$coachAssignmentsReady =
    teacherLearningTableExists(
        $pdo,
        'student_coach_assignments'
    );

/*
 * Coached students for Student Modules mode.
 */
$students = [];

if ($coachAssignmentsReady) {
    $stmt = $pdo->prepare(
        "SELECT
            st.id,
            st.student_number,
            st.full_name,
            st.class_name,
            st.membership_status
         FROM student_coach_assignments sca
         JOIN students st
           ON st.id = sca.student_id
         WHERE sca.teacher_id = ?
           AND sca.school_id = ?
         ORDER BY st.full_name"
    );

    $stmt->execute([
        $teacherId,
        $schoolId,
    ]);

    $students = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT
            id,
            student_number,
            full_name,
            class_name,
            membership_status
         FROM students
         WHERE school_id = ?
         ORDER BY full_name"
    );

    $stmt->execute([
        $schoolId,
    ]);

    $students = $stmt->fetchAll();
}

$studentIds = array_map(
    static fn(array $student): int =>
        (int)$student['id'],
    $students
);

$studentId = (int)(
    $_GET['student_id']
    ?? $_POST['student_id']
    ?? ($studentIds[0] ?? 0)
);

if (
    $studentId > 0
    && !in_array(
        $studentId,
        $studentIds,
        true
    )
) {
    http_response_code(403);
    exit(
        'You can update learning modules only for students assigned to you.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $moduleId = (int)(
            $_POST['module_id']
            ?? 0
        );

        $status = strtolower(
            trim(
                (string)(
                    $_POST['progress_status']
                    ?? 'not_started'
                )
            )
        );

        if (
            !in_array(
                $status,
                [
                    'not_started',
                    'in_progress',
                    'completed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid module status.'
            );
        }

        $youtubeUrl =
            teacherLearningYoutubeUrl(
                $_POST['youtube_url']
                ?? ''
            );

        if (
            $status === 'completed'
            && $youtubeUrl === null
        ) {
            throw new RuntimeException(
                'YouTube video evidence is required before a module can be marked Completed.'
            );
        }

        $moduleCheck = $pdo->prepare(
            'SELECT id
             FROM learning_modules
             WHERE id = ?
             LIMIT 1'
        );

        $moduleCheck->execute([
            $moduleId,
        ]);

        if (!$moduleCheck->fetch()) {
            throw new RuntimeException(
                'Learning module not found.'
            );
        }

        if ($mode === 'teacher') {
            if (!$teacherYoutubeReady) {
                throw new RuntimeException(
                    'Run sql/add_teacher_module_youtube_url.sql before saving teacher module videos.'
                );
            }

            $existingStmt = $pdo->prepare(
                'SELECT id, started_at
                 FROM teacher_module_progress
                 WHERE teacher_id = ?
                   AND module_id = ?
                 LIMIT 1'
            );

            $existingStmt->execute([
                $teacherId,
                $moduleId,
            ]);

            $existing =
                $existingStmt->fetch();

            $startedAt = null;
            $completedAt = null;

            if ($status !== 'not_started') {
                $startedAt =
                    $existing['started_at']
                    ?? date('Y-m-d H:i:s');
            }

            if ($status === 'completed') {
                $completedAt =
                    date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO teacher_module_progress
                 (
                    teacher_id,
                    module_id,
                    progress_status,
                    youtube_url,
                    started_at,
                    completed_at
                 )
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    progress_status =
                        VALUES(progress_status),
                    youtube_url =
                        VALUES(youtube_url),
                    started_at =
                        CASE
                            WHEN VALUES(progress_status)
                                 = 'not_started'
                                THEN NULL
                            WHEN started_at IS NULL
                                THEN VALUES(started_at)
                            ELSE started_at
                        END,
                    completed_at =
                        CASE
                            WHEN VALUES(progress_status)
                                 = 'completed'
                                THEN VALUES(completed_at)
                            ELSE NULL
                        END"
            );

            $stmt->execute([
                $teacherId,
                $moduleId,
                $status,
                $youtubeUrl,
                $startedAt,
                $completedAt,
            ]);

            setFlash(
                'success',
                $status === 'completed'
                    ? 'Your module was completed and YouTube video saved.'
                    : 'Your module progress was updated.'
            );

            redirect(
                '/teacher/learning_modules.php?mode=teacher'
            );
        }

        if (!$studentYoutubeReady) {
            throw new RuntimeException(
                'Run sql/add_student_module_youtube_url.sql before saving student module videos.'
            );
        }

        $existingStmt = $pdo->prepare(
            'SELECT id, started_at
             FROM student_module_progress
             WHERE student_id = ?
               AND module_id = ?
             LIMIT 1'
        );

        $existingStmt->execute([
            $studentId,
            $moduleId,
        ]);

        $existing =
            $existingStmt->fetch();

        $startedAt = null;
        $completedAt = null;

        if ($status !== 'not_started') {
            $startedAt =
                $existing['started_at']
                ?? date('Y-m-d H:i:s');
        }

        if ($status === 'completed') {
            $completedAt =
                date('Y-m-d H:i:s');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO student_module_progress
             (
                student_id,
                module_id,
                progress_status,
                youtube_url,
                started_at,
                completed_at
             )
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                progress_status =
                    VALUES(progress_status),
                youtube_url =
                    VALUES(youtube_url),
                started_at =
                    CASE
                        WHEN VALUES(progress_status)
                             = 'not_started'
                            THEN NULL
                        WHEN started_at IS NULL
                            THEN VALUES(started_at)
                        ELSE started_at
                    END,
                completed_at =
                    CASE
                        WHEN VALUES(progress_status)
                             = 'completed'
                            THEN VALUES(completed_at)
                        ELSE NULL
                    END"
        );

        $stmt->execute([
            $studentId,
            $moduleId,
            $status,
            $youtubeUrl,
            $startedAt,
            $completedAt,
        ]);

        setFlash(
            'success',
            $status === 'completed'
                ? 'Student module completed and YouTube video saved.'
                : 'Student learning module updated.'
        );
    } catch (Throwable $e) {
        error_log(
            'Teacher learning modules update error: '
            . $e->getMessage()
        );

        setFlash(
            'danger',
            $e->getMessage()
        );
    }

    redirect(
        '/teacher/learning_modules.php'
        . '?mode=student'
        . '&student_id='
        . $studentId
    );
}

/*
 * Load Teacher's own modules.
 */
$teacherModules = [];

$teacherYoutubeSelect =
    $teacherYoutubeReady
        ? 'tmp.youtube_url'
        : 'NULL AS youtube_url';

$stmt = $pdo->prepare(
    "SELECT
        lm.id,
        lm.module_code,
        lm.sequence_no,
        lm.week_number,
        lm.module_name,
        lm.game_title,
        lm.dreams_activity,
        COALESCE(
            tmp.progress_status,
            'not_started'
        ) AS progress_status,
        {$teacherYoutubeSelect},
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

$teacherModules =
    $stmt->fetchAll();

foreach ($teacherModules as &$module) {
    $module['embed_url'] =
        teacherLearningEmbedUrl(
            (string)(
                $module['youtube_url']
                ?? ''
            )
        );
}
unset($module);

/*
 * Load selected student's modules.
 */
$studentModules = [];

if ($studentId > 0) {
    $studentYoutubeSelect =
        $studentYoutubeReady
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
            lm.dreams_activity,
            COALESCE(
                smp.progress_status,
                'not_started'
            ) AS progress_status,
            {$studentYoutubeSelect},
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

    $studentModules =
        $stmt->fetchAll();

    foreach ($studentModules as &$module) {
        $module['embed_url'] =
            teacherLearningEmbedUrl(
                (string)(
                    $module['youtube_url']
                    ?? ''
                )
            );
    }
    unset($module);
}

$selectedStudent = null;

foreach ($students as $student) {
    if ((int)$student['id'] === $studentId) {
        $selectedStudent = $student;
        break;
    }
}

$currentModules =
    $mode === 'teacher'
        ? $teacherModules
        : $studentModules;

$completedCount = count(
    array_filter(
        $currentModules,
        static fn(array $module): bool =>
            (string)$module['progress_status']
            === 'completed'
    )
);

$videoCount = count(
    array_filter(
        $currentModules,
        static fn(array $module): bool =>
            !empty($module['embed_url'])
    )
);

portalPageStart(
    'teacher',
    'learning_modules',
    'Learning Modules'
);
?>

<style>
.teacher-module-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:.8rem;
}
.teacher-module-card{
    min-width:0;
    height:100%;
}
.teacher-module-card .card-body{
    padding:.85rem;
}
.teacher-module-code{
    font-size:.66rem;
}
.teacher-module-title{
    font-size:.84rem;
    line-height:1.25;
    font-weight:700;
    min-height:2.1rem;
}
.teacher-module-game{
    font-size:.72rem;
    color:var(--bs-secondary-color);
}
.teacher-module-video{
    width:100%;
    aspect-ratio:16/9;
    border:0;
    border-radius:.45rem;
    background:#000;
    display:block;
}
.teacher-module-placeholder{
    width:100%;
    aspect-ratio:16/9;
    border:1px solid var(--bs-border-color);
    border-radius:.45rem;
    background:#f8f9fa;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#6c757d;
    font-size:.72rem;
    text-align:center;
}
.teacher-module-card .form-label{
    font-size:.7rem;
    margin-bottom:.25rem;
}
.teacher-module-card .form-control,
.teacher-module-card .form-select{
    font-size:.75rem;
}
.teacher-module-card .btn{
    font-size:.74rem;
}
@media (max-width:1199.98px){
    .teacher-module-grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}
@media (max-width:767.98px){
    .teacher-module-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}
@media (max-width:575.98px){
    .teacher-module-grid{
        grid-template-columns:1fr;
    }
}
</style>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a
        class="btn <?= $mode === 'teacher' ? 'btn-primary' : 'btn-outline-primary' ?>"
        href="/teacher/learning_modules.php?mode=teacher"
    >
        <i class="bi bi-person-badge me-1"></i>
        My Modules
    </a>

    <a
        class="btn <?= $mode === 'student' ? 'btn-primary' : 'btn-outline-primary' ?>"
        href="/teacher/learning_modules.php?mode=student<?= $studentId > 0 ? '&student_id=' . (int)$studentId : '' ?>"
    >
        <i class="bi bi-people me-1"></i>
        Student Modules
    </a>
</div>

<?php if (
    $mode === 'teacher'
    && !$teacherYoutubeReady
): ?>
    <div class="alert alert-warning">
        <i class="bi bi-database-exclamation me-2"></i>
        Run
        <code>sql/add_teacher_module_youtube_url.sql</code>
        before saving teacher YouTube links.
    </div>
<?php endif; ?>

<?php if (
    $mode === 'student'
    && !$studentYoutubeReady
): ?>
    <div class="alert alert-warning">
        <i class="bi bi-database-exclamation me-2"></i>
        Run
        <code>sql/add_student_module_youtube_url.sql</code>
        before saving student YouTube links.
    </div>
<?php endif; ?>

<div class="card stat-card mb-4">
    <div class="card-body p-4">
        <div class="row g-3 align-items-end">
            <?php if ($mode === 'teacher'): ?>
                <div class="col-lg-6">
                    <div class="small text-secondary">
                        Module Owner
                    </div>

                    <div class="h5 mb-0">
                        <i class="bi bi-person-badge me-1"></i>
                        My Teacher Modules
                    </div>

                    <div class="small text-secondary mt-1">
                        Teachers complete the same 20 esports modules as students.
                    </div>
                </div>
            <?php else: ?>
                <div class="col-lg-6">
                    <label class="form-label fw-semibold">
                        Student
                    </label>

                    <select
                        class="form-select"
                        onchange="if(this.value){window.location.href='/teacher/learning_modules.php?mode=student&student_id='+encodeURIComponent(this.value)}"
                    >
                        <?php if (!$students): ?>
                            <option value="">
                                No students assigned
                            </option>
                        <?php endif; ?>

                        <?php foreach ($students as $student): ?>
                            <option
                                value="<?= (int)$student['id'] ?>"
                                <?= $studentId === (int)$student['id'] ? 'selected' : '' ?>
                            >
                                <?= e((string)$student['full_name']) ?>
                                <?php if (!empty($student['class_name'])): ?>
                                    — <?= e((string)$student['class_name']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-6 col-lg-3">
                <div class="small text-secondary">
                    Modules Completed
                </div>

                <div class="h3 mb-0 text-success">
                    <?= number_format($completedCount) ?>/<?= number_format(count($currentModules)) ?>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="small text-secondary">
                    YouTube Videos
                </div>

                <div class="h3 mb-0">
                    <?= number_format($videoCount) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-light border mb-4">
    <i class="bi bi-youtube text-danger me-2"></i>

    <?php if ($mode === 'teacher'): ?>
        Complete the same esports modules as students and upload your own recorded gameplay to YouTube.
    <?php else: ?>
        Add the selected student's recorded gameplay YouTube link to the matching module.
    <?php endif; ?>

    A module cannot be marked
    <strong>Completed</strong>
    without YouTube evidence.
</div>

<?php if (
    $mode === 'student'
    && !$selectedStudent
): ?>
    <div class="card stat-card">
        <div class="card-body py-5 text-center text-secondary">
            <i class="bi bi-person-video3 display-4 d-block mb-3"></i>
            No student is available for learning-module updates.
        </div>
    </div>
<?php else: ?>
    <div class="teacher-module-grid">
        <?php foreach ($currentModules as $module): ?>
            <form
                method="post"
                class="card stat-card teacher-module-card"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="mode"
                    value="<?= e($mode) ?>"
                >

                <?php if ($mode === 'student'): ?>
                    <input
                        type="hidden"
                        name="student_id"
                        value="<?= (int)$studentId ?>"
                    >
                <?php endif; ?>

                <input
                    type="hidden"
                    name="module_id"
                    value="<?= (int)$module['id'] ?>"
                >

                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <span class="badge text-bg-primary teacher-module-code">
                            <?= e((string)$module['module_code']) ?>
                        </span>

                        <span class="small text-secondary">
                            W<?= (int)$module['week_number'] ?>
                        </span>
                    </div>

                    <div class="teacher-module-title mb-1">
                        <?= e((string)$module['module_name']) ?>
                    </div>

                    <div class="teacher-module-game mb-2">
                        <i class="bi bi-controller me-1"></i>
                        <?= e((string)$module['game_title']) ?>
                    </div>

                    <?php if (!empty($module['embed_url'])): ?>
                        <iframe
                            class="teacher-module-video mb-2"
                            src="<?= e((string)$module['embed_url']) ?>"
                            title="<?= e((string)$module['module_name']) ?>"
                            loading="lazy"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen
                        ></iframe>
                    <?php else: ?>
                        <div class="teacher-module-placeholder mb-2">
                            <div>
                                <i class="bi bi-youtube fs-4 d-block mb-1"></i>
                                No video uploaded
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label">
                            Status
                        </label>

                        <select
                            class="form-select form-select-sm"
                            name="progress_status"
                        >
                            <option
                                value="not_started"
                                <?= $module['progress_status'] === 'not_started' ? 'selected' : '' ?>
                            >
                                Not Started
                            </option>

                            <option
                                value="in_progress"
                                <?= $module['progress_status'] === 'in_progress' ? 'selected' : '' ?>
                            >
                                In Progress
                            </option>

                            <option
                                value="completed"
                                <?= $module['progress_status'] === 'completed' ? 'selected' : '' ?>
                            >
                                Completed
                            </option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">
                            YouTube URL
                        </label>

                        <input
                            type="url"
                            class="form-control form-control-sm"
                            name="youtube_url"
                            value="<?= e((string)($module['youtube_url'] ?? '')) ?>"
                            placeholder="https://youtu.be/..."
                            <?= (
                                $mode === 'teacher'
                                    ? $teacherYoutubeReady
                                    : $studentYoutubeReady
                            )
                                ? ''
                                : 'disabled' ?>
                        >
                    </div>

                    <button
                        class="btn btn-sm btn-success mt-auto"
                        type="submit"
                        <?= (
                            $mode === 'teacher'
                                ? $teacherYoutubeReady
                                : $studentYoutubeReady
                        )
                            ? ''
                            : 'disabled' ?>
                    >
                        <i class="bi bi-save me-1"></i>
                        Save Module
                    </button>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php portalPageEnd(); ?>
