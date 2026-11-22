<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/registration_api_cache.php';
require_once __DIR__ . '/registration_contact_verification.php';

$errors = [];
$serviceStatus = registrationVerificationServiceStatus();
$serviceAvailable = (bool) ($serviceStatus['available'] ?? false);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'send_parent_email_code') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        verifyCsrf();
        $response = registrationRequestEmailCode(
            'parent_email_verification',
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['full_name'] ?? ''),
            'Parent / Guardian'
        );
        http_response_code((int) ($response['status'] ?? 200));
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        error_log('Parent email verification request failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to send the email code.']);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();

    $fullName = registrationUppercaseInput((string) ($_POST['full_name'] ?? ''));
    $identity = registrationNormalizeApiIdentity((string) ($_POST['identity_number'] ?? ''));
    $phone = registrationNormalizeSmsNumber((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $emailCode = preg_replace('/[^0-9]/', '', (string) ($_POST['email_code'] ?? '')) ?? '';

    if ($fullName === '') $errors[] = 'Parent / guardian name is required.';
    if ($identity === '') $errors[] = 'NRIC / Passport is required.';
    if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 15) $errors[] = 'A valid contact mobile number is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!$serviceAvailable) $errors[] = 'Email verification is unavailable. Please try again later.';

    if (!$errors) {
        $check = registrationVerifyEmailCode('parent_email_verification', $email, $emailCode);
        if (!$check['success']) $errors[] = (string) $check['message'];
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT id FROM guardians
                 WHERE identity_number = ?
                   OR UPPER(REPLACE(REPLACE(COALESCE(identity_number,''),'-',''),' ','')) = ?
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$identity, $identity]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('This NRIC / Passport is already registered as a parent / guardian.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO guardians
                 (guardian_number, full_name, identity_number, phone, email, phone_verified_at, email_verified_at)
                 VALUES (NULL, ?, ?, ?, ?, NULL, NOW())'
            );
            $stmt->execute([$fullName, $identity, $phone, $email]);
            $guardianId = (int) $pdo->lastInsertId();
            $guardianNumber = 'PG-' . str_pad((string) $guardianId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE guardians SET guardian_number = ? WHERE id = ?')
                ->execute([$guardianNumber, $guardianId]);
            $pdo->commit();
            unset($_SESSION['parent_email_verification']);
            registrationIdentityCacheInvalidate($identity);
            setFlash('success', 'Parent / Guardian ' . $guardianNumber . ' registered successfully. Email verified. You may sign in to the Parent Portal using this email.');
            redirect('register_parent.php');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Parent registration failed: ' . $error->getMessage());
            $errors[] = $error instanceof RuntimeException ? $error->getMessage() : 'Unable to complete parent registration.';
        }
    }
}

$pageTitle = 'Parent / Guardian Registration';
require 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-xl-8"><div class="card content-card">
<div class="card-body p-4 p-md-5">
<h1 class="h3 mb-1">Parent / Guardian Registration</h1>
<p class="text-secondary mb-4">Register using your email address. We verify your email, not your mobile number.</p>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$serviceAvailable): ?>
<div class="alert alert-warning">Email verification is temporarily unavailable. Registration cannot be completed until email delivery is restored.</div>
<?php endif; ?>
<form method="post" class="row g-3 registration-uppercase" id="parentRegistrationForm">
<input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
<div class="col-md-8"><label class="form-label required" for="parentFullName">Full Name</label>
<input id="parentFullName" class="form-control" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" required></div>
<div class="col-md-4"><label class="form-label required" for="parentIdentityNumber">NRIC / Passport</label>
<input id="parentIdentityNumber" class="form-control" name="identity_number" data-registration-identity="1" data-identity-role="parent" data-status-target="parentIdentityStatus" value="<?= e($_POST['identity_number'] ?? '') ?>" required>
<div class="form-text" id="parentIdentityStatus">The parent role is checked independently of other roles.</div></div>
<div class="col-md-6"><label class="form-label required" for="parentEmail">Email</label>
<div class="input-group"><input type="email" class="form-control" id="parentEmail" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
<button class="btn btn-outline-primary" type="button" id="sendParentCode" <?= !$serviceAvailable ? 'disabled' : '' ?>>Send Email Code</button></div>
<div class="form-text" id="parentCodeStatus">Enter your email and request a six-digit verification code.</div></div>
<div class="col-md-6"><label class="form-label required" for="parentPhone">Mobile Number (contact only)</label>
<input class="form-control" id="parentPhone" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" inputmode="tel" placeholder="60123456789" required></div>
<div class="col-md-6"><label class="form-label required" for="parentEmailCode">Email Verification Code</label>
<input class="form-control" id="parentEmailCode" name="email_code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" value="" required>
<div class="form-text">The code expires in 10 minutes. Check your inbox or spam folder.</div></div>
<div class="col-12"><button class="btn btn-primary" type="submit" <?= !$serviceAvailable ? 'disabled' : '' ?>><i class="bi bi-person-plus me-2"></i>Register Parent / Guardian</button>
<a class="btn btn-outline-secondary" href="register.php">Other Roles</a></div>
</form></div></div></div></div>
<script src="assets/js/registration_common.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const send = document.getElementById('sendParentCode');
    const email = document.getElementById('parentEmail');
    const code = document.getElementById('parentEmailCode');
    const status = document.getElementById('parentCodeStatus');
    let unlock = null;
    email?.addEventListener('input', () => { if (code) code.value = ''; });
    send?.addEventListener('click', async () => {
        if (!email.checkValidity()) { email.reportValidity(); return; }
        send.disabled = true;
        status.textContent = 'Sending email verification code...';
        status.className = 'form-text text-secondary';
        try {
            const body = new URLSearchParams({
                action: 'send_parent_email_code',
                email: email.value,
                full_name: document.getElementById('parentFullName').value,
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            });
            const response = await fetch('register_parent.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
                body: body.toString(), cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Unable to send email code.');
            code.value = '';
            code.focus();
            status.textContent = data.message || 'Email code sent.';
            status.className = 'form-text text-success';
            clearTimeout(unlock);
            unlock = setTimeout(() => { send.disabled = false; }, (Number(data.retry_after) || 60) * 1000);
        } catch (error) {
            status.textContent = error.message || 'Unable to send email code.';
            status.className = 'form-text text-danger';
            send.disabled = false;
        }
    });
});
</script>
<?php require 'includes/footer.php'; ?>
