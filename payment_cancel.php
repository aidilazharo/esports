<?php
require_once 'config.php';
$studentId = (int) ($_GET['student_id'] ?? 0);
$stage = trim($_GET['stage'] ?? 'payment');
$message = match ($stage) {
    'registration' => 'The RM10 registration payment was not completed. The student remains pending registration.',
    'activation' => 'The RM100 membership activation was not completed. The student remains registered but non-active.',
    'restart' => 'The RM30 monthly membership restart was not completed.',
    default => 'No payment has been recorded. The student remains pending or non-active.',
};
$pageTitle = 'Payment Cancelled';
require 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-7"><div class="card content-card"><div class="card-body p-5 text-center">
    <div class="cancel-icon mx-auto mb-4"><i class="bi bi-x-lg"></i></div>
    <h1 class="h2">Payment not completed</h1>
    <p class="text-secondary"><?= e($message) ?></p>
    <?php if ($studentId > 0): ?><a class="btn btn-success" href="student_payment.php?student_id=<?= $studentId ?>">Return to Payment Page</a><?php endif; ?>
    <a class="btn btn-outline-secondary" href="dashboard.php">Dashboard</a>
</div></div></div></div>
<?php require 'includes/footer.php'; ?>
