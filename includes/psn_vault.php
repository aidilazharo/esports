<?php
declare(strict_types=1);

/**
 * PSN account vault. Credentials are encrypted using an externally configured
 * 256-bit key; the PSN TOTP shared secret is NEVER rendered to the browser.
 * Required environment: PSN_CREDENTIAL_KEY=base64-encoded 32 random bytes.
 */

function psnVaultKey(): string
{
    $configured = (string)(getenv('PSN_CREDENTIAL_KEY') ?: '');
    $key = base64_decode($configured, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('PSN credential encryption key is not configured.');
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for PSN credential encryption.');
    }
    return $key;
}

function psnVaultEncrypt(string $plaintext): string
{
    $key = psnVaultKey();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('PSN encryption failed.');
    }
    return 'gcm1:' . base64_encode($iv . $tag . $ciphertext);
}

function psnVaultDecrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '') return null;
    if (!str_starts_with($stored, 'gcm1:')) {
        throw new RuntimeException('Unrecognized PSN encrypted credential format.');
    }
    $packed = base64_decode(substr($stored, 5), true);
    if ($packed === false || strlen($packed) < 28) {
        throw new RuntimeException('Corrupted PSN encrypted credential.');
    }
    $plaintext = openssl_decrypt(
        substr($packed, 28), 'aes-256-gcm', psnVaultKey(),
        OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16)
    );
    if ($plaintext === false) {
        throw new RuntimeException('Unable to decrypt PSN credential.');
    }
    return $plaintext;
}

/** Base32 RFC 4648, as used by the uploaded psn_totp.php reference. */
function psnTotpNormalize(string $secret): string
{
    $value = strtoupper(preg_replace('/[\s\-]+/', '', $secret) ?? '');
    $value = rtrim($value, '=');
    if (strlen($value) < 16 || strlen($value) > 256 || !preg_match('/^[A-Z2-7]+$/D', $value)) {
        throw new InvalidArgumentException('Enter a valid Base32 authenticator secret (at least 16 characters).');
    }
    psnBase32Decode($value);
    return $value;
}

function psnBase32Decode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = 0;
    $buffer = 0;
    $result = '';
    foreach (str_split($value) as $character) {
        $index = strpos($alphabet, $character);
        if ($index === false) throw new InvalidArgumentException('Invalid Base32 character.');
        $buffer = ($buffer << 5) | $index;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $result .= chr(($buffer >> $bits) & 0xff);
            $buffer &= (1 << $bits) - 1;
        }
    }
    if ($result === '') throw new InvalidArgumentException('Invalid Base32 secret.');
    return $result;
}

/** RFC 6238 SHA-1 / 6-digit / 30-second code, matching psn_totp.php. */
function psnTotpCode(string $base32Secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $counter = intdiv(max(0, $timestamp), 30);
    $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $message, psnBase32Decode($base32Secret), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $truncated = unpack('N', substr($hash, $offset, 4));
    $integer = ($truncated[1] ?? 0) & 0x7fffffff;
    return str_pad((string)($integer % 1000000), 6, '0', STR_PAD_LEFT);
}

function psnNoCacheHeaders(): void
{
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
}

