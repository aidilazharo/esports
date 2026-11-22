<?php
require_once 'config.php';
require_once __DIR__ . '/events-competitions-helpers.php';

if (!pmAdminAuthenticated()) {
    redirect('admin.php');
}

if (!pmSchemaReady($pdo)) {
    $pageTitle = 'Events & Competitions Manager';
    require 'includes/header.php';
    ?>
    <div class="alert alert-warning">
        <h1 class="h4">Events &amp; Competitions database setup required</h1>
        <p>Run <code>programme_schema.sql</code> once before using Events &amp; Competitions management.</p>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

$manualPrizeColumnsReady =
    pmColumnExists($pdo, 'programme_events', 'prize_distribution_mode')
    && pmColumnExists($pdo, 'programme_events', 'manual_first_prize_minor')
    && pmColumnExists($pdo, 'programme_events', 'manual_second_prize_minor')
    && pmColumnExists($pdo, 'programme_events', 'manual_third_prize_minor');

$programmeParticipationReady = pmProgrammeParticipationSchemaReady($pdo);

if (!$programmeParticipationReady) {
    $pageTitle = 'Events & Competitions Manager';
    require 'includes/header.php';
    ?>
    <div class="alert alert-warning">
        <h1 class="h4">Events &amp; Competitions upgrade required</h1>
        <p class="mb-2">
            Run <code>upgrade_programme_school_competition.sql</code> once before using
            school status, participant selection and Events &amp; Competitions payment features.
        </p>
        <a href="admin.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

$errors = [];
$tab = (string) ($_GET['tab'] ?? 'events');
if (!in_array($tab, ['events', 'applications', 'report'], true)) {
    $tab = 'events';
}

function pmAdminEvent(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM programme_events WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Return the effective prize distribution for an event.
 *
 * automatic = prize pool from application fees marked Paid, distributed by percentages.
 * manual    = 1st/2nd/3rd prize amounts entered by the administrator.
 *
 * fee_pool_minor always shows how much has actually been collected from paid applications.
 */
function pmAdminEffectivePrizeSummary(PDO $pdo, array $event): array
{
    $automatic = pmPrizeSummary($pdo, $event);
    $automatic['fee_pool_minor'] = (int) ($automatic['pool_minor'] ?? 0);
    $automatic['distribution_mode'] = 'automatic';

    if (($event['prize_distribution_mode'] ?? 'automatic') !== 'manual') {
        return $automatic;
    }

    $first = max(0, (int) ($event['manual_first_prize_minor'] ?? 0));
    $second = max(0, (int) ($event['manual_second_prize_minor'] ?? 0));
    $third = max(0, (int) ($event['manual_third_prize_minor'] ?? 0));

    return [
        'pool_minor' => $first + $second + $third,
        'fee_pool_minor' => (int) ($automatic['pool_minor'] ?? 0),
        'first_minor' => $first,
        'second_minor' => $second,
        'third_minor' => $third,
        'paid_applications' => (int) ($automatic['paid_applications'] ?? 0),
        'distribution_mode' => 'manual',
    ];
}

function pmAdminApplicationRows(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.*,
                s.school_name, s.school_code,
                st.full_name AS student_name, st.student_number,
                (SELECT COUNT(*) FROM programme_application_students pas WHERE pas.application_id = a.id) AS participant_count
         FROM programme_applications a
         LEFT JOIN schools s ON s.id = a.school_id
         LEFT JOIN students st ON st.id = a.student_id
         WHERE a.event_id = ?
           AND a.status IN ('applied','approved')
         ORDER BY
           CASE a.status WHEN 'approved' THEN 0 ELSE 1 END,
           COALESCE(st.full_name, s.school_name)"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if (in_array($action, ['create_event', 'update_event'], true)) {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $eventType = (string) ($_POST['event_type'] ?? 'programme');
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $eligibility = (string) ($_POST['eligibility'] ?? 'both');
        $maxStudentsPerSchool = max(0, (int) ($_POST['max_students_per_school'] ?? 0));
        $deadline = pmDbDateTime((string) ($_POST['application_deadline'] ?? ''));
        $startAt = pmDbDateTime((string) ($_POST['start_at'] ?? ''));
        $endAt = pmDbDateTime((string) ($_POST['end_at'] ?? ''));
        $deliveryMode = trim((string) ($_POST['delivery_mode'] ?? ''));
        $eventLevel = trim((string) ($_POST['event_level'] ?? ''));
        $organiser = trim((string) ($_POST['organiser'] ?? 'Metadata Berhad'));
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $entryFeeMinor = pmAmountToMinor((string) ($_POST['entry_fee_rm'] ?? '0'));
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'MYR')));
        $prizeDistributionMode = (string) ($_POST['prize_distribution_mode'] ?? 'automatic');
        $p1 = (float) ($_POST['first_prize_percent'] ?? 50);
        $p2 = (float) ($_POST['second_prize_percent'] ?? 30);
        $p3 = (float) ($_POST['third_prize_percent'] ?? 20);
        $manualFirstPrizeMinor = pmAmountToMinor((string) ($_POST['manual_first_prize_rm'] ?? '0'));
        $manualSecondPrizeMinor = pmAmountToMinor((string) ($_POST['manual_second_prize_rm'] ?? '0'));
        $manualThirdPrizeMinor = pmAmountToMinor((string) ($_POST['manual_third_prize_rm'] ?? '0'));
        $status = (string) ($_POST['status'] ?? 'draft');

        $reportSchedule = trim((string) ($_POST['report_schedule'] ?? ''));
        $reportExpenditure = trim((string) ($_POST['report_expenditure'] ?? ''));
        $reportVip = trim((string) ($_POST['report_vip'] ?? ''));
        $reportIssues = trim((string) ($_POST['report_issues'] ?? ''));
        $reportImprovements = trim((string) ($_POST['report_improvements'] ?? ''));

        if (!in_array($eventType, ['programme', 'tournament'], true)) $errors[] = 'Event type is invalid.';
        if ($title === '') $errors[] = 'Event / competition title is required.';
        if (!in_array($eligibility, ['school', 'student', 'both'], true)) $errors[] = 'Eligibility is invalid.';
        if (in_array($eligibility, ['school', 'both'], true) && $maxStudentsPerSchool < 1) {
            $errors[] = 'Maximum students per school must be at least 1 for school applications.';
        }
        if (!$deadline) $errors[] = 'Application deadline is required.';
        if (!in_array($status, ['draft', 'open', 'closed', 'completed', 'cancelled'], true)) $errors[] = 'Status is invalid.';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = 'Currency must be a 3-letter code.';
        if (!in_array($prizeDistributionMode, ['automatic', 'manual'], true)) {
            $errors[] = 'Prize distribution mode is invalid.';
        }
        if ($prizeDistributionMode === 'automatic'
            && ($p1 < 0 || $p2 < 0 || $p3 < 0 || abs(($p1 + $p2 + $p3) - 100.0) > 0.01)) {
            $errors[] = 'For automatic distribution, 1st, 2nd and 3rd prize percentages must total 100%.';
        }
        if ($prizeDistributionMode === 'manual' && !$manualPrizeColumnsReady) {
            $errors[] = 'Run add_manual_prize_distribution.sql before using manual prize distribution.';
        }
        if ($startAt && $endAt && strtotime($endAt) < strtotime($startAt)) {
            $errors[] = 'End date/time cannot be earlier than start date/time.';
        }

        if (!$errors) {
            try {
                if ($action === 'create_event') {
                    $pdo->beginTransaction();

                    $tempCode = 'TMP-' . bin2hex(random_bytes(8));
                    if ($manualPrizeColumnsReady) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO programme_events
                             (event_code, event_type, title, description, eligibility, max_students_per_school,
                              application_deadline, start_at, end_at, delivery_mode, event_level,
                              organiser, venue, entry_fee_minor, currency,
                              prize_distribution_mode,
                              first_prize_percent, second_prize_percent, third_prize_percent,
                              manual_first_prize_minor, manual_second_prize_minor, manual_third_prize_minor,
                              report_schedule, report_expenditure, report_vip, report_issues, report_improvements,
                              status)
                             VALUES
                             (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $tempCode, $eventType, $title, $description ?: null, $eligibility, $maxStudentsPerSchool,
                            $deadline, $startAt, $endAt, $deliveryMode ?: null, $eventLevel ?: null,
                            $organiser ?: 'Metadata Berhad', $venue ?: null, $entryFeeMinor, $currency,
                            $prizeDistributionMode,
                            $p1, $p2, $p3,
                            $manualFirstPrizeMinor, $manualSecondPrizeMinor, $manualThirdPrizeMinor,
                            $reportSchedule ?: null, $reportExpenditure ?: null, $reportVip ?: null,
                            $reportIssues ?: null, $reportImprovements ?: null,
                            $status,
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO programme_events
                             (event_code, event_type, title, description, eligibility, max_students_per_school,
                              application_deadline, start_at, end_at, delivery_mode, event_level,
                              organiser, venue, entry_fee_minor, currency,
                              first_prize_percent, second_prize_percent, third_prize_percent,
                              report_schedule, report_expenditure, report_vip, report_issues, report_improvements,
                              status)
                             VALUES
                             (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $tempCode, $eventType, $title, $description ?: null, $eligibility, $maxStudentsPerSchool,
                            $deadline, $startAt, $endAt, $deliveryMode ?: null, $eventLevel ?: null,
                            $organiser ?: 'Metadata Berhad', $venue ?: null, $entryFeeMinor, $currency,
                            $p1, $p2, $p3,
                            $reportSchedule ?: null, $reportExpenditure ?: null, $reportVip ?: null,
                            $reportIssues ?: null, $reportImprovements ?: null,
                            $status,
                        ]);
                    }

                    $eventId = (int) $pdo->lastInsertId();
                    $eventCode = pmCreateEventCode($eventType, $eventId);

                    $stmt = $pdo->prepare('UPDATE programme_events SET event_code = ? WHERE id = ?');
                    $stmt->execute([$eventCode, $eventId]);

                    $pdo->commit();
                    setFlash('success', $eventCode . ' created successfully.');
                } else {
                    if ($manualPrizeColumnsReady) {
                        $stmt = $pdo->prepare(
                            'UPDATE programme_events SET
                                event_type = ?, title = ?, description = ?, eligibility = ?, max_students_per_school = ?,
                                application_deadline = ?, start_at = ?, end_at = ?,
                                delivery_mode = ?, event_level = ?, organiser = ?, venue = ?,
                                entry_fee_minor = ?, currency = ?,
                                prize_distribution_mode = ?,
                                first_prize_percent = ?, second_prize_percent = ?, third_prize_percent = ?,
                                manual_first_prize_minor = ?, manual_second_prize_minor = ?, manual_third_prize_minor = ?,
                                report_schedule = ?, report_expenditure = ?, report_vip = ?,
                                report_issues = ?, report_improvements = ?, status = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $eventType, $title, $description ?: null, $eligibility, $maxStudentsPerSchool,
                            $deadline, $startAt, $endAt,
                            $deliveryMode ?: null, $eventLevel ?: null, $organiser ?: 'Metadata Berhad', $venue ?: null,
                            $entryFeeMinor, $currency,
                            $prizeDistributionMode,
                            $p1, $p2, $p3,
                            $manualFirstPrizeMinor, $manualSecondPrizeMinor, $manualThirdPrizeMinor,
                            $reportSchedule ?: null, $reportExpenditure ?: null, $reportVip ?: null,
                            $reportIssues ?: null, $reportImprovements ?: null, $status,
                            $eventId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE programme_events SET
                                event_type = ?, title = ?, description = ?, eligibility = ?, max_students_per_school = ?,
                                application_deadline = ?, start_at = ?, end_at = ?,
                                delivery_mode = ?, event_level = ?, organiser = ?, venue = ?,
                                entry_fee_minor = ?, currency = ?,
                                first_prize_percent = ?, second_prize_percent = ?, third_prize_percent = ?,
                                report_schedule = ?, report_expenditure = ?, report_vip = ?,
                                report_issues = ?, report_improvements = ?, status = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $eventType, $title, $description ?: null, $eligibility, $maxStudentsPerSchool,
                            $deadline, $startAt, $endAt,
                            $deliveryMode ?: null, $eventLevel ?: null, $organiser ?: 'Metadata Berhad', $venue ?: null,
                            $entryFeeMinor, $currency, $p1, $p2, $p3,
                            $reportSchedule ?: null, $reportExpenditure ?: null, $reportVip ?: null,
                            $reportIssues ?: null, $reportImprovements ?: null, $status,
                            $eventId,
                        ]);
                    }

                    setFlash('success', 'Event / competition updated successfully.');
                }

                redirect('events-competitions-report.php?tab=events');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to save event/competition: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_event') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        try {
            $stmt = $pdo->prepare('DELETE FROM programme_events WHERE id = ?');
            $stmt->execute([$eventId]);
            setFlash('success', 'Event / competition deleted successfully.');
            redirect('events-competitions-report.php?tab=events');
        } catch (Throwable $e) {
            $errors[] = 'Unable to delete event/competition.';
        }
    }

    if ($action === 'application_status') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $status = (string) ($_POST['application_status'] ?? '');

        if (!in_array($status, ['applied', 'approved', 'rejected', 'withdrawn'], true)) {
            $errors[] = 'Invalid application status.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE programme_applications
                 SET status = ?, withdrawn_at = CASE WHEN ? = "withdrawn" THEN NOW() ELSE NULL END
                 WHERE id = ? AND event_id = ?'
            );
            $stmt->execute([$status, $status, $applicationId, $eventId]);

            if (in_array($status, ['withdrawn', 'rejected'], true)) {
                $participantStmt = $pdo->prepare(
                    'DELETE FROM programme_application_students WHERE application_id = ?'
                );
                $participantStmt->execute([$applicationId]);
            }

            setFlash('success', 'Application status updated.');
            redirect('events-competitions-report.php?tab=applications&event_id=' . $eventId);
        }
    }

    if ($action === 'application_payment') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $paymentStatus = (string) ($_POST['payment_status'] ?? '');
        $feePaidMinor = pmAmountToMinor((string) ($_POST['fee_paid_rm'] ?? '0'));

        if (!in_array($paymentStatus, ['unpaid', 'paid', 'refunded', 'waived'], true)) {
            $errors[] = 'Invalid payment status.';
        } else {
            if ($paymentStatus !== 'paid') {
                $feePaidMinor = 0;
            }

            $stmt = $pdo->prepare(
                'UPDATE programme_applications
                 SET payment_status = ?,
                     fee_paid_minor = ?,
                     paid_at = CASE
                        WHEN ? = "paid" THEN COALESCE(paid_at, NOW())
                        ELSE NULL
                     END
                 WHERE id = ? AND event_id = ?'
            );
            $stmt->execute([
                $paymentStatus,
                $feePaidMinor,
                $paymentStatus,
                $applicationId,
                $eventId,
            ]);

            setFlash('success', 'Application fee/payment status updated.');
            redirect('events-competitions-report.php?tab=applications&event_id=' . $eventId);
        }
    }

    if ($action === 'set_winner') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $place = (int) ($_POST['place'] ?? 0);

        $event = pmAdminEvent($pdo, $eventId);
        if (!$event || !in_array($place, [1, 2, 3], true)) {
            $errors[] = 'Winner information is invalid.';
        } else {
            $validStmt = $pdo->prepare(
                "SELECT 1 FROM programme_applications
                 WHERE id = ? AND event_id = ? AND status IN ('applied','approved')
                 LIMIT 1"
            );
            $validStmt->execute([$applicationId, $eventId]);

            if (!$validStmt->fetchColumn()) {
                $errors[] = 'Winner must be selected from an active application.';
            } else {
                $prizes = pmAdminEffectivePrizeSummary($pdo, $event);
                $amount = $place === 1
                    ? $prizes['first_minor']
                    : ($place === 2 ? $prizes['second_minor'] : $prizes['third_minor']);

                $stmt = $pdo->prepare(
                    'INSERT INTO programme_winners
                     (event_id, application_id, place, prize_amount_minor)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        application_id = VALUES(application_id),
                        prize_amount_minor = VALUES(prize_amount_minor),
                        updated_at = NOW()'
                );
                $stmt->execute([$eventId, $applicationId, $place, $amount]);

                setFlash('success', 'Winner saved for place #' . $place . '.');
                redirect('events-competitions-report.php?tab=applications&event_id=' . $eventId);
            }
        }
    }

    if ($action === 'remove_winner') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $place = (int) ($_POST['place'] ?? 0);

        $stmt = $pdo->prepare('DELETE FROM programme_winners WHERE event_id = ? AND place = ?');
        $stmt->execute([$eventId, $place]);

        setFlash('success', 'Winner removed.');
        redirect('events-competitions-report.php?tab=applications&event_id=' . $eventId);
    }

    if ($action === 'recalculate_prizes') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $event = pmAdminEvent($pdo, $eventId);

        if ($event) {
            $prizes = pmAdminEffectivePrizeSummary($pdo, $event);
            $stmt = $pdo->prepare(
                'UPDATE programme_winners
                 SET prize_amount_minor = CASE place
                    WHEN 1 THEN ?
                    WHEN 2 THEN ?
                    WHEN 3 THEN ?
                    ELSE prize_amount_minor
                 END
                 WHERE event_id = ?'
            );
            $stmt->execute([
                $prizes['first_minor'],
                $prizes['second_minor'],
                $prizes['third_minor'],
                $eventId,
            ]);

            setFlash('success', 'Winner prize amounts recalculated from paid application fees.');
        }

        redirect('events-competitions-report.php?tab=applications&event_id=' . $eventId);
    }
}

