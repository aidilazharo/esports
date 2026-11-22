<?php
declare(strict_types=1);

require_once 'config.php';

$schools = getSchools($pdo);

$schoolId = (int)(
    $_GET['school_id']
    ?? $_POST['school_id']
    ?? 0
);

$teacherId = (int)(
    $_GET['teacher_id']
    ?? $_POST['teacher_id']
    ?? 0
);

function teacherModuleNormaliseScore(
    mixed $value,
    array &$errors,
    string $label
): ?float {
    if ($value === '' || $value === null) {
        return null;
    }

    if (!is_numeric($value)) {
        $errors[] = $label . ' must be a number.';
        return null;
    }

    $score = (float)$value;

    if ($score < 0 || $score > 100) {
        $errors[] = $label . ' must be between 0 and 100.';
        return null;
    }

    return round($score, 2);
}

function teacherModuleYoutubeUrl(
    mixed $value,
    array &$errors
): ?string {
    $url = trim((string)$value);

    if ($url === '') {
        return null;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'YouTube URL must be a valid URL.';
        return null;
    }

    $host = strtolower(
        (string)(parse_url($url, PHP_URL_HOST) ?? '')
    );

    $allowed = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'www.youtu.be',
    ];

    if (!in_array($host, $allowed, true)) {
        $errors[] = 'Video evidence must use a YouTube URL.';
        return null;
    }

    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $moduleId =
        (int)($_POST['module_id'] ?? 0);

    $status =
        (string)(
            $_POST['progress_status']
            ?? 'not_started'
        );

    $attendedOn =
        trim(
            (string)(
                $_POST['attended_on']
                ?? ''
            )
        ) ?: null;

    $notes =
        trim(
            (string)(
                $_POST['notes']
                ?? ''
            )
        );

    $errors = [];

    $youtubeUrl =
        teacherModuleYoutubeUrl(
            $_POST['youtube_url'] ?? null,
            $errors
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
        $errors[] = 'Invalid progress status.';
    }

    if (
        $status === 'completed'
        && $youtubeUrl === null
    ) {
        $errors[] =
            'A YouTube video URL is required before this module can be marked Completed.';
    }

    $gameplay =
        teacherModuleNormaliseScore(
            $_POST['gameplay_score']
            ?? null,
            $errors,
            'Gameplay score'
        );

    $quiz =
        teacherModuleNormaliseScore(
            $_POST['quiz_score']
            ?? null,
            $errors,
            'Quiz score'
        );

    $performance =
        teacherModuleNormaliseScore(
            $_POST['development_score']
            ?? null,
            $errors,
            'Competitive performance score'
        );

    $participation =
        teacherModuleNormaliseScore(
            $_POST['participation_score']
            ?? null,
            $errors,
            'Attitude and participation score'
        );

    $teacherCheck = $pdo->prepare(
        'SELECT id, school_id
         FROM teachers
         WHERE id = ?'
    );

    $teacherCheck->execute([
        $teacherId,
    ]);

    $teacherRecord =
        $teacherCheck->fetch();

    if (!$teacherRecord) {
        $errors[] = 'Teacher not found.';
    }

    $moduleCheck = $pdo->prepare(
        'SELECT id
         FROM learning_modules
         WHERE id = ?'
    );

    $moduleCheck->execute([
        $moduleId,
    ]);

    if (!$moduleCheck->fetch()) {
        $errors[] =
            'Learning module not found.';
    }

    if (!$errors) {
        $hasAnyScore =
            $gameplay !== null
            || $quiz !== null
            || $performance !== null
            || $participation !== null;

        $totalScore =
            $hasAnyScore
                ? round(
                    ($gameplay ?? 0) * 0.40
                    + ($quiz ?? 0) * 0.30
                    + ($performance ?? 0) * 0.20
                    + ($participation ?? 0) * 0.10,
                    2
                )
                : null;

        $now =
            date('Y-m-d H:i:s');

        $startedAt =
            $status === 'not_started'
                ? null
                : $now;

        $completedAt =
            $status === 'completed'
                ? $now
                : null;

        $stmt = $pdo->prepare(
            "INSERT INTO teacher_module_progress
             (
                teacher_id,
                module_id,
                progress_status,
                gameplay_score,
                quiz_score,
                development_score,
                participation_score,
                total_score,
                attended_on,
                notes,
                youtube_url,
                started_at,
                completed_at
             )
             VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?
             )
             ON DUPLICATE KEY UPDATE
                progress_status =
                    VALUES(progress_status),
                gameplay_score =
                    VALUES(gameplay_score),
                quiz_score =
                    VALUES(quiz_score),
                development_score =
                    VALUES(development_score),
                participation_score =
                    VALUES(participation_score),
                total_score =
                    VALUES(total_score),
                attended_on =
                    VALUES(attended_on),
                notes =
                    VALUES(notes),
                youtube_url =
                    VALUES(youtube_url),
                started_at =
                    CASE
                        WHEN VALUES(progress_status) = 'not_started'
                            THEN NULL
                        WHEN started_at IS NULL
                            THEN VALUES(started_at)
                        ELSE started_at
                    END,
                completed_at =
                    VALUES(completed_at)"
        );

        $stmt->execute([
            $teacherId,
            $moduleId,
            $status,
            $gameplay,
            $quiz,
            $performance,
            $participation,
            $totalScore,
            $attendedOn,
            $notes ?: null,
            $youtubeUrl,
            $startedAt,
            $completedAt,
        ]);

        setFlash(
            'success',
            'Teacher learning module progress updated.'
        );

        redirect(
            'teacher_learning_modules.php'
            . '?teacher_id='
            . $teacherId
            . '&school_id='
            . (int)$teacherRecord['school_id']
        );
    }

    setFlash(
        'danger',
        implode(' ', $errors)
    );

    redirect(
        'teacher_learning_modules.php'
        . '?teacher_id='
        . $teacherId
        . '&school_id='
        . $schoolId
    );
}

