<?php
require_once 'config.php';

$perPage = 10;
$schools = getSchools($pdo);
$schoolId = (int) ($_GET['school_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$month = monthRange($_GET['month'] ?? null);
$monthDate = substr($month['start'], 0, 10);

$studentWhere = [];
$studentFilterParams = [];

if ($schoolId > 0) {
    $studentWhere[] = 'st.school_id = :student_school_id';
    $studentFilterParams[':student_school_id'] = $schoolId;
}

if ($search !== '') {
    $studentWhere[] = '(st.full_name LIKE :student_name
                       OR st.student_number LIKE :student_number
                       OR s.school_name LIKE :student_school_name
                       OR s.school_code LIKE :student_school_code)';
    $like = '%' . $search . '%';
    $studentFilterParams[':student_name'] = $like;
    $studentFilterParams[':student_number'] = $like;
    $studentFilterParams[':student_school_name'] = $like;
    $studentFilterParams[':student_school_code'] = $like;
}

$studentWhereSql = $studentWhere ? ' WHERE ' . implode(' AND ', $studentWhere) : '';

$studentCountStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM students st
     JOIN schools s ON s.id = st.school_id'
     . $studentWhereSql
);
foreach ($studentFilterParams as $name => $value) {
    $studentCountStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$studentCountStmt->execute();
$totalStudents = (int) $studentCountStmt->fetchColumn();

$totalStudentPages = max(1, (int) ceil($totalStudents / $perPage));
$studentPage = max(1, min((int) ($_GET['student_page'] ?? 1), $totalStudentPages));
$studentOffset = ($studentPage - 1) * $perPage;

$studentSql = "SELECT st.id, st.student_number, st.full_name, st.class_name, st.membership_status, st.qr_token,
                      s.school_name, s.school_code,
                      COALESCE(a.actual_minutes, 0) AS actual_minutes,
                      COALESCE(a.credited_minutes, 0) AS credited_minutes,
                      COALESCE(a.session_count, 0) AS session_count,
                      a.open_check_in
               FROM students st
               JOIN schools s ON s.id = st.school_id
               LEFT JOIN (
                   SELECT student_id,
                          SUM(CASE WHEN status = 'completed' THEN actual_minutes ELSE 0 END) AS actual_minutes,
                          SUM(CASE WHEN status = 'completed' THEN credited_minutes ELSE 0 END) AS credited_minutes,
                          SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS session_count,
                          MAX(CASE WHEN status = 'open' THEN check_in_at END) AS open_check_in
                   FROM attendance_sessions
                   WHERE attendance_month = :attendance_month
                   GROUP BY student_id
               ) a ON a.student_id = st.id"
               . $studentWhereSql
               . " ORDER BY s.school_name, st.full_name
                   LIMIT :student_limit OFFSET :student_offset";

$studentStmt = $pdo->prepare($studentSql);
$studentStmt->bindValue(':attendance_month', $monthDate, PDO::PARAM_STR);
foreach ($studentFilterParams as $name => $value) {
    $studentStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$studentStmt->bindValue(':student_limit', $perPage, PDO::PARAM_INT);
$studentStmt->bindValue(':student_offset', $studentOffset, PDO::PARAM_INT);
$studentStmt->execute();
$students = $studentStmt->fetchAll();

$statsSql = "SELECT
                COALESCE(SUM(a.actual_minutes), 0) AS total_actual_minutes,
                COALESCE(SUM(a.credited_minutes), 0) AS total_credited_minutes,
                COALESCE(SUM(a.session_count), 0) AS total_sessions,
                COUNT(CASE WHEN a.open_check_in IS NOT NULL THEN 1 END) AS currently_inside
             FROM students st
             JOIN schools s ON s.id = st.school_id
             LEFT JOIN (
                 SELECT student_id,
                        SUM(CASE WHEN status = 'completed' THEN actual_minutes ELSE 0 END) AS actual_minutes,
                        SUM(CASE WHEN status = 'completed' THEN credited_minutes ELSE 0 END) AS credited_minutes,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS session_count,
                        MAX(CASE WHEN status = 'open' THEN check_in_at END) AS open_check_in
                 FROM attendance_sessions
                 WHERE attendance_month = :stats_month
                 GROUP BY student_id
             ) a ON a.student_id = st.id"
             . $studentWhereSql;

$statsStmt = $pdo->prepare($statsSql);
$statsStmt->bindValue(':stats_month', $monthDate, PDO::PARAM_STR);
foreach ($studentFilterParams as $name => $value) {
    $statsStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$statsStmt->execute();
$stats = $statsStmt->fetch() ?: [];

$totalCredited = (int) ($stats['total_credited_minutes'] ?? 0);
$totalActual = (int) ($stats['total_actual_minutes'] ?? 0);
$totalSessions = (int) ($stats['total_sessions'] ?? 0);
$currentlyInside = (int) ($stats['currently_inside'] ?? 0);

$recentWhere = ['a.attendance_month = :recent_month'];
$recentFilterParams = [':recent_month' => $monthDate];

if ($schoolId > 0) {
    $recentWhere[] = 'st.school_id = :recent_school_id';
    $recentFilterParams[':recent_school_id'] = $schoolId;
}

if ($search !== '') {
    $recentWhere[] = '(st.full_name LIKE :recent_student_name
                      OR st.student_number LIKE :recent_student_number
                      OR s.school_name LIKE :recent_school_name
                      OR s.school_code LIKE :recent_school_code)';
    $like = '%' . $search . '%';
    $recentFilterParams[':recent_student_name'] = $like;
    $recentFilterParams[':recent_student_number'] = $like;
    $recentFilterParams[':recent_school_name'] = $like;
    $recentFilterParams[':recent_school_code'] = $like;
}

$recentWhereSql = ' WHERE ' . implode(' AND ', $recentWhere);

$recentCountStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM attendance_sessions a
     JOIN students st ON st.id = a.student_id
     JOIN schools s ON s.id = st.school_id'
     . $recentWhereSql
);
foreach ($recentFilterParams as $name => $value) {
    $recentCountStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$recentCountStmt->execute();
$totalRecentSessions = (int) $recentCountStmt->fetchColumn();

$totalSessionPages = max(1, (int) ceil($totalRecentSessions / $perPage));
$sessionPage = max(1, min((int) ($_GET['session_page'] ?? 1), $totalSessionPages));
$sessionOffset = ($sessionPage - 1) * $perPage;

$recentSql = 'SELECT a.*, st.full_name, st.student_number, s.school_name
              FROM attendance_sessions a
              JOIN students st ON st.id = a.student_id
              JOIN schools s ON s.id = st.school_id'
              . $recentWhereSql
              . ' ORDER BY a.check_in_at DESC
                  LIMIT :session_limit OFFSET :session_offset';

$recentStmt = $pdo->prepare($recentSql);
foreach ($recentFilterParams as $name => $value) {
    $recentStmt->bindValue(
        $name,
        $value,
        is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
    );
}
$recentStmt->bindValue(':session_limit', $perPage, PDO::PARAM_INT);
$recentStmt->bindValue(':session_offset', $sessionOffset, PDO::PARAM_INT);
$recentStmt->execute();
$recentSessions = $recentStmt->fetchAll();

$studentShowingFrom = $totalStudents > 0 ? $studentOffset + 1 : 0;
$studentShowingTo = min($studentOffset + $perPage, $totalStudents);
$sessionShowingFrom = $totalRecentSessions > 0 ? $sessionOffset + 1 : 0;
$sessionShowingTo = min($sessionOffset + $perPage, $totalRecentSessions);

$buildPageUrl = static function (array $changes): string {
    $query = array_merge($_GET, $changes);

    foreach (['student_page', 'session_page'] as $pageKey) {
        if (isset($query[$pageKey]) && (int) $query[$pageKey] <= 1) {
            unset($query[$pageKey]);
        }
    }

    if (($query['school_id'] ?? '') === '' || (int) ($query['school_id'] ?? 0) <= 0) {
        unset($query['school_id']);
    }
    if (($query['search'] ?? '') === '') {
        unset($query['search']);
    }
    if (($query['month'] ?? '') === '') {
        unset($query['month']);
    }

    return '?' . http_build_query($query);
};

$pageTitle = 'Attendance & Hours';
require 'includes/header.php';
?>
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div>
        <h1 class="h2 mb-1">Attendance & Monthly Hours</h1>
        <p class="text-secondary mb-0">Each active RM30 membership includes 5 hours (300 minutes) per calendar month.</p>
    </div>
    <a href="attendance_scanner.php" class="btn btn-primary"><i class="bi bi-qr-code-scan me-2"></i>Open QR Scanner</a>
</div>

<div class="card content-card mb-4">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Month</label>
                <input type="month" class="form-control" name="month" value="<?= e($month['key']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">School</label>
                <select class="form-select" name="school_id">
                    <option value="">All schools</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['school_name']) ?> (<?= e($school['school_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input class="form-control" name="search" value="<?= e($search) ?>" placeholder="Student or school">
            </div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php
    $cards = [
        ['Credited Hours', formatMinutes($totalCredited), 'bi-hourglass-split', 'primary'],
        ['Actual Attendance', formatMinutes($totalActual), 'bi-clock-history', 'info'],
        ['Completed Sessions', number_format($totalSessions), 'bi-calendar-check', 'success'],
        ['Currently Checked In', number_format($currentlyInside), 'bi-person-badge', 'warning'],
    ];
    foreach ($cards as [$label, $value, $icon, $color]):
    ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card h-100"><div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div><div class="text-secondary small mb-1"><?= e($label) ?></div><div class="h2 fw-semibold mb-0"><?= e($value) ?></div></div>
                <div class="icon-box bg-<?= e($color) ?>-subtle text-<?= e($color) ?>"><i class="bi <?= e($icon) ?>"></i></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card content-card mb-4">
    <div class="card-header bg-white border-0 p-4 pb-0">
        <h2 class="h5 mb-1">Student Hour Balances — <?= e($month['label']) ?></h2>
        <p class="small text-secondary mb-0">Only completed sessions use the monthly 300-minute allowance.</p>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Student</th><th>School</th><th>Status</th><th>Usage</th><th>Sessions</th><th></th></tr></thead>
                <tbody>
                <?php if (!$students): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-5">No students found.</td></tr>
                <?php else: foreach ($students as $student):
                    $used = min(MONTHLY_ATTENDANCE_MINUTES, (int) $student['credited_minutes']);
                    $remaining = max(0, MONTHLY_ATTENDANCE_MINUTES - $used);
                    $percent = min(100, (int) round(($used / MONTHLY_ATTENDANCE_MINUTES) * 100));
                    $token = ensureStudentQrToken($pdo, (int) $student['id'], $student['qr_token'] ?? null);
                ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($student['full_name']) ?></div>
                            <small class="text-secondary"><?= e($student['student_number']) ?><?= $student['class_name'] ? ' · ' . e($student['class_name']) : '' ?></small>
                            <?php if ($student['open_check_in']): ?><div><span class="badge text-bg-warning mt-1">Checked in since <?= e(date('h:i A', strtotime($student['open_check_in']))) ?></span></div><?php endif; ?>
                        </td>
                        <td><?= e($student['school_name']) ?><br><small class="text-secondary"><?= e($student['school_code']) ?></small></td>
                        <td><span class="badge text-bg-<?= e(membershipBadge($student['membership_status'])) ?>"><?= e(membershipLabel($student['membership_status'])) ?></span></td>
                        <td style="min-width: 220px;">
                            <div class="d-flex justify-content-between small mb-1"><span><?= e(formatMinutes($used)) ?> used</span><span><?= e(formatMinutes($remaining)) ?> left</span></div>
                            <div class="progress attendance-progress" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?= $percent ?>%"></div></div>
                        </td>
                        <td><?= (int) $student['session_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="student_qr.php?student_id=<?= (int) $student['id'] ?>"><i class="bi bi-qr-code"></i> QR</a>
                            <a class="btn btn-sm btn-outline-primary" href="learning_modules.php?student_id=<?= (int) $student['id'] ?>">Learning</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mt-4">
            <div class="small text-secondary">
                Showing <?= number_format($studentShowingFrom) ?>–<?= number_format($studentShowingTo) ?> of <?= number_format($totalStudents) ?> students
            </div>

            <?php if ($totalStudentPages > 1): ?>
                <nav aria-label="Student hour balance pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $studentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($buildPageUrl(['student_page' => max(1, $studentPage - 1)])) ?>">Previous</a>
                        </li>

                        <?php
                        $startPage = max(1, $studentPage - 2);
                        $endPage = min($totalStudentPages, $studentPage + 2);
                        for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                        ?>
                            <li class="page-item <?= $pageNumber === $studentPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($buildPageUrl(['student_page' => $pageNumber])) ?>"><?= $pageNumber ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $studentPage >= $totalStudentPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($buildPageUrl(['student_page' => min($totalStudentPages, $studentPage + 1)])) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card content-card">
    <div class="card-header bg-white border-0 p-4 pb-0">
        <h2 class="h5 mb-1">Recent Attendance Sessions</h2>
        <p class="small text-secondary mb-0">Attendance records for the selected month, displayed 10 per page.</p>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Student</th><th>Check In</th><th>Check Out</th><th>Status</th><th>Actual</th><th>Credited</th></tr></thead>
                <tbody>
                <?php if (!$recentSessions): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-5">No attendance sessions recorded.</td></tr>
                <?php else: foreach ($recentSessions as $session): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($session['full_name']) ?></div><small class="text-secondary"><?= e($session['school_name']) ?></small></td>
                        <td><?= e(date('d M Y, h:i A', strtotime($session['check_in_at']))) ?></td>
                        <td><?= $session['check_out_at'] ? e(date('d M Y, h:i A', strtotime($session['check_out_at']))) : '-' ?></td>
                        <td><span class="badge text-bg-<?= $session['status'] === 'open' ? 'warning' : 'success' ?>"><?= e(ucfirst($session['status'])) ?></span></td>
                        <td><?= e(formatMinutes((int) $session['actual_minutes'])) ?></td>
                        <td><?= e(formatMinutes((int) $session['credited_minutes'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mt-4">
            <div class="small text-secondary">
                Showing <?= number_format($sessionShowingFrom) ?>–<?= number_format($sessionShowingTo) ?> of <?= number_format($totalRecentSessions) ?> sessions
            </div>

            <?php if ($totalSessionPages > 1): ?>
                <nav aria-label="Attendance session pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $sessionPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($buildPageUrl(['session_page' => max(1, $sessionPage - 1)])) ?>">Previous</a>
                        </li>

                        <?php
                        $startPage = max(1, $sessionPage - 2);
                        $endPage = min($totalSessionPages, $sessionPage + 2);
                        for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                        ?>
                            <li class="page-item <?= $pageNumber === $sessionPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e($buildPageUrl(['session_page' => $pageNumber])) ?>"><?= $pageNumber ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $sessionPage >= $totalSessionPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e($buildPageUrl(['session_page' => min($totalSessionPages, $sessionPage + 1)])) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
