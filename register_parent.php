<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/registration_api_cache.php';
require_once __DIR__ . '/registration_contact_verification.php';

$errors = [];
$verificationServiceStatus = registrationVerificationServiceStatus();
$verificationServiceAvailable = (bool)($verificationServiceStatus['available'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_parent_sms_code') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        verifyCsrf();

        $result = registrationRequestSmsCodeWithEmailFallback(
            'parent_sms_verification',
            (string)($_POST['phone'] ?? ''),
            (string)($_POST['email'] ?? ''),
            (string)($_POST['full_name'] ?? ''),
            'parent-registration-verification',
            'Parent / Guardian'
        );

        http_response_code((int)($result['status'] ?? 200));
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Unable to send verification code.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'send_parent_sms_code') {
    verifyCsrf();

    $fullName = registrationUppercaseInput((string)($_POST['full_name'] ?? ''));
    $identityNumber = registrationNormalizeApiIdentity((string)($_POST['identity_number'] ?? ''));
    $phone = registrationNormalizeSmsNumber((string)($_POST['phone'] ?? ''));
    $email = registrationUppercaseInput((string)($_POST['email'] ?? ''));
    $smsCode = preg_replace('/\D+/', '', (string)($_POST['sms_code'] ?? '')) ?? '';
    $skipPhoneVerification = isset($_POST['skip_phone_verification']) || !$verificationServiceAvailable;

    if ($fullName === '') $errors[] = 'Parent / guardian full name is required.';
    if ($identityNumber === '') $errors[] = 'NRIC / Passport is required.';
    if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 15) $errors[] = 'A valid mobile number is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';

    if (!$errors) {
        $stmt = $pdo->prepare(
            "SELECT id,guardian_number
             FROM guardians
             WHERE identity_number=?
                OR UPPER(REPLACE(REPLACE(COALESCE(identity_number,''),'-',''),' ',''))=?
             LIMIT 1"
        );
        $stmt->execute([$identityNumber,$identityNumber]);
        if ($stmt->fetch()) {
            $errors[] = 'This NRIC / Passport is already registered as a parent / guardian. You may still register the same NRIC under a different role.';
        }
    }

    $phoneVerifiedAt = null;
    if (!$skipPhoneVerification && !$errors) {
        $check = registrationVerifyStaffCode('parent_sms_verification', $phone, $smsCode);
        if (!$check['success']) {
            $errors[] = (string)$check['message'];
        } else {
            $phoneVerifiedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id FROM guardians
                 WHERE identity_number=?
                    OR UPPER(REPLACE(REPLACE(COALESCE(identity_number,''),'-',''),' ',''))=?
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$identityNumber,$identityNumber]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('This NRIC / Passport is already registered as a parent / guardian.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO guardians
                 (guardian_number,full_name,identity_number,phone,email,phone_verified_at,email_verified_at)
                 VALUES (NULL,?,?,?,?,?,NULL)'
            );
            $stmt->execute([$fullName,$identityNumber,$phone,$email,$phoneVerifiedAt]);

            $guardianId = (int)$pdo->lastInsertId();
            $guardianNumber = 'PG-' . str_pad((string)$guardianId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE guardians SET guardian_number=? WHERE id=?')->execute([$guardianNumber,$guardianId]);

            $pdo->commit();
            registrationIdentityCacheInvalidate($identityNumber);
            unset($_SESSION['parent_sms_verification']);

            setFlash(
                $skipPhoneVerification ? 'warning' : 'success',
                'Parent / Guardian ' . $guardianNumber . ' registered successfully.'
                . ($skipPhoneVerification ? ' Phone verification was skipped and can be completed later.' : ' Mobile number verified.')
                . ' You may use the Parent Portal login with this email address.'
            );
            redirect('register_parent.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to register the parent / guardian at this time.';
            error_log('Parent registration error: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Parent / Guardian Registration';
require 'includes/header.php';
?>
<div class="row justify-content-center">
<div class="col-xl-8">
<div class="card content-card">
<div class="card-body p-4 p-md-5">
<h1 class="h3 mb-1">Parent / Guardian Registration</h1>
<p class="text-secondary mb-4">The same NRIC / Passport may also register separately as a Student or Teacher. Roles are independent.</p>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul></div>
<?php endif; ?>

<form method="post" class="row g-3 registration-uppercase" id="parentRegistrationForm">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>">

<div class="col-md-8">
<label class="form-label required">Full Name</label>
<input class="form-control" name="full_name" value="<?=e($_POST['full_name']??'')?>" required>
</div>

<div class="col-md-4">
<label class="form-label required">NRIC / Passport</label>
<input class="form-control" id="parentIdentityNumber" name="identity_number"
       data-registration-identity="1" data-identity-role="parent" data-status-target="parentIdentityStatus"
       value="<?=e($_POST['identity_number']??'')?>" required>
<div class="form-text" id="parentIdentityStatus">CHECKS ONLY THE PARENT ROLE. OTHER ROLES DO NOT BLOCK THIS REGISTRATION.</div>
</div>

<div class="col-md-6">
<label class="form-label required">Email</label>
<input type="email" class="form-control" id="parentEmail" name="email" value="<?=e($_POST['email']??'')?>" required>
</div>

<div class="col-md-6">
<label class="form-label required">Mobile Number</label>
<div class="input-group">
<input class="form-control" id="parentPhone" name="phone" value="<?=e($_POST['phone']??'')?>" inputmode="tel" placeholder="60123456789" required>
<button class="btn btn-outline-primary" type="button" id="sendParentCode"><i class="bi bi-chat-dots me-1"></i>Send Code</button>
</div>
<div class="form-text" id="parentCodeStatus">PHONE VERIFICATION IS SKIPPED BY DEFAULT.</div>
</div>

<div class="col-md-6">
<label class="form-label">Verification Code</label>
<input class="form-control" id="parentSmsCode" name="sms_code" inputmode="numeric" maxlength="6" placeholder="6-DIGIT CODE" disabled>
</div>

<div class="col-md-6 d-flex align-items-end">
<div class="form-check mb-2">
<input class="form-check-input" type="checkbox" value="1" name="skip_phone_verification" id="skipPhoneVerification" checked>
<label class="form-check-label fw-semibold" for="skipPhoneVerification">SKIP PHONE NUMBER VERIFICATION</label>
<div class="form-text">CHECKED BY DEFAULT. UNCHECK TO VERIFY NOW.</div>
</div>
</div>

<div class="col-12">
<button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-2"></i>Register Parent / Guardian</button>
<a class="btn btn-outline-secondary" href="register.php">Other Roles</a>
</div>
</form>
</div>
</div>
</div>
</div>

<script src="assets/js/registration_common.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const skip=document.getElementById('skipPhoneVerification');
    const send=document.getElementById('sendParentCode');
    const code=document.getElementById('parentSmsCode');
    const phone=document.getElementById('parentPhone');
    const email=document.getElementById('parentEmail');
    const name=document.querySelector('input[name="full_name"]');
    const status=document.getElementById('parentCodeStatus');
    const csrf=document.querySelector('input[name="csrf_token"]');

    const applyMode=()=>{
        const bypass=Boolean(skip?.checked) || <?= $verificationServiceAvailable ? 'false' : 'true' ?>;
        if(send) send.disabled=bypass;
        if(code){code.disabled=bypass;code.required=!bypass;if(bypass)code.value='';}
        if(status){
            status.textContent=bypass
                ? 'PHONE VERIFICATION WILL BE SKIPPED. YOU CAN VERIFY LATER.'
                : 'PHONE VERIFICATION ENABLED. REQUEST A 6-DIGIT CODE.';
            status.className=bypass?'form-text text-warning':'form-text text-secondary';
        }
    };
    skip?.addEventListener('change',applyMode);
    applyMode();

    send?.addEventListener('click', async()=>{
        try{
            const body=new URLSearchParams({
                action:'send_parent_sms_code',
                phone:phone?.value||'',
                email:email?.value||'',
                full_name:name?.value||'',
                csrf_token:csrf?.value||''
            });
            send.disabled=true;
            status.textContent='SENDING VERIFICATION CODE...';
            const response=await fetch('register_parent.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString(),cache:'no-store'});
            const data=await response.json();
            if(!response.ok||!data.success)throw new Error(data.message||'UNABLE TO SEND CODE.');
            code.disabled=false;code.required=true;code.focus();
            status.textContent=String(data.message||'CODE SENT.').toUpperCase();
            status.className='form-text text-success';
        }catch(error){
            status.textContent=String(error.message||'UNABLE TO SEND CODE.').toUpperCase();
            status.className='form-text text-danger';
        }finally{
            if(!skip?.checked)send.disabled=false;
        }
    });
});
</script>
<?php require 'includes/footer.php'; ?>
