<?php
declare(strict_types=1);

if (!function_exists('portalFixedPinSchemaReady')) {
    function portalFixedPinSchemaReady(PDO $pdo, string $role): bool
    {
        $tables = match ($role) {
            'admin' => ['admin_users', 'admin_access_codes'],
            'jpn' => ['jpn_officials', 'jpn_access_codes'],
            default => [],
        };

        if (!$tables) {
            return false;
        }

        try {
            foreach ($tables as $table) {
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
            error_log(
                'Fixed PIN schema check failed: '
                . $e->getMessage()
            );
            return false;
        }
    }
}

if (!function_exists('portalFixedPinVerify')) {
    function portalFixedPinVerify(
        PDO $pdo,
        string $role,
        string $email,
        string $pin
    ): array {
        $role = strtolower(trim($role));
        $email = strtolower(trim($email));
        $pin = preg_replace('/\D+/', '', $pin) ?? '';

        if (
            !in_array($role, ['admin', 'jpn'], true)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !preg_match('/^\d{6}$/', $pin)
        ) {
            return [
                'success' => false,
                'message' => 'Enter a valid email and 6-digit PIN.',
            ];
        }

        if (!portalFixedPinSchemaReady($pdo, $role)) {
            return [
                'success' => false,
                'message' => strtoupper($role)
                    . ' PIN login database setup is required.',
            ];
        }

        if ($role === 'admin') {
            $stmt = $pdo->prepare(
                'SELECT
                    u.id,
                    u.full_name,
                    u.email,
                    u.is_active,
                    a.code_hash,
                    a.expires_at
                 FROM admin_users u
                 JOIN admin_access_codes a
                   ON a.admin_id = u.id
                 WHERE LOWER(u.email) = LOWER(?)
                 LIMIT 1'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT
                    u.id,
                    u.full_name,
                    u.email,
                    u.position,
                    u.department,
                    u.scope_state,
                    u.scope_district,
                    u.is_active,
                    a.code_hash,
                    a.expires_at
                 FROM jpn_officials u
                 JOIN jpn_access_codes a
                   ON a.jpn_official_id = u.id
                 WHERE LOWER(u.email) = LOWER(?)
                 LIMIT 1'
            );
        }

        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (
            !$row
            || empty($row['is_active'])
            || !password_verify(
                $pin,
                (string)($row['code_hash'] ?? '')
            )
        ) {
            return [
                'success' => false,
                'message' => 'Incorrect email or 6-digit PIN.',
            ];
        }

        $expiresTs = strtotime(
            (string)($row['expires_at'] ?? '')
        );

        if (
            $expiresTs === false
            || $expiresTs < time()
        ) {
            return [
                'success' => false,
                'expired' => true,
                'message' => 'This 6-digit PIN has expired.',
            ];
        }

        if ($role === 'admin') {
            $pdo->prepare(
                'UPDATE admin_access_codes
                 SET last_used_at = NOW()
                 WHERE admin_id = ?'
            )->execute([(int)$row['id']]);
        } else {
            $pdo->prepare(
                'UPDATE jpn_access_codes
                 SET last_used_at = NOW()
                 WHERE jpn_official_id = ?'
            )->execute([(int)$row['id']]);

            try {
                $pdo->prepare(
                    'UPDATE jpn_officials
                     SET last_login_at = NOW()
                     WHERE id = ?'
                )->execute([(int)$row['id']]);
            } catch (Throwable $e) {
                // Older JPN table versions may not have last_login_at.
            }
        }

        return [
            'success' => true,
            'row' => $row,
            'expires_ts' => $expiresTs,
        ];
    }
}
