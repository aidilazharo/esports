<?php
require_once 'config.php';
require_once __DIR__ . '/events-competitions-helpers.php';

if (!pmSchemaReady($pdo)) {
    $pageTitle = 'Events & Competitions';
    require 'includes/header.php';
    ?>
    <div class="alert alert-warning">
        <h1 class="h4">Events &amp; Competitions database setup required</h1>
        <p class="mb-0">Run <code>programme_schema.sql</code> once before using Events &amp; Competitions.</p>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

if (!pmProgrammeParticipationSchemaReady($pdo)) {
    $pageTitle = 'Events & Competitions';
    require 'includes/header.php';
    ?>
    <div class="alert alert-warning">
        <h1 class="h4">Events &amp; Competitions upgrade required</h1>
        <p class="mb-0">
            Run <code>upgrade_programme_school_competition.sql</code> once before using
            school status, participant selection and Events &amp; Competitions payment.
        </p>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX: searchable schools and students
|--------------------------------------------------------------------------
*/
$ajax = trim((string) ($_GET['ajax'] ?? ''));

if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        if ($ajax === 'schools') {
            $q = trim((string) ($_GET['q'] ?? ''));
            $like = '%' . $q . '%';

            $stmt = $pdo->prepare(
                "SELECT
                    s.id,
                    s.school_code,
                    s.school_name,
                    SUM(CASE WHEN st.membership_status = 'active' THEN 1 ELSE 0 END) AS active_students
                 FROM schools s
                 LEFT JOIN students st ON st.school_id = s.id
                 WHERE s.school_name LIKE ?
                    OR s.school_code LIKE ?
                 GROUP BY s.id, s.school_code, s.school_name
                 ORDER BY
                    CASE WHEN s.school_code = ? THEN 0 ELSE 1 END,
                    s.school_name
                 LIMIT 20"
            );
            $stmt->execute([$like, $like, strtoupper($q)]);
            $schools = [];

            foreach ($stmt->fetchAll() as $school) {
                $active = (int) $school['active_students'];
                $minimum = pmMinimumActiveStudentsForSchool();

                $isActiveSchool = $active >= $minimum;

                $schools[] = [
                    'id' => (int) $school['id'],
                    'school_code' => (string) $school['school_code'],
                    'school_name' => (string) $school['school_name'],
                    'active_students' => $active,
                    'minimum_active_students' => $minimum,
                    'active_school' => $isActiveSchool,
                    'qualified' => true,
                    'payment_required' => !$isActiveSchool,
                ];
            }

            echo json_encode(['success' => true, 'schools' => $schools]);
            exit;
        }

        if ($ajax === 'students') {
            $schoolId = (int) ($_GET['school_id'] ?? 0);
            $q = trim((string) ($_GET['q'] ?? ''));

            if ($schoolId <= 0) {
                throw new RuntimeException('Select a school first.');
            }

            if (mb_strlen($q) < 3) {
                throw new RuntimeException('Enter at least 3 characters of the student name or Student ID.');
            }

            $like = '%' . $q . '%';

            $stmt = $pdo->prepare(
                "SELECT id, student_number, full_name, membership_status
                 FROM students
                 WHERE school_id = ?
                   AND (full_name LIKE ? OR student_number LIKE ?)
                 ORDER BY
                    CASE WHEN membership_status = 'active' THEN 0 ELSE 1 END,
                    full_name
                 LIMIT 30"
            );
            $stmt->execute([$schoolId, $like, $like]);

            $students = [];
            foreach ($stmt->fetchAll() as $student) {
                $students[] = [
                    'id' => (int) $student['id'],
                    'student_number' => (string) $student['student_number'],
                    'full_name' => (string) $student['full_name'],
                    'membership_status' => (string) $student['membership_status'],
                    'active' => $student['membership_status'] === 'active',
                ];
            }

            echo json_encode(['success' => true, 'students' => $students]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Unknown request.']);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage() ?: 'Unable to load records.',
        ]);
    }
    exit;
}

$errors = [];
$postedSchool = null;
$postedParticipants = [];

