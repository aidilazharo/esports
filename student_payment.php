<?php
require_once 'stripe_helpers.php';

$studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);

$annualMembershipFee = defined('ANNUAL_MEMBERSHIP_FEE')
    ? (int) ANNUAL_MEMBERSHIP_FEE
    : (defined('MEMBERSHIP_ACTIVATION_FEE') ? (int) MEMBERSHIP_ACTIVATION_FEE : 10000);

$annualMembershipFlowReady = function_exists('createStudentAnnualMembershipCheckoutSession');

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

/*
 * Keep status correct even if the daily cron has not run yet.
 * An expired annual membership becomes inactive on page load.
 */
$expiryTimestamp = !empty($student['membership_next_due_at'])
    ? strtotime((string) $student['membership_next_due_at'] . ' UTC')
    : false;

if (
    strtolower((string) ($student['membership_status'] ?? '')) === 'active'
    && $expiryTimestamp !== false
    && $expiryTimestamp < time()
) {
    $pdo->prepare(
        "UPDATE students
         SET membership_status = 'inactive',
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    )->execute([$studentId]);

    $student['membership_status'] = 'inactive';
}

$expiryTimestamp = !empty($student['membership_next_due_at'])
    ? strtotime((string) $student['membership_next_due_at'] . ' UTC')
    : false;

$expiryLabel = $expiryTimestamp !== false
    ? date('d M Y', $expiryTimestamp)
    : null;

$isActive = strtolower((string) ($student['membership_status'] ?? '')) === 'active'
    && $expiryTimestamp !== false
    && $expiryTimestamp >= time();

$annualPaidStmt = $pdo->prepare(
    "SELECT MAX(paid_at)
     FROM payments
     WHERE student_id = ?
       AND payment_type = 'annual_membership'
       AND status = 'paid'"
);
$annualPaidStmt->execute([$studentId]);
$lastAnnualPaymentAt = $annualPaidStmt->fetchColumn();
$lastAnnualPaymentLabel = $lastAnnualPaymentAt
    ? date('d M Y', strtotime((string) $lastAnnualPaymentAt))
    : null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action !== 'annual_membership') {
            throw new RuntimeException('Invalid membership payment action.');
        }

        if (!$annualMembershipFlowReady) {
            throw new RuntimeException(
                'Annual membership payment module is not fully updated. Upload the new stripe_helpers.php together with student_payment.php.'
            );
        }

        redirect(createStudentAnnualMembershipCheckoutSession($pdo, $student));
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$pageTitle = 'Student Membership';
require 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="card content-card">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
                    <div>
                        <span class="badge text-bg-<?= e(membershipBadge($student['membership_status'])) ?> mb-2">
                            <?= e(membershipLabel($student['membership_status'])) ?>
                        </span>
                        <h1 class="h3 mb-1"><?= e($student['full_name']) ?></h1>
                        <div class="text-secondary">
                            <?= e($student['school_name']) ?> · <?= e($student['school_code']) ?>
                        </div>
                    </div>
                    <div class="text-md-end">
                        <div class="small text-secondary">Student No.</div>
                        <div class="fw-semibold"><?= e($student['student_number']) ?></div>
                    </div>
                </div>

                <?php if (!$annualMembershipFlowReady): ?>
                    <div class="alert alert-danger">
                        <div class="fw-semibold">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Annual membership module incomplete
                        </div>
                        Upload the updated <code>stripe_helpers.php</code> together with this file.
                        Payment is disabled until the required helper is available.
                    </div>
                <?php endif; ?>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="card border-primary mb-4">
                    <div class="card-body p-4 p-md-5 text-center">
                        <div class="mb-3">
                            <i class="bi bi-calendar-check text-primary display-5"></i>
                        </div>
                        <h2 class="h4 mb-2">1 Year Student Membership</h2>
                        <div class="display-6 fw-bold text-primary mb-2">
                            <?= e(moneyFromMinor($annualMembershipFee)) ?>
                        </div>

                        <div class="row g-3 text-start mb-4">
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="small text-secondary">Membership Fee</div>
                                    <div class="fw-semibold">RM100</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="small text-secondary">Validity</div>
                                    <div class="fw-semibold">1 Year</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="small text-secondary">Payment Methods</div>
                                    <div class="fw-semibold">Card / FPX / GrabPay</div>
                                </div>
                            </div>
                        </div>

                        <?php if ($isActive): ?>
                            <div class="alert alert-success text-start">
                                <div class="fw-semibold">
                                    <i class="bi bi-check-circle-fill me-2"></i>Membership Active
                                </div>
                                <?php if ($expiryLabel): ?>
                                    Valid until <strong><?= e($expiryLabel) ?></strong>.
                                <?php endif; ?>
                                Early renewal will extend the membership by another year from the current expiry date.
                            </div>
                        <?php elseif (!empty($student['membership_activation_paid_at']) || $lastAnnualPaymentAt): ?>
                            <div class="alert alert-warning text-start">
                                <div class="fw-semibold">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Membership Renewal Required
                                </div>
                                Pay RM100 to activate membership for another year.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-start">
                                <div class="fw-semibold">
                                    <i class="bi bi-info-circle-fill me-2"></i>Membership Payment Required
                                </div>
                                Pay RM100 once to activate this student's membership for 1 year.
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                            <input type="hidden" name="action" value="annual_membership">

                            <button class="btn btn-primary btn-lg" <?= !$annualMembershipFlowReady ? 'disabled' : '' ?>>
                                <i class="bi bi-credit-card-fill me-2"></i>
                                <?= !$annualMembershipFlowReady
                                    ? 'Payment Module Update Required'
                                    : ($isActive ? 'Extend 1 Year — RM100' : 'Pay RM100 — 1 Year Membership') ?>
                            </button>
                        </form>

                        <p class="small text-secondary mt-3 mb-0">
                            FPX and GrabPay must be enabled in Stripe and are displayed only when eligible.
                        </p>
                    </div>
                </div>

                <div class="border rounded-4 p-4 mb-4">
                    <h2 class="h5 mb-3">Membership Status</h2>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Status</span>
                        <strong><?= e(membershipLabel($student['membership_status'])) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Current / next expiry</span>
                        <strong><?= e($expiryLabel ?: '-') ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Last annual payment</span>
                        <strong><?= e($lastAnnualPaymentLabel ?: '-') ?></strong>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <?php if ($isActive): ?>
                        <a href="student_qr.php?student_id=<?= (int) $student['id'] ?>" class="btn btn-outline-info">
                            <i class="bi bi-qr-code me-2"></i>Attendance QR
                        </a>
                    <?php endif; ?>
                    <a href="school_report.php?school_id=<?= (int) $student['school_id'] ?>" class="btn btn-outline-secondary">
                        School Report
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>