$editEventId = (int) ($_GET['edit_event'] ?? 0);
$editEvent = pmAdminEvent($pdo, $editEventId);

$selectedEventId = (int) ($_GET['event_id'] ?? 0);
$selectedEvent = pmAdminEvent($pdo, $selectedEventId);

// EVENTS PAGINATION
$eventSearch = trim((string) ($_GET['event_search'] ?? ''));
$eventTypeFilter = (string) ($_GET['event_type'] ?? '');
$eventStatusFilter = (string) ($_GET['event_status'] ?? '');
$eventPage = max(1, (int) ($_GET['event_page'] ?? 1));
$eventPerPage = 10;

$eventWhere = [];
$eventParams = [];

if ($eventSearch !== '') {
    $eventWhere[] = '(title LIKE :eq1 OR event_code LIKE :eq2 OR venue LIKE :eq3)';
    $like = '%' . $eventSearch . '%';
    $eventParams += ['eq1' => $like, 'eq2' => $like, 'eq3' => $like];
}
if (in_array($eventTypeFilter, ['programme', 'tournament'], true)) {
    $eventWhere[] = 'event_type = :event_type';
    $eventParams['event_type'] = $eventTypeFilter;
}
if (in_array($eventStatusFilter, ['draft','open','closed','completed','cancelled'], true)) {
    $eventWhere[] = 'status = :event_status';
    $eventParams['event_status'] = $eventStatusFilter;
}

