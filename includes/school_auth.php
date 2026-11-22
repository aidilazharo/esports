<?php
/**
 * School/teacher portal authentication helpers.
 *
 * Schools receive a reusable 6-digit access code at schools.contact_email.
 * Teachers receive a reusable 6-digit access code at teachers.email.
 * Codes are stored only as password hashes and expire one month after issue.
 */

if (!function_exists('portalAccessTableExists')) {
    function portalAccessTableExists(PDO $pdo, string $table): bool
    {
        // Do not query information_schema here. Some shared-hosting MySQL users
        // have restricted metadata permissions, which can turn the login page
        // into HTTP 500 before the form is even rendered.
        $allowed = ['school_access_codes', 'teacher_access_codes'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }

        try {
            // The table name comes only from the whitelist above.
            $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            // Missing table / restricted metadata / incomplete upgrade should
            // be shown as "database upgrade required", not as HTTP 500.
            error_log('Portal auth table check failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('schoolAccessSchemaReady')) {
    function schoolAccessSchemaReady(PDO $pdo): bool
    {
        return portalAccessTableExists($pdo, 'school_access_codes');
    }
}

if (!function_exists('teacherAccessSchemaReady')) {
    function teacherAccessSchemaReady(PDO $pdo): bool
    {
        return portalAccessTableExists($pdo, 'teacher_access_codes');
    }
}

if (!function_exists('schoolAccessCurrentUrl')) {
    function schoolAccessCurrentUrl(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($requestUri === '' || !str_starts_with($requestUri, '/') || preg_match('/[\r\n]/', $requestUri)) {
            return basename((string) ($_SERVER['PHP_SELF'] ?? 'school_portal.php'));
        }
        return $requestUri;
    }
}

if (!function_exists('schoolAccessSafeReturn')) {
    function schoolAccessSafeReturn(?string $value, string $fallback = 'school_portal.php'): string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/[\r\n]/', $value)) {
            return $fallback;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_starts_with($value, '/\\')) {
            return $value;
        }
        if (preg_match('/^[A-Za-z0-9_\-\.]+(?:\.php)?(?:\?.*)?$/', $value)) {
            return $value;
        }
        return $fallback;
    }
}

if (!function_exists('teacherAccessLogout')) {
    function teacherAccessLogout(bool $regenerate = true): void
    {
        unset(
            $_SESSION['teacher_authenticated'],
            $_SESSION['teacher_id'],
            $_SESSION['teacher_name'],
            $_SESSION['teacher_email'],
            $_SESSION['teacher_access_expires_at'],
            $_SESSION['teacher_login_attempts'],
            $_SESSION['teacher_locked_until'],
            $_SESSION['teacher_pending_email']
        );
        if (empty($_SESSION['school_authenticated'])) {
            unset($_SESSION['school_id'], $_SESSION['school_name'], $_SESSION['school_code']);
        }
        if (($_SESSION['portal_role'] ?? '') === 'teacher') {
            unset($_SESSION['portal_role']);
        }
        if ($regenerate && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

if (!function_exists('schoolAccessLogout')) {
    function schoolAccessLogout(bool $regenerate = true): void
    {
        unset(
            $_SESSION['school_authenticated'],
            $_SESSION['school_id'],
            $_SESSION['school_name'],
            $_SESSION['school_code'],
            $_SESSION['school_access_expires_at'],
            $_SESSION['school_login_attempts'],
            $_SESSION['school_locked_until'],
            $_SESSION['school_pending_code'],
            $_SESSION['psn_unlock_school_id'],
            $_SESSION['psn_unlock_until'],
            $_SESSION['psn_unlock_attempts'],
            $_SESSION['psn_unlock_locked_until'],
            $_SESSION['teacher_authenticated'],
            $_SESSION['teacher_id'],
            $_SESSION['teacher_name'],
            $_SESSION['teacher_email'],
            $_SESSION['teacher_access_expires_at'],
            $_SESSION['teacher_login_attempts'],
            $_SESSION['teacher_locked_until'],
            $_SESSION['teacher_pending_email']
        );
        if (in_array((string) ($_SESSION['portal_role'] ?? ''), ['school', 'teacher'], true)) {
            unset($_SESSION['portal_role']);
        }
        if ($regenerate && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

if (!function_exists('schoolAccessSession')) {
    function schoolAccessSession(): ?array
    {
        if (!empty($_SESSION['school_authenticated']) && !empty($_SESSION['school_id'])) {
            $expiresAt = (int) ($_SESSION['school_access_expires_at'] ?? 0);
            if ($expiresAt > time()) {
                return [
                    'auth_type' => 'school',
                    'school_id' => (int) $_SESSION['school_id'],
                    'school_name' => (string) ($_SESSION['school_name'] ?? ''),
                    'school_code' => (string) ($_SESSION['school_code'] ?? ''),
                    'expires_at' => $expiresAt,
                    'teacher_id' => 0,
                    'teacher_name' => '',
                    'teacher_email' => '',
                ];
            }
            schoolAccessLogout(false);
        }

        if (!empty($_SESSION['teacher_authenticated']) && !empty($_SESSION['teacher_id']) && !empty($_SESSION['school_id'])) {
            $expiresAt = (int) ($_SESSION['teacher_access_expires_at'] ?? 0);
            if ($expiresAt > time()) {
                return [
                    'auth_type' => 'teacher',
                    'school_id' => (int) $_SESSION['school_id'],
                    'school_name' => (string) ($_SESSION['school_name'] ?? ''),
                    'school_code' => (string) ($_SESSION['school_code'] ?? ''),
                    'expires_at' => $expiresAt,
                    'teacher_id' => (int) $_SESSION['teacher_id'],
                    'teacher_name' => (string) ($_SESSION['teacher_name'] ?? ''),
                    'teacher_email' => (string) ($_SESSION['teacher_email'] ?? ''),
                ];
            }
            teacherAccessLogout(false);
        }

        return null;
    }
}

if (!function_exists('schoolAccessIsAuthenticated')) {
    function schoolAccessIsAuthenticated(): bool
    {
        return schoolAccessSession() !== null;
    }
}

if (!function_exists('schoolAccessSchoolId')) {
    function schoolAccessSchoolId(): int
    {
        $session = schoolAccessSession();
        return $session ? (int) $session['school_id'] : 0;
    }
}

if (!function_exists('requireSchoolAccess')) {
    function requireSchoolAccess(string $protectedPageName = 'School Portal'): void
    {
        $session = schoolAccessSession();
        if ($session !== null) {
            $_SESSION['portal_role'] = (string) $session['auth_type'];
            return;
        }

        $return = schoolAccessCurrentUrl();
        header('Location: login.php?role=school&return=' . rawurlencode($return));
        exit;
    }
}

if (!function_exists('schoolAccessMaskEmail')) {
    function schoolAccessMaskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }
        $local = $parts[0];
        $masked = substr($local, 0, min(2, strlen($local))) . str_repeat('*', max(2, strlen($local) - 2));
        return $masked . '@' . $parts[1];
    }
}

if (!function_exists('portalAccessSendBrevoEmail')) {
    function portalAccessSendBrevoEmail(string $recipientEmail, string $recipientName, string $subject, string $html, string $text, string $tag): array
    {
        $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));
        $senderEmail = trim((string) (getenv('BREVO_SENDER_EMAIL') ?: ''));
        $senderName = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'Metadata Berhad'));
        $replyTo = trim((string) (getenv('BREVO_REPLY_TO_EMAIL') ?: $senderEmail));

        if ($apiKey === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Brevo email is not configured. Check BREVO_API_KEY and BREVO_SENDER_EMAIL.'];
        }
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'A valid recipient email is required.'];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => 'PHP cURL is required to send the access email.'];
        }

        $payload = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [['name' => $recipientName, 'email' => $recipientEmail]],
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
            'tags' => [$tag],
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $payload['replyTo'] = ['name' => $senderName, 'email' => $replyTo];
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return ['success' => false, 'message' => 'Brevo email connection error: ' . $error];
        }
        $decoded = json_decode((string) $body, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['code'] ?? 'Unknown Brevo error') : 'Unknown Brevo error';
            return ['success' => false, 'message' => 'Brevo email HTTP ' . $httpCode . ': ' . $message];
        }
        return ['success' => true, 'messageId' => is_array($decoded) ? (string) ($decoded['messageId'] ?? '') : ''];
    }
}