function psnVaultSchemaReady(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT psn_online_id, password_encrypted, totp_secret_encrypted FROM school_psn_accounts LIMIT 0');
        $pdo->query('SELECT id FROM psn_access_audit LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function psnAudit(PDO $pdo, int $accountId, int $schoolId, string $role, int $actorId, string $action): void
{
    $stmt = $pdo->prepare('INSERT INTO psn_access_audit (psn_account_id, school_id, actor_role, actor_id, action) VALUES (?,?,?,?,?)');
    $stmt->execute([$accountId, $schoolId, $role, $actorId, $action]);
}

/** The school PIN itself is checked against the existing authenticated school record. */
function psnSchoolPinValid(PDO $pdo, int $schoolId, string $pin): bool
{
    if (!preg_match('/^\d{6}$/D', $pin)) return false;
    $stmt = $pdo->prepare('SELECT code_hash, expires_at FROM school_access_codes WHERE school_id = ? LIMIT 1');
    $stmt->execute([$schoolId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) return false;
    $expiry = strtotime((string)($record['expires_at'] ?? ''));
    return $expiry !== false && $expiry > time()
        && password_verify($pin, (string)$record['code_hash']);
}

function psnSchoolUnlocked(int $schoolId): bool
{
    return $schoolId > 0
        && (int)($_SESSION['psn_unlock_school_id'] ?? 0) === $schoolId
        && (int)($_SESSION['psn_unlock_until'] ?? 0) > time()
        && hash_equals((string)($_SESSION['psn_unlock_session_id_hash'] ?? ''), hash('sha256', session_id()))
        && !empty($_SESSION['school_authenticated'])
        && (int)($_SESSION['school_id'] ?? 0) === $schoolId;
}

function psnSchoolGate(): int
{
    requirePortalRole('school');
    // Admin Test Mode is not a valid substitute for a genuine school session.
    if (portalAuthIsAdminTestMode('school') || empty($_SESSION['school_authenticated'])) {
        http_response_code(403);
        exit('A direct school login is required for PSN credentials.');
    }
    $schoolId = (int)($_SESSION['school_id'] ?? 0);
    if ($schoolId < 1) {
        http_response_code(403);
        exit('School session is required.');
    }
    return $schoolId;
}


/**
 * Selects an explicitly authorized PSN credential scope. Administrator Test
 * Mode is treated as an ADMIN action, never as a genuine school login.
 * The school_id query parameter is honored ONLY for a live admin session.
 *
 * @return array{role:string,actor_id:int,school_id:int,mode:string,layout_role:string,school_name:string}
 */
function psnCredentialScope(PDO $pdo): array
{
    $adminAuthenticated = portalAuthIsAdminAuthenticated();
    $testRole = (string)($_SESSION['admin_test_role'] ?? '');

    if ($testRole !== '' && !$adminAuthenticated) {
        http_response_code(403);
        exit('An active administrator session is required.');
    }

    if ($adminAuthenticated) {
        $actorId = (int)($_SESSION['admin_id'] ?? 0);
        if ($actorId < 1) {
            http_response_code(403);
            exit('A valid administrator login is required.');
        }
        if ($testRole === 'school') {
            // The school must be the specific one selected for Admin Test Mode.
            $schoolId = (int)($_SESSION['admin_test_target_id'] ?? 0);
            if ($schoolId < 1 || $schoolId !== (int)($_SESSION['school_id'] ?? 0)
                || empty($_SESSION['school_authenticated'])
                || (int)($_SESSION['school_access_expires_at'] ?? 0) <= time()) {
                http_response_code(403);
                exit('The selected school session is no longer valid.');
            }
            $mode = 'admin_test';
            $layoutRole = 'school';
        } else {
            // An admin can explicitly select any school, but a school session
            // never gets to choose its own ID through a query parameter.
            $schoolId = max(0, (int)($_GET['school_id'] ?? 0));
            if ($schoolId < 1) {
                header('Location: /admin/psn_accounts.php');
                exit;
            }
            $mode = 'admin_direct';
            $layoutRole = 'admin';
        }
        $role = 'admin';
    } else {
        // This is NOT a test session. Only a direct school login is accepted.
        requirePortalRole('school');
        if (empty($_SESSION['school_authenticated'])
            || (int)($_SESSION['school_access_expires_at'] ?? 0) <= time()) {
            http_response_code(403);
            exit('A direct school login is required for PSN credentials.');
        }
        $schoolId = (int)($_SESSION['school_id'] ?? 0);
        $actorId = $schoolId;
        $role = 'school';
        $mode = 'school';
        $layoutRole = 'school';
    }

    if ($schoolId < 1) {
        http_response_code(403);
        exit('School context is required.');
    }
    $stmt = $pdo->prepare('SELECT school_name FROM schools WHERE id=? LIMIT 1');
    $stmt->execute([$schoolId]);
    $schoolName = $stmt->fetchColumn();
    if ($schoolName === false) {
        http_response_code(404);
        exit('School not found.');
    }

    return [
        'role' => $role,
        'actor_id' => $actorId,
        'school_id' => $schoolId,
        'mode' => $mode,
        'layout_role' => $layoutRole,
        'school_name' => (string)$schoolName,
    ];
}

/** Admin step-up using the current admin's own reusable portal access PIN. */
function psnAdminPinValid(PDO $pdo, int $adminId, string $pin): bool
{
    if ($adminId < 1 || !preg_match('/^[0-9]{6}$/D', $pin)) return false;
    $stmt = $pdo->prepare(
        'SELECT c.code_hash, c.expires_at FROM admin_access_codes c
         JOIN admin_users u ON u.id=c.admin_id
         WHERE c.admin_id=? AND u.is_active=1 LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) return false;
    $expires = strtotime((string)($record['expires_at'] ?? ''));
    return $expires !== false && $expires > time()
        && password_verify($pin, (string)$record['code_hash']);
}

function psnClearCredentialUnlock(): void
{
    unset(
        $_SESSION['psn_unlock_school_id'],
        $_SESSION['psn_unlock_until'],
        $_SESSION['psn_unlock_session_id_hash'],
        $_SESSION['psn_unlock_actor_role'],
        $_SESSION['psn_unlock_actor_id'],
        $_SESSION['psn_unlock_context_mode']
    );
}

/** Credentials are bound to one school, one actor, one login mode, and a session. */
function psnCredentialUnlocked(array $scope): bool
{
    if ((int)($_SESSION['psn_unlock_school_id'] ?? 0) !== $scope['school_id']
        || (int)($_SESSION['psn_unlock_actor_id'] ?? 0) !== $scope['actor_id']
        || (string)($_SESSION['psn_unlock_actor_role'] ?? '') !== $scope['role']
        || (string)($_SESSION['psn_unlock_context_mode'] ?? '') !== $scope['mode']
        || (int)($_SESSION['psn_unlock_until'] ?? 0) <= time()) return false;

    $expected = hash('sha256', session_id());
    $actual = (string)($_SESSION['psn_unlock_session_id_hash'] ?? '');
    if (!hash_equals($expected, $actual)) return false;

    if ($scope['role'] === 'admin') {
        return portalAuthIsAdminAuthenticated()
            && (int)($_SESSION['admin_id'] ?? 0) === $scope['actor_id'];
    }
    return !empty($_SESSION['school_authenticated'])
        && (int)($_SESSION['school_id'] ?? 0) === $scope['school_id']
        && (int)($_SESSION['school_access_expires_at'] ?? 0) > time()
        && !portalAuthIsAdminTestMode();
}
