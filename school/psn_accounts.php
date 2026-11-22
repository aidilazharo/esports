<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';
require_once __DIR__ . '/../includes/psn_vault.php';
$scope = psnCredentialScope($pdo);
$schoolId = $scope['school_id'];
$isAdmin = $scope['role'] === 'admin';
// Include school_id only when an admin deliberately selected a school.
$pageUrl = '/school/psn_accounts.php' . ($scope['mode'] === 'admin_direct' ? '?school_id=' . $schoolId : '');
$codeUrl = '/school/psn_code.php' . ($scope['mode'] === 'admin_direct' ? '?school_id=' . $schoolId : '');
psnNoCacheHeaders();
$ready = psnVaultSchemaReady($pdo);
$unlockError = '';
$now = time();
$keyReady = true;
try { psnVaultKey(); } catch (Throwable $e) { $keyReady = false; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'lock') {
        psnClearCredentialUnlock();
        redirect($pageUrl);
    }
    if ($action === 'unlock') {
        $lockedUntil = (int)($_SESSION['psn_unlock_locked_until'] ?? 0);
        $attempts = (int)($_SESSION['psn_unlock_attempts'] ?? 0);
        if ($lockedUntil > $now) {
            $unlockError = 'Too many attempts. Try again later.';
        } elseif (($isAdmin
            ? psnAdminPinValid($pdo, $scope['actor_id'], (string)($_POST['access_pin'] ?? ''))
            : psnSchoolPinValid($pdo, $schoolId, (string)($_POST['access_pin'] ?? '')))) {
            if (!$ready || !$keyReady) {
                $unlockError = 'PSN storage or encryption key is unavailable. Contact the administrator.';
            } else {
                try {
                    $audit = $pdo->prepare("SELECT id FROM school_psn_accounts WHERE school_id=? AND account_status='active'");
                    $audit->execute([$schoolId]);
                    foreach ($audit->fetchAll(PDO::FETCH_COLUMN) as $id) {
                        psnAudit($pdo, (int)$id, $schoolId, $scope['role'], $scope['actor_id'], 'reveal');
                    }
                    session_regenerate_id(true);
                    $_SESSION['psn_unlock_school_id'] = $schoolId;
                    $_SESSION['psn_unlock_actor_role'] = $scope['role'];
                    $_SESSION['psn_unlock_actor_id'] = $scope['actor_id'];
                    $_SESSION['psn_unlock_context_mode'] = $scope['mode'];
                    $_SESSION['psn_unlock_session_id_hash'] = hash('sha256', session_id());
                    $_SESSION['psn_unlock_until'] = time() + 180;
                    unset($_SESSION['psn_unlock_attempts'], $_SESSION['psn_unlock_locked_until']);
                    redirect($pageUrl);
                } catch (Throwable $e) {
                    $unlockError = 'Unable to unlock credentials. Contact the programme administrator.';
                }
            }
        } else {
            $attempts++;
            $_SESSION['psn_unlock_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['psn_unlock_locked_until'] = time() + 900;
                $unlockError = 'Too many attempts. Try again in 15 minutes.';
            } else {
                $unlockError = ($isAdmin ? 'Incorrect or expired administrator access PIN.' : 'Incorrect or expired school access PIN.');
            }
        }
    }
}
$unlocked = psnCredentialUnlocked($scope);
$accounts = [];
if ($ready) {
    $stmt = $pdo->prepare('SELECT id, academic_year, threshold_student_count, psn_online_id, account_email, password_encrypted, totp_secret_encrypted, account_status, updated_at FROM school_psn_accounts WHERE school_id = ? ORDER BY academic_year DESC, threshold_student_count ASC, id ASC');
    $stmt->execute([$schoolId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
portalPageStart($scope['layout_role'], 'psn_accounts', $isAdmin ? 'PSN Credentials · ' . $scope['school_name'] : 'School PSN Accounts');
?>
<?php if ($isAdmin): ?>
<div class="alert alert-warning"><i class="bi bi-shield-lock me-1"></i>Administrator credential access for <strong><?= $escape($scope['school_name']) ?></strong>. Confirm with your <strong>administrator PIN</strong> to view this school's PSN passwords and live 2FA codes. Access expires after 3 minutes and is audited.</div>
<?php if ($scope['mode'] === 'admin_direct'): ?><a href="/admin/psn_accounts.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Back to PSN Management</a><?php endif; ?>
<?php else: ?>
<div class="alert alert-info"><i class="bi bi-shield-lock me-1"></i>PSN accounts are assigned by the programme administrator. For security, the school access PIN is required before passwords or live 2FA codes can be viewed. Access automatically locks after 3 minutes.</div>
<?php endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">PSN database upgrade has not been installed. Contact the programme administrator.</div><?php endif; ?>
<?php if (!$keyReady): ?><div class="alert alert-warning">PSN credentials are temporarily unavailable. Contact the programme administrator.</div><?php endif; ?>
<?php if ($unlockError): ?><div class="alert alert-danger" role="alert"><?= $escape($unlockError) ?></div><?php endif; ?>
<?php if (!$unlocked): ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-body p-4"><h2 class="h5">Unlock PSN Credentials</h2><form method="post" action="<?= $escape($pageUrl) ?>" autocomplete="off" class="row align-items-end g-3"><input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>"><input type="hidden" name="action" value="unlock"><div class="col-sm-6 col-md-4"><label class="form-label" for="psn-pin"><?= $isAdmin ? 'Administrator 6-digit access PIN' : 'School portal 6-digit access PIN' ?></label><input id="psn-pin" type="password" class="form-control" name="access_pin" pattern="[0-9]{6}" inputmode="numeric" maxlength="6" minlength="6" required autocomplete="off"></div><div class="col-sm-auto"><button class="btn btn-primary" type="submit" <?= !$ready || !$keyReady ? 'disabled':'' ?>><i class="bi bi-unlock me-1"></i>Unlock for 3 minutes</button></div></form></div></div>
<?php else: ?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-3"><span class="badge text-bg-success"><i class="bi bi-unlock me-1"></i>Credentials unlocked</span><span class="small text-secondary" id="psn-access-time">Access expires shortly</span><form method="post" action="<?= $escape($pageUrl) ?>" class="m-0"><input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>"><input type="hidden" name="action" value="lock"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-lock me-1"></i>Lock now</button></form></div>
<?php endif; ?>
<div class="row g-3">
<?php foreach ($accounts as $account): ?>
<?php
$status = (string)$account['account_status'];
$canShow = $unlocked && $keyReady && $status === 'active';
$password = null;
if ($canShow && !empty($account['password_encrypted'])) {
    try { $password = psnVaultDecrypt((string)$account['password_encrypted']); }
    catch (Throwable $e) { $password = null; }
}
?>
<div class="col-12 col-xl-6"><div class="card h-100 border-0 shadow-sm"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 mb-0"><i class="bi bi-controller me-2"></i>PSN Account · <?= (int)$account['academic_year'] ?></h2><span class="badge text-bg-<?= $status==='active' ? 'success':($status==='disabled'?'secondary':'warning') ?>"><?= $escape(ucfirst($status)) ?></span></div>
<div class="mb-2 small text-secondary">Allocation milestone: <?= (int)$account['threshold_student_count'] ?> students</div>
<dl class="row mb-3"><dt class="col-sm-4">PSN Online ID</dt><dd class="col-sm-8 fw-semibold text-break"><?= $escape($account['psn_online_id'] ?: 'Not assigned') ?></dd><dt class="col-sm-4">PSN email</dt><dd class="col-sm-8 text-break"><?= $escape($account['account_email'] ?: 'Not assigned') ?></dd><dt class="col-sm-4">Password</dt><dd class="col-sm-8">
<?php if ($canShow && $password !== null): ?><div class="input-group input-group-sm"><input class="form-control psn-secret-field" aria-label="PSN password" type="password" value="<?= $escape($password) ?>" readonly autocomplete="off"><button class="btn btn-outline-secondary psn-toggle" type="button" aria-label="Show password" title="Show password"><i class="bi bi-eye"></i></button></div><?php else: ?><span class="text-secondary"><?= $status==='disabled' ? 'Account disabled' : ($unlocked ? 'Not configured' : 'Unlock to view') ?></span><?php endif; ?>
</dd><dt class="col-sm-4">2FA code</dt><dd class="col-sm-8">
<?php if ($canShow && !empty($account['totp_secret_encrypted'])): ?><span class="fs-4 fw-bold font-monospace psn-live-code" data-account-id="<?= (int)$account['id'] ?>" aria-live="off">••••••</span><span class="small text-secondary d-block psn-code-expiry" data-account-id="<?= (int)$account['id'] ?>">Loading…</span><?php else: ?><span class="text-secondary"><?= $status==='disabled' ? 'Account disabled' : ($unlocked ? 'Not configured' : 'Unlock to view') ?></span><?php endif; ?></dd></dl>
<div class="small text-secondary">Codes refresh every 30 seconds while unlocked. Never share account credentials outside your authorized school team.</div>
</div></div></div>
<?php endforeach; ?>
<?php if (!$accounts): ?><div class="col-12"><div class="alert alert-secondary">No PSN account has been assigned to this school yet.</div></div><?php endif; ?>
</div>
<?php if ($unlocked): ?>
<script>
(function(){
  const csrf = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const unlockUntil = <?= (int)($_SESSION['psn_unlock_until'] ?? 0) ?> * 1000;
  const codeUrl = <?= json_encode($codeUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let serverOffsetMs = 0;
  document.querySelectorAll('.psn-toggle').forEach(button => button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.setAttribute('title', show ? 'Hide password' : 'Show password');
    button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  }));
  async function refreshCodes(){
    if(Date.now() >= unlockUntil) { location.reload(); return; }
    try {
      const data = new URLSearchParams({csrf_token:csrf});
      const res = await fetch(codeUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data,cache:'no-store',credentials:'same-origin'});
      if (!res.ok) { if(res.status === 403) location.reload(); return; }
      const json = await res.json();
      if(!json.ok) return;
      serverOffsetMs = json.server_time*1000 - Date.now();
      document.querySelectorAll('.psn-live-code').forEach(element => {
        element.textContent = Object.prototype.hasOwnProperty.call(json.codes,element.dataset.accountId) ? json.codes[element.dataset.accountId] : 'Unavailable';
      });
    } catch(e) { /* Retain previous value; retry on next interval. */ }
  }
  function countdown(){
    const serverTime = Math.floor((Date.now()+serverOffsetMs)/1000);
    const remaining = 30 - (serverTime % 30);
    document.querySelectorAll('.psn-code-expiry').forEach(el => el.textContent = 'Expires in ' + remaining + 's');
    const access = document.getElementById('psn-access-time');
    if (access) access.textContent = 'Locks in ' + Math.max(0,Math.ceil((unlockUntil-Date.now())/1000))+'s';
    if(Date.now() >= unlockUntil) location.reload();
  }
  refreshCodes(); countdown();
  setInterval(refreshCodes, 5000);
  setInterval(countdown,1000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden) refreshCodes();});
})();
</script>
<?php endif; ?>
<?php portalPageEnd(); ?>
