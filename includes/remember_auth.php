<?php
declare(strict_types=1);

const PORTAL_REMEMBER_COOKIE = 'metadata_portal_remember';
const PORTAL_REMEMBER_DAYS = 30;

if (!function_exists('portalRememberTableReady')) {
    function portalRememberTableReady(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'portal_remember_tokens'"
            );

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portalRememberCookieOptions')) {
    function portalRememberCookieOptions(int $expires): array
    {
        $secure =
            (!empty($_SERVER['HTTPS'])
                && $_SERVER['HTTPS'] !== 'off')
            || strtolower(
                trim(
                    (string)(
                        $_SERVER['HTTP_X_FORWARDED_PROTO']
                        ?? ''
                    )
                )
            ) === 'https';

        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}

if (!function_exists('portalRememberIssue')) {
    function portalRememberIssue(
        PDO $pdo,
        string $role,
        int $subjectId
    ): void {
        if (
            !portalRememberTableReady($pdo)
            || $subjectId < 1
            || !in_array(
                $role,
                ['school','teacher','parent','jpn','admin'],
                true
            )
        ) {
            return;
        }

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);

        $expires = (new DateTimeImmutable('now'))
            ->modify('+' . PORTAL_REMEMBER_DAYS . ' days');

        /*
         * Only one remembered session per browser/account pair is needed.
         * Old tokens for the same role/account are removed first.
         */
        $pdo->prepare(
            'DELETE FROM portal_remember_tokens
             WHERE role = ?
               AND subject_id = ?'
        )->execute([$role, $subjectId]);

        $pdo->prepare(
            'INSERT INTO portal_remember_tokens
             (
                role,
                subject_id,
                selector,
                validator_hash,
                expires_at,
                created_at,
                last_used_at,
                user_agent_hash
             )
             VALUES (?, ?, ?, ?, ?, NOW(), NULL, ?)'
        )->execute([
            $role,
            $subjectId,
            $selector,
            $validatorHash,
            $expires->format('Y-m-d H:i:s'),
            hash(
                'sha256',
                (string)(
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                )
            ),
        ]);

        setcookie(
            PORTAL_REMEMBER_COOKIE,
            $selector . ':' . $validator,
            portalRememberCookieOptions(
                $expires->getTimestamp()
            )
        );
    }
}

if (!function_exists('portalRememberForgetCurrent')) {
    function portalRememberForgetCurrent(PDO $pdo): void
    {
        $cookie = (string)(
            $_COOKIE[PORTAL_REMEMBER_COOKIE] ?? ''
        );

        if (
            $cookie !== ''
            && portalRememberTableReady($pdo)
        ) {
            [$selector] = array_pad(
                explode(':', $cookie, 2),
                2,
                ''
            );

            if ($selector !== '') {
                $pdo->prepare(
                    'DELETE FROM portal_remember_tokens
                     WHERE selector = ?'
                )->execute([$selector]);
            }
        }

        setcookie(
            PORTAL_REMEMBER_COOKIE,
            '',
            portalRememberCookieOptions(
                time() - 3600
            )
        );

        unset(
            $_COOKIE[PORTAL_REMEMBER_COOKIE]
        );
    }
}

