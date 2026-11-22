<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';
require_once __DIR__ . '/../includes/psn_vault.php';
requirePortalRole('admin');
psnNoCacheHeaders();

$ready = psnVaultSchemaReady($pdo);
$keyReady = true;
try { psnVaultKey(); } catch (Throwable $e) { $keyReady = false; }
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$ready || !$keyReady) {
        $errors[] = 'Run the PSN database upgrade and configure PSN_CREDENTIAL_KEY first.';
    } else {
        $id = max(0, (int)($_POST['id'] ?? 0));
        $schoolId = (int)($_POST['school_id'] ?? 0);
        $year = (int)($_POST['academic_year'] ?? date('Y'));
        $threshold = (int)($_POST['threshold_student_count'] ?? 30);
        $psnId = trim((string)($_POST['psn_online_id'] ?? ''));
        $email = trim((string)($_POST['account_email'] ?? ''));
        $password = (string)($_POST['account_password_new'] ?? '');
        $secretInput = trim((string)($_POST['totp_secret_new'] ?? ''));
        $status = (string)($_POST['account_status'] ?? 'pending');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $old = compact('id', 'schoolId', 'year', 'threshold', 'psnId', 'email', 'status', 'notes');
        if ($schoolId <= 0 || !in_array($schoolId, array_map('intval', $pdo->query('SELECT id FROM schools')->fetchAll(PDO::FETCH_COLUMN)), true)) $errors[] = 'Select an existing school.';
        if ($year < 2020 || $year > 2100) $errors[] = 'Enter a valid academic year.';
        if ($threshold < 30 || $threshold > 3000 || $threshold % 30 !== 0) $errors[] = 'The PSN allocation milestone must be 30, 60, 90, etc.';
        if ($psnId === '' || strlen($psnId) > 80) $errors[] = 'PSN Online ID is required (maximum 80 characters).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Enter a valid PSN account email.';
        if (strlen($password) > 1024) $errors[] = 'Password is too long.';
        if (strlen($notes) > 255) $errors[] = 'Notes must be at most 255 characters.';
        if (!in_array($status, ['pending', 'active', 'disabled'], true)) $errors[] = 'Invalid status.';
        $secret = null;
        if ($secretInput !== '') {
            try { $secret = psnTotpNormalize($secretInput); }
            catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
        }
        $previous = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT id, school_id, password_encrypted, totp_secret_encrypted FROM school_psn_accounts WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $previous = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$previous) $errors[] = 'PSN allocation not found.';
        }
        $hasPassword = $password !== '' || !empty($previous['password_encrypted']);
        $hasTotp = $secret !== null || !empty($previous['totp_secret_encrypted']);
        if ($status === 'active' && (!$hasPassword || !$hasTotp)) {
            $errors[] = 'Active accounts require both an encrypted password and a TOTP secret.';
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $passwordEncrypted = $password !== '' ? psnVaultEncrypt($password) : ($previous['password_encrypted'] ?? null);
                $secretEncrypted = $secret !== null ? psnVaultEncrypt($secret) : ($previous['totp_secret_encrypted'] ?? null);
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE school_psn_accounts SET school_id=?, academic_year=?, threshold_student_count=?, psn_online_id=?, account_email=?, password_encrypted=?, totp_secret_encrypted=?, account_status=?, notes=?, account_password=NULL, two_factor_code=NULL WHERE id=?');
                    $stmt->execute([$schoolId, $year, $threshold, $psnId, $email, $passwordEncrypted, $secretEncrypted, $status, $notes ?: null, $id]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO school_psn_accounts (school_id,academic_year,threshold_student_count,psn_online_id,account_email,password_encrypted,totp_secret_encrypted,account_status,notes) VALUES (?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$schoolId, $year, $threshold, $psnId, $email, $passwordEncrypted, $secretEncrypted, $status, $notes ?: null]);
                    $id = (int)$pdo->lastInsertId();
                }
                psnAudit($pdo, $id, $schoolId, 'admin', (int)($_SESSION['admin_id'] ?? 0), $previous ? 'update' : 'create');
                $pdo->commit();
                setFlash('success', 'PSN assignment saved securely.');
                redirect('/admin/psn_accounts.php');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getCode() === '23000' ? 'This school, year and milestone already have an allocation.' : 'Unable to save PSN account. Check database setup.';
                error_log('PSN account save failed (database operation).');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Unable to encrypt and save PSN credentials. Check encryption key configuration.';
            }
        }
    }
}

