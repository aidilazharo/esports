<?php
declare(strict_types=1);

if (!function_exists('jpnAuthSchemaReady')) {
    function jpnAuthSchemaReady(PDO $pdo): bool
    {
        try {
            foreach (['jpn_officials', 'jpn_access_codes'] as $table) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?'
                );
                $stmt->execute([$table]);

                if ((int)$stmt->fetchColumn() < 1) {
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('jpnAuthSafeReturn')) {
    function jpnAuthSafeReturn(
        ?string $value,
        string $fallback = '/jpn/'
    ): string {
        $value = trim((string)$value);

        if (
            $value === ''
            || preg_match('/[\r\n]/', $value)
        ) {
            return $fallback;
        }

        if (
            str_starts_with($value, '/')
            && !str_starts_with($value, '//')
            && !str_starts_with($value, '/\\')
        ) {
            return $value;
        }

        return $fallback;
    }
}

if (!function_exists('jpnAuthLogout')) {
    function jpnAuthLogout(bool $regenerate = true): void
    {
        unset(
            $_SESSION['jpn_authenticated'],
            $_SESSION['jpn_official_id'],
            $_SESSION['jpn_name'],
            $_SESSION['jpn_email'],
            $_SESSION['jpn_position'],
            $_SESSION['jpn_department'],
            $_SESSION['jpn_scope_state'],
            $_SESSION['jpn_scope_district'],
            $_SESSION['jpn_access_expires_at'],
            $_SESSION['jpn_login_attempts'],
            $_SESSION['jpn_locked_until']
        );

        if (
            ($_SESSION['portal_role'] ?? '') === 'jpn'
        ) {
            unset($_SESSION['portal_role']);
        }

        if (
            $regenerate
            && session_status() === PHP_SESSION_ACTIVE
        ) {
            session_regenerate_id(true);
        }
    }
}

if (!function_exists('jpnAuthSession')) {
    function jpnAuthSession(): ?array
    {
        if (empty($_SESSION['jpn_authenticated'])) {
            return null;
        }

        $id = (int)($_SESSION['jpn_official_id'] ?? 0);
        $expiresAt = (int)(
            $_SESSION['jpn_access_expires_at'] ?? 0
        );

        if (
            $id < 1
            || $expiresAt <= time()
        ) {
            jpnAuthLogout(false);
            return null;
        }

        return [
            'id' => $id,
            'name' => (string)(
                $_SESSION['jpn_name']
                ?? 'JPN Official'
            ),
            'email' => (string)(
                $_SESSION['jpn_email'] ?? ''
            ),
            'position' => (string)(
                $_SESSION['jpn_position'] ?? ''
            ),
            'department' => (string)(
                $_SESSION['jpn_department'] ?? ''
            ),
            'scope_state' => (string)(
                $_SESSION['jpn_scope_state'] ?? ''
            ),
            'scope_district' => (string)(
                $_SESSION['jpn_scope_district'] ?? ''
            ),
            'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('jpnAuthIsAuthenticated')) {
    function jpnAuthIsAuthenticated(): bool
    {
        return jpnAuthSession() !== null;
    }
}

if (!function_exists('jpnAuthRequire')) {
    function jpnAuthRequire(): array
    {
        $session = jpnAuthSession();

        if ($session !== null) {
            $_SESSION['portal_role'] = 'jpn';
            return $session;
        }

        $return = rawurlencode(
            (string)(
                $_SERVER['REQUEST_URI'] ?? '/jpn/'
            )
        );

        header(
            'Location: /login.php?role=jpn&return='
            . $return
        );
        exit;
    }
}

if (!function_exists('jpnAuthScope')) {
    function jpnAuthScope(string $schoolAlias = 's'): array
    {
        $session = jpnAuthSession();

        if ($session === null) {
            return [
                'sql' => '1=0',
                'params' => [],
                'label' => 'No access',
            ];
        }

        $conditions = [];
        $params = [];
        $labels = [];

        $state = trim(
            (string)$session['scope_state']
        );

        $district = trim(
            (string)$session['scope_district']
        );

        if ($state !== '') {
            $conditions[] =
                'UPPER('
                . $schoolAlias
                . '.state) = UPPER(?)';

            $params[] = $state;
            $labels[] = $state;
        }

        if ($district !== '') {
            $conditions[] =
                'UPPER('
                . $schoolAlias
                . '.district) = UPPER(?)';

            $params[] = $district;
            $labels[] = $district;
        }

        return [
            'sql' => $conditions
                ? implode(' AND ', $conditions)
                : '1=1',
            'params' => $params,
            'label' => $labels
                ? implode(' · ', $labels)
                : 'All Malaysia',
        ];
    }
}
