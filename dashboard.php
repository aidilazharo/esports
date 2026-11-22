<?php
require_once 'config.php';
require_once 'includes/admin_auth.php';

requireAdminAccess('Dashboard');

$currentAttendanceMonth = date('Y-m-01');

$stats = [
    'schools' => (int) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn(),
    'students' => (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'active' => (int) $pdo->query("SELECT COUNT(*) FROM students WHERE membership_status = 'active'")->fetchColumn(),
    'non_active' => (int) $pdo->query("SELECT COUNT(*) FROM students WHERE membership_status IN ('inactive', 'cancelled')")->fetchColumn(),
    'pending_registration' => (int) $pdo->query("SELECT COUNT(*) FROM students WHERE membership_status IN ('pending_registration', 'pending_payment')")->fetchColumn(),
    'registered' => (int) $pdo->query("SELECT COUNT(*) FROM students WHERE membership_status IN ('registered', 'pending_activation')")->fetchColumn(),
    'revenue' => (int) $pdo->query("SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE status = 'paid'")->fetchColumn(),
    'attendance_minutes' => (int) $pdo->query("SELECT COALESCE(SUM(credited_minutes), 0) FROM attendance_sessions WHERE attendance_month = '" . $currentAttendanceMonth . "' AND status = 'completed'")->fetchColumn(),
    'checked_in' => (int) $pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE status = 'open'")->fetchColumn(),
    'modules_completed' => (int) $pdo->query("SELECT COUNT(*) FROM student_module_progress WHERE progress_status = 'completed'")->fetchColumn(),
];

$schoolSummary = $pdo->query(
    "SELECT s.id, s.school_code, s.school_name, s.state,
        COUNT(DISTINCT st.id) AS student_count,
        COUNT(DISTINCT CASE WHEN st.membership_status = 'active' THEN st.id END) AS active_student_count,
        COUNT(DISTINCT t.id) AS teacher_count,
        COUNT(DISTINCT c.id) AS coach_count
     FROM schools s
     LEFT JOIN students st ON st.school_id = s.id
     LEFT JOIN teachers t ON t.school_id = s.id
     LEFT JOIN coaches c ON c.school_id = s.id
     GROUP BY s.id
     ORDER BY active_student_count DESC, s.school_name
     LIMIT 20"
)->fetchAll();

$pageTitle = 'Dashboard';
require 'includes/header.php';
?>
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
    <div><h1 class="h2 mb-1">Dashboard</h1><p class="text-secondary mb-0">Membership, Stripe payment and school reward overview.</p></div>
    <div class="d-flex flex-wrap gap-2"><a href="attendance_scanner.php" class="btn btn-outline-success"><i class="bi bi-qr-code-scan me-2"></i>Scan</a><a href="attendance.php" class="btn btn-outline-primary">Attendance</a><a href="register_student.php" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Student</a></div>
</div>

<div class="row g-4 mb-4">
    <?php
    $cards = [
        ['Schools', $stats['schools'], 'bi-buildings', 'primary'],
        ['Total Students', $stats['students'], 'bi-people', 'info'],
        ['Active Students', $stats['active'], 'bi-person-check', 'success'],
        ['Non-Active', $stats['non_active'], 'bi-person-x', 'danger'],
        ['Pending Registration', $stats['pending_registration'], 'bi-hourglass-split', 'warning'],
        ['Registered, Not Active', $stats['registered'], 'bi-person-vcard', 'info'],
        ['Payments Received', moneyFromMinor($stats['revenue']), 'bi-cash-stack', 'primary'],
        ['Hours Used This Month', formatMinutes($stats['attendance_minutes']), 'bi-clock-history', 'info'],
        ['Currently Checked In', $stats['checked_in'], 'bi-person-badge', 'warning'],
        ['Modules Completed', $stats['modules_completed'], 'bi-journal-check', 'success'],
    ];
    foreach ($cards as [$label, $count, $icon, $color]):
    ?>
    <div class="col-sm-6 col-xl-4">
        <div class="card stat-card h-100"><div class="card-body p-4 d-flex justify-content-between align-items-center">
            <div><div class="text-secondary small mb-1"><?= e($label) ?></div><div class="display-6 fw-semibold"><?= is_int($count) ? number_format($count) : e($count) ?></div></div>
            <div class="icon-box bg-<?= e($color) ?>-subtle text-<?= e($color) ?>"><i class="bi <?= e($icon) ?>"></i></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card content-card">
    <div class="card-header bg-white border-0 p-4 pb-0 d-flex justify-content-between align-items-center">
        <div><h2 class="h5 mb-1">School Membership & Reward Summary</h2><p class="text-secondary small mb-0">Equipment eligibility is calculated from active paid students.</p></div>
        <a href="school_report.php" class="btn btn-sm btn-outline-primary">All Reports</a>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>School</th><th>State</th><th class="text-center">Total</th><th class="text-center">Active</th><th>Equipment Eligibility</th><th></th></tr></thead>
                <tbody>
                <?php if (!$schoolSummary): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-5">No schools registered yet.</td></tr>
                <?php else: foreach ($schoolSummary as $school): $reward = schoolReward((int) $school['active_student_count']); ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($school['school_name']) ?></div><small class="text-secondary"><?= e($school['school_code']) ?></small></td>
                        <td><?= e($school['state']) ?></td>
                        <td class="text-center"><?= (int) $school['student_count'] ?></td>
                        <td class="text-center fw-semibold text-success"><?= (int) $school['active_student_count'] ?></td>
                        <td><span class="badge text-bg-<?= e($reward['badge']) ?>"><?= e($reward['level']) ?></span><div class="small text-secondary mt-1"><?= e($reward['description']) ?></div></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="school_report.php?school_id=<?= (int) $school['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
