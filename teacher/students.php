<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';
require_once __DIR__ . '/../includes/profile_scoring.php';

requirePortalRole('teacher');

$teacherId = (int)($_SESSION['teacher_id'] ?? 0);
$schoolId = (int)($_SESSION['school_id'] ?? 0);
$focusStudentId = (int)($_GET['student_id'] ?? 0);

$assignmentReady =
    profileTableExists(
        $pdo,
        'student_coach_assignments'
    );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    try {
        if (!$assignmentReady) {
            throw new RuntimeException(
                'Student coaching setup is required.'
            );
        }

        $action = trim(
            (string)($_POST['team_action'] ?? '')
        );

        $studentId = (int)(
            $_POST['student_id'] ?? 0
        );

        $stmt = $pdo->prepare(
            'SELECT id, full_name
             FROM students
             WHERE id = ?
               AND school_id = ?
             LIMIT 1'
        );
        $stmt->execute([$studentId, $schoolId]);
        $student = $stmt->fetch();

        if (!$student) {
            throw new RuntimeException(
                'Student was not found in your school.'
            );
        }

        if ($action === 'assign') {
            $stmt = $pdo->prepare(
                'SELECT teacher_id
                 FROM student_coach_assignments
                 WHERE student_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$studentId]);
            $existingTeacherId =
                (int)($stmt->fetchColumn() ?: 0);

            if (
                $existingTeacherId > 0
                && $existingTeacherId !== $teacherId
            ) {
                throw new RuntimeException(
                    'This student is already assigned to another teacher.'
                );
            }

            if ($existingTeacherId === $teacherId) {
                setFlash(
                    'info',
                    'This student is already assigned to you.'
                );
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO student_coach_assignments
                     (
                        student_id,
                        teacher_id,
                        school_id,
                        assigned_by_teacher_id,
                        assigned_at,
                        updated_at
                     )
                     VALUES (?, ?, ?, ?, NOW(), NOW())'
                );

                $stmt->execute([
                    $studentId,
                    $teacherId,
                    $schoolId,
                    $teacherId,
                ]);

                setFlash(
                    'success',
                    'Student assigned to your team.'
                );
            }
        } elseif ($action === 'unassign') {
            $stmt = $pdo->prepare(
                'DELETE FROM student_coach_assignments
                 WHERE student_id = ?
                   AND teacher_id = ?'
            );
            $stmt->execute([
                $studentId,
                $teacherId,
            ]);

            setFlash(
                'success',
                'Student removed from your team.'
            );
        } else {
            throw new RuntimeException(
                'Invalid team action.'
            );
        }
    } catch (Throwable $e) {
        error_log(
            'Teacher student assignment error: '
            . $e->getMessage()
        );

        setFlash(
            'danger',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to update the student assignment.'
        );
    }

    redirect(
        '/teacher/students.php'
        . (
            $focusStudentId > 0
                ? '?student_id=' . $focusStudentId
                : ''
        )
    );
}

$scoreData = profileScoringData($pdo);

if ($assignmentReady) {
    $stmt = $pdo->prepare(
        'SELECT
            st.id,
            st.student_number,
            st.full_name,
            st.class_name,
            st.membership_status,
            a.teacher_id AS coaching_teacher_id,
            t.full_name AS coaching_teacher_name
         FROM students st
         LEFT JOIN student_coach_assignments a
           ON a.student_id = st.id
         LEFT JOIN teachers t
           ON t.id = a.teacher_id
         WHERE st.school_id = ?
         ORDER BY
            CASE WHEN st.id = ? THEN 0 ELSE 1 END,
            st.full_name'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT
            st.id,
            st.student_number,
            st.full_name,
            st.class_name,
            st.membership_status,
            NULL AS coaching_teacher_id,
            NULL AS coaching_teacher_name
         FROM students st
         WHERE st.school_id = ?
         ORDER BY
            CASE WHEN st.id = ? THEN 0 ELSE 1 END,
            st.full_name'
    );
}

$stmt->execute([
    $schoolId,
    $focusStudentId,
]);

$students = $stmt->fetchAll();

$myTeamCount = 0;
$unassignedCount = 0;

foreach ($students as &$student) {
    $studentId = (int)$student['id'];

    $student['points'] =
        (int)(
            $scoreData['students'][$studentId]['points']
            ?? 0
        );

    $student['rank'] =
        $scoreData['students'][$studentId]['rank']
        ?? null;

    $assignedTeacherId =
        (int)($student['coaching_teacher_id'] ?? 0);

    if ($assignedTeacherId === $teacherId) {
        $myTeamCount++;
    }

    if ($assignedTeacherId < 1) {
        $unassignedCount++;
    }
}
unset($student);

