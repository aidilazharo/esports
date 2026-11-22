<?php
/** Unified role auth for admin, school, teacher and parent/guardian. */

function portalAuthTableExists(PDO $pdo, string $table): bool
{
    $allowed = ['school_access_codes', 'teacher_access_codes', 'parent_access_codes'];
    if (!in_array($table, $allowed, true)) return false;
    try {
        $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        error_log('Portal auth table check failed for ' . $table . ': ' . $e->getMessage());
        return false;
    }
}

function portalAuthSafeReturn(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/[\r\n]/', $value)) return $fallback;
    if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_starts_with($value, '/\\')) return $value;
    return $fallback;
}

function portalAuthDashboardForRole(string $role): string
{
    return match ($role) {
        'admin' => '/admin/', 'school' => '/school/', 'teacher' => '/teacher/', 'parent' => '/parent/', default => '/login.php'
    };
}

function portalAuthMaskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    $local = $parts[0];
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(2, strlen($local) - strlen($visible))) . '@' . $parts[1];
}

function portalAuthAdminConfigured(): bool
{
    $hash = defined('ADMIN_ACCESS_CODE_HASH') ? trim((string)ADMIN_ACCESS_CODE_HASH) : trim((string)(getenv('ADMIN_ACCESS_CODE_HASH') ?: ''));
    $code = defined('ADMIN_ACCESS_CODE') ? trim((string)ADMIN_ACCESS_CODE) : trim((string)(getenv('ADMIN_ACCESS_CODE') ?: ''));
    return $hash !== '' || preg_match('/^\d{6}$/', $code) === 1;
}

function portalAuthAdminMatches(string $code): bool
{
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $hash = defined('ADMIN_ACCESS_CODE_HASH') ? trim((string)ADMIN_ACCESS_CODE_HASH) : trim((string)(getenv('ADMIN_ACCESS_CODE_HASH') ?: ''));
    if ($hash !== '') return password_verify($code, $hash);
    $configured = defined('ADMIN_ACCESS_CODE') ? trim((string)ADMIN_ACCESS_CODE) : trim((string)(getenv('ADMIN_ACCESS_CODE') ?: ''));
    return preg_match('/^\d{6}$/', $configured) === 1 && hash_equals($configured, $code);
}

function portalAuthCurrentRole(): ?string
{
    // During Admin Test Mode, expose the selected role as the effective role
    // while keeping admin_authenticated=true in the background.
    if (!empty($_SESSION['admin_authenticated'])) {
        $adminExpiresAt = (int)($_SESSION['admin_access_expires_at'] ?? 0);

        if ($adminExpiresAt <= time()) {
            unset(
                $_SESSION['admin_authenticated'],
                $_SESSION['admin_id'],
                $_SESSION['admin_name'],
                $_SESSION['admin_email'],
                $_SESSION['admin_access_expires_at'],
                $_SESSION['admin_test_role'],
                $_SESSION['admin_test_target_id'],
                $_SESSION['admin_test_label'],
                $_SESSION['admin_test_started_at']
            );
        } else {
        $testRole = (string)($_SESSION['admin_test_role'] ?? '');
        if ($testRole === 'school'
            && !empty($_SESSION['school_authenticated'])
            && !empty($_SESSION['school_id'])
            && (int)($_SESSION['school_access_expires_at'] ?? 0) > time()) {
            return 'school';
        }
        if ($testRole === 'teacher'
            && !empty($_SESSION['teacher_authenticated'])
            && !empty($_SESSION['teacher_id'])
            && (int)($_SESSION['teacher_access_expires_at'] ?? 0) > time()) {
            return 'teacher';
        }
        if ($testRole === 'parent'
            && !empty($_SESSION['parent_authenticated'])
            && !empty($_SESSION['parent_id'])
            && (int)($_SESSION['parent_access_expires_at'] ?? 0) > time()) {
            return 'parent';
        }
        return 'admin';
        }
    }

    if (!empty($_SESSION['school_authenticated']) && !empty($_SESSION['school_id']) && (int)($_SESSION['school_access_expires_at'] ?? 0) > time()) return 'school';
    if (!empty($_SESSION['teacher_authenticated']) && !empty($_SESSION['teacher_id']) && (int)($_SESSION['teacher_access_expires_at'] ?? 0) > time()) return 'teacher';
    if (!empty($_SESSION['parent_authenticated']) && !empty($_SESSION['parent_id']) && (int)($_SESSION['parent_access_expires_at'] ?? 0) > time()) return 'parent';
    return null;
}


