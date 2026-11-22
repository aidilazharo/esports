<?php
declare(strict_types=1);

/**
 * Administrator access now uses email + reusable 6-digit PIN.
 * The PIN expiry timestamp is stored in admin_access_codes.
 */

if (!function_exists('adminAccessCurrentUrl')) {
    function adminAccessCurrentUrl(): string
    {
        $requestUri = (string)(
            $_SERVER['REQUEST_URI'] ?? ''
        );

        if (
            $requestUri === ''
            || !str_starts_with($requestUri, '/')
            || preg_match('/[\r\n]/', $requestUri)
        ) {
            return '/admin/';
        }

        return $requestUri;
    }
}

if (!function_exists('adminAccessIsAuthenticated')) {
    function adminAccessIsAuthenticated(): bool
    {
        if (empty($_SESSION['admin_authenticated'])) {
            return false;
        }

        $expiresAt = (int)(
            $_SESSION['admin_access_expires_at'] ?? 0
        );

        if ($expiresAt <= time()) {
            adminAccessLogout(false);
            return false;
        }

        return true;
    }
}

if (!function_exists('adminAccessLogout')) {
    function adminAccessLogout(
        bool $regenerate = true
    ): void {
        unset(
            $_SESSION['admin_authenticated'],
            $_SESSION['admin_id'],
            $_SESSION['admin_name'],
            $_SESSION['admin_email'],
            $_SESSION['admin_access_expires_at'],
            $_SESSION['admin_login_attempts'],
            $_SESSION['admin_locked_until']
        );

        if (
            ($_SESSION['portal_role'] ?? '') === 'admin'
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

if (!function_exists('adminAccessIsConfigured')) {
    function adminAccessIsConfigured(): bool
    {
        global $pdo;

        if (!isset($pdo) || !$pdo instanceof PDO) {
            return false;
        }

        try {
            foreach (['admin_users', 'admin_access_codes'] as $table) {
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

/*
 * Retained only for compatibility with older code paths.
 * New login.php validates email + PIN using includes/pin_auth.php.
 */
if (!function_exists('adminAccessCodeMatches')) {
    function adminAccessCodeMatches(string $code): bool
    {
        return false;
    }
}

if (!function_exists('requireAdminAccess')) {
    function requireAdminAccess(
        string $protectedPageName = 'Admin Page'
    ): void {
        if (adminAccessIsAuthenticated()) {
            $_SESSION['portal_role'] = 'admin';
            return;
        }

        $return = rawurlencode(
            adminAccessCurrentUrl()
        );

        header(
            'Location: /login.php?role=admin&return='
            . $return
        );
        exit;
    }
}