$schools = $pdo->query(
    'SELECT id, school_code, school_name FROM schools ORDER BY school_name ASC, school_code ASC'
)->fetchAll(PDO::FETCH_ASSOC);

// Database-backed filters ensure that search covers every PSN assignment, not
// just the latest items on screen. No credential fields are selected.
$getString = static function (string $key, int $limit = 120): string {
    $value = $_GET[$key] ?? '';
    return is_string($value) ? substr(trim($value), 0, $limit) : '';
};
$search = $getString('q');
$filterSchool = max(0, (int)$getString('school_filter', 12));
$filterStatus = $getString('status_filter', 20);
if (!in_array($filterStatus, ['', 'pending', 'active', 'disabled'], true)) {
    $filterStatus = '';
}
$filterYearString = $getString('year_filter', 4);
$filterYear = ctype_digit($filterYearString) && (int)$filterYearString >= 2020 && (int)$filterYearString <= 2100
    ? (int)$filterYearString
    : 0;
$requestedPage = max(1, min(100000, (int)$getString('page', 8)));
$perPage = 25;
$accounts = [];
$edit = null;
$totalAccounts = 0;
$totalPages = 1;
$currentPage = 1;

$filterParams = [];
$filters = [];
if ($filterSchool > 0) {
    $filters[] = 'a.school_id = :school_filter';
    $filterParams[':school_filter'] = $filterSchool;
}
if ($filterStatus !== '') {
    $filters[] = 'a.account_status = :status_filter';
    $filterParams[':status_filter'] = $filterStatus;
}
if ($filterYear > 0) {
    $filters[] = 'a.academic_year = :year_filter';
    $filterParams[':year_filter'] = $filterYear;
}
if ($search !== '') {
    $filters[] = '(s.school_code LIKE :school_code_search OR s.school_name LIKE :school_name_search '
        . 'OR a.psn_online_id LIKE :psn_id_search OR a.account_email LIKE :email_search)';
    $like = '%' . $search . '%';
    $filterParams[':school_code_search'] = $like;
    $filterParams[':school_name_search'] = $like;
    $filterParams[':psn_id_search'] = $like;
    $filterParams[':email_search'] = $like;
}
$whereClause = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
$accountsFrom = ' FROM school_psn_accounts a INNER JOIN schools s ON s.id = a.school_id';