$selectedTeacher = null;

if ($teacherId > 0) {
    $stmt = $pdo->prepare(
        'SELECT
            t.*,
            s.school_name,
            s.school_code
         FROM teachers t
         JOIN schools s
           ON s.id = t.school_id
         WHERE t.id = ?
         LIMIT 1'
    );

    $stmt->execute([
        $teacherId,
    ]);

    $selectedTeacher =
        $stmt->fetch();

    if ($selectedTeacher) {
        $schoolId =
            (int)$selectedTeacher['school_id'];
    }
}

$params = [];

$sql =
    'SELECT
        t.id,
        t.full_name,
        t.position,
        t.school_id,
        s.school_name,
        s.school_code
     FROM teachers t
     JOIN schools s
       ON s.id = t.school_id';

if ($schoolId > 0) {
    $sql .=
        ' WHERE t.school_id = ?';

    $params[] =
        $schoolId;
}

$sql .=
    ' ORDER BY
        s.school_name,
        t.full_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$teacherOptions =
    $stmt->fetchAll();

$modules = [];
$completedCount = 0;
$inProgressCount = 0;
$averageScore = null;

if ($selectedTeacher) {
    $stmt = $pdo->prepare(
        "SELECT
            lm.*,
            COALESCE(
                tmp.progress_status,
                'not_started'
            ) AS progress_status,
            tmp.gameplay_score,
            tmp.quiz_score,
            tmp.development_score,
            tmp.participation_score,
            tmp.total_score,
            tmp.attended_on,
            tmp.notes,
            tmp.youtube_url,
            tmp.started_at,
            tmp.completed_at
         FROM learning_modules lm
         LEFT JOIN teacher_module_progress tmp
           ON tmp.module_id = lm.id
          AND tmp.teacher_id = ?
         ORDER BY
            lm.week_number,
            lm.sequence_no"
    );

    $stmt->execute([
        $teacherId,
    ]);

    $modules =
        $stmt->fetchAll();

    $completedCount =
        count(
            array_filter(
                $modules,
                static fn(array $module): bool =>
                    $module['progress_status']
                    === 'completed'
            )
        );

    $inProgressCount =
        count(
            array_filter(
                $modules,
                static fn(array $module): bool =>
                    $module['progress_status']
                    === 'in_progress'
            )
        );

    $scores =
        array_values(
            array_filter(
                array_map(
                    static fn(array $module): ?float =>
                        $module['total_score']
                        !== null
                            ? (float)$module['total_score']
                            : null,
                    $modules
                ),
                static fn(?float $score): bool =>
                    $score !== null
            )
        );

    if ($scores) {
        $averageScore =
            round(
                array_sum($scores)
                / count($scores),
                2
            );
    }
}

$modulesByWeek = [];