$eventWhereSql = $eventWhere ? ' WHERE ' . implode(' AND ', $eventWhere) : '';

$stmt = $pdo->prepare('SELECT COUNT(*) FROM programme_events' . $eventWhereSql);
$stmt->execute($eventParams);
$eventTotal = (int) $stmt->fetchColumn();
$eventTotalPages = max(1, (int) ceil($eventTotal / $eventPerPage));
$eventPage = min($eventPage, $eventTotalPages);
$eventOffset = ($eventPage - 1) * $eventPerPage;

$stmt = $pdo->prepare(
    'SELECT e.*,
        (SELECT COUNT(*) FROM programme_applications a WHERE a.event_id = e.id AND a.status <> "withdrawn") AS application_count,
        (SELECT COALESCE(SUM(a.fee_paid_minor),0) FROM programme_applications a WHERE a.event_id = e.id AND a.payment_status = "paid") AS prize_pool_minor
     FROM programme_events e'
    . $eventWhereSql .
    ' ORDER BY e.created_at DESC
      LIMIT :limit OFFSET :offset'
);
foreach ($eventParams as $name => $value) {
    $stmt->bindValue(':' . $name, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $eventPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $eventOffset, PDO::PARAM_INT);
$stmt->execute();
$eventRows = $stmt->fetchAll();

// APPLICATION PAGINATION
$applicationRows = [];
$applicationTotal = 0;
$applicationTotalPages = 1;
$applicationPage = max(1, (int) ($_GET['application_page'] ?? 1));
$applicationPerPage = 15;
$applicationOffset = 0;
$applicationSearch = trim((string) ($_GET['application_search'] ?? ''));

if ($selectedEvent) {
    $appWhere = ['a.event_id = :event_id'];
    $appParams = ['event_id' => $selectedEventId];

    if ($applicationSearch !== '') {
        $appWhere[] = '(a.application_code LIKE :aq1 OR s.school_name LIKE :aq2 OR s.school_code LIKE :aq3 OR st.full_name LIKE :aq4 OR st.student_number LIKE :aq5)';
        $like = '%' . $applicationSearch . '%';
        $appParams += ['aq1' => $like, 'aq2' => $like, 'aq3' => $like, 'aq4' => $like, 'aq5' => $like];
    }

    $appWhereSql = ' WHERE ' . implode(' AND ', $appWhere);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM programme_applications a
         LEFT JOIN schools s ON s.id = a.school_id
         LEFT JOIN students st ON st.id = a.student_id'
         . $appWhereSql
    );
    $stmt->execute($appParams);
    $applicationTotal = (int) $stmt->fetchColumn();
    $applicationTotalPages = max(1, (int) ceil($applicationTotal / $applicationPerPage));
    $applicationPage = min($applicationPage, $applicationTotalPages);
    $applicationOffset = ($applicationPage - 1) * $applicationPerPage;

    $stmt = $pdo->prepare(
        'SELECT a.*,
                s.school_name, s.school_code,
                st.full_name AS student_name, st.student_number,
                (SELECT COUNT(*) FROM programme_application_students pas WHERE pas.application_id = a.id) AS participant_count
         FROM programme_applications a
         LEFT JOIN schools s ON s.id = a.school_id
         LEFT JOIN students st ON st.id = a.student_id'
        . $appWhereSql .
        ' ORDER BY a.applied_at DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($appParams as $name => $value) {
        $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $applicationPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $applicationOffset, PDO::PARAM_INT);
    $stmt->execute();
    $applicationRows = $stmt->fetchAll();
}

$winnerRows = [];
$activeApplications = [];
$prizeSummary = null;

if ($selectedEvent) {
    $activeApplications = pmAdminApplicationRows($pdo, $selectedEventId);
    $prizeSummary = pmAdminEffectivePrizeSummary($pdo, $selectedEvent);

    $stmt = $pdo->prepare(
        'SELECT w.*,
                a.application_code, a.applicant_type,
                s.school_name, s.school_code,
                st.full_name AS student_name, st.student_number
         FROM programme_winners w
         JOIN programme_applications a ON a.id = w.application_id
         LEFT JOIN schools s ON s.id = a.school_id
         LEFT JOIN students st ON st.id = a.student_id
         WHERE w.event_id = ?
         ORDER BY w.place'
    );
    $stmt->execute([$selectedEventId]);
    $winnerRows = $stmt->fetchAll();
}