if ($ready) {
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $accountsFrom . $whereClause);
    foreach ($filterParams as $name => $value) {
        $countStmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalAccounts = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalAccounts / $perPage));
    $currentPage = min($requestedPage, $totalPages);

    $listSql = 'SELECT a.id, a.school_id, a.academic_year, a.threshold_student_count, '
        . 'a.psn_online_id, a.account_email, a.account_status, a.updated_at, '
        . 'a.password_encrypted IS NOT NULL AS has_password, '
        . 'a.totp_secret_encrypted IS NOT NULL AS has_totp, '
        . 's.school_code, s.school_name'
        . $accountsFrom . $whereClause
        . ' ORDER BY a.updated_at DESC, a.id DESC LIMIT :list_limit OFFSET :list_offset';
    $listStmt = $pdo->prepare($listSql);
    foreach ($filterParams as $name => $value) {
        $listStmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listStmt->bindValue(':list_limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':list_offset', ($currentPage - 1) * $perPage, PDO::PARAM_INT);
    $listStmt->execute();
    $accounts = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $editId = max(0, (int)$getString('edit', 10));
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT id,school_id,academic_year,threshold_student_count,psn_online_id,account_email,account_status,notes FROM school_psn_accounts WHERE id=?');
        $stmt->execute([$editId]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
$val = $old ?: ($edit ?: []);
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filterQuery = [
    'q' => $search,
    'school_filter' => $filterSchool ?: null,
    'status_filter' => $filterStatus ?: null,
    'year_filter' => $filterYear ?: null,
];
$filterQuery = array_filter($filterQuery, static fn($value) => $value !== null && $value !== '');
$psnUrl = static function (array $changes = []) use ($filterQuery): string {
    $params = array_merge($filterQuery, $changes);
    $params = array_filter($params, static fn($value) => $value !== null && $value !== '');
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return '/admin/psn_accounts.php' . ($query !== '' ? '?' . $query : '');
};
portalPageStart('admin', 'psn_accounts', 'Manage PSN Accounts');
?>
<style>
.psn-search-help{font-size:.8rem;color:var(--bs-secondary-color,#6c757d)}
.psn-table-actions{min-width:78px}
.psn-filter-bar .form-label{font-size:.85rem;font-weight:600}
.psn-allocation-summary{font-size:.88rem}
/* Native <select> popup contents are not reliably filterable (especially in Safari).
   When JavaScript is available, use an accessible DOM-backed combobox instead. */
.psn-picker{position:relative}
.psn-picker-input[hidden],.psn-picker-list[hidden]{display:none!important}
.psn-picker-list{position:absolute;top:calc(100% - 2px);left:0;right:0;z-index:1050;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#212529);border:1px solid var(--bs-border-color,#dee2e6);border-radius:.5rem;box-shadow:0 .5rem 1.2rem rgba(0,0,0,.16);max-height:260px;overflow-y:auto;padding:.25rem 0}
.psn-picker-option{display:block;width:100%;padding:.55rem .75rem;border:0;background:transparent;color:inherit;text-align:left;line-height:1.3;cursor:pointer;font:inherit;font-size:.9rem}
.psn-picker-option:hover,.psn-picker-option.psn-picker-active{background:var(--bs-primary-bg-subtle,#e8f2ff);color:var(--bs-emphasis-color,#212529)}
.psn-picker-option[aria-selected="true"]{font-weight:700}
.psn-picker-empty{padding:.65rem .75rem;font-size:.88rem;color:var(--bs-secondary-color,#6c757d)}
.psn-picker-input{width:100%}
.psn-picker:focus-within .psn-picker-input{border-color:#86b7fe;box-shadow:0 0 0 .2rem rgba(13,110,253,.15)}
@media(max-width:767.98px){
  .psn-table-actions .btn{white-space:nowrap}
  .psn-filter-bar .btn{width:100%}
}
</style>
<div class="alert alert-info"><i class="bi bi-shield-lock me-2"></i>Assign PlayStation Network accounts by school and milestone. Passwords and authenticator secrets are encrypted; they are never displayed in the admin list. Leave password/2FA inputs empty when editing to keep existing encrypted values.</div>
<?php if (!$ready): ?><div class="alert alert-warning">PSN database upgrade required: <code>sql/psn_accounts_secure_upgrade.sql</code>.</div><?php endif; ?>
<?php if (!$keyReady): ?><div class="alert alert-danger">Set <code>PSN_CREDENTIAL_KEY</code> in the hosting environment before saving credentials.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= $escape($error) ?></div><?php endforeach; ?>

<div class="card shadow-sm border-0 mb-4"><div class="card-body p-4">
<h2 class="h5 mb-3"><?= !empty($val['id']) ? 'Edit PSN assignment' : 'Assign new PSN account' ?></h2>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
<input type="hidden" name="id" value="<?= (int)($val['id'] ?? 0) ?>">
<div class="row g-3">
<div class="col-md-6">
  <label for="psn-school" class="form-label" id="psn-school-label">School</label>
  <div class="psn-picker" id="psn-school-picker">
    <input type="search" class="form-control psn-picker-input" id="psn-school-search"
      placeholder="Search school name or code (e.g. GAMBANG)" autocomplete="off" hidden
      role="combobox" aria-autocomplete="list" aria-expanded="false"
      aria-controls="psn-school-matches" aria-describedby="psn-school-search-help"
      aria-label="Search and select a school">
    <div class="psn-picker-list" id="psn-school-matches" role="listbox"
      aria-label="Matching schools" hidden></div>
    <select required class="form-select" id="psn-school" name="school_id">
      <option value="">Select school</option>
      <?php foreach ($schools as $school): ?>
      <option value="<?= (int)$school['id'] ?>" <?= (int)($val['school_id'] ?? $val['schoolId'] ?? 0) === (int)$school['id'] ? 'selected' : '' ?>><?= $escape($school['school_code'] . ' — ' . $school['school_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="psn-search-help mt-1" id="psn-school-search-help" aria-live="polite">Type a school name or code and select a matching result.</div>
</div>
<div class="col-md-3"><label class="form-label" for="psn-year">Academic year</label><input type="number" class="form-control" id="psn-year" name="academic_year" min="2020" max="2100" required value="<?= (int)($val['academic_year'] ?? $val['year'] ?? date('Y')) ?>"></div>
<div class="col-md-3"><label class="form-label" for="psn-milestone">Student milestone</label><input type="number" class="form-control" id="psn-milestone" name="threshold_student_count" min="30" max="3000" step="30" required value="<?= (int)($val['threshold_student_count'] ?? $val['threshold'] ?? 30) ?>"></div>
<div class="col-md-6"><label class="form-label" for="psn-id">PSN Online ID</label><input class="form-control" id="psn-id" name="psn_online_id" maxlength="80" required value="<?= $escape($val['psn_online_id'] ?? $val['psnId'] ?? '') ?>"></div>
<div class="col-md-6"><label class="form-label" for="psn-email">PSN Email</label><input class="form-control" type="email" id="psn-email" name="account_email" maxlength="190" required autocomplete="off" value="<?= $escape($val['account_email'] ?? $val['email'] ?? '') ?>"></div>
<div class="col-md-6"><label class="form-label" for="psn-password">PSN Password <?= !empty($val['id']) ? '(optional replacement)' : '' ?></label><input class="form-control" type="password" id="psn-password" name="account_password_new" maxlength="1024" autocomplete="new-password" placeholder="<?= !empty($val['id']) ? 'Leave blank to retain' : 'Enter PSN password' ?>"></div>
<div class="col-md-6"><label class="form-label" for="psn-secret">Authenticator Base32 secret <?= !empty($val['id']) ? '(optional replacement)' : '' ?></label><input class="form-control" type="password" id="psn-secret" name="totp_secret_new" autocomplete="off" placeholder="<?= !empty($val['id']) ? 'Leave blank to retain' : 'Authenticator setup secret' ?>"><div class="form-text">Use the secret from PSN authenticator setup, not a temporary six-digit code. The secret is never shown to schools.</div></div>
<div class="col-md-3"><label class="form-label" for="psn-status">Status</label><select class="form-select" id="psn-status" name="account_status"><?php foreach (['pending'=>'Pending','active'=>'Active','disabled'=>'Disabled'] as $key=>$label): ?><option value="<?= $key ?>" <?= (string)($val['account_status'] ?? $val['status'] ?? 'pending') === $key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-9"><label class="form-label" for="psn-notes">Notes (no secrets)</label><input class="form-control" id="psn-notes" name="notes" maxlength="255" value="<?= $escape($val['notes'] ?? '') ?>"></div>
<div class="col-12 d-flex flex-wrap gap-2"><button type="submit" class="btn btn-primary" <?= !$ready || !$keyReady ? 'disabled':'' ?>><i class="bi bi-shield-check me-1"></i>Save PSN Account</button><?php if (!empty($val['id'])): ?><a class="btn btn-outline-secondary" href="<?= $escape($psnUrl()) ?>">Cancel Edit</a><?php endif; ?></div>
</div></form></div></div>

<div class="card shadow-sm border-0">
 <div class="card-header bg-white p-3"><h2 class="h5 mb-0">School PSN Allocations</h2></div>
 <div class="card-body border-bottom p-3">
  <form method="get" action="/admin/psn_accounts.php" class="row g-2 align-items-end psn-filter-bar" role="search">
   <div class="col-12 col-md-4">
    <label class="form-label" for="psn-filter-query">Search</label>
    <input class="form-control" type="search" name="q" id="psn-filter-query" value="<?= $escape($search) ?>" placeholder="School name, code, PSN ID or email" maxlength="120">
   </div>
   <div class="col-12 col-md-3">
    <label class="form-label" for="psn-filter-school">Filter School</label>
    <div class="psn-picker" id="psn-filter-school-picker">
     <input class="form-control psn-picker-input" type="search" id="psn-filter-school-search"
       placeholder="All schools — type to find" autocomplete="off" hidden
       role="combobox" aria-autocomplete="list" aria-expanded="false"
       aria-controls="psn-filter-school-matches" aria-describedby="psn-filter-school-help"
       aria-label="Search schools to filter allocations">
     <div class="psn-picker-list" id="psn-filter-school-matches" role="listbox"
       aria-label="School filter matches" hidden></div>
     <select class="form-select" name="school_filter" id="psn-filter-school">
      <option value="">All schools</option>
      <?php foreach ($schools as $school): ?><option value="<?= (int)$school['id'] ?>" <?= $filterSchool === (int)$school['id'] ? 'selected' : '' ?>><?= $escape($school['school_code'] . ' — ' . $school['school_name']) ?></option><?php endforeach; ?>
     </select>
    </div>
    <div class="psn-search-help mt-1" id="psn-filter-school-help" aria-live="polite">Leave blank for all schools.</div>
   </div>
   <div class="col-6 col-md-2">
    <label class="form-label" for="psn-filter-status">Status</label>
    <select class="form-select" name="status_filter" id="psn-filter-status">
     <option value="">All statuses</option>
     <?php foreach (['pending'=>'Pending','active'=>'Active','disabled'=>'Disabled'] as $statusValue=>$statusLabel): ?><option value="<?= $statusValue ?>" <?= $filterStatus === $statusValue ? 'selected' : '' ?>><?= $statusLabel ?></option><?php endforeach; ?>
    </select>
   </div>
   <div class="col-6 col-md-1">
    <label class="form-label" for="psn-filter-year">Year</label>
    <input class="form-control" type="number" id="psn-filter-year" name="year_filter" min="2020" max="2100" placeholder="All" value="<?= $filterYear ?: '' ?>">
   </div>
   <div class="col-6 col-md-1"><button class="btn btn-primary w-100" type="submit" title="Search and filter PSN allocations"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden"> Apply filters</span></button></div>
   <div class="col-6 col-md-1"><a class="btn btn-outline-secondary w-100" href="/admin/psn_accounts.php" title="Clear all filters" aria-label="Clear all filters"><i class="bi bi-x-circle" aria-hidden="true"></i></a></div>
  </form>
  <div class="psn-allocation-summary text-secondary mt-3" aria-live="polite">
   <?= number_format($totalAccounts) ?> matching assignment<?= $totalAccounts === 1 ? '' : 's' ?>
   <?php if ($totalAccounts): ?> · Displaying <?= number_format(($currentPage - 1) * $perPage + 1) ?>–<?= number_format(min($currentPage * $perPage, $totalAccounts)) ?><?php endif; ?>
  </div>
 </div>
 <div class="table-responsive">
 <table class="table align-middle table-hover mb-0">
  <thead><tr><th>School</th><th>Year</th><th>Milestone</th><th>PSN ID</th><th>Email</th><th>Credentials</th><th>Status</th><th>Manage</th></tr></thead>
  <tbody>
   <?php foreach ($accounts as $account): ?>
   <tr>
    <td><?= $escape($account['school_code']) ?><div class="small text-secondary"><?= $escape($account['school_name']) ?></div></td>
    <td><?= (int)$account['academic_year'] ?></td>
    <td><?= (int)$account['threshold_student_count'] ?></td>
    <td><?= $escape($account['psn_online_id'] ?: '—') ?></td>
    <td><?= $escape($account['account_email'] ?: '—') ?></td>
    <td><span class="badge text-bg-<?= $account['has_password'] && $account['has_totp'] ? 'success' : 'warning' ?>"><?= $account['has_password'] && $account['has_totp'] ? 'Encrypted / Configured' : 'Incomplete' ?></span></td>
    <td><?= $escape(ucfirst($account['account_status'])) ?></td>
    <td class="psn-table-actions"><a class="btn btn-sm btn-outline-success me-1" href="/school/psn_accounts.php?school_id=<?= (int)$account['school_id'] ?>" title="View PSN credentials (administrator PIN required)" aria-label="View PSN credentials for <?= $escape($account['school_name']) ?>"><i class="bi bi-shield-lock"></i> View</a><a class="btn btn-sm btn-outline-primary" href="<?= $escape($psnUrl(['edit' => (int)$account['id'], 'page' => $currentPage > 1 ? $currentPage : null])) ?>" title="Edit PSN assignment" aria-label="Edit PSN assignment"><i class="bi bi-pencil-square"></i> Edit</a></td>
   </tr>
   <?php endforeach; ?>
   <?php if (!$accounts): ?><tr><td colspan="8" class="text-center text-secondary p-4"><?= $ready ? 'No PSN assignments match the selected search or filters.' : 'Database upgrade required.' ?></td></tr><?php endif; ?>
  </tbody>
 </table>
 </div>
 <?php if ($totalPages > 1): ?>
 <nav class="p-3 border-top" aria-label="PSN allocations pages"><ul class="pagination pagination-sm flex-wrap mb-0">
  <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $escape($psnUrl(['page' => max(1, $currentPage - 1)])) ?>" <?= $currentPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Previous</a></li>
  <?php for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++): ?>
   <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= $escape($psnUrl(['page' => $page])) ?>" <?= $page === $currentPage ? 'aria-current="page"' : '' ?>><?= $page ?></a></li>
  <?php endfor; ?>
  <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $escape($psnUrl(['page' => min($totalPages, $currentPage + 1)])) ?>" <?= $currentPage >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Next</a></li>
 </ul></nav>
 <?php endif; ?>
</div>
<script>
(function () {
  'use strict';

  // Upgrade native school selects only after JavaScript is ready. The original
  // selects retain their name/value and remain usable if JS is unavailable.
  function makeSchoolPicker(selectId, inputId, listId, hintId, mandatory) {
    const select = document.getElementById(selectId);
    const input = document.getElementById(inputId);
    const list = document.getElementById(listId);
    const hint = document.getElementById(hintId);
    if (!select || !input || !list || !hint) return;

    const records = Array.from(select.options).filter(function (option) {
      return option.value !== '';
    }).map(function (option) {
      const label = (option.textContent || '').trim();
      return { id: option.value, label: label, searchable: label.toLocaleLowerCase() };
    });
    if (!records.length) return;

    const picker = input.closest('.psn-picker');
    let selected = records.find(function (school) { return school.id === select.value; }) || null;
    let matches = [];
    let activeIndex = -1;
    const MAX_RESULTS = 40;

    // Avoid a native select popup that displays all schools on Safari/iOS.
    select.classList.add('d-none');
    select.required = false;
    input.hidden = false;
    input.required = Boolean(mandatory);
    input.value = selected ? selected.label : '';
    const label = document.querySelector('label[for="' + selectId + '"]');
    if (label) label.htmlFor = inputId;

    function closeList() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      activeIndex = -1;
    }

    function setSelected(school) {
      selected = school || null;
      select.value = school ? school.id : '';
      input.value = school ? school.label : '';
      input.setCustomValidity('');
      hint.textContent = school ? 'Selected: ' + school.label :
        (mandatory ? 'Type a school name or code, then choose a result.' : 'All schools.');
      closeList();
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function highlight(index) {
      activeIndex = index;
      const options = Array.from(list.querySelectorAll('[role="option"]'));
      options.forEach(function (option, idx) {
        const active = idx === index;
        option.classList.toggle('psn-picker-active', active);
        if (active) {
          input.setAttribute('aria-activedescendant', option.id);
          option.scrollIntoView({ block: 'nearest' });
        }
      });
      if (index < 0) input.removeAttribute('aria-activedescendant');
    }

    function render() {
      const term = input.value.trim().toLocaleLowerCase();
      // Match both school code and name, but cap rendered results for speed.
      const allMatches = records.filter(function (school) {
        return !term || school.searchable.includes(term);
      });
      matches = allMatches.slice(0, MAX_RESULTS);
      activeIndex = -1;
      const fragment = document.createDocumentFragment();
      if (!mandatory) {
        const allButton = document.createElement('button');
        allButton.type = 'button';
        allButton.className = 'psn-picker-option';
        allButton.setAttribute('role', 'option');
        allButton.id = listId + '-all';
        allButton.setAttribute('aria-selected', selected === null ? 'true' : 'false');
        allButton.textContent = 'All schools (clear filter)';
        allButton.addEventListener('click', function () { setSelected(null); });
        fragment.appendChild(allButton);
      }
      matches.forEach(function (school) {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'psn-picker-option';
        option.setAttribute('role', 'option');
        option.id = listId + '-' + school.id;
        option.setAttribute('aria-selected', school.id === select.value ? 'true' : 'false');
        option.textContent = school.label;
        option.addEventListener('click', function () { setSelected(school); });
        fragment.appendChild(option);
      });
      if (!allMatches.length) {
        const empty = document.createElement('div');
        empty.className = 'psn-picker-empty';
        empty.textContent = 'No matching schools. Try another name or code.';
        fragment.appendChild(empty);
      }
      list.replaceChildren(fragment);
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      const countText = allMatches.length + ' matching school' + (allMatches.length === 1 ? '' : 's') + '.';
      hint.textContent = countText + (allMatches.length > MAX_RESULTS ? ' Showing first ' + MAX_RESULTS + '; keep typing to narrow.' : ' Select a result.');
    }

    input.addEventListener('focus', render);
    input.addEventListener('input', function () {
      // Never submit the previous school's ID after the typed label changes.
      if (!selected || input.value !== selected.label) {
        select.value = '';
        selected = null;
      }
      input.setCustomValidity('');
      render();
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeList();
        return;
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (list.hidden) render();
        const count = list.querySelectorAll('[role="option"]').length;
        if (count) highlight(activeIndex < 0 ? (event.key === 'ArrowDown' ? 0 : count - 1) : (activeIndex + (event.key === 'ArrowDown' ? 1 : count - 1)) % count);
        return;
      }
      if (event.key === 'Enter' && !list.hidden) {
        event.preventDefault();
        const options = list.querySelectorAll('[role="option"]');
        if (activeIndex >= 0 && options[activeIndex]) {
          options[activeIndex].click();
        } else if (matches.length === 1) {
          setSelected(matches[0]);
        }
      }
    });
    document.addEventListener('pointerdown', function (event) {
      if (picker && !picker.contains(event.target)) closeList();
    });
    const form = select.closest('form');
    if (form) {
      form.addEventListener('submit', function (event) {
        if (mandatory && !select.value) {
          input.setCustomValidity('Choose a school from the search results.');
          event.preventDefault();
          input.reportValidity();
          input.focus();
          return;
        }
        // An unselected filter should not accidentally apply to an old school.
        if (!mandatory && selected === null) select.value = '';
      });
    }
    if (selected) hint.textContent = 'Selected: ' + selected.label;
  }

  function init() {
    makeSchoolPicker('psn-school', 'psn-school-search', 'psn-school-matches', 'psn-school-search-help', true);
    makeSchoolPicker('psn-filter-school', 'psn-filter-school-search', 'psn-filter-school-matches', 'psn-filter-school-help', false);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
</script>
<?php portalPageEnd(); ?>