function portalAuthIsAdminAuthenticated(): bool
{
    if (empty($_SESSION['admin_authenticated'])) {
        return false;
    }

    $expiresAt = (int)($_SESSION['admin_access_expires_at'] ?? 0);

    if ($expiresAt <= time()) {
        unset(
            $_SESSION['admin_authenticated'],
            $_SESSION['admin_id'],
            $_SESSION['admin_name'],
            $_SESSION['admin_email'],
            $_SESSION['admin_access_expires_at']
        );
        return false;
    }

    return true;
}

function portalAuthIsAdminTestMode(?string $role = null): bool
{
    if (!portalAuthIsAdminAuthenticated()) return false;

    $testRole = (string)($_SESSION['admin_test_role'] ?? '');
    if (!in_array($testRole, ['school', 'teacher', 'parent'], true)) return false;

    if ($role !== null && $role !== $testRole) return false;
    return true;
}

function portalAuthClearRoleContextsForAdminTest(): void
{
    $keys = [
        'school_authenticated','school_id','school_name','school_code','school_access_expires_at',
        'school_login_attempts','school_locked_until','school_pending_code',
        'teacher_authenticated','teacher_id','teacher_name','teacher_email','teacher_access_expires_at',
        'teacher_login_attempts','teacher_locked_until','teacher_pending_email',
        'parent_authenticated','parent_id','parent_name','parent_email','parent_number','parent_access_expires_at',
        'parent_login_attempts','parent_locked_until','parent_pending_email',
        'portal_role'
    ];
    foreach ($keys as $key) unset($_SESSION[$key]);
}

function portalAuthEndAdminTest(): void
{
    if (!portalAuthIsAdminAuthenticated()) return;

    portalAuthClearRoleContextsForAdminTest();
    unset(
        $_SESSION['admin_test_role'],
        $_SESSION['admin_test_target_id'],
        $_SESSION['admin_test_label'],
        $_SESSION['admin_test_started_at']
    );
    $_SESSION['portal_role'] = 'admin';
}

