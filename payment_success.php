<?php
require_once 'stripe_helpers.php';

$sessionId = trim((string) ($_GET['session_id'] ?? ''));
$student = null;
$error = null;
$paymentStage = '';
$successTitle = 'Payment received';
$successMessage = 'Stripe is confirming the payment through the secure webhook.';

if ($sessionId !== '') {
    try {
        $session = stripeClient()->checkout->sessions->retrieve($sessionId, []);
        $paymentStage = (string) ($session->metadata->payment_stage ?? '');
        $studentId = (int) (
            $session->client_reference_id
            ?: ($session->metadata->student_id ?? 0)
        );

        if ($studentId > 0) {
            // Save only safe Stripe linkage here. Membership dates/status are
            // changed by stripe_webhook.php so the annual period cannot be
            // accidentally extended twice.
            $stmt = $pdo->prepare(
                'UPDATE students
                 SET stripe_customer_id = COALESCE(?, stripe_customer_id),
                     stripe_checkout_session_id = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );
            $stmt->execute([
                $session->customer ?: null,
                $session->id,
                $studentId,
            ]);

            if ($paymentStage === 'annual_membership') {
                if ($session->payment_status === 'paid') {
                    $successTitle = 'Membership Payment Received';
                    $successMessage = 'The RM100 payment was received. The membership will be active for 1 year after Stripe webhook confirmation.';
                } else {
                    $successTitle = 'Membership Payment Processing';
                    $successMessage = 'Stripe is processing the RM100 one-year membership payment.';
                }
            }

            $stmt = $pdo->prepare(
                'SELECT st.*, s.school_name, s.school_code
                 FROM students st
                 JOIN schools s ON s.id = st.school_id
                 WHERE st.id = ?'
            );
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Payment Successful';
require 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-body p-5 text-center">
                <div class="success-icon mx-auto mb-4">
                    <i class="bi bi-check-lg"></i>
                </div>

                <h1 class="h2"><?= e($successTitle) ?></h1>
                <p class="text-secondary"><?= e($successMessage) ?></p>

                <?php if ($student): ?>
                    <div class="border rounded-4 p-3 my-4 text-start">
                        <div class="fw-semibold"><?= e($student['full_name']) ?></div>
                        <div class="text-secondary">
                            <?= e($student['school_name']) ?> · <?= e($student['student_number']) ?>
                        </div>
                        <div class="mt-2">
                            <span class="badge text-bg-<?= e(membershipBadge($student['membership_status'])) ?>">
                                <?= e(membershipLabel($student['membership_status'])) ?>
                            </span>
                        </div>
                    </div>

                    <a class="btn btn-primary" href="student_payment.php?student_id=<?= (int) $student['id'] ?>">
                        View Membership
                    </a>

                    <?php if ($student['membership_status'] === 'active'): ?>
                        <a class="btn btn-outline-info" href="student_qr.php?student_id=<?= (int) $student['id'] ?>">
                            Open Attendance QR
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert alert-warning text-start"><?= e($error) ?></div>
                    <?php endif; ?>
                    <a class="btn btn-primary" href="dashboard.php">Open Dashboard</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
