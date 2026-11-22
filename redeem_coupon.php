<?php
require_once 'config.php';

/** Normalize a membership coupon code for consistent lookup/storage. */
function normalizeRedeemCouponCode(string $code): string
{
    $code = strtoupper(trim($code));
    return preg_replace('/\s+/', '', $code) ?? '';
}

/** Return a coupon row by code. Use FOR UPDATE while redeeming. */
function getRedeemCoupon(PDO $pdo, string $code, bool $lock = false): ?array
{
    $code = normalizeRedeemCouponCode($code);
    if ($code === '') {
        return null;
    }

    $sql = 'SELECT * FROM coupons WHERE code = ? LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code]);
    $coupon = $stmt->fetch();

    return $coupon ?: null;
}

/** Return null when redeemable, otherwise a user-facing validation message. */
function redeemCouponValidationError(?array $coupon): ?string
{
    if (!$coupon) {
        return 'Coupon code is invalid.';
    }

    if (empty($coupon['is_active'])) {
        return 'This coupon is no longer active.';
    }

    $now = time();

    if (!empty($coupon['starts_at'])) {
        $startsAt = strtotime((string) $coupon['starts_at']);
        if ($startsAt !== false && $startsAt > $now) {
            return 'This coupon is not active yet.';
        }
    }

    if (!empty($coupon['expires_at'])) {
        $expiresAt = strtotime((string) $coupon['expires_at']);
        if ($expiresAt !== false && $expiresAt < $now) {
            return 'This coupon has expired.';
        }
    }

    $maxRedemptions = $coupon['max_redemptions'] === null
        ? null
        : (int) $coupon['max_redemptions'];
    $redeemedCount = (int) ($coupon['redeemed_count'] ?? 0);

    if ($maxRedemptions !== null && $maxRedemptions > 0 && $redeemedCount >= $maxRedemptions) {
        return 'This coupon has reached its redemption limit.';
    }

    return null;
}

function couponStudentAlreadyRedeemed(PDO $pdo, int $couponId, int $studentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT id FROM coupon_redemptions WHERE coupon_id = ? AND student_id = ? LIMIT 1'
    );
    $stmt->execute([$couponId, $studentId]);
    return (bool) $stmt->fetchColumn();
}

function couponRedeemableStudentStatus(string $status): bool
{
    return in_array(
        strtolower(trim($status)),
        ['pending_activation', 'pending_payment', 'inactive', 'active', 'registered'],
        true
    );
}

$studentId = (int) ($_POST['student_id'] ?? $_GET['student_id'] ?? 0);

if ($studentId <= 0) {
    setFlash('danger', 'Student membership was not found.');
    redirect('memberships.php');
    exit;
}

$studentStmt = $pdo->prepare(
    'SELECT st.*, s.school_name, s.school_code
     FROM students st
     JOIN schools s ON s.id = st.school_id
     WHERE st.id = ?
     LIMIT 1'
);
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();

if (!$student) {
    setFlash('danger', 'Student membership was not found.');
    redirect('memberships.php');
    exit;
}