function portalAuthStartAdminTest(PDO $pdo, string $role, int $targetId): array
{
    if (!portalAuthIsAdminAuthenticated()) {
        return ['success' => false, 'message' => 'Administrator login is required.'];
    }

    if (!in_array($role, ['school', 'teacher', 'parent'], true) || $targetId <= 0) {
        return ['success' => false, 'message' => 'Invalid test role or record.'];
    }

    portalAuthClearRoleContextsForAdminTest();

    // Test sessions are intentionally temporary. They do not alter access-code records.
    $expiresAt = time() + 3600;

    try {
        if ($role === 'school') {
            $stmt = $pdo->prepare(
                'SELECT id, school_code, school_name
                 FROM schools
                 WHERE id=?
                 LIMIT 1'
            );
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();

            if (!$row) {
                return ['success' => false, 'message' => 'School record was not found.'];
            }

            $_SESSION['school_authenticated'] = true;
            $_SESSION['school_id'] = (int)$row['id'];
            $_SESSION['school_code'] = (string)$row['school_code'];
            $_SESSION['school_name'] = (string)$row['school_name'];
            $_SESSION['school_access_expires_at'] = $expiresAt;
            $_SESSION['admin_test_label'] = (string)$row['school_name'];
        }

        if ($role === 'teacher') {
            $stmt = $pdo->prepare(
                'SELECT t.id AS teacher_id, t.full_name, t.email, t.school_id,
                        s.school_code, s.school_name
                 FROM teachers t
                 JOIN schools s ON s.id=t.school_id
                 WHERE t.id=?
                 LIMIT 1'
            );
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();

            if (!$row) {
                return ['success' => false, 'message' => 'Teacher record was not found.'];
            }

            $_SESSION['teacher_authenticated'] = true;
            $_SESSION['teacher_id'] = (int)$row['teacher_id'];
            $_SESSION['teacher_name'] = (string)$row['full_name'];
            $_SESSION['teacher_email'] = (string)($row['email'] ?? '');
            $_SESSION['teacher_access_expires_at'] = $expiresAt;

            // Teacher portal pages also need school context.
            $_SESSION['school_id'] = (int)$row['school_id'];
            $_SESSION['school_code'] = (string)$row['school_code'];
            $_SESSION['school_name'] = (string)$row['school_name'];

            $_SESSION['admin_test_label'] = (string)$row['full_name'] . ' · ' . (string)$row['school_name'];
        }

        if ($role === 'parent') {
            $stmt = $pdo->prepare(
                'SELECT id, guardian_number, full_name, email
                 FROM guardians
                 WHERE id=?
                 LIMIT 1'
            );
            $stmt->execute([$targetId]);
            $row = $stmt->fetch();

            if (!$row) {
                return ['success' => false, 'message' => 'Parent / guardian record was not found.'];
            }

            $_SESSION['parent_authenticated'] = true;
            $_SESSION['parent_id'] = (int)$row['id'];
            $_SESSION['parent_number'] = (string)($row['guardian_number'] ?? '');
            $_SESSION['parent_name'] = (string)$row['full_name'];
            $_SESSION['parent_email'] = (string)($row['email'] ?? '');
            $_SESSION['parent_access_expires_at'] = $expiresAt;

            $_SESSION['admin_test_label'] = (string)$row['full_name'];
        }

        $_SESSION['admin_test_role'] = $role;
        $_SESSION['admin_test_target_id'] = $targetId;
        $_SESSION['admin_test_started_at'] = time();
        $_SESSION['portal_role'] = $role;

        return [
            'success' => true,
            'role' => $role,
            'redirect' => portalAuthDashboardForRole($role),
        ];
    } catch (Throwable $e) {
        error_log('Admin Test Mode error: ' . $e->getMessage());
        portalAuthEndAdminTest();
        return ['success' => false, 'message' => 'Unable to start Admin Test Mode for the selected record.'];
    }
}