if (!function_exists('portalRememberRestore')) {
    function portalRememberRestore(PDO $pdo): bool
    {
        /*
         * Existing authenticated sessions take precedence.
         */
        if (
            !empty($_SESSION['admin_authenticated'])
            || !empty($_SESSION['jpn_authenticated'])
            || !empty($_SESSION['school_authenticated'])
            || !empty($_SESSION['teacher_authenticated'])
            || !empty($_SESSION['parent_authenticated'])
        ) {
            return false;
        }

        if (!portalRememberTableReady($pdo)) {
            return false;
        }

        $cookie = (string)(
            $_COOKIE[PORTAL_REMEMBER_COOKIE] ?? ''
        );

        if ($cookie === '') {
            return false;
        }

        [$selector, $validator] = array_pad(
            explode(':', $cookie, 2),
            2,
            ''
        );

        if (
            !preg_match('/^[a-f0-9]{24}$/', $selector)
            || !preg_match('/^[a-f0-9]{64}$/', $validator)
        ) {
            portalRememberForgetCurrent($pdo);
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM portal_remember_tokens
             WHERE selector = ?
             LIMIT 1'
        );
        $stmt->execute([$selector]);
        $token = $stmt->fetch();

        if (!$token) {
            portalRememberForgetCurrent($pdo);
            return false;
        }

        $expiresTs = strtotime(
            (string)$token['expires_at']
        );

        if (
            $expiresTs === false
            || $expiresTs < time()
            || !hash_equals(
                (string)$token['validator_hash'],
                hash('sha256', $validator)
            )
        ) {
            $pdo->prepare(
                'DELETE FROM portal_remember_tokens
                 WHERE id = ?'
            )->execute([(int)$token['id']]);

            portalRememberForgetCurrent($pdo);
            return false;
        }

        $role = (string)$token['role'];
        $subjectId = (int)$token['subject_id'];

        session_regenerate_id(true);

        /*
         * Rebuild the same session context normally created after PIN login.
         */
        if ($role === 'school') {
            $stmt = $pdo->prepare(
                'SELECT id, school_code, school_name
                 FROM schools
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$subjectId]);
            $row = $stmt->fetch();

            if (!$row) {
                portalRememberForgetCurrent($pdo);
                return false;
            }

            portalAuthClearOtherRoles('school');

            $_SESSION['school_authenticated'] = true;
            $_SESSION['school_id'] = (int)$row['id'];
            $_SESSION['school_code'] = (string)$row['school_code'];
            $_SESSION['school_name'] = (string)$row['school_name'];
            $_SESSION['school_access_expires_at'] = $expiresTs;
            $_SESSION['portal_role'] = 'school';
        }

        if ($role === 'teacher') {
            $stmt = $pdo->prepare(
                'SELECT
                    t.id,
                    t.full_name,
                    t.email,
                    t.school_id,
                    s.school_code,
                    s.school_name
                 FROM teachers t
                 JOIN schools s
                   ON s.id = t.school_id
                 WHERE t.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$subjectId]);
            $row = $stmt->fetch();

            if (!$row) {
                portalRememberForgetCurrent($pdo);
                return false;
            }

            portalAuthClearOtherRoles('teacher');

            $_SESSION['teacher_authenticated'] = true;
            $_SESSION['teacher_id'] = (int)$row['id'];
            $_SESSION['teacher_name'] = (string)$row['full_name'];
            $_SESSION['teacher_email'] = (string)$row['email'];
            $_SESSION['teacher_access_expires_at'] = $expiresTs;

            $_SESSION['school_id'] = (int)$row['school_id'];
            $_SESSION['school_code'] = (string)$row['school_code'];
            $_SESSION['school_name'] = (string)$row['school_name'];
            $_SESSION['portal_role'] = 'teacher';
        }

        if ($role === 'parent') {
            $stmt = $pdo->prepare(
                'SELECT id, guardian_number, full_name, email
                 FROM guardians
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$subjectId]);
            $row = $stmt->fetch();

            if (!$row) {
                portalRememberForgetCurrent($pdo);
                return false;
            }

            portalAuthClearOtherRoles('parent');

            $_SESSION['parent_authenticated'] = true;
            $_SESSION['parent_id'] = (int)$row['id'];
            $_SESSION['parent_number'] =
                (string)($row['guardian_number'] ?? '');
            $_SESSION['parent_name'] = (string)$row['full_name'];
            $_SESSION['parent_email'] = (string)$row['email'];
            $_SESSION['parent_access_expires_at'] = $expiresTs;
            $_SESSION['portal_role'] = 'parent';
        }

        if ($role === 'jpn') {
            $stmt = $pdo->prepare(
                'SELECT
                    id,
                    full_name,
                    email,
                    position,
                    department,
                    scope_state,
                    scope_district,
                    is_active
                 FROM jpn_officials
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$subjectId]);
            $row = $stmt->fetch();

            if (!$row || empty($row['is_active'])) {
                portalRememberForgetCurrent($pdo);
                return false;
            }

            portalAuthLogoutAll(false);

            $_SESSION['jpn_authenticated'] = true;
            $_SESSION['jpn_official_id'] = (int)$row['id'];
            $_SESSION['jpn_name'] = (string)$row['full_name'];
            $_SESSION['jpn_email'] = (string)$row['email'];
            $_SESSION['jpn_position'] =
                (string)($row['position'] ?? '');
            $_SESSION['jpn_department'] =
                (string)($row['department'] ?? '');
            $_SESSION['jpn_scope_state'] =
                (string)($row['scope_state'] ?? '');
            $_SESSION['jpn_scope_district'] =
                (string)($row['scope_district'] ?? '');
            $_SESSION['jpn_access_expires_at'] = $expiresTs;
            $_SESSION['portal_role'] = 'jpn';
        }

        if ($role === 'admin') {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, is_active
                 FROM admin_users
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$subjectId]);
            $row = $stmt->fetch();

            if (!$row || empty($row['is_active'])) {
                portalRememberForgetCurrent($pdo);
                return false;
            }

            portalAuthLogoutAll(false);

            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_id'] = (int)$row['id'];
            $_SESSION['admin_name'] = (string)$row['full_name'];
            $_SESSION['admin_email'] = (string)$row['email'];
            $_SESSION['admin_access_expires_at'] = $expiresTs;
            $_SESSION['portal_role'] = 'admin';
        }

        if (
            !in_array(
                $role,
                ['school','teacher','parent','jpn','admin'],
                true
            )
        ) {
            portalRememberForgetCurrent($pdo);
            return false;
        }

        $pdo->prepare(
            'UPDATE portal_remember_tokens
             SET last_used_at = NOW()
             WHERE id = ?'
        )->execute([(int)$token['id']]);

        return true;
    }
}