// AJAX pre-check used by the Validate Coupon button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_coupon') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        verifyCsrf();

        if (!couponRedeemableStudentStatus((string) ($student['membership_status'] ?? ''))) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'This student membership cannot redeem a coupon in its current status.',
            ]);
            exit;
        }

        $couponCode = normalizeRedeemCouponCode((string) ($_POST['coupon_code'] ?? ''));

        if ($couponCode === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Enter a coupon code first.']);
            exit;
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,63}$/', $couponCode)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Coupon code format is invalid.']);
            exit;
        }

        $coupon = getRedeemCoupon($pdo, $couponCode);
        $couponError = redeemCouponValidationError($coupon);

        if ($couponError !== null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $couponError]);
            exit;
        }

        if (couponStudentAlreadyRedeemed($pdo, (int) $coupon['id'], $studentId)) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'This coupon has already been redeemed for this student.',
            ]);
            exit;
        }

        $maxRedemptions = $coupon['max_redemptions'] === null
            ? null
            : (int) $coupon['max_redemptions'];
        $redeemedCount = (int) ($coupon['redeemed_count'] ?? 0);
        $remaining = $maxRedemptions === null
            ? null
            : max(0, $maxRedemptions - $redeemedCount);

        echo json_encode([
            'success' => true,
            'code' => (string) $coupon['code'],
            'description' => trim((string) ($coupon['description'] ?? '')),
            'remaining_redemptions' => $remaining,
            'coupon_expires_at' => $coupon['expires_at'] ?: null,
            'message' => 'Coupon is valid for a 1-year membership.',
        ]);
    } catch (PDOException $e) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Coupon validation is unavailable. Please ensure the coupon database migration has been applied.',
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to validate this coupon right now.']);
    }
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redeem_coupon') {
    verifyCsrf();

    $couponCode = normalizeRedeemCouponCode((string) ($_POST['coupon_code'] ?? ''));

    if ($couponCode === '') {
        $errors[] = 'Enter a coupon code.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,63}$/', $couponCode)) {
        $errors[] = 'Coupon code format is invalid.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $lockedStudentStmt = $pdo->prepare(
                'SELECT id, student_number, full_name, membership_status, membership_next_due_at
                 FROM students
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $lockedStudentStmt->execute([$studentId]);
            $lockedStudent = $lockedStudentStmt->fetch();

            if (!$lockedStudent) {
                throw new RuntimeException('Student membership was not found.');
            }

            if (!couponRedeemableStudentStatus((string) ($lockedStudent['membership_status'] ?? ''))) {
                throw new RuntimeException('This student membership cannot redeem a coupon in its current status.');
            }

            $coupon = getRedeemCoupon($pdo, $couponCode, true);
            $couponError = redeemCouponValidationError($coupon);
            if ($couponError !== null) {
                throw new RuntimeException($couponError);
            }

            if (couponStudentAlreadyRedeemed($pdo, (int) $coupon['id'], $studentId)) {
                throw new RuntimeException('This coupon has already been redeemed for this student.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $baseDate = $now;

            if (!empty($lockedStudent['membership_next_due_at'])) {
                $currentExpiry = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    (string) $lockedStudent['membership_next_due_at'],
                    new DateTimeZone('UTC')
                );

                if ($currentExpiry instanceof DateTimeImmutable && $currentExpiry > $now) {
                    $baseDate = $currentExpiry;
                }
            }

            $membershipExpiresAt = $baseDate->modify('+1 year')->format('Y-m-d H:i:s');

            $updateStudent = $pdo->prepare(
                "UPDATE students
                 SET membership_status = 'active',
                     membership_next_due_at = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $updateStudent->execute([$membershipExpiresAt, $studentId]);

            $insertRedemption = $pdo->prepare(
                'INSERT INTO coupon_redemptions
                 (coupon_id, student_id, coupon_code, membership_years, membership_expires_at, redeemed_at)
                 VALUES (?, ?, ?, 1, ?, CURRENT_TIMESTAMP)'
            );
            $insertRedemption->execute([
                (int) $coupon['id'],
                $studentId,
                (string) $coupon['code'],
                $membershipExpiresAt,
            ]);

            $pdo->prepare(
                'UPDATE coupons
                 SET redeemed_count = redeemed_count + 1,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([(int) $coupon['id']]);

            $pdo->commit();

            setFlash(
                'success',
                'Coupon ' . (string) $coupon['code'] . ' redeemed successfully for student '
                . (string) $lockedStudent['student_number'] . '. Membership is active until '
                . date('d M Y', strtotime($membershipExpiresAt)) . '.'
            );
            redirect('memberships.php?search=' . rawurlencode((string) $lockedStudent['student_number']));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $errors[] = 'This coupon has already been redeemed for this student.';
            } elseif ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                $errors[] = 'Unable to redeem this coupon. Please ensure the coupon database migration has been applied.';
            }
        }
    }
}