$teacherScore = $scoreData['teachers'][$teacherId] ?? [
    'points' => 0,
    'rank' => null,
];

portalPageStart(
    'teacher',
    'students',
    'Students & Team'
);
?>

<link
    rel="stylesheet"
    href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css"
>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-secondary">My Team</div>
                <div class="h3 mb-0"><?= number_format($myTeamCount) ?></div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-secondary">Unassigned Students</div>
                <div class="h3 mb-0"><?= number_format($unassignedCount) ?></div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-secondary">Team Points</div>
                <div class="h3 mb-0 text-primary">
                    <?= number_format((int)$teacherScore['points']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-secondary">Teacher Ranking</div>
                <div class="h3 mb-0">
                    <?= e(profileRankLabel(
                        $teacherScore['rank'] ?? null,
                        (int)$teacherScore['points']
                    )) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$assignmentReady): ?>
    <div class="alert alert-warning">
        Run the student coaching assignment database update first.
    </div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-1">School Students</h2>
        <div class="small text-secondary">
            A student can have one coaching teacher only.
        </div>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table
                id="teacherTeamTable"
                class="table table-hover align-middle w-100"
            >
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Coaching Teacher</th>
                        <th>Rank</th>
                        <th>Points</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($students as $student): ?>
                    <?php
                    $studentId = (int)$student['id'];
                    $assignedTeacherId =
                        (int)($student['coaching_teacher_id'] ?? 0);
                    $isMine =
                        $assignedTeacherId === $teacherId;
                    $isUnassigned =
                        $assignedTeacherId < 1;
                    ?>
                    <tr
                        <?= $studentId === $focusStudentId
                            ? 'class="table-warning"'
                            : '' ?>
                    >
                        <td>
                            <div class="fw-semibold">
                                <?= e((string)$student['full_name']) ?>
                            </div>
                            <small class="text-secondary">
                                <?= e((string)$student['student_number']) ?>
                            </small>
                        </td>

                        <td>
                            <?= e((string)($student['class_name'] ?? '-')) ?>
                        </td>

                        <td>
                            <?php if ($isMine): ?>
                                <span class="badge text-bg-success">
                                    Assigned to You
                                </span>
                            <?php elseif ($isUnassigned): ?>
                                <span class="badge text-bg-secondary">
                                    Not Assigned
                                </span>
                            <?php else: ?>
                                <span class="badge text-bg-info">
                                    <?= e((string)$student['coaching_teacher_name']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= e(profileRankLabel(
                                $student['rank'] ?? null,
                                (int)$student['points']
                            )) ?>
                        </td>

                        <td class="fw-semibold">
                            <?= number_format((int)$student['points']) ?>
                        </td>

                        <td class="text-end text-nowrap">
                            <a
                                class="btn btn-sm btn-outline-secondary"
                                href="/student_profile.php?student_id=<?= $studentId ?>"
                                title="Student Profile"
                            >
                                <i class="bi bi-person-vcard"></i>
                            </a>

                            <?php if ($assignmentReady && $isUnassigned): ?>
                                <form method="post" class="d-inline">
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrfToken()) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="team_action"
                                        value="assign"
                                    >
                                    <input
                                        type="hidden"
                                        name="student_id"
                                        value="<?= $studentId ?>"
                                    >

                                    <button
                                        class="btn btn-sm btn-primary"
                                        type="submit"
                                    >
                                        <i class="bi bi-person-plus me-1"></i>
                                        Assign to Me
                                    </button>
                                </form>
                            <?php elseif ($assignmentReady && $isMine): ?>
                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Remove this student from your team?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(csrfToken()) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="team_action"
                                        value="unassign"
                                    >
                                    <input
                                        type="hidden"
                                        name="student_id"
                                        value="<?= $studentId ?>"
                                    >

                                    <button
                                        class="btn btn-sm btn-outline-danger"
                                        type="submit"
                                    >
                                        Unassign
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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

    jQuery('#teacherTeamTable').DataTable({
        pageLength: 10,
        lengthChange: false,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        order: [],
        columnDefs: [
            {
                targets: -1,
                orderable: false,
                searchable: false
            }
        ],
        language: {
            search: 'Search:',
            emptyTable: 'No students registered.'
        }
    });
});
</script>

<?php portalPageEnd(); ?>