/*
|--------------------------------------------------------------------------
| Apply / Withdraw
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'apply') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $applicantType = (string) ($_POST['applicant_type'] ?? '');
        $selectedSchoolId = (int) ($_POST['school_id'] ?? 0);
        $studentNumber = strtoupper(trim((string) ($_POST['student_number'] ?? '')));

        $rawStudentIds = $_POST['student_ids'] ?? [];
        if (!is_array($rawStudentIds)) {
            $rawStudentIds = [];
        }

        $selectedStudentIds = array_values(array_unique(array_filter(
            array_map('intval', $rawStudentIds),
            static fn (int $id): bool => $id > 0
        )));

        $eventStmt = $pdo->prepare('SELECT * FROM programme_events WHERE id = ? LIMIT 1');
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch();

        if (!$event) {
            $errors[] = 'Event or competition was not found.';
        } elseif (!pmEventIsOpen($event)) {
            $errors[] = 'Applications for this event/competition are closed.';
        } elseif (!in_array($applicantType, ['school', 'student'], true)) {
            $errors[] = 'Select whether the application is for a school or a student.';
        } elseif ($event['eligibility'] !== 'both' && $event['eligibility'] !== $applicantType) {
            $errors[] = 'This event/competition does not accept this applicant type.';
        }

        $schoolId = null;
        $studentId = null;
        $applicantKey = '';
        $participantRows = [];

        if (!$errors && $applicantType === 'school') {
            if ($selectedSchoolId <= 0) {
                $errors[] = 'Search and select a school.';
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, school_code, school_name FROM schools WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$selectedSchoolId]);
                $school = $stmt->fetch();

                if (!$school) {
                    $errors[] = 'Selected school was not found.';
                } else {
                    $postedSchool = $school;
                    $schoolId = (int) $school['id'];
                    $applicantKey = 'school:' . $schoolId;

                    $eligibility = pmSchoolCompetitionEligibility($pdo, $schoolId);
                }
            }

            $maxStudents = $event ? pmMaxStudentsPerSchool($event) : 1;

            if (!$selectedStudentIds) {
                $errors[] = 'Add at least one active student from the selected school.';
            } elseif (count($selectedStudentIds) > $maxStudents) {
                $errors[] = 'You can select a maximum of ' . $maxStudents . ' student(s) for this event/competition.';
            }

            if ($schoolId && $selectedStudentIds) {
                $placeholders = implode(',', array_fill(0, count($selectedStudentIds), '?'));
                $params = array_merge([$schoolId], $selectedStudentIds);

                $stmt = $pdo->prepare(
                    "SELECT id, student_number, full_name, membership_status
                     FROM students
                     WHERE school_id = ?
                       AND id IN ({$placeholders})
                     ORDER BY full_name"
                );
                $stmt->execute($params);
                $participantRows = $stmt->fetchAll();
                $postedParticipants = $participantRows;

                if (count($participantRows) !== count($selectedStudentIds)) {
                    $errors[] = 'One or more selected students do not belong to the selected school.';
                }

                $inactiveNames = [];
                foreach ($participantRows as $participant) {
                    if ($participant['membership_status'] !== 'active') {
                        $inactiveNames[] = $participant['full_name'] . ' (' . $participant['student_number'] . ')';
                    }
                }

                if ($inactiveNames) {
                    $errors[] = 'Not active and cannot join: ' . implode(', ', $inactiveNames) . '.';
                }
            }
        }

        if (!$errors && $applicantType === 'student') {
            if ($studentNumber === '') {
                $errors[] = 'Student ID / Registration Number is required.';
            } else {
                $stmt = $pdo->prepare(
                    'SELECT
                        st.id,
                        st.student_number,
                        st.full_name,
                        st.school_id,
                        st.membership_status,
                        s.school_code,
                        s.school_name
                     FROM students st
                     JOIN schools s ON s.id = st.school_id
                     WHERE UPPER(st.student_number) = ?
                     LIMIT 1'
                );
                $stmt->execute([$studentNumber]);
                $student = $stmt->fetch();

                if (!$student) {
                    $errors[] = 'Student ID / Registration Number was not found.';
                } elseif ($student['membership_status'] !== 'active') {
                    $errors[] = $student['full_name'] . ' is not active and cannot join this event/competition.';
                } else {
                    $studentId = (int) $student['id'];
                    $schoolId = (int) $student['school_id'];
                    $applicantKey = 'student:' . $studentId;
                    $selectedStudentIds = [$studentId];
                    $participantRows = [$student];
                }
            }
        }

        // Prevent one student from being entered more than once in the same event.
        if (!$errors && $event && $selectedStudentIds) {
            $placeholders = implode(',', array_fill(0, count($selectedStudentIds), '?'));
            $params = array_merge([$eventId], $selectedStudentIds);

            $stmt = $pdo->prepare(
                "SELECT st.student_number, st.full_name
                 FROM programme_application_students pas
                 JOIN programme_applications a ON a.id = pas.application_id
                 JOIN students st ON st.id = pas.student_id
                 WHERE pas.event_id = ?
                   AND pas.student_id IN ({$placeholders})
                   AND a.status <> 'withdrawn'
                 LIMIT 10"
            );
            $stmt->execute($params);
            $duplicates = $stmt->fetchAll();

            if ($duplicates) {
                $names = array_map(
                    static fn (array $row): string => $row['full_name'] . ' (' . $row['student_number'] . ')',
                    $duplicates
                );
                $errors[] = 'Already entered in this event/competition: ' . implode(', ', $names) . '.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $existingStmt = $pdo->prepare(
                    'SELECT * FROM programme_applications
                     WHERE event_id = ? AND applicant_key = ?
                     LIMIT 1 FOR UPDATE'
                );
                $existingStmt->execute([$eventId, $applicantKey]);
                $existing = $existingStmt->fetch();

                $withdrawToken = bin2hex(random_bytes(32));

                if ($applicantType === 'school' && $schoolId) {
                    $applicationFeeMinor = pmSchoolApplicationFeeMinor($pdo, $event, $schoolId);
                } else {
                    $applicationFeeMinor = max(0, (int) $event['entry_fee_minor']);
                }

                $paymentStatus = $applicationFeeMinor > 0 ? 'unpaid' : 'waived';

                if ($existing && $existing['status'] !== 'withdrawn') {
                    throw new RuntimeException('An active application already exists for this event/competition.');
                }

                if ($existing) {
                    $applicationId = (int) $existing['id'];
                    $applicationCode = (string) $existing['application_code'];

                    $stmt = $pdo->prepare(
                        "UPDATE programme_applications
                         SET status = 'applied',
                             school_id = ?,
                             student_id = ?,
                             payment_status = ?,
                             fee_paid_minor = 0,
                             stripe_checkout_session_id = NULL,
                             stripe_payment_intent_id = NULL,
                             paid_at = NULL,
                             withdraw_token = ?,
                             withdrawn_at = NULL,
                             applied_at = NOW(),
                             updated_at = NOW()
                         WHERE id = ?"
                    );
                    $stmt->execute([
                        $schoolId,
                        $studentId,
                        $paymentStatus,
                        $withdrawToken,
                        $applicationId,
                    ]);

                    $deleteParticipants = $pdo->prepare(
                        'DELETE FROM programme_application_students WHERE application_id = ?'
                    );
                    $deleteParticipants->execute([$applicationId]);
                } else {
                    $temporaryCode = 'TMP-' . bin2hex(random_bytes(8));

                    $stmt = $pdo->prepare(
                        'INSERT INTO programme_applications
                         (application_code, event_id, applicant_type, school_id, student_id,
                          applicant_key, status, payment_status, fee_paid_minor, withdraw_token)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
                    );
                    $stmt->execute([
                        $temporaryCode,
                        $eventId,
                        $applicantType,
                        $schoolId,
                        $studentId,
                        $applicantKey,
                        'applied',
                        $paymentStatus,
                        $withdrawToken,
                    ]);

                    $applicationId = (int) $pdo->lastInsertId();
                    $applicationCode = pmCreateApplicationCode($applicationId);

                    $stmt = $pdo->prepare(
                        'UPDATE programme_applications SET application_code = ? WHERE id = ?'
                    );
                    $stmt->execute([$applicationCode, $applicationId]);
                }

                $participantInsert = $pdo->prepare(
                    'INSERT INTO programme_application_students
                     (application_id, event_id, school_id, student_id)
                     VALUES (?, ?, ?, ?)'
                );

                foreach ($selectedStudentIds as $selectedStudentId) {
                    $participantInsert->execute([
                        $applicationId,
                        $eventId,
                        $schoolId,
                        $selectedStudentId,
                    ]);
                }

                $pdo->commit();

                if ($applicationFeeMinor > 0) {
                    redirect(pmProgrammePaymentUrl($applicationCode, $withdrawToken));
                }

                redirect(
                    'events-competitions.php?application=' . rawurlencode($applicationCode)
                    . '&token=' . rawurlencode($withdrawToken)
                    . '&joined=1'
                );
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to submit the application at this time.';
            }
        }
    }

    if ($action === 'withdraw') {
        $applicationCode = trim((string) ($_POST['application_code'] ?? ''));
        $token = trim((string) ($_POST['withdraw_token'] ?? ''));

        $stmt = $pdo->prepare(
            'SELECT a.*, e.application_deadline
             FROM programme_applications a
             JOIN programme_events e ON e.id = a.event_id
             WHERE a.application_code = ?
               AND a.withdraw_token = ?
             LIMIT 1'
        );
        $stmt->execute([$applicationCode, $token]);
        $application = $stmt->fetch();

        if (!$application) {
            $errors[] = 'The application management link is invalid.';
        } elseif ($application['status'] === 'withdrawn') {
            $errors[] = 'This application has already been withdrawn.';
        } elseif (strtotime((string) $application['application_deadline']) < time()) {
            $errors[] = 'The withdrawal deadline has passed. Please contact the organiser.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "UPDATE programme_applications
                     SET status = 'withdrawn',
                         withdrawn_at = NOW(),
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([(int) $application['id']]);

                // Free participant places so those students may be entered again.
                $stmt = $pdo->prepare(
                    'DELETE FROM programme_application_students WHERE application_id = ?'
                );
                $stmt->execute([(int) $application['id']]);

                $pdo->commit();

                redirect(
                    'events-competitions.php?application=' . rawurlencode($applicationCode)
                    . '&token=' . rawurlencode($token)
                    . '&withdrawn=1'
                );
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to withdraw this application.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Application management card
|--------------------------------------------------------------------------
*/
$manageApplication = null;
$manageParticipants = [];
$manageCode = trim((string) ($_GET['application'] ?? ''));
$manageToken = trim((string) ($_GET['token'] ?? ''));