$pageTitle = 'Redeem Membership Coupon';
require 'includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-xl-8 col-lg-9">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h2 mb-1">Redeem Membership Coupon</h1>
                <p class="text-secondary mb-0">Activate one year of student membership without the RM100 payment.</p>
            </div>
            <a href="memberships.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Memberships
            </a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card content-card mb-4">
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-7">
                        <div class="text-secondary small">Student</div>
                        <div class="fw-semibold fs-5"><?= e($student['full_name']) ?></div>
                        <div class="text-secondary"><?= e($student['student_number']) ?></div>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <div class="text-secondary small">School</div>
                        <div class="fw-semibold"><?= e($student['school_name']) ?></div>
                        <div class="small text-secondary"><?= e($student['school_code']) ?></div>
                    </div>
                    <div class="col-12">
                        <span class="badge text-bg-<?= e(membershipBadge($student['membership_status'])) ?>">
                            <?= e(membershipLabel($student['membership_status'])) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!couponRedeemableStudentStatus((string) ($student['membership_status'] ?? ''))): ?>
            <div class="alert alert-warning">
                This membership cannot redeem a coupon in its current status.
            </div>
        <?php else: ?>
            <div class="card content-card">
                <div class="card-body p-4 p-md-5">
                    <form method="post" id="redeemCouponForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
                        <input type="hidden" name="action" value="redeem_coupon">

                        <div class="col-12">
                            <label class="form-label required" for="couponCode">Coupon Code</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-ticket-perforated"></i></span>
                                <input
                                    class="form-control text-uppercase fw-semibold"
                                    id="couponCode"
                                    name="coupon_code"
                                    maxlength="64"
                                    autocomplete="off"
                                    value="<?= e($_POST['coupon_code'] ?? '') ?>"
                                    placeholder="Enter coupon code"
                                    required
                                >
                                <button class="btn btn-outline-primary" type="button" id="validateCouponButton">
                                    <i class="bi bi-check-circle me-1"></i>Validate Coupon
                                </button>
                            </div>
                            <div class="form-text" id="couponStatus">
                                Validate the coupon before redeeming. The code is checked again when you submit.
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="alert alert-light border mb-0">
                                <i class="bi bi-info-circle me-2"></i>
                                A valid coupon activates <strong>1 year of membership</strong> with <strong>RM0 payment</strong>.
                            </div>
                        </div>

                        <div class="col-12 d-flex flex-wrap gap-2 mt-4">
                            <button class="btn btn-success" type="submit" id="redeemCouponButton" disabled>
                                <i class="bi bi-ticket-perforated-fill me-2"></i>Redeem Coupon & Activate Membership
                            </button>
                            <a href="student_payment.php?student_id=<?= (int) $studentId ?>" class="btn btn-outline-primary">
                                <i class="bi bi-credit-card me-2"></i>Pay RM100 Instead
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (couponRedeemableStudentStatus((string) ($student['membership_status'] ?? ''))): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('redeemCouponForm');
    const couponCode = document.getElementById('couponCode');
    const validateButton = document.getElementById('validateCouponButton');
    const redeemButton = document.getElementById('redeemCouponButton');
    const couponStatus = document.getElementById('couponStatus');
    const csrfToken = form?.querySelector('input[name="csrf_token"]');
    const studentId = form?.querySelector('input[name="student_id"]');
    let validatedCode = '';

    const resetValidation = () => {
        validatedCode = '';
        if (redeemButton) redeemButton.disabled = true;
        if (couponStatus) {
            couponStatus.textContent = 'Validate the coupon before redeeming. The code is checked again when you submit.';
            couponStatus.className = 'form-text text-secondary';
        }
    };

    couponCode?.addEventListener('input', () => {
        couponCode.value = couponCode.value.toUpperCase().replace(/\s+/g, '');
        resetValidation();
    });

    validateButton?.addEventListener('click', async () => {
        const code = couponCode?.value.trim() || '';
        if (!code) {
            couponStatus.textContent = 'Enter a coupon code first.';
            couponStatus.className = 'form-text text-danger fw-semibold';
            couponCode?.focus();
            return;
        }

        validateButton.disabled = true;
        validateButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Checking...';
        couponStatus.textContent = 'Checking coupon...';
        couponStatus.className = 'form-text text-secondary';
        redeemButton.disabled = true;

        try {
            const body = new URLSearchParams();
            body.set('action', 'validate_coupon');
            body.set('student_id', studentId?.value || '');
            body.set('coupon_code', code);
            body.set('csrf_token', csrfToken?.value || '');

            const response = await fetch('redeem_coupon.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                },
                body: body.toString(),
                cache: 'no-store',
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Coupon is invalid.');
            }

            validatedCode = String(data.code || code).toUpperCase();
            couponCode.value = validatedCode;

            const details = [];
            if (data.description) details.push(data.description);
            if (data.remaining_redemptions !== null && data.remaining_redemptions !== undefined) {
                details.push(`${data.remaining_redemptions} redemption(s) remaining`);
            }
            if (data.coupon_expires_at) {
                const expires = new Date(String(data.coupon_expires_at).replace(' ', 'T'));
                if (!Number.isNaN(expires.getTime())) {
                    details.push(`Coupon expires ${expires.toLocaleDateString()}`);
                }
            }

            couponStatus.textContent = `Valid coupon. 1-year membership will be activated with RM0 payment.${details.length ? ' ' + details.join(' · ') : ''}`;
            couponStatus.className = 'form-text text-success fw-semibold';
            redeemButton.disabled = false;
        } catch (error) {
            validatedCode = '';
            couponStatus.textContent = error.message || 'Unable to validate coupon.';
            couponStatus.className = 'form-text text-danger fw-semibold';
            redeemButton.disabled = true;
        } finally {
            validateButton.disabled = false;
            validateButton.innerHTML = '<i class="bi bi-check-circle me-1"></i>Validate Coupon';
        }
    });

    form?.addEventListener('submit', (event) => {
        const code = couponCode?.value.trim().toUpperCase() || '';
        if (!validatedCode || code !== validatedCode) {
            event.preventDefault();
            couponStatus.textContent = 'Please validate this coupon code before redeeming.';
            couponStatus.className = 'form-text text-danger fw-semibold';
            redeemButton.disabled = true;
        }
    });
});
</script>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
