<?php
require_once 'config.php';

$studentId = (int) ($_GET['student_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT st.*, s.school_name, s.school_code
     FROM students st
     JOIN schools s ON s.id = st.school_id
     WHERE st.id = ?'
);
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    $pageTitle = 'Student Not Found';
    require 'includes/header.php';
    echo '<div class="alert alert-danger">Student not found.</div>';
    require 'includes/footer.php';
    exit;
}

$qrToken = ensureStudentQrToken($pdo, (int) $student['id'], $student['qr_token'] ?? null);
$qrValue = 'ESPORTS:' . $qrToken;
$pageTitle = 'Student Attendance QR';
require 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div><h1 class="h2 mb-1">Student Attendance QR</h1><p class="text-secondary mb-0">Print or save this QR card for attendance scanning.</p></div>
    <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-2"></i>Print</button>
</div>

<div class="card content-card qr-card qr-print-card">
    <div class="card-body p-4 p-md-5 text-center">
        <div class="small text-uppercase text-secondary fw-semibold mb-2">School Esports Member</div>
        <h2 class="h4 mb-1"><?= e($student['full_name']) ?></h2>
        <div class="text-secondary mb-3"><?= e($student['student_number']) ?> · <?= e($student['class_name']) ?></div>
        <div class="mb-3"><span class="badge text-bg-<?= e(membershipBadge($student['membership_status'])) ?>"><?= e(membershipLabel($student['membership_status'])) ?></span></div>
        <div id="qr-code" class="d-flex justify-content-center my-4"></div>
        <div class="fw-semibold"><?= e($student['school_name']) ?></div>
        <div class="small text-secondary"><?= e($student['school_code']) ?></div>
        <hr>
        <div class="small text-secondary">Scan once when entering and scan again when leaving.</div>
    </div>
</div>

<div class="text-center mt-4 no-print">
    <a href="attendance.php" class="btn btn-outline-primary">Back to Attendance</a>
    <a href="learning_modules.php?student_id=<?= (int) $student['id'] ?>" class="btn btn-primary">Learning Progress</a>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr-code'), {
    text: <?= json_encode($qrValue) ?>,
    width: 280,
    height: 280,
    correctLevel: QRCode.CorrectLevel.H
});
</script>
<?php require 'includes/footer.php'; ?>