// REPORT DATA
$reportRows = [];
$reportTotals = [
    'students' => 0, 'teachers' => 0,
    'malay_m' => 0, 'malay_f' => 0,
    'chinese_m' => 0, 'chinese_f' => 0,
    'indian_m' => 0, 'indian_f' => 0,
    'asli_m' => 0, 'asli_f' => 0,
    'other_m' => 0, 'other_f' => 0,
];

$hasEthnicity = pmColumnExists($pdo, 'students', 'ethnicity');
$hasGender = pmColumnExists($pdo, 'students', 'gender');

if ($selectedEvent && $hasGender) {
    $ethnicitySelect = $hasEthnicity
        ? "
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('malay','melayu') AND LOWER(TRIM(st.gender)) IN ('male','lelaki','l') THEN 1 ELSE 0 END) AS malay_m,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('malay','melayu') AND LOWER(TRIM(st.gender)) IN ('female','perempuan','p') THEN 1 ELSE 0 END) AS malay_f,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('chinese','cina') AND LOWER(TRIM(st.gender)) IN ('male','lelaki','l') THEN 1 ELSE 0 END) AS chinese_m,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('chinese','cina') AND LOWER(TRIM(st.gender)) IN ('female','perempuan','p') THEN 1 ELSE 0 END) AS chinese_f,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('indian','india') AND LOWER(TRIM(st.gender)) IN ('male','lelaki','l') THEN 1 ELSE 0 END) AS indian_m,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('indian','india') AND LOWER(TRIM(st.gender)) IN ('female','perempuan','p') THEN 1 ELSE 0 END) AS indian_f,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('orang asli','asli') AND LOWER(TRIM(st.gender)) IN ('male','lelaki','l') THEN 1 ELSE 0 END) AS asli_m,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) IN ('orang asli','asli') AND LOWER(TRIM(st.gender)) IN ('female','perempuan','p') THEN 1 ELSE 0 END) AS asli_f,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) NOT IN ('malay','melayu','chinese','cina','indian','india','orang asli','asli') AND LOWER(TRIM(st.gender)) IN ('male','lelaki','l') THEN 1 ELSE 0 END) AS other_m,
            SUM(CASE WHEN LOWER(TRIM(st.ethnicity)) NOT IN ('malay','melayu','chinese','cina','indian','india','orang asli','asli') AND LOWER(TRIM(st.gender)) IN ('female','perempuan','p') THEN 1 ELSE 0 END) AS other_f
        "
        : "
            0 AS malay_m, 0 AS malay_f, 0 AS chinese_m, 0 AS chinese_f,
            0 AS indian_m, 0 AS indian_f, 0 AS asli_m, 0 AS asli_f,
            0 AS other_m, 0 AS other_f
        ";

    /*
     * Only students explicitly selected for the event/competition application
     * are counted in the report. This respects max_students_per_school.
     */
    $sql = "
        SELECT
            s.id AS school_id,
            s.school_code,
            s.school_name,
            s.district,
            s.state,
            COUNT(DISTINCT st.id) AS total_students,
            {$ethnicitySelect},
            (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
        FROM programme_application_students pas
        JOIN programme_applications a
          ON a.id = pas.application_id
         AND a.event_id = pas.event_id
        JOIN students st ON st.id = pas.student_id
        JOIN schools s ON s.id = pas.school_id
        WHERE pas.event_id = ?
          AND a.status IN ('applied','approved')
        GROUP BY s.id, s.school_code, s.school_name, s.district, s.state
        ORDER BY COALESCE(s.district, s.state), s.school_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selectedEventId]);
    $reportRows = $stmt->fetchAll();

    foreach ($reportRows as $row) {
        $reportTotals['students'] += (int) $row['total_students'];
        $reportTotals['teachers'] += (int) $row['teacher_count'];
        foreach (['malay_m','malay_f','chinese_m','chinese_f','indian_m','indian_f','asli_m','asli_f','other_m','other_f'] as $key) {
            $reportTotals[$key] += (int) $row[$key];
        }
    }
}

$pageTitle = 'Events & Competitions Manager';
require 'includes/header.php';

$today = pmNow()->format('Y-m-d');
$eventForm = $editEvent ?: [
    'id' => 0,
    'event_type' => 'programme',
    'title' => '',
    'description' => '',
    'eligibility' => 'both',
    'max_students_per_school' => 5,
    'application_deadline' => '',
    'start_at' => '',
    'end_at' => '',
    'delivery_mode' => 'Hybrid (Physical / Online)',
    'event_level' => 'Pahang State',
    'organiser' => 'Metadata Berhad',
    'venue' => '',
    'entry_fee_minor' => 0,
    'currency' => 'MYR',
    'prize_distribution_mode' => 'automatic',
    'first_prize_percent' => 50,
    'second_prize_percent' => 30,
    'third_prize_percent' => 20,
    'manual_first_prize_minor' => 0,
    'manual_second_prize_minor' => 0,
    'manual_third_prize_minor' => 0,
    'report_schedule' => '',
    'report_expenditure' => '',
    'report_vip' => '',
    'report_issues' => '',
    'report_improvements' => '',
    'status' => 'draft',
];

$eventForm['prize_distribution_mode'] = $eventForm['prize_distribution_mode'] ?? 'automatic';
$eventForm['manual_first_prize_minor'] = (int) ($eventForm['manual_first_prize_minor'] ?? 0);
$eventForm['manual_second_prize_minor'] = (int) ($eventForm['manual_second_prize_minor'] ?? 0);
$eventForm['manual_third_prize_minor'] = (int) ($eventForm['manual_third_prize_minor'] ?? 0);
$eventForm['max_students_per_school'] = max(1, (int) ($eventForm['max_students_per_school'] ?? 5));

$eventPageUrl = static function (int $p): string {
    $query = $_GET;
    $query['tab'] = 'events';
    $query['event_page'] = $p;
    unset($query['edit_event'], $query['event_id'], $query['application_page']);
    if ($p <= 1) unset($query['event_page']);
    return 'events-competitions-report.php?' . http_build_query($query);
};

$appPageUrl = static function (int $p) use ($selectedEventId): string {
    $query = $_GET;
    $query['tab'] = 'applications';
    $query['event_id'] = $selectedEventId;
    $query['application_page'] = $p;
    unset($query['edit_event'], $query['event_page']);
    if ($p <= 1) unset($query['application_page']);
    return 'events-competitions-report.php?' . http_build_query($query);
};

$winnerMap = [];
foreach ($winnerRows as $winner) {
    $winnerMap[(int) $winner['place']] = $winner;
}
?>