foreach ($modules as $module) {
    $modulesByWeek[
        (int)$module['week_number']
    ][] = $module;
}

$pageTitle =
    'Teacher Learning Modules';

require 'includes/header.php';
?>

<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div>
        <h1 class="h2 mb-1">
            Teacher Esports Learning Modules
        </h1>

        <p class="text-secondary mb-0">
            Competitive esports modules with recorded YouTube evidence.
        </p>
    </div>
</div>

<div class="card content-card mb-4">
    <div class="card-body p-4">
        <form
            method="get"
            class="row g-3 align-items-end"
        >
            <div class="col-md-5">
                <label class="form-label">
                    School
                </label>

                <select
                    class="form-select"
                    name="school_id"
                    onchange="this.form.submit()"
                >
                    <option value="">
                        All schools
                    </option>

                    <?php foreach ($schools as $school): ?>
                        <option
                            value="<?= (int)$school['id'] ?>"
                            <?= $schoolId === (int)$school['id'] ? 'selected' : '' ?>
                        >
                            <?= e((string)$school['school_name']) ?>
                            (<?= e((string)$school['school_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label">
                    Teacher
                </label>

                <select
                    class="form-select"
                    name="teacher_id"
                    required
                >
                    <option value="">
                        Select teacher
                    </option>

                    <?php foreach ($teacherOptions as $option): ?>
                        <option
                            value="<?= (int)$option['id'] ?>"
                            <?= $teacherId === (int)$option['id'] ? 'selected' : '' ?>
                        >
                            <?= e((string)$option['full_name']) ?>
                            — <?= e((string)$option['school_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-primary">
                    Open Tracker
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedTeacher): ?>
    <div class="card content-card mb-4">
        <div class="card-body p-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-5">
                    <span class="badge text-bg-primary mb-2">
                        <?= e((string)$selectedTeacher['school_code']) ?>
                    </span>

                    <h2 class="h4 mb-1">
                        <?= e((string)$selectedTeacher['full_name']) ?>
                    </h2>

                    <div class="text-secondary">
                        <?= e((string)($selectedTeacher['position'] ?? 'Teacher')) ?>
                        · <?= e((string)$selectedTeacher['school_name']) ?>
                    </div>
                </div>

                <div class="col-4 col-lg-2">
                    <div class="small text-secondary">
                        Completed
                    </div>

                    <div class="h3 mb-0 text-success">
                        <?= $completedCount ?>/20
                    </div>
                </div>

                <div class="col-4 col-lg-2">
                    <div class="small text-secondary">
                        In Progress
                    </div>

                    <div class="h3 mb-0 text-warning">
                        <?= $inProgressCount ?>
                    </div>
                </div>

                <div class="col-4 col-lg-3">
                    <div class="small text-secondary">
                        Average Score
                    </div>

                    <div class="h3 mb-0">
                        <?= $averageScore !== null
                            ? e(number_format($averageScore, 2)) . '%'
                            : '-' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-light border">
        <strong>Assessment:</strong>
        Gameplay 40%, Quiz 30%, Competitive Performance 20%,
        Attitude &amp; Participation 10%.
        <strong>YouTube evidence is required for Completed modules.</strong>
    </div>

    <div class="accordion" id="teacherWeeklyModules">
    <?php foreach ($modulesByWeek as $weekNumber => $weekModules): ?>
        <?php
        $weekCompleted =
            count(
                array_filter(
                    $weekModules,
                    static fn(array $module): bool =>
                        $module['progress_status']
                        === 'completed'
                )
            );

        $weekTitle =
            (string)$weekModules[0]['week_title'];
        ?>

        <div class="accordion-item border-0 content-card mb-4 overflow-hidden">
            <h2 class="accordion-header">
                <button
                    class="accordion-button <?= $weekNumber === 1 ? '' : 'collapsed' ?>"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#teacherWeek<?= (int)$weekNumber ?>"
                >
                    <span class="fw-semibold">
                        Week <?= (int)$weekNumber ?>:
                        <?= e($weekTitle) ?>
                    </span>

                    <span
                        class="badge text-bg-<?= $weekCompleted === 5 ? 'success' : 'secondary' ?> ms-3"
                    >
                        <?= $weekCompleted ?>/5 completed
                    </span>
                </button>
            </h2>

            <div
                id="teacherWeek<?= (int)$weekNumber ?>"
                class="accordion-collapse collapse <?= $weekNumber === 1 ? 'show' : '' ?>"
                data-bs-parent="#teacherWeeklyModules"
            >
                <div class="accordion-body p-3 p-md-4">
                    <?php foreach ($weekModules as $module): ?>
                        <form
                            method="post"
                            class="card module-card mb-3"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e(csrfToken()) ?>"
                            >

                            <input
                                type="hidden"
                                name="teacher_id"
                                value="<?= (int)$selectedTeacher['id'] ?>"
                            >

                            <input
                                type="hidden"
                                name="school_id"
                                value="<?= (int)$selectedTeacher['school_id'] ?>"
                            >

                            <input
                                type="hidden"
                                name="module_id"
                                value="<?= (int)$module['id'] ?>"
                            >

                            <div class="card-body p-4">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge text-bg-primary">
                                                <?= e((string)$module['module_code']) ?>
                                            </span>

                                            <span class="small text-secondary">
                                                <?= e((string)$module['day_name']) ?>
                                            </span>
                                        </div>

                                        <h3 class="h5 mb-1">
                                            <?= e((string)$module['module_name']) ?>
                                        </h3>

                                        <div class="text-secondary">
                                            <?= e((string)$module['game_title']) ?>
                                            · <strong>Challenge:</strong>
                                            <?= e((string)$module['dreams_activity']) ?>
                                        </div>
                                    </div>

                                    <div class="text-lg-end small">
                                        <strong>Objective:</strong><br>
                                        <?= e((string)$module['objective']) ?>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">
                                            Status
                                        </label>

                                        <select
                                            class="form-select"
                                            name="progress_status"
                                        >
                                            <?php foreach (
                                                [
                                                    'not_started' => 'Not Started',
                                                    'in_progress' => 'In Progress',
                                                    'completed' => 'Completed',
                                                ]
                                                as $value => $label
                                            ): ?>
                                                <option
                                                    value="<?= e($value) ?>"
                                                    <?= $module['progress_status'] === $value ? 'selected' : '' ?>
                                                >
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">
                                            Attendance Date
                                        </label>

                                        <input
                                            type="date"
                                            class="form-control"
                                            name="attended_on"
                                            value="<?= e((string)($module['attended_on'] ?? '')) ?>"
                                        >
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Quiz
                                        </label>

                                        <div class="form-control bg-light h-auto">
                                            <?= e((string)$module['quiz_title']) ?>:
                                            <?= e((string)$module['quiz_question']) ?>
                                        </div>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label class="form-label">
                                            Gameplay (40%)
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="form-control"
                                            name="gameplay_score"
                                            value="<?= e((string)($module['gameplay_score'] ?? '')) ?>"
                                        >
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label class="form-label">
                                            Quiz (30%)
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="form-control"
                                            name="quiz_score"
                                            value="<?= e((string)($module['quiz_score'] ?? '')) ?>"
                                        >
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label class="form-label">
                                            Competitive Performance (20%)
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="form-control"
                                            name="development_score"
                                            value="<?= e((string)($module['development_score'] ?? '')) ?>"
                                        >
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label class="form-label">
                                            Attitude &amp; Participation (10%)
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="form-control"
                                            name="participation_score"
                                            value="<?= e((string)($module['participation_score'] ?? '')) ?>"
                                        >
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">
                                            <i class="bi bi-youtube text-danger me-1"></i>
                                            YouTube Gameplay Evidence
                                        </label>

                                        <input
                                            type="url"
                                            class="form-control"
                                            name="youtube_url"
                                            value="<?= e((string)($module['youtube_url'] ?? '')) ?>"
                                            placeholder="https://www.youtube.com/watch?v=..."
                                        >

                                        <div class="form-text">
                                            Required before the module can be marked Completed.
                                        </div>
                                    </div>

                                    <div class="col-md-10">
                                        <label class="form-label">
                                            Notes
                                        </label>

                                        <input
                                            class="form-control"
                                            name="notes"
                                            value="<?= e((string)($module['notes'] ?? '')) ?>"
                                        >
                                    </div>

                                    <div class="col-md-2 d-grid align-self-end">
                                        <button
                                            class="btn btn-success"
                                            type="submit"
                                        >
                                            <i class="bi bi-save me-2"></i>
                                            Save
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="text-center py-5 text-secondary">
        <i class="bi bi-journal-check display-3 d-block mb-3"></i>
        Select a teacher to open the esports learning tracker.
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