if (!function_exists('schoolAccessSendEmail')) {
    function schoolAccessSendEmail(array $school, string $code, DateTimeImmutable $expiresAt): array
    {
        $recipientEmail = trim((string) ($school['contact_email'] ?? ''));
        $recipientName = trim((string) ($school['contact_name'] ?? '')) ?: trim((string) ($school['school_name'] ?? 'School Administrator'));
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Unable to generate a valid 6-digit school code.'];
        }

        $safeSchool = htmlspecialchars((string) ($school['school_name'] ?? 'School'), ENT_QUOTES, 'UTF-8');
        $safeSchoolCode = htmlspecialchars((string) ($school['school_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeExpiry = htmlspecialchars($expiresAt->format('d M Y, h:i A'), ENT_QUOTES, 'UTF-8');
        $subject = 'School Portal 6-Digit Access Code - ' . (string) ($school['school_code'] ?? '');
        $html = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f8;padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;"><tr><td style="padding:28px;">
<h1 style="font-size:22px;margin:0 0 14px;">School Portal Access Code</h1>
<p style="font-size:15px;line-height:1.6;margin:0 0 10px;"><strong>{$safeSchool}</strong> ({$safeSchoolCode})</p>
<p style="font-size:15px;line-height:1.6;margin:0 0 18px;">Use this 6-digit code to sign in to the school portal:</p>
<div style="font-size:32px;letter-spacing:8px;font-weight:700;text-align:center;background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:20px 0;">{$safeCode}</div>
<p style="font-size:14px;line-height:1.6;margin:0;">This code is reusable until <strong>{$safeExpiry}</strong> (1 month from issue).</p>
<p style="font-size:13px;line-height:1.6;color:#6c757d;margin:18px 0 0;">Requesting a new code invalidates the previous code.</p>
</td></tr></table></td></tr></table></body></html>
HTML;
        $text = "School Portal Access Code\n\n" . (string) ($school['school_name'] ?? 'School') . ' (' . (string) ($school['school_code'] ?? '') . ")\nCode: {$code}\nValid until: " . $expiresAt->format('d M Y, h:i A') . " (1 month from issue).\n";
        return portalAccessSendBrevoEmail($recipientEmail, $recipientName, $subject, $html, $text, 'school-portal-access-code');
    }
}

if (!function_exists('teacherAccessSendEmail')) {
    function teacherAccessSendEmail(array $teacher, string $code, DateTimeImmutable $expiresAt): array
    {
        $recipientEmail = trim((string) ($teacher['email'] ?? ''));
        $recipientName = trim((string) ($teacher['full_name'] ?? 'Teacher'));
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Unable to generate a valid 6-digit teacher code.'];
        }

        $safeTeacher = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeSchool = htmlspecialchars((string) ($teacher['school_name'] ?? 'School'), ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeExpiry = htmlspecialchars($expiresAt->format('d M Y, h:i A'), ENT_QUOTES, 'UTF-8');
        $subject = 'Teacher School Portal 6-Digit Access Code';
        $html = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f8;padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;"><tr><td style="padding:28px;">
<h1 style="font-size:22px;margin:0 0 14px;">Teacher School Portal Access</h1>
<p style="font-size:15px;line-height:1.6;margin:0 0 10px;">Hello <strong>{$safeTeacher}</strong>,</p>
<p style="font-size:15px;line-height:1.6;margin:0 0 18px;">Use this code to access <strong>{$safeSchool}</strong>:</p>
<div style="font-size:32px;letter-spacing:8px;font-weight:700;text-align:center;background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:20px 0;">{$safeCode}</div>
<p style="font-size:14px;line-height:1.6;margin:0;">This code is reusable until <strong>{$safeExpiry}</strong> (1 month from issue).</p>
<p style="font-size:13px;line-height:1.6;color:#6c757d;margin:18px 0 0;">Requesting a new code invalidates the previous code. Do not share it with another person.</p>
</td></tr></table></td></tr></table></body></html>
HTML;
        $text = "Teacher School Portal Access\n\n{$recipientName}\nSchool: " . (string) ($teacher['school_name'] ?? 'School') . "\nCode: {$code}\nValid until: " . $expiresAt->format('d M Y, h:i A') . " (1 month from issue).\n";
        return portalAccessSendBrevoEmail($recipientEmail, $recipientName, $subject, $html, $text, 'teacher-portal-access-code');
    }
}