<style>
@media print {
    nav, footer, .no-print { display: none !important; }
    main.container { max-width: none !important; width: 100% !important; padding: 0 !important; }
    .report-sheet { border: 0 !important; box-shadow: none !important; }
    @page { size: A4 portrait; margin: 8mm; }
}
.report-sheet { background:#fff; color:#111; border:1px solid #dee2e6; padding:26px; }
.report-table { width:100%; border-collapse:collapse; font-size:.73rem; }
.report-table th,.report-table td { border:1px solid #555; padding:4px; text-align:center; vertical-align:middle; }
.report-table .text-start { text-align:left!important; }
</style>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4 no-print">
    <div>
        <h1 class="h2 mb-1">Events &amp; Competitions Manager</h1>
        <p class="text-secondary mb-0">Create and manage Events &amp; Competitions, applications, prize pools and winners.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="programmes" class="btn btn-outline-primary"><i class="bi bi-box-arrow-up-right me-2"></i>View Events &amp; Competitions</a>
        <a href="admin.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Admin</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger no-print">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if (!$manualPrizeColumnsReady): ?>
    <div class="alert alert-warning no-print">
        Manual prize amounts are not enabled yet.
        Run <code>add_manual_prize_distribution.sql</code> once to enable manual Prize Pool Distribution.
        Automatic percentage-based distribution will continue to work until then.
    </div>
<?php endif; ?>

<ul class="nav nav-pills gap-2 mb-4 no-print">
    <li class="nav-item"><a class="nav-link <?= $tab === 'events' ? 'active' : '' ?>" href="events-competitions-report.php?tab=events"><i class="bi bi-calendar-event me-2"></i>Events &amp; Competitions</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'applications' ? 'active' : '' ?>" href="events-competitions-report.php?tab=applications<?= $selectedEventId ? '&event_id=' . $selectedEventId : '' ?>"><i class="bi bi-people me-2"></i>Applications & Winners</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'report' ? 'active' : '' ?>" href="events-competitions-report.php?tab=report<?= $selectedEventId ? '&event_id=' . $selectedEventId : '' ?>"><i class="bi bi-file-earmark-bar-graph me-2"></i>JPN Report</a></li>
</ul>

<?php if ($tab === 'events'): ?>

    <div class="card content-card mb-4 no-print">
        <div class="card-header bg-light border-0 p-4">
            <h2 class="h5 mb-1"><?= $editEvent ? 'Edit Event / Competition' : 'Create Event / Competition' ?></h2>
            <?php if ($editEvent): ?><div class="small text-secondary">Unique ID: <code><?= e($editEvent['event_code']) ?></code></div><?php endif; ?>
        </div>
        <div class="card-body p-4">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $editEvent ? 'update_event' : 'create_event' ?>">
                <input type="hidden" name="event_id" value="<?= (int) $eventForm['id'] ?>">

                <div class="col-md-3">
                    <label class="form-label required">Type</label>
                    <select class="form-select" name="event_type" required>
                        <option value="programme" <?= $eventForm['event_type'] === 'programme' ? 'selected' : '' ?>>Programme</option>
                        <option value="tournament" <?= $eventForm['event_type'] === 'tournament' ? 'selected' : '' ?>>Tournament</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label required">Title</label>
                    <input class="form-control" name="title" maxlength="200" value="<?= e($eventForm['title']) ?>" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label required">Status</label>
                    <select class="form-select" name="status" required>
                        <?php foreach (['draft'=>'Draft','open'=>'Open','closed'=>'Closed','completed'=>'Completed','cancelled'=>'Cancelled'] as $value=>$label): ?>
                            <option value="<?= e($value) ?>" <?= $eventForm['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3"><?= e($eventForm['description']) ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Who Can Apply</label>
                    <select class="form-select" name="eligibility" required>
                        <option value="both" <?= $eventForm['eligibility'] === 'both' ? 'selected' : '' ?>>School or Student</option>
                        <option value="school" <?= $eventForm['eligibility'] === 'school' ? 'selected' : '' ?>>School only</option>
                        <option value="student" <?= $eventForm['eligibility'] === 'student' ? 'selected' : '' ?>>Student only</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Maximum Students per School</label>
                    <input
                        type="number"
                        min="1"
                        max="500"
                        class="form-control"
                        name="max_students_per_school"
                        value="<?= (int) $eventForm['max_students_per_school'] ?>"
                        required
                    >
                    <div class="form-text">
                        A school must select between 1 and this number of active students when applying.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label required">Application Deadline</label>
                    <input type="datetime-local" class="form-control" name="application_deadline" value="<?= e(pmLocalDateTime($eventForm['application_deadline'])) ?>" required>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Standard Entry Fee (RM)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="entry_fee_rm" value="<?= e(number_format(((int) $eventForm['entry_fee_minor']) / 100, 2, '.', '')) ?>">
                    <div class="form-text">Active Schools (<?= pmMinimumActiveStudentsForSchool() ?>+ active students) skip this fee; Non-Active Schools must pay it.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Currency</label>
                    <input class="form-control text-uppercase" name="currency" maxlength="3" value="<?= e($eventForm['currency']) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Start</label>
                    <input type="datetime-local" class="form-control" name="start_at" value="<?= e(pmLocalDateTime($eventForm['start_at'])) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">End</label>
                    <input type="datetime-local" class="form-control" name="end_at" value="<?= e(pmLocalDateTime($eventForm['end_at'])) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Delivery Mode</label>
                    <input class="form-control" name="delivery_mode" value="<?= e($eventForm['delivery_mode']) ?>" placeholder="Online / Physical / Hybrid">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Level</label>
                    <input class="form-control" name="event_level" value="<?= e($eventForm['event_level']) ?>" placeholder="School / District / State / International">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Organiser</label>
                    <input class="form-control" name="organiser" value="<?= e($eventForm['organiser']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Venue</label>
                    <input class="form-control" name="venue" value="<?= e($eventForm['venue']) ?>">
                </div>

                <div class="col-12"><hr><h3 class="h6">Prize Pool Distribution</h3></div>

                <div class="col-md-4">
                    <label class="form-label">Distribution Method</label>
                    <select class="form-select" name="prize_distribution_mode" id="prizeDistributionMode" <?= !$manualPrizeColumnsReady ? 'disabled' : '' ?>>
                        <option value="automatic" <?= ($eventForm['prize_distribution_mode'] ?? 'automatic') === 'automatic' ? 'selected' : '' ?>>
                            Automatic — based on paid entry fees
                        </option>
                        <option value="manual" <?= ($eventForm['prize_distribution_mode'] ?? 'automatic') === 'manual' ? 'selected' : '' ?>>
                            Manual — set fixed prize amounts
                        </option>
                    </select>
                    <?php if (!$manualPrizeColumnsReady): ?>
                        <input type="hidden" name="prize_distribution_mode" value="automatic">
                    <?php endif; ?>
                </div>

                <div class="col-md-8">
                    <div class="alert alert-light border mb-0 h-100">
                        <strong>Automatic:</strong> prize pool comes from application fees marked Paid and is divided by percentage.<br>
                        <strong>Manual:</strong> enter the exact RM amount for 1st, 2nd and 3rd place. The manual prize pool is the total of those three amounts.
                    </div>
                </div>

                <div class="col-12 prize-auto-fields">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">1st Place %</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control" name="first_prize_percent" value="<?= e((string) $eventForm['first_prize_percent']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">2nd Place %</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control" name="second_prize_percent" value="<?= e((string) $eventForm['second_prize_percent']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">3rd Place %</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control" name="third_prize_percent" value="<?= e((string) $eventForm['third_prize_percent']) ?>">
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Automatic distribution percentages must total 100%.
                    </div>
                </div>

                <div class="col-12 prize-manual-fields">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">1st Place Prize (RM)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="manual_first_prize_rm"
                                value="<?= e(number_format(((int) $eventForm['manual_first_prize_minor']) / 100, 2, '.', '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">2nd Place Prize (RM)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="manual_second_prize_rm"
                                value="<?= e(number_format(((int) $eventForm['manual_second_prize_minor']) / 100, 2, '.', '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">3rd Place Prize (RM)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="manual_third_prize_rm"
                                value="<?= e(number_format(((int) $eventForm['manual_third_prize_minor']) / 100, 2, '.', '')) ?>">
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Manual Prize Pool = 1st + 2nd + 3rd place prize amounts. It does not need to match fees collected.
                    </div>
                </div>

                <div class="col-12"><hr><h3 class="h6">JPN Report Details</h3></div>

                <div class="col-md-6">
                    <label class="form-label">Schedule / Activities</label>
                    <textarea class="form-control" name="report_schedule" rows="3"><?= e($eventForm['report_schedule']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Expenditure / Allocation</label>
                    <textarea class="form-control" name="report_expenditure" rows="3"><?= e($eventForm['report_expenditure']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">VIP / Guest of Honour</label>
                    <textarea class="form-control" name="report_vip" rows="2"><?= e($eventForm['report_vip']) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Issues / Challenges</label>
                    <textarea class="form-control" name="report_issues" rows="2"><?= e($eventForm['report_issues']) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Improvements</label>
                    <textarea class="form-control" name="report_improvements" rows="2"><?= e($eventForm['report_improvements']) ?></textarea>
                </div>

                <div class="col-12 mt-4">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-2"></i><?= $editEvent ? 'Save Changes' : 'Create' ?></button>
                    <?php if ($editEvent): ?><a href="events-competitions-report.php?tab=events" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card content-card no-print">
        <div class="card-body p-4">
            <form method="get" class="row g-3 align-items-end mb-4">
                <input type="hidden" name="tab" value="events">
                <div class="col-lg-5">
                    <label class="form-label">Search</label>
                    <input class="form-control" name="event_search" value="<?= e($eventSearch) ?>" placeholder="Name, unique ID or venue">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Type</label>
                    <select class="form-select" name="event_type">
                        <option value="">All</option>
                        <option value="programme" <?= $eventTypeFilter === 'programme' ? 'selected' : '' ?>>Programme</option>
                        <option value="tournament" <?= $eventTypeFilter === 'tournament' ? 'selected' : '' ?>>Tournament</option>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="event_status">
                        <option value="">All</option>
                        <?php foreach (['draft','open','closed','completed','cancelled'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $eventStatusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search me-2"></i>Filter</button></div>
                <div class="col-lg-1 d-grid"><a href="events-competitions-report.php?tab=events" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a></div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Unique ID</th>
                            <th>Event / Competition</th>
                            <th>Deadline</th>
                            <th>Fee</th>
                            <th>Applications</th>
                            <th>Prize Pool</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$eventRows): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-5">No Events &amp; Competitions found.</td></tr>
                    <?php else: foreach ($eventRows as $event): ?>
                        <tr>
                            <td><code><?= e($event['event_code']) ?></code></td>
                            <td>
                                <div class="fw-semibold"><?= e($event['title']) ?></div>
                                <small class="text-secondary">
                                    <?= e(ucfirst($event['event_type'])) ?> · <?= e(pmEligibilityLabel($event['eligibility'])) ?>
                                    <?php if (in_array($event['eligibility'], ['school','both'], true)): ?>
                                        · Max <?= (int) $event['max_students_per_school'] ?> student(s)/school
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td><?= e(pmDisplayDateTime($event['application_deadline'])) ?></td>
                            <td><?= e(pmMoney((int) $event['entry_fee_minor'], $event['currency'])) ?></td>
                            <td><?= (int) $event['application_count'] ?></td>
                            <?php
                                $eventPrizePoolMinor = (($event['prize_distribution_mode'] ?? 'automatic') === 'manual')
                                    ? (
                                        (int) ($event['manual_first_prize_minor'] ?? 0)
                                        + (int) ($event['manual_second_prize_minor'] ?? 0)
                                        + (int) ($event['manual_third_prize_minor'] ?? 0)
                                    )
                                    : (int) $event['prize_pool_minor'];
                            ?>
                            <td class="fw-semibold">
                                <?= e(pmMoney($eventPrizePoolMinor, $event['currency'])) ?>
                                <div class="small text-secondary">
                                    <?= (($event['prize_distribution_mode'] ?? 'automatic') === 'manual') ? 'Manual' : 'Automatic' ?>
                                </div>
                            </td>
                            <td><span class="badge text-bg-<?= e(pmEventStatusBadge($event)) ?>"><?= e(pmEventStatusLabel($event)) ?></span></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="events-competitions-report.php?tab=applications&event_id=<?= (int) $event['id'] ?>">Manage</a>
                                <a class="btn btn-sm btn-outline-secondary" href="events-competitions-report.php?tab=report&event_id=<?= (int) $event['id'] ?>">Report</a>
                                <a class="btn btn-sm btn-outline-warning" href="events-competitions-report.php?tab=events&edit_event=<?= (int) $event['id'] ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this event/competition and all applications/winners?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_event">
                                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
                <div class="small text-secondary">
                    Showing <?= $eventTotal > 0 ? number_format($eventOffset + 1) : 0 ?>–<?= number_format(min($eventOffset + $eventPerPage, $eventTotal)) ?>
                    of <?= number_format($eventTotal) ?>
                </div>
                <?php if ($eventTotalPages > 1): ?>
                    <nav><ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $eventPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($eventPageUrl(max(1, $eventPage - 1))) ?>">Previous</a></li>
                        <?php for ($p=max(1,$eventPage-2); $p<=min($eventTotalPages,$eventPage+2); $p++): ?>
                            <li class="page-item <?= $p === $eventPage ? 'active' : '' ?>"><a class="page-link" href="<?= e($eventPageUrl($p)) ?>"><?= $p ?></a></li>
                        <?php endfor; ?>
                        <li class="page-item <?= $eventPage >= $eventTotalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($eventPageUrl(min($eventTotalPages, $eventPage + 1))) ?>">Next</a></li>
                    </ul></nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'applications'): ?>

    <div class="card content-card mb-4 no-print">
        <div class="card-body p-4">
            <form method="get" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="applications">
                <div class="col-md-7">
                    <label class="form-label required">Event / Competition</label>
                    <select class="form-select" name="event_id" required onchange="this.form.submit()">
                        <option value="">Select event / competition</option>
                        <?php
                        $allEvents = $pdo->query('SELECT id,event_code,title,event_type FROM programme_events ORDER BY created_at DESC')->fetchAll();
                        foreach ($allEvents as $event):
                        ?>
                            <option value="<?= (int) $event['id'] ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                                <?= e($event['event_code']) ?> — <?= e($event['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selectedEvent): ?>
                    <div class="col-md-4">
                        <label class="form-label">Search Applications</label>
                        <input class="form-control" name="application_search" value="<?= e($applicationSearch) ?>" placeholder="Application ID, school or student">
                    </div>
                    <div class="col-md-1 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-search"></i></button></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (!$selectedEvent): ?>
        <div class="alert alert-info no-print">Select an event or competition to manage applications, fees and winners.</div>
    <?php else: ?>

        <div class="row g-3 mb-4 no-print">
            <div class="col-md-3">
                <div class="card content-card h-100"><div class="card-body">
                    <div class="small text-secondary">Event</div>
                    <div class="fw-semibold"><?= e($selectedEvent['event_code']) ?></div>
                    <div><?= e($selectedEvent['title']) ?></div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card content-card h-100"><div class="card-body">
                    <div class="small text-secondary">Paid Applications</div>
                    <div class="display-6"><?= (int) $prizeSummary['paid_applications'] ?></div>
                    <div class="small text-secondary">
                        Fees collected: <?= e(pmMoney((int) ($prizeSummary['fee_pool_minor'] ?? 0), $selectedEvent['currency'])) ?>
                    </div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card content-card h-100"><div class="card-body">
                    <div class="small text-secondary">Current Prize Pool</div>
                    <div class="h4 mb-0"><?= e(pmMoney($prizeSummary['pool_minor'], $selectedEvent['currency'])) ?></div>
                    <div class="small text-secondary mt-1">
                        <?= ($prizeSummary['distribution_mode'] ?? 'automatic') === 'manual' ? 'Manual distribution' : 'Automatic from paid fees' ?>
                    </div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card content-card h-100"><div class="card-body">
                    <div class="small text-secondary">Deadline</div>
                    <div class="fw-semibold"><?= e(pmDisplayDateTime($selectedEvent['application_deadline'])) ?></div>
                </div></div>
            </div>
        </div>

        <div class="card content-card mb-4 no-print">
            <div class="card-header bg-light border-0 p-4 d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <h2 class="h5 mb-1">Prize Pool & Winners</h2>
                    <div class="small text-secondary">
                        <?php if (($prizeSummary['distribution_mode'] ?? 'automatic') === 'manual'): ?>
                            Manual distribution is active. Prize amounts are fixed in the Events &amp; Competitions settings.
                            Fees collected: <?= e(pmMoney((int) ($prizeSummary['fee_pool_minor'] ?? 0), $selectedEvent['currency'])) ?>.
                        <?php else: ?>
                            Automatic distribution is active. Prize pool is calculated from application fees marked Paid.
                        <?php endif; ?>
                    </div>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="recalculate_prizes">
                    <input type="hidden" name="event_id" value="<?= (int) $selectedEventId ?>">
                    <button class="btn btn-outline-primary btn-sm">Update Winner Prize Amounts</button>
                </form>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 mb-4">
                    <?php foreach ([1=>'first_minor',2=>'second_minor',3=>'third_minor'] as $place=>$key): ?>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-secondary"><?= $place === 1 ? '1st' : ($place === 2 ? '2nd' : '3rd') ?> Place</div>
                                <div class="h4"><?= e(pmMoney($prizeSummary[$key], $selectedEvent['currency'])) ?></div>

                                <?php if (isset($winnerMap[$place])): $winner = $winnerMap[$place]; ?>
                                    <div class="fw-semibold"><?= e(pmApplicationName($winner)) ?></div>
                                    <div class="small text-secondary"><?= e(pmApplicationReference($winner)) ?></div>
                                    <form method="post" class="mt-2">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="remove_winner">
                                        <input type="hidden" name="event_id" value="<?= (int) $selectedEventId ?>">
                                        <input type="hidden" name="place" value="<?= $place ?>">
                                        <button class="btn btn-sm btn-outline-danger">Remove Winner</button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-secondary small">No winner assigned.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="set_winner">
                    <input type="hidden" name="event_id" value="<?= (int) $selectedEventId ?>">

                    <div class="col-md-2">
                        <label class="form-label">Place</label>
                        <select class="form-select" name="place" required>
                            <option value="1">1st Place</option>
                            <option value="2">2nd Place</option>
                            <option value="3">3rd Place</option>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Winner</label>
                        <select class="form-select" name="application_id" required>
                            <option value="">Select approved/applied participant</option>
                            <?php foreach ($activeApplications as $application): ?>
                                <option value="<?= (int) $application['id'] ?>">
                                    <?= e($application['application_code']) ?> — <?= e(pmApplicationName($application)) ?> (<?= e(pmApplicationReference($application)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2 d-grid">
                        <button class="btn btn-primary">Save Winner</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card content-card no-print">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Application</th><th>Applicant</th><th>Participants</th><th>Applied</th><th>Status</th><th>Payment / Fee</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$applicationRows): ?>
                            <tr><td colspan="7" class="text-center text-secondary py-5">No applications found.</td></tr>
                        <?php else: foreach ($applicationRows as $application): ?>
                            <tr>
                                <td><code><?= e($application['application_code']) ?></code><br><small class="text-secondary"><?= e(ucfirst($application['applicant_type'])) ?></small></td>
                                <td><div class="fw-semibold"><?= e(pmApplicationName($application)) ?></div><small class="text-secondary"><?= e(pmApplicationReference($application)) ?></small></td>
                                <td>
                                    <span class="badge text-bg-light border"><?= (int) ($application['participant_count'] ?? 0) ?> student(s)</span>
                                    <?php if ((int) ($application['participant_count'] ?? 0) > 0): ?>
                                        <details class="small mt-1 text-start">
                                            <summary>View</summary>
                                            <?php foreach (pmApplicationParticipants($pdo, (int) $application['id']) as $participant): ?>
                                                <div><?= e($participant['student_number']) ?> — <?= e($participant['full_name']) ?></div>
                                            <?php endforeach; ?>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(pmDisplayDateTime($application['applied_at'])) ?></td>
                                <td><span class="badge text-bg-<?= e(pmApplicationStatusBadge($application['status'])) ?>"><?= e(ucfirst($application['status'])) ?></span></td>
                                <td>
                                    <span class="badge text-bg-<?= e(pmPaymentStatusBadge($application['payment_status'])) ?>"><?= e(ucfirst($application['payment_status'])) ?></span>
                                    <div class="small mt-1"><?= e(pmMoney((int) $application['fee_paid_minor'], $selectedEvent['currency'])) ?></div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-column gap-2 align-items-end">
                                        <form method="post" class="d-flex gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="application_status">
                                            <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= (int) $selectedEventId ?>">
                                            <select class="form-select form-select-sm" name="application_status">
                                                <?php foreach (['applied','approved','rejected','withdrawn'] as $status): ?>
                                                    <option value="<?= e($status) ?>" <?= $application['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>

                                        <form method="post" class="d-flex gap-1">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="application_payment">
                                            <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= (int) $selectedEventId ?>">
                                            <select class="form-select form-select-sm" name="payment_status">
                                                <?php foreach (['unpaid','paid','refunded','waived'] as $paymentStatus): ?>
                                                    <option value="<?= e($paymentStatus) ?>" <?= $application['payment_status'] === $paymentStatus ? 'selected' : '' ?>><?= e(ucfirst($paymentStatus)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input class="form-control form-control-sm" style="width:100px" type="number" step="0.01" min="0" name="fee_paid_rm"
                                                value="<?= e(number_format(((int) $application['fee_paid_minor'])/100, 2, '.', '')) ?>"
                                                title="Fee paid RM">
                                            <button class="btn btn-sm btn-outline-success">Update Fee</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
                    <div class="small text-secondary">
                        Showing <?= $applicationTotal > 0 ? number_format($applicationOffset + 1) : 0 ?>–<?= number_format(min($applicationOffset + $applicationPerPage, $applicationTotal)) ?>
                        of <?= number_format($applicationTotal) ?>
                    </div>
                    <?php if ($applicationTotalPages > 1): ?>
                        <nav><ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $applicationPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($appPageUrl(max(1, $applicationPage - 1))) ?>">Previous</a></li>
                            <?php for ($p=max(1,$applicationPage-2); $p<=min($applicationTotalPages,$applicationPage+2); $p++): ?>
                                <li class="page-item <?= $p === $applicationPage ? 'active' : '' ?>"><a class="page-link" href="<?= e($appPageUrl($p)) ?>"><?= $p ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?= $applicationPage >= $applicationTotalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($appPageUrl(min($applicationTotalPages, $applicationPage + 1))) ?>">Next</a></li>
                        </ul></nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>

<?php else: ?>

    <div class="card content-card mb-4 no-print">
        <div class="card-body p-4">
            <form method="get" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="report">
                <div class="col-md-9">
                    <label class="form-label">Event / Competition</label>
                    <select class="form-select" name="event_id" required>
                        <option value="">Select event / competition</option>
                        <?php
                        $allEvents = $pdo->query('SELECT id,event_code,title FROM programme_events ORDER BY created_at DESC')->fetchAll();
                        foreach ($allEvents as $event):
                        ?>
                            <option value="<?= (int) $event['id'] ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                                <?= e($event['event_code']) ?> — <?= e($event['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Load Report</button></div>
                <div class="col-md-1 d-grid"><button type="button" class="btn btn-primary" onclick="window.print()" <?= !$selectedEvent ? 'disabled' : '' ?>><i class="bi bi-printer"></i></button></div>
            </form>
        </div>
    </div>

    <?php if (!$selectedEvent): ?>
        <div class="alert alert-info no-print">Select an event or competition to generate its report.</div>
    <?php else: ?>
        <div class="report-sheet">
            <div class="small mb-3">JPNP.SPMd.600-3/5/2 Jld.50 ( &nbsp;&nbsp;&nbsp; )</div>
            <h2 class="h6 fw-bold text-center text-uppercase mb-4">LAPORAN (NAMA PROGRAM/AKTIVITI)</h2>

            <table class="table table-sm table-borderless mb-4">
                <tr><td style="width:30px">1.0</td><td style="width:190px">Nama Program/Aktiviti:</td><td><strong><?= e($selectedEvent['title']) ?></strong> <code><?= e($selectedEvent['event_code']) ?></code></td></tr>
                <tr><td>2.0</td><td>Tarikh Pelaksanaan:</td><td><?= e(pmDisplayDateRange($selectedEvent['start_at'], $selectedEvent['end_at'])) ?></td></tr>
                <tr><td>3.0</td><td>Mod Pelaksanaan:</td><td><?= e($selectedEvent['delivery_mode'] ?: '-') ?></td></tr>
                <tr><td>4.0</td><td>Peringkat Pelaksanaan:</td><td><?= e($selectedEvent['event_level'] ?: '-') ?></td></tr>
                <tr><td>5.0</td><td>Pengelolaan:</td><td><?= e($selectedEvent['organiser']) ?></td></tr>
                <tr><td>6.0</td><td>Tempat Program:</td><td><?= e($selectedEvent['venue'] ?: '-') ?></td></tr>
                <tr><td>7.0</td><td>Bil. Murid:</td><td><strong><?= (int) $reportTotals['students'] ?></strong></td></tr>
                <tr><td></td><td>Bil. Guru/Pegawai:</td><td><strong><?= (int) $reportTotals['teachers'] ?></strong></td></tr>
            </table>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th rowspan="3">BIL</th><th rowspan="3">KOD SEKOLAH</th><th rowspan="3">SEKOLAH</th><th rowspan="3">DAERAH/NEGERI</th>
                            <th colspan="10">BANGSA/JANTINA MURID</th><th colspan="2">JUMLAH</th>
                        </tr>
                        <tr>
                            <th colspan="2">MELAYU</th><th colspan="2">CINA</th><th colspan="2">INDIA</th><th colspan="2">ASLI</th><th colspan="2">LAIN-LAIN</th>
                            <th rowspan="2">MURID</th><th rowspan="2">GURU</th>
                        </tr>
                        <tr><th>L</th><th>P</th><th>L</th><th>P</th><th>L</th><th>P</th><th>L</th><th>P</th><th>L</th><th>P</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$reportRows): ?>
                        <tr><td colspan="16">Tiada penyertaan aktif.</td></tr>
                    <?php else: foreach ($reportRows as $index=>$row): ?>
                        <tr>
                            <td><?= $index+1 ?></td>
                            <td><?= e($row['school_code']) ?></td>
                            <td class="text-start"><?= e($row['school_name']) ?></td>
                            <td><?= e($row['district'] ?: $row['state']) ?></td>
                            <td><?= (int)$row['malay_m'] ?></td><td><?= (int)$row['malay_f'] ?></td>
                            <td><?= (int)$row['chinese_m'] ?></td><td><?= (int)$row['chinese_f'] ?></td>
                            <td><?= (int)$row['indian_m'] ?></td><td><?= (int)$row['indian_f'] ?></td>
                            <td><?= (int)$row['asli_m'] ?></td><td><?= (int)$row['asli_f'] ?></td>
                            <td><?= (int)$row['other_m'] ?></td><td><?= (int)$row['other_f'] ?></td>
                            <td><strong><?= (int)$row['total_students'] ?></strong></td>
                            <td><strong><?= (int)$row['teacher_count'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <th colspan="4">JUMLAH</th>
                            <th><?= (int)$reportTotals['malay_m'] ?></th><th><?= (int)$reportTotals['malay_f'] ?></th>
                            <th><?= (int)$reportTotals['chinese_m'] ?></th><th><?= (int)$reportTotals['chinese_f'] ?></th>
                            <th><?= (int)$reportTotals['indian_m'] ?></th><th><?= (int)$reportTotals['indian_f'] ?></th>
                            <th><?= (int)$reportTotals['asli_m'] ?></th><th><?= (int)$reportTotals['asli_f'] ?></th>
                            <th><?= (int)$reportTotals['other_m'] ?></th><th><?= (int)$reportTotals['other_f'] ?></th>
                            <th><?= (int)$reportTotals['students'] ?></th><th><?= (int)$reportTotals['teachers'] ?></th>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4"><strong>8.0 Jadual Program/Pengisian/Pelaksanaan Aktiviti:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e($selectedEvent['report_schedule'] ?: '-')) ?></div></div>
            <div class="mt-3"><strong>9.0 Peruntukan/Perbelanjaan yang diturunkan:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e($selectedEvent['report_expenditure'] ?: '-')) ?></div></div>
            <div class="mt-3"><strong>10.0 Tetamu Kehormat/VIP/Perasmi:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e($selectedEvent['report_vip'] ?: '-')) ?></div></div>
            <div class="mt-3"><strong>11.0 Sinopsis Program:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e($selectedEvent['description'] ?: '-')) ?></div></div>

            <div class="mt-4">
                <strong>12.0 Pemenang:</strong>
                <table class="report-table mt-2">
                    <thead><tr><th>KEDUDUKAN</th><th>PEMENANG</th><th>ID</th><th>SEKOLAH</th><th>KOD SEKOLAH</th><th>HADIAH</th></tr></thead>
                    <tbody>
                    <?php for ($place=1; $place<=3; $place++): $winner=$winnerMap[$place] ?? null; ?>
                        <tr>
                            <td><?= $place === 1 ? 'PERTAMA' : ($place === 2 ? 'KEDUA' : 'KETIGA') ?></td>
                            <td><?= $winner ? e(pmApplicationName($winner)) : '-' ?></td>
                            <td><?= $winner ? e(pmApplicationReference($winner)) : '-' ?></td>
                            <td><?= $winner ? e($winner['school_name'] ?: '-') : '-' ?></td>
                            <td><?= $winner ? e($winner['school_code'] ?: '-') : '-' ?></td>
                            <td><?= $winner ? e(pmMoney((int)$winner['prize_amount_minor'], $selectedEvent['currency'])) : '-' ?></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3"><strong>13.0 Isu dan cabaran:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e($selectedEvent['report_issues'] ?: '-')) ?></div></div>
            <div class="mt-3"><strong>14.0 Cadangan penambahbaikan:</strong><div class="border rounded p-2 mt-1"><?= nl2br(e($selectedEvent['report_improvements'] ?: '-')) ?></div></div>

            <div class="mt-4">
                <strong>Prize Pool:</strong> <?= e(pmMoney($prizeSummary['pool_minor'], $selectedEvent['currency'])) ?>
                (<?= ($prizeSummary['distribution_mode'] ?? 'automatic') === 'manual' ? 'Manual' : 'Automatic' ?>)
                · 1st <?= e(pmMoney($prizeSummary['first_minor'], $selectedEvent['currency'])) ?>
                · 2nd <?= e(pmMoney($prizeSummary['second_minor'], $selectedEvent['currency'])) ?>
                · 3rd <?= e(pmMoney($prizeSummary['third_minor'], $selectedEvent['currency'])) ?>
                <?php if (($prizeSummary['distribution_mode'] ?? 'automatic') === 'manual'): ?>
                    <br><span class="small">Application fees collected: <?= e(pmMoney((int) ($prizeSummary['fee_pool_minor'] ?? 0), $selectedEvent['currency'])) ?></span>
                <?php endif; ?>
            </div>

            <div class="mt-4 small">Disediakan oleh: <?= e($selectedEvent['organiser']) ?> · Tarikh: <?= e($today) ?></div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modeSelect = document.getElementById('prizeDistributionMode');
    const automaticFields = document.querySelectorAll('.prize-auto-fields');
    const manualFields = document.querySelectorAll('.prize-manual-fields');

    const syncPrizeDistributionFields = () => {
        const mode = modeSelect?.value || 'automatic';

        automaticFields.forEach((element) => {
            element.classList.toggle('d-none', mode !== 'automatic');
        });

        manualFields.forEach((element) => {
            element.classList.toggle('d-none', mode !== 'manual');
        });
    };

    modeSelect?.addEventListener('change', syncPrizeDistributionFields);
    syncPrizeDistributionFields();
});
</script>

<?php require 'includes/footer.php'; ?>