function portalAuthLogoutAll(bool $regenerate = true): void
{
    $keys = [
        'admin_authenticated','admin_id','admin_name','admin_email','admin_access_expires_at','admin_login_attempts','admin_locked_until',
        'school_authenticated','school_id','school_name','school_code','school_access_expires_at','school_login_attempts','school_locked_until','school_pending_code',
        'teacher_authenticated','teacher_id','teacher_name','teacher_email','teacher_access_expires_at','teacher_login_attempts','teacher_locked_until','teacher_pending_email',
        'parent_authenticated','parent_id','parent_name','parent_email','parent_number','parent_access_expires_at','parent_login_attempts','parent_locked_until','parent_pending_email',
        'admin_test_role','admin_test_target_id','admin_test_label','admin_test_started_at',
        'portal_role'
    ];
    foreach ($keys as $key) unset($_SESSION[$key]);
    if ($regenerate && session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function portalAuthClearOtherRoles(string $keepRole): void
{
    $preserveSchoolContext = $keepRole === 'teacher';
    if ($keepRole !== 'admin') unset(
        $_SESSION['admin_authenticated'],
        $_SESSION['admin_id'],
        $_SESSION['admin_name'],
        $_SESSION['admin_email'],
        $_SESSION['admin_access_expires_at'],
        $_SESSION['admin_login_attempts'],
        $_SESSION['admin_locked_until']
    );
    if ($keepRole !== 'school' && !$preserveSchoolContext) unset($_SESSION['school_authenticated'], $_SESSION['school_id'], $_SESSION['school_name'], $_SESSION['school_code'], $_SESSION['school_access_expires_at'], $_SESSION['school_login_attempts'], $_SESSION['school_locked_until'], $_SESSION['school_pending_code']);
    if ($keepRole !== 'teacher') unset($_SESSION['teacher_authenticated'], $_SESSION['teacher_id'], $_SESSION['teacher_name'], $_SESSION['teacher_email'], $_SESSION['teacher_access_expires_at'], $_SESSION['teacher_login_attempts'], $_SESSION['teacher_locked_until'], $_SESSION['teacher_pending_email']);
    if ($keepRole !== 'parent') unset($_SESSION['parent_authenticated'], $_SESSION['parent_id'], $_SESSION['parent_name'], $_SESSION['parent_email'], $_SESSION['parent_number'], $_SESSION['parent_access_expires_at'], $_SESSION['parent_login_attempts'], $_SESSION['parent_locked_until'], $_SESSION['parent_pending_email']);
    $_SESSION['portal_role'] = $keepRole;
}

function portalAuthSendBrevoEmail(string $email, string $name, string $subject, string $html, string $text, string $tag): array
{
    $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim((string)(getenv('BREVO_SENDER_EMAIL') ?: ''));
    $senderName = trim((string)(getenv('BREVO_SENDER_NAME') ?: 'Metadata Berhad'));
    $replyTo = trim((string)(getenv('BREVO_REPLY_TO_EMAIL') ?: $senderEmail));
    if ($apiKey === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) return ['success'=>false,'message'=>'Brevo email is not configured.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success'=>false,'message'=>'A valid recipient email is required.'];
    if (!function_exists('curl_init')) return ['success'=>false,'message'=>'PHP cURL is required.'];
    $payload = ['sender'=>['name'=>$senderName,'email'=>$senderEmail],'to'=>[['name'=>$name,'email'=>$email]],'subject'=>$subject,'htmlContent'=>$html,'textContent'=>$text,'tags'=>[$tag]];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $payload['replyTo'] = ['name'=>$senderName,'email'=>$replyTo];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['accept: application/json','content-type: application/json','api-key: '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $body = curl_exec($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body === false || $error !== '') return ['success'=>false,'message'=>'Brevo connection error: '.$error];
    if ($http < 200 || $http >= 300) {
        $decoded = json_decode((string)$body, true);
        return ['success'=>false,'message'=>'Brevo HTTP '.$http.': '.(is_array($decoded) ? (string)($decoded['message'] ?? $decoded['code'] ?? 'Unknown error') : 'Unknown error')];
    }
    return ['success'=>true];
}

function portalAuthSendCodeEmail(string $roleLabel, string $email, string $name, string $code, DateTimeImmutable $expiresAt, string $tag): array
{
    $safeName = htmlspecialchars($name, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $safeRole = htmlspecialchars($roleLabel, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $safeExpiry = htmlspecialchars($expiresAt->format('d M Y, h:i A'), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $subject = $roleLabel . ' Portal 6-Digit Access Code';
    $html = '<!doctype html><html><body style="font-family:Arial;background:#f5f6f8;padding:24px"><div style="max-width:560px;margin:auto;background:white;padding:28px;border-radius:12px"><h2>'.$safeRole.' Portal Access</h2><p>Hello <strong>'.$safeName.'</strong>,</p><p>Use this 6-digit code to sign in:</p><div style="font-size:32px;letter-spacing:8px;font-weight:700;text-align:center;background:#f8f9fa;padding:16px;border-radius:10px">'.$safeCode.'</div><p>This code is reusable until <strong>'.$safeExpiry.'</strong>.</p><p style="color:#6c757d">Requesting a new code invalidates the previous code.</p></div></body></html>';
    $text = $roleLabel." Portal Access\n\n".$name."\nCode: ".$code."\nValid until: ".$expiresAt->format('d M Y, h:i A')."\n";
    return portalAuthSendBrevoEmail($email, $name, $subject, $html, $text, $tag);
}

function requirePortalRole(string $requiredRole): void
{
    // A real administrator remains authorized for /admin/* even while testing
    // a School, Teacher or Parent view.
    if ($requiredRole === 'admin' && portalAuthIsAdminAuthenticated()) {
        return;
    }

    if (portalAuthCurrentRole() === $requiredRole) {
        $_SESSION['portal_role'] = $requiredRole;
        return;
    }

    $return = (string)($_SERVER['REQUEST_URI'] ?? portalAuthDashboardForRole($requiredRole));
    header('Location: /login.php?role=' . rawurlencode($requiredRole) . '&return=' . rawurlencode($return));
    exit;
}