if ($manageCode !== '' && $manageToken !== '') {
    $stmt = $pdo->prepare(
        'SELECT
            a.*,
            e.title,
            e.event_code,
            e.application_deadline,
            e.entry_fee_minor,
            e.currency,
            e.max_students_per_school,
            s.school_name,
            s.school_code,
            st.full_name AS student_name,
            st.student_number
         FROM programme_applications a
         JOIN programme_events e ON e.id = a.event_id
         LEFT JOIN schools s ON s.id = a.school_id
         LEFT JOIN students st ON st.id = a.student_id
         WHERE a.application_code = ?
           AND a.withdraw_token = ?
         LIMIT 1'
    );
    $stmt->execute([$manageCode, $manageToken]);
    $manageApplication = $stmt->fetch() ?: null;

    if ($manageApplication) {
        $manageParticipants = pmApplicationParticipants($pdo, (int) $manageApplication['id']);
    }
}

/*
|--------------------------------------------------------------------------
| Events & Competitions list
|--------------------------------------------------------------------------
*/
$type = (string) ($_GET['type'] ?? '');
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;

$where = ["status IN ('open','closed','completed')"];
$params = [];

if (in_array($type, ['programme', 'tournament'], true)) {
    $where[] = 'event_type = :event_type';
    $params['event_type'] = $type;
}

if ($search !== '') {
    $where[] = '(title LIKE :q OR event_code LIKE :q2 OR venue LIKE :q3)';
    $like = '%' . $search . '%';
    $params['q'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$whereSql = ' WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM programme_events' . $whereSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    'SELECT
        e.*,
        (SELECT COUNT(*) FROM programme_applications a
         WHERE a.event_id = e.id AND a.status <> "withdrawn") AS application_count,
        (SELECT COALESCE(SUM(a.fee_paid_minor), 0) FROM programme_applications a
         WHERE a.event_id = e.id AND a.payment_status = "paid") AS prize_pool_minor,
        (SELECT COUNT(*) FROM programme_applications a
         WHERE a.event_id = e.id AND a.payment_status = "paid") AS paid_application_count
     FROM programme_events e'
    . $whereSql .
    ' ORDER BY
        CASE WHEN e.status = "open" AND e.application_deadline >= NOW() THEN 0 ELSE 1 END,
        e.application_deadline ASC,
        e.created_at DESC
      LIMIT :limit OFFSET :offset'
);

foreach ($params as $name => $value) {
    $listStmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$events = $listStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Apply form event
|--------------------------------------------------------------------------
*/
$applyEvent = null;
$applyPrizeSummary = null;
$applyId = (int) ($_GET['apply'] ?? 0);

if ($applyId <= 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    $applyId = (int) ($_POST['event_id'] ?? 0);
}

if ($applyId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM programme_events WHERE id = ? LIMIT 1');
    $stmt->execute([$applyId]);
    $applyEvent = $stmt->fetch() ?: null;

    if ($applyEvent) {
        $applyPrizeSummary = pmEffectivePrizeSummary($pdo, $applyEvent);
    }
}

/*
|--------------------------------------------------------------------------
| Schools joining Events & Competitions, grouped by event
|--------------------------------------------------------------------------
| Pagination is based on school applications: 10 records per page.
| Both fee-waived Active Schools and paid Non-Active Schools are listed.
*/
$joiningPage = max(1, (int) ($_GET['joining_page'] ?? 1));
$joiningPerPage = 10;

$joiningCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM programme_applications a
     JOIN programme_events e ON e.id = a.event_id
     JOIN schools s ON s.id = a.school_id
     WHERE a.applicant_type = 'school'
       AND a.status IN ('applied','approved')
       AND a.payment_status IN ('paid','waived')"
);
$joiningCountStmt->execute();
$joiningCount = (int) $joiningCountStmt->fetchColumn();

$joiningTotalPages = max(1, (int) ceil($joiningCount / $joiningPerPage));
$joiningPage = min($joiningPage, $joiningTotalPages);
$joiningOffset = ($joiningPage - 1) * $joiningPerPage;

$joiningStmt = $pdo->prepare(
    "SELECT
        a.id,
        a.application_code,
        a.status AS application_status,
        a.payment_status,
        a.fee_paid_minor,
        a.paid_at,
        a.applied_at,
        a.updated_at,

        e.id AS event_id,
        e.event_code,
        e.event_type,
        e.title,
        e.currency,
        e.start_at,
        e.end_at,
        e.application_deadline,

        s.id AS school_id,
        s.school_code,
        s.school_name,

        (
            SELECT COUNT(*)
            FROM students active_student
            WHERE active_student.school_id = s.id
              AND active_student.membership_status = 'active'
        ) AS active_student_count,

        (
            SELECT COUNT(*)
            FROM programme_application_students pas
            WHERE pas.application_id = a.id
        ) AS participant_count

     FROM programme_applications a
     JOIN programme_events e ON e.id = a.event_id
     JOIN schools s ON s.id = a.school_id

     WHERE a.applicant_type = 'school'
       AND a.status IN ('applied','approved')
       AND a.payment_status IN ('paid','waived')

     ORDER BY
        e.start_at IS NULL,
        e.start_at DESC,
        e.id DESC,
        s.school_name ASC

     LIMIT :limit OFFSET :offset"
);
$joiningStmt->bindValue(':limit', $joiningPerPage, PDO::PARAM_INT);
$joiningStmt->bindValue(':offset', $joiningOffset, PDO::PARAM_INT);
$joiningStmt->execute();
$joiningSchools = $joiningStmt->fetchAll();

$pageTitle = 'Events & Competitions';
require 'includes/header.php';

$buildPageUrl = static function (int $targetPage): string {
    $query = $_GET;
    unset($query['apply'], $query['application'], $query['token'], $query['withdrawn'], $query['joined']);
    $query['page'] = $targetPage;
    if ($targetPage <= 1) unset($query['page']);
    return 'events-competitions.php' . ($query ? '?' . http_build_query($query) : '');
};

$buildJoiningPageUrl = static function (int $targetPage): string {
    $query = $_GET;
    unset(
        $query['apply'],
        $query['application'],
        $query['token'],
        $query['withdrawn'],
        $query['joined']
    );

    $query['joining_page'] = $targetPage;

    if ($targetPage <= 1) {
        unset($query['joining_page']);
    }

    $queryString = http_build_query($query);

    return 'events-competitions.php'
        . ($queryString !== '' ? '?' . $queryString : '')
        . '#joiningSchools';
};
?>

<style>
.search-result-button {
    width: 100%;
    text-align: left;
}
.selected-student-chip {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .45rem .7rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    background: var(--bs-light);
    margin: .2rem .25rem .2rem 0;
}
</style>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div>
        <h1 class="h2 mb-1">Events &amp; Competitions</h1>
        <p class="text-secondary mb-0">
            Schools with <?= pmMinimumActiveStudentsForSchool() ?> or &gt; <?= pmMinimumActiveStudentsForSchool() ?> active students are treated as <strong>Active Schools</strong> and join without an entry fee.
            Schools below that level can still join but must pay the Events &amp; Competitions entry fee. Every selected participant must have an active membership.
        </p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($manageApplication): ?>
    <div class="card content-card border-primary mb-4" id="application">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <div class="small text-secondary">Application ID</div>
                    <h2 class="h5 mb-1"><?= e($manageApplication['application_code']) ?></h2>
                    <div class="fw-semibold"><?= e($manageApplication['title']) ?></div>
                    <div class="small text-secondary"><?= e($manageApplication['event_code']) ?></div>
                </div>
                <div class="text-lg-end">
                    <span class="badge text-bg-<?= e(pmApplicationStatusBadge((string) $manageApplication['status'])) ?>">
                        <?= e(ucfirst((string) $manageApplication['status'])) ?>
                    </span>
                    <span class="badge text-bg-<?= e(pmPaymentStatusBadge((string) $manageApplication['payment_status'])) ?>">
                        <?= e(ucfirst((string) $manageApplication['payment_status'])) ?>
                    </span>
                </div>
            </div>

            <?php if (isset($_GET['payment_cancelled'])): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    Payment was not completed. You can continue to payment below.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['joined'])): ?>
                <div class="alert alert-success mt-3 mb-0">Application submitted successfully.</div>
            <?php endif; ?>

            <hr>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="small text-secondary">Applicant</div>
                    <div class="fw-semibold"><?= e(pmApplicationName($manageApplication)) ?></div>
                    <div class="small"><?= e(pmApplicationReference($manageApplication)) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-secondary">Participants</div>
                    <div class="fw-semibold"><?= count($manageParticipants) ?> student(s)</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-secondary">Application Deadline</div>
                    <div><?= e(pmDisplayDateTime($manageApplication['application_deadline'])) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-secondary">Entry Fee</div>
                    <div><?= e(pmMoney((int) $manageApplication['entry_fee_minor'], (string) $manageApplication['currency'])) ?></div>
                </div>
            </div>

            <?php if ($manageParticipants): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>#</th><th>Student ID</th><th>Student</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($manageParticipants as $index => $participant): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= e($participant['student_number']) ?></td>
                                <td><?= e($participant['full_name']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $participant['membership_status'] === 'active' ? 'success' : 'danger' ?>">
                                        <?= e(ucfirst($participant['membership_status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if (
                    $manageApplication['payment_status'] !== 'paid'
                    && (int) $manageApplication['entry_fee_minor'] > 0
                    && $manageApplication['status'] !== 'withdrawn'
                ): ?>
                    <a
                        class="btn btn-success"
                        href="<?= e(pmProgrammePaymentUrl((string) $manageApplication['application_code'], $manageToken)) ?>"
                    >
                        <i class="bi bi-credit-card me-2"></i>
                        Pay <?= e(pmMoney((int) $manageApplication['entry_fee_minor'], (string) $manageApplication['currency'])) ?>
                    </a>
                <?php endif; ?>

                <?php if (
                    $manageApplication['status'] !== 'withdrawn'
                    && strtotime((string) $manageApplication['application_deadline']) >= time()
                ): ?>
                    <form method="post" onsubmit="return confirm('Withdraw this application?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="withdraw">
                        <input type="hidden" name="application_code" value="<?= e($manageApplication['application_code']) ?>">
                        <input type="hidden" name="withdraw_token" value="<?= e($manageToken) ?>">
                        <button class="btn btn-outline-danger" type="submit">
                            <i class="bi bi-x-circle me-2"></i>Withdraw Application
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="alert alert-light border mt-3 mb-0">
                Save this application page or URL. The private token in the URL is required to manage the application.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($applyEvent): ?>
    <div class="card content-card border-success mb-4" id="applyForm">
        <div class="card-header bg-success-subtle border-0 p-4">
            <h2 class="h5 mb-1">Join Event / Competition: <?= e($applyEvent['title']) ?></h2>
            <div class="small text-secondary">
                <?= e($applyEvent['event_code']) ?>
                · Deadline <?= e(pmDisplayDateTime($applyEvent['application_deadline'])) ?>
                <?php if (in_array($applyEvent['eligibility'], ['school','both'], true)): ?>
                    · Maximum <?= pmMaxStudentsPerSchool($applyEvent) ?> student(s) per school
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body p-4">
            <?php if (!pmEventIsOpen($applyEvent)): ?>
                <div class="alert alert-warning mb-0">Applications are closed.</div>
            <?php else: ?>
                <form method="post" class="row g-3" id="programmeApplicationForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="event_id" value="<?= (int) $applyEvent['id'] ?>">

                    <div class="col-md-4">
                        <label class="form-label required">Apply As</label>
                        <select class="form-select" name="applicant_type" id="applicantType" required>
                            <option value="">Select applicant</option>
                            <?php if (in_array($applyEvent['eligibility'], ['school', 'both'], true)): ?>
                                <option value="school" <?= ($_POST['applicant_type'] ?? '') === 'school' ? 'selected' : '' ?>>School</option>
                            <?php endif; ?>
                            <?php if (in_array($applyEvent['eligibility'], ['student', 'both'], true)): ?>
                                <option value="student" <?= ($_POST['applicant_type'] ?? '') === 'student' ? 'selected' : '' ?>>Student</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-12 school-application-fields">
                        <div class="border rounded p-3">
                            <h3 class="h6">1. Select School</h3>
                            <p class="small text-secondary">
                                Search by school name or school code.
                                Schools with <?= pmMinimumActiveStudentsForSchool() ?> or &gt; <?= pmMinimumActiveStudentsForSchool() ?> active students join free.
                                Schools below that level can still join, but payment is required.
                            </p>

                            <input type="hidden" name="school_id" id="selectedSchoolId" value="<?= (int) ($_POST['school_id'] ?? 0) ?>">

                            <div class="input-group">
                                <input
                                    type="search"
                                    class="form-control"
                                    id="schoolSearch"
                                    placeholder="Search school name or code"
                                    autocomplete="off"
                                >
                                <button class="btn btn-outline-primary" type="button" id="schoolSearchButton">Search</button>
                            </div>

                            <div id="schoolSearchResults" class="list-group mt-2"></div>
                            <div id="selectedSchoolSummary" class="mt-3"></div>
                        </div>
                    </div>

                    <div class="col-12 school-application-fields">
                        <div class="border rounded p-3">
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                                <div>
                                    <h3 class="h6 mb-1">2. Add Active Students</h3>
                                    <div class="small text-secondary">
                                        Maximum <?= pmMaxStudentsPerSchool($applyEvent) ?> student(s).
                                        Inactive students will be shown but cannot be added.
                                    </div>
                                </div>
                                <div>
                                    <span class="badge text-bg-primary" id="selectedStudentCount">
                                        0 / <?= pmMaxStudentsPerSchool($applyEvent) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="input-group">
                                <input
                                    type="search"
                                    class="form-control"
                                    id="studentSearch"
                                    placeholder="Enter at least 3 characters of student name or Student ID"
                                    autocomplete="off"
                                    disabled
                                >
                                <button class="btn btn-outline-primary" type="button" id="studentSearchButton" disabled>Search</button>
                            </div>

                            <div id="studentSearchMessage" class="small text-secondary mt-2">
                                Select a school first.
                            </div>
                            <div id="studentSearchResults" class="list-group mt-2"></div>

                            <div class="mt-3">
                                <div class="small fw-semibold mb-1">Selected Students</div>
                                <div id="selectedStudents"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-8 student-application-fields">
                        <label class="form-label required">Student ID / Registration No.</label>
                        <input
                            class="form-control"
                            name="student_number"
                            value="<?= e($_POST['student_number'] ?? '') ?>"
                            placeholder="Student registration number"
                        >
                        <div class="form-text">The student must have an active membership.</div>
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info mb-0">
                            <div>
                                Standard entry fee:
                                <strong><?= e(pmMoney((int) $applyEvent['entry_fee_minor'], (string) $applyEvent['currency'])) ?></strong>.
                                <?php if (in_array($applyEvent['eligibility'], ['school','both'], true)): ?>
                                    Active Schools (<?= pmMinimumActiveStudentsForSchool() ?> or &gt; <?= pmMinimumActiveStudentsForSchool() ?> active students) skip this fee.
                                    Non-Active Schools must pay it.
                                <?php elseif ((int) $applyEvent['entry_fee_minor'] > 0): ?>
                                    After applying, you will be redirected directly to Stripe Checkout.
                                <?php else: ?>
                                    No application fee is required.
                                <?php endif; ?>
                            </div>

                            <?php if ($applyPrizeSummary): ?>
                                <hr class="my-2">
                                <div>
                                    Prize Pool:
                                    <strong><?= e(pmMoney((int) $applyPrizeSummary['pool_minor'], (string) $applyEvent['currency'])) ?></strong>
                                    <span class="badge text-bg-light border ms-1"><?= e(pmPrizeDistributionModeLabel($applyEvent)) ?></span>
                                </div>
                                <div class="small mt-1">
                                    1st <?= e(pmMoney((int) $applyPrizeSummary['first_minor'], (string) $applyEvent['currency'])) ?>
                                    · 2nd <?= e(pmMoney((int) $applyPrizeSummary['second_minor'], (string) $applyEvent['currency'])) ?>
                                    · 3rd <?= e(pmMoney((int) $applyPrizeSummary['third_minor'], (string) $applyEvent['currency'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12">
                        <button class="btn btn-success" type="submit">
                            <i class="bi bi-credit-card me-2"></i>
                            Apply / Join
                        </button>
                        <a href="events-competitions.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card content-card mb-4">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Event / competition name, ID or venue">
            </div>
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select class="form-select" name="type">
                    <option value="">All Events &amp; Competitions</option>
                    <option value="programme" <?= $type === 'programme' ? 'selected' : '' ?>>Programme</option>
                    <option value="tournament" <?= $type === 'tournament' ? 'selected' : '' ?>>Tournament</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary"><i class="bi bi-search me-2"></i>Search</button>
            </div>
            <div class="col-md-1 d-grid">
                <a href="events-competitions.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <?php if (!$events): ?>
        <div class="col-12">
            <div class="card content-card"><div class="card-body text-center text-secondary py-5">No Events &amp; Competitions found.</div></div>
        </div>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <?php
                $eventPrizeSummary = pmEffectivePrizeSummaryFromPool(
                    $event,
                    (int) $event['prize_pool_minor'],
                    (int) ($event['paid_application_count'] ?? 0)
                );
            ?>
            <div class="col-lg-6">
                <div class="card content-card h-100">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex justify-content-between gap-3 mb-2">
                            <div>
                                <span class="badge text-bg-<?= $event['event_type'] === 'tournament' ? 'warning' : 'primary' ?>">
                                    <?= e(ucfirst((string) $event['event_type'])) ?>
                                </span>
                                <span class="badge text-bg-<?= e(pmEventStatusBadge($event)) ?>">
                                    <?= e(pmEventStatusLabel($event)) ?>
                                </span>
                            </div>
                            <code><?= e($event['event_code']) ?></code>
                        </div>

                        <h2 class="h5"><?= e($event['title']) ?></h2>
                        <p class="text-secondary flex-grow-1"><?= nl2br(e($event['description'] ?: 'No description provided.')) ?></p>

                        <div class="row g-3 small mb-3">
                            <div class="col-6">
                                <div class="text-secondary">Application Deadline</div>
                                <div class="fw-semibold"><?= e(pmDisplayDateTime($event['application_deadline'])) ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-secondary">Eligibility</div>
                                <div class="fw-semibold"><?= e(pmEligibilityLabel((string) $event['eligibility'])) ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-secondary">Entry Fee</div>
                                <div class="fw-semibold"><?= e(pmMoney((int) $event['entry_fee_minor'], (string) $event['currency'])) ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-secondary">Current Prize Pool</div>
                                <div class="fw-semibold"><?= e(pmMoney((int) $eventPrizeSummary['pool_minor'], (string) $event['currency'])) ?></div>
                                <div class="small text-secondary"><?= e(pmPrizeDistributionModeLabel($event)) ?></div>
                            </div>

                            <?php if (in_array($event['eligibility'], ['school','both'], true)): ?>
                                <div class="col-6">
                                    <div class="text-secondary">Active School</div>
                                    <div class="fw-semibold"><?= pmMinimumActiveStudentsForSchool() ?> or &gt; <?= pmMinimumActiveStudentsForSchool() ?> active students = free entry</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-secondary">Max Students / School</div>
                                    <div class="fw-semibold"><?= pmMaxStudentsPerSchool($event) ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="col-6">
                                <div class="text-secondary">Applications</div>
                                <div class="fw-semibold"><?= (int) $event['application_count'] ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-secondary">Event Dates</div>
                                <div class="fw-semibold"><?= e(pmDisplayDateRange($event['start_at'], $event['end_at'])) ?></div>
                            </div>

                            <div class="col-12">
                                <div class="border rounded p-2 bg-light">
                                    <div class="text-secondary mb-1">Prize Distribution</div>
                                    <div class="d-flex flex-wrap gap-3">
                                        <span><strong>1st:</strong> <?= e(pmMoney((int) $eventPrizeSummary['first_minor'], (string) $event['currency'])) ?></span>
                                        <span><strong>2nd:</strong> <?= e(pmMoney((int) $eventPrizeSummary['second_minor'], (string) $event['currency'])) ?></span>
                                        <span><strong>3rd:</strong> <?= e(pmMoney((int) $eventPrizeSummary['third_minor'], (string) $event['currency'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (pmEventIsOpen($event)): ?>
                            <a class="btn btn-primary mt-auto" href="events-competitions.php?apply=<?= (int) $event['id'] ?>#applyForm">
                                <i class="bi bi-send me-2"></i>Apply / Join
                            </a>
                        <?php else: ?>
                            <button class="btn btn-outline-secondary mt-auto" disabled>Applications Closed</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
    <div class="small text-secondary">
        Showing <?= $totalRecords > 0 ? number_format($offset + 1) : 0 ?>–<?= number_format(min($offset + $perPage, $totalRecords)) ?>
        of <?= number_format($totalRecords) ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Events and Competitions pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($buildPageUrl(max(1, $page - 1))) ?>">Previous</a>
                </li>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e($buildPageUrl($p)) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($buildPageUrl(min($totalPages, $page + 1))) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<div class="card content-card mt-5" id="joiningSchools">
    <div class="card-header bg-light border-0 p-4">
        <h2 class="h4 mb-1">Schools Joining Events &amp; Competitions</h2>
        <p class="text-secondary mb-0">
            Schools are grouped by Event / Competition. Active Schools with waived fees
            and Non-Active Schools that have completed payment are included.
        </p>
    </div>

    <div class="card-body p-4">
        <?php if (!$joiningSchools): ?>
            <div class="text-center text-secondary py-5">
                No school has joined an Event / Competition yet.
            </div>
        <?php else: ?>
            <?php
                $currentJoiningEventId = null;
                $joiningRowNumber = $joiningOffset;
            ?>

            <?php foreach ($joiningSchools as $joiningSchool): ?>
                <?php if ($currentJoiningEventId !== (int) $joiningSchool['event_id']): ?>
                    <?php
                        $currentJoiningEventId = (int) $joiningSchool['event_id'];
                        $eventTypeLabel = $joiningSchool['event_type'] === 'tournament'
                            ? 'Competition'
                            : 'Event';
                    ?>

                    <?php if ($joiningRowNumber > $joiningOffset): ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="border rounded mb-4 overflow-hidden">
                        <div class="bg-light border-bottom px-3 py-3">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="badge text-bg-<?= $joiningSchool['event_type'] === 'tournament' ? 'warning' : 'primary' ?>">
                                            <?= e($eventTypeLabel) ?>
                                        </span>
                                        <code><?= e($joiningSchool['event_code']) ?></code>
                                    </div>
                                    <h3 class="h6 mt-2 mb-0"><?= e($joiningSchool['title']) ?></h3>
                                </div>

                                <div class="small text-secondary text-lg-end">
                                    <?= e(pmDisplayDateRange($joiningSchool['start_at'], $joiningSchool['end_at'])) ?>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:60px">#</th>
                                        <th>School</th>
                                        <th class="text-center">Active Students</th>
                                        <th class="text-center">Participants</th>
                                        <th>Application</th>
                                        <th>Fee Status</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                <?php endif; ?>

                <?php $joiningRowNumber++; ?>
                <tr>
                    <td><?= $joiningRowNumber ?></td>

                    <td>
                        <div class="fw-semibold"><?= e($joiningSchool['school_name']) ?></div>
                        <small class="text-secondary"><?= e($joiningSchool['school_code']) ?></small>
                    </td>

                    <td class="text-center">
                        <?= (int) $joiningSchool['active_student_count'] ?>
                    </td>

                    <td class="text-center">
                        <?= (int) $joiningSchool['participant_count'] ?>
                    </td>

                    <td>
                        <code><?= e($joiningSchool['application_code']) ?></code>
                        <div class="small mt-1">
                            <span class="badge text-bg-<?= e(pmApplicationStatusBadge((string) $joiningSchool['application_status'])) ?>">
                                <?= e(ucfirst((string) $joiningSchool['application_status'])) ?>
                            </span>
                        </div>
                    </td>

                    <td>
                        <?php if ($joiningSchool['payment_status'] === 'waived'): ?>
                            <span class="badge text-bg-success">Active School — Fee Waived</span>
                        <?php else: ?>
                            <span class="badge text-bg-success">Paid</span>
                            <div class="small mt-1">
                                <?= e(pmMoney((int) $joiningSchool['fee_paid_minor'], (string) $joiningSchool['currency'])) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= e(pmDisplayDateTime(
                            $joiningSchool['payment_status'] === 'paid'
                                ? ($joiningSchool['paid_at'] ?: $joiningSchool['updated_at'])
                                : $joiningSchool['applied_at']
                        )) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
            <div class="small text-secondary">
                Showing
                <?= $joiningCount > 0 ? number_format($joiningOffset + 1) : 0 ?>
                –
                <?= number_format(min($joiningOffset + $joiningPerPage, $joiningCount)) ?>
                of <?= number_format($joiningCount) ?> school application(s)
            </div>

            <?php if ($joiningTotalPages > 1): ?>
                <nav aria-label="Schools joining Events and Competitions pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $joiningPage <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e($buildJoiningPageUrl(max(1, $joiningPage - 1))) ?>"
                            >
                                Previous
                            </a>
                        </li>

                        <?php for (
                            $p = max(1, $joiningPage - 2);
                            $p <= min($joiningTotalPages, $joiningPage + 2);
                            $p++
                        ): ?>
                            <li class="page-item <?= $p === $joiningPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($buildJoiningPageUrl($p)) ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $joiningPage >= $joiningTotalPages ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= e($buildJoiningPageUrl(min($joiningTotalPages, $joiningPage + 1))) ?>"
                            >
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const applicantType = document.getElementById('applicantType');
    const schoolFields = document.querySelectorAll('.school-application-fields');
    const studentFields = document.querySelectorAll('.student-application-fields');

    const schoolSearch = document.getElementById('schoolSearch');
    const schoolSearchButton = document.getElementById('schoolSearchButton');
    const schoolResults = document.getElementById('schoolSearchResults');
    const selectedSchoolId = document.getElementById('selectedSchoolId');
    const selectedSchoolSummary = document.getElementById('selectedSchoolSummary');

    const studentSearch = document.getElementById('studentSearch');
    const studentSearchButton = document.getElementById('studentSearchButton');
    const studentResults = document.getElementById('studentSearchResults');
    const studentMessage = document.getElementById('studentSearchMessage');
    const selectedStudentsContainer = document.getElementById('selectedStudents');
    const selectedStudentCount = document.getElementById('selectedStudentCount');

    const maxStudents = <?= $applyEvent ? pmMaxStudentsPerSchool($applyEvent) : 1 ?>;
    const selectedStudents = new Map();

    const syncApplicantFields = () => {
        const type = applicantType?.value || '';
        schoolFields.forEach(el => el.classList.toggle('d-none', type !== 'school'));
        studentFields.forEach(el => el.classList.toggle('d-none', type !== 'student'));
    };

    const renderSelectedStudents = () => {
        if (!selectedStudentsContainer) return;

        selectedStudentsContainer.innerHTML = '';

        selectedStudents.forEach(student => {
            const chip = document.createElement('span');
            chip.className = 'selected-student-chip';

            const label = document.createElement('span');
            label.textContent = `${student.student_number} — ${student.full_name}`;

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'student_ids[]';
            hidden.value = student.id;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-link text-danger p-0';
            remove.innerHTML = '<i class="bi bi-x-circle"></i>';
            remove.addEventListener('click', () => {
                selectedStudents.delete(student.id);
                renderSelectedStudents();
            });

            chip.appendChild(label);
            chip.appendChild(hidden);
            chip.appendChild(remove);
            selectedStudentsContainer.appendChild(chip);
        });

        if (selectedStudentCount) {
            selectedStudentCount.textContent = `${selectedStudents.size} / ${maxStudents}`;
            selectedStudentCount.className = selectedStudents.size > maxStudents
                ? 'badge text-bg-danger'
                : 'badge text-bg-primary';
        }
    };

    const selectSchool = school => {
        selectedSchoolId.value = school.id;
        selectedStudents.clear();
        renderSelectedStudents();

        if (school.active_school) {
            selectedSchoolSummary.innerHTML =
                `<div class="alert alert-success mb-0">
                    <strong>${escapeHtml(school.school_name)}</strong>
                    (${escapeHtml(school.school_code)}) selected.
                    Active students: <strong>${school.active_students}</strong>.
                    <span class="badge text-bg-success ms-1">Active School — No Entry Fee</span>
                </div>`;
        } else {
            selectedSchoolSummary.innerHTML =
                `<div class="alert alert-warning mb-0">
                    <strong>${escapeHtml(school.school_name)}</strong>
                    (${escapeHtml(school.school_code)}) selected.
                    Active students: <strong>${school.active_students}</strong>.
                    <span class="badge text-bg-warning ms-1">Non-Active School — Payment Required</span>
                    <div class="small mt-1">
                        Active School status requires at least <strong>${school.minimum_active_students}</strong> active students.
                    </div>
                </div>`;
        }

        schoolResults.innerHTML = '';
        studentSearch.disabled = false;
        studentSearchButton.disabled = false;
        studentMessage.textContent = `Search active students from ${school.school_name}.`;
        studentSearch.focus();
    };

    const escapeHtml = value => {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    };

    const searchSchools = async () => {
        if (!schoolSearch || !schoolResults) return;

        const q = schoolSearch.value.trim();
        schoolResults.innerHTML = '<div class="list-group-item text-secondary">Searching...</div>';

        try {
            const response = await fetch(`events-competitions.php?ajax=schools&q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to search schools.');
            }

            schoolResults.innerHTML = '';

            if (!data.schools.length) {
                schoolResults.innerHTML = '<div class="list-group-item text-secondary">No schools found.</div>';
                return;
            }

            data.schools.forEach(school => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = `list-group-item list-group-item-action search-result-button ${school.qualified ? '' : 'list-group-item-light'}`;

                const qualification = school.active_school
                    ? `<span class="badge text-bg-success">Active — Free Entry</span>`
                    : `<span class="badge text-bg-warning">Non-Active — Payment Required</span>`;

                item.innerHTML =
                    `<div class="d-flex justify-content-between gap-3">
                        <div>
                            <div class="fw-semibold">${escapeHtml(school.school_name)}</div>
                            <div class="small text-secondary">${escapeHtml(school.school_code)}</div>
                        </div>
                        <div class="text-end">
                            ${qualification}
                            <div class="small mt-1">
                                ${school.active_students} active student(s)
                                ${school.active_school ? '— no entry fee' : `— active status starts at ${school.minimum_active_students}`}
                            </div>
                        </div>
                    </div>`;

                item.addEventListener('click', () => selectSchool(school));
                schoolResults.appendChild(item);
            });
        } catch (error) {
            schoolResults.innerHTML =
                `<div class="list-group-item text-danger">${escapeHtml(error.message)}</div>`;
        }
    };

    const searchStudents = async () => {
        if (!studentSearch || !studentResults || !selectedSchoolId?.value) return;

        const q = studentSearch.value.trim();
        studentResults.innerHTML = '<div class="list-group-item text-secondary">Searching...</div>';

        try {
            const response = await fetch(
                `events-competitions.php?ajax=students&school_id=${encodeURIComponent(selectedSchoolId.value)}&q=${encodeURIComponent(q)}`,
                { headers: { Accept: 'application/json' }, cache: 'no-store' }
            );
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to search students.');
            }

            studentResults.innerHTML = '';

            if (!data.students.length) {
                studentResults.innerHTML = '<div class="list-group-item text-secondary">No students found.</div>';
                return;
            }

            data.students.forEach(student => {
                const item = document.createElement('div');
                item.className = 'list-group-item';

                const status = student.active
                    ? '<span class="badge text-bg-success">Active</span>'
                    : `<span class="badge text-bg-danger">Not Active</span>`;

                const addDisabled = !student.active || selectedStudents.has(student.id) || selectedStudents.size >= maxStudents;

                item.innerHTML =
                    `<div class="d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold">${escapeHtml(student.full_name)}</div>
                            <div class="small text-secondary">${escapeHtml(student.student_number)} · ${status}</div>
                        </div>
                        <button type="button" class="btn btn-sm ${student.active ? 'btn-outline-primary' : 'btn-outline-danger'}" ${addDisabled ? 'disabled' : ''}>
                            ${student.active ? (selectedStudents.has(student.id) ? 'Added' : 'Add') : 'Not Active'}
                        </button>
                    </div>`;

                const button = item.querySelector('button');
                if (student.active && button) {
                    button.addEventListener('click', () => {
                        if (selectedStudents.size >= maxStudents) {
                            studentMessage.textContent = `Maximum ${maxStudents} student(s) allowed.`;
                            studentMessage.className = 'small text-danger mt-2';
                            return;
                        }

                        selectedStudents.set(student.id, student);
                        studentMessage.textContent = 'Student added.';
                        studentMessage.className = 'small text-success mt-2';
                        renderSelectedStudents();
                        searchStudents();
                    });
                } else if (!student.active) {
                    item.title = 'This student is not active and cannot join.';
                }

                studentResults.appendChild(item);
            });
        } catch (error) {
            studentResults.innerHTML =
                `<div class="list-group-item text-danger">${escapeHtml(error.message)}</div>`;
        }
    };

    applicantType?.addEventListener('change', syncApplicantFields);
    schoolSearchButton?.addEventListener('click', searchSchools);
    studentSearchButton?.addEventListener('click', searchStudents);

    schoolSearch?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchSchools();
        }
    });

    studentSearch?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchStudents();
        }
    });

    syncApplicantFields();
    renderSelectedStudents();

    <?php if ($postedSchool): ?>
    selectSchool({
        id: <?= (int) $postedSchool['id'] ?>,
        school_code: <?= json_encode((string) $postedSchool['school_code']) ?>,
        school_name: <?= json_encode((string) $postedSchool['school_name']) ?>,
        active_students: <?= pmActiveStudentCount($pdo, (int) $postedSchool['id']) ?>,
        minimum_active_students: <?= pmMinimumActiveStudentsForSchool() ?>,
        active_school: <?= pmActiveStudentCount($pdo, (int) $postedSchool['id']) >= pmMinimumActiveStudentsForSchool() ? 'true' : 'false' ?>,
        qualified: true,
        payment_required: <?= pmActiveStudentCount($pdo, (int) $postedSchool['id']) >= pmMinimumActiveStudentsForSchool() ? 'false' : 'true' ?>
    });

    <?php foreach ($postedParticipants as $participant): ?>
    selectedStudents.set(<?= (int) $participant['id'] ?>, {
        id: <?= (int) $participant['id'] ?>,
        student_number: <?= json_encode((string) $participant['student_number']) ?>,
        full_name: <?= json_encode((string) $participant['full_name']) ?>,
        membership_status: <?= json_encode((string) $participant['membership_status']) ?>,
        active: <?= $participant['membership_status'] === 'active' ? 'true' : 'false' ?>
    });
    <?php endforeach; ?>
    renderSelectedStudents();
    <?php endif; ?>
});
</script>

<?php require 'includes/footer.php'; ?>
