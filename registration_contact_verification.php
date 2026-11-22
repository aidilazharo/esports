<?php

/**
 * Load registration-related values from .env without overwriting values that
 * are already provided by the server environment.
 */
function registrationLoadEnvFile(string $file): array
{
    $result = [
        'loaded' => false,
        'file_exists' => is_file($file),
        'file_readable' => is_readable($file),
        'message' => '',
    ];

    if (!$result['file_exists']) {
        $result['message'] = '.env file was not found.';
        return $result;
    }

    if (!$result['file_readable']) {
        $result['message'] = '.env file is not readable.';
        return $result;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $result['message'] = 'Unable to read .env file.';
        return $result;
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && $value[strlen($value) - 1] === '"')
                || ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        // Preserve explicit server environment values when already configured.
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    $result['loaded'] = true;
    $result['message'] = '.env loaded successfully.';
    return $result;
}

$GLOBALS['registration_env_load_status'] = registrationLoadEnvFile(__DIR__ . '/.env');

/**
 * Validate the local values required for email-only verification.
 *
 * This is intentionally a local configuration check only; no external API
 * call is made during page load.
 */
function registrationVerificationServiceStatus(): array
{
    $envStatus = $GLOBALS['registration_env_load_status'] ?? [
        'loaded' => false,
        'file_exists' => false,
        'file_readable' => false,
        'message' => '.env status is unavailable.',
    ];

    $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim((string) (getenv('BREVO_SENDER_EMAIL') ?: ''));
    $appUrl = trim((string) (getenv('APP_URL') ?: ''));

    $problems = [];

    if ($apiKey === '') {
        $problems[] = 'BREVO_API_KEY is missing.';
    }

    if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        $problems[] = 'BREVO_SENDER_EMAIL is missing or invalid.';
    }

    if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
        $problems[] = 'APP_URL is missing or invalid.';
    }

    if (!function_exists('curl_init')) {
        $problems[] = 'PHP cURL is not available.';
    }

    return [
        'available' => $problems === [],
        'env_loaded' => !empty($envStatus['loaded']),
        'problems' => $problems,
        'message' => $problems === []
            ? 'Email verification service is configured.'
            : 'Email verification is currently unavailable. Please try again later.',
    ];
}

/**
 * Shared email-code and email-link verification helpers for registration pages.
 *
 * Requires .env values:
 *   BREVO_API_KEY
 *   BREVO_SENDER_EMAIL
 *   BREVO_SENDER_NAME=Metadata Berhad (optional)
 *   BREVO_REPLY_TO_EMAIL (optional)
 *   APP_URL=https://esports.metadata.technology
 */

if (!defined('REGISTRATION_EMAIL_CODE_EXPIRY_SECONDS')) {
    define('REGISTRATION_EMAIL_CODE_EXPIRY_SECONDS', 600);
}
if (!defined('REGISTRATION_EMAIL_CODE_RESEND_SECONDS')) {
    define('REGISTRATION_EMAIL_CODE_RESEND_SECONDS', 60);
}
if (!defined('REGISTRATION_EMAIL_VERIFY_EXPIRY_HOURS')) {
    define('REGISTRATION_EMAIL_VERIFY_EXPIRY_HOURS', 24);
}
if (!defined('REGISTRATION_BREVO_EMAIL_ENDPOINT')) {
    define('REGISTRATION_BREVO_EMAIL_ENDPOINT', 'https://api.brevo.com/v3/smtp/email');
}

function registrationAppBaseUrl(): string
{
    if (defined('APP_URL') && trim((string) APP_URL) !== '') {
        return rtrim((string) APP_URL, '/');
    }

    $env = trim((string) (getenv('APP_URL') ?: ''));
    return $env !== '' ? rtrim($env, '/') : 'https://esports.metadata.technology';
}

function registrationNormalizeSmsNumber(string $value): string
{
    $digits = preg_replace('/\\D+/', '', trim($value)) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0')) {
        $digits = '60' . substr($digits, 1);
    }

    return $digits;
}

function registrationSendCodeEmail(
    string $recipientEmail,
    string $recipientName,
    string $code,
    string $accountType
): array {
    $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim((string) (getenv('BREVO_SENDER_EMAIL') ?: ''));
    $senderName = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'Metadata Berhad'));
    $replyTo = trim((string) (getenv('BREVO_REPLY_TO_EMAIL') ?: $senderEmail));

    $recipientEmail = trim($recipientEmail);
    $recipientName = trim($recipientName);
    $accountType = trim($accountType);

    if ($apiKey === '' || $senderEmail === '') {
        return ['success' => false, 'message' => 'BREVO_API_KEY or BREVO_SENDER_EMAIL is not configured.'];
    }
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Enter a valid email address to receive the verification code.'];
    }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'BREVO_SENDER_EMAIL is invalid.'];
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['success' => false, 'message' => 'Unable to generate a valid verification code.'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL is required for Brevo Transactional Email.'];
    }

    $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'there', ENT_QUOTES, 'UTF-8');
    $safeAccountType = htmlspecialchars($accountType !== '' ? $accountType : 'registration', ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $subject = 'Metadata Verification Code - ' . ($accountType !== '' ? $accountType : 'Registration');

    $html = <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f8;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
<tr><td style="padding:28px;">
<h1 style="font-size:22px;margin:0 0 14px 0;">Your verification code</h1>
<p style="font-size:15px;line-height:1.6;margin:0 0 12px 0;">Hi {$safeName},</p>
<p style="font-size:15px;line-height:1.6;margin:0 0 18px 0;">
Use this email verification code to continue your {$safeAccountType} registration.
</p>
<div style="font-size:30px;letter-spacing:8px;font-weight:700;text-align:center;background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:20px 0;">{$safeCode}</div>
<p style="font-size:13px;line-height:1.6;color:#6c757d;margin:18px 0 0 0;">
This code is valid for 10 minutes. Do not share this code with anyone.
</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $text = "Hi " . ($recipientName !== '' ? $recipientName : 'there')
        . ",\n\nUse this email verification code to continue your "
        . ($accountType !== '' ? $accountType : 'registration')
        . " registration.\n\n"
        . "Verification code: {$code}\n\nThis code is valid for 10 minutes. Do not share this code.";

    $payload = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [[
            'name' => $recipientName !== '' ? $recipientName : $recipientEmail,
            'email' => $recipientEmail,
        ]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
        'tags' => ['registration-code-email'],
    ];

    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['replyTo'] = ['name' => $senderName, 'email' => $replyTo];
    }

    $ch = curl_init(REGISTRATION_BREVO_EMAIL_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
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
        $apiMessage = is_array($decoded)
            ? (string) ($decoded['message'] ?? $decoded['code'] ?? 'Unknown Brevo error')
            : 'Unknown Brevo error';

        return ['success' => false, 'message' => 'Brevo email HTTP ' . $httpCode . ': ' . $apiMessage];
    }

    return [
        'success' => true,
        'messageId' => is_array($decoded) ? (string) ($decoded['messageId'] ?? '') : '',
    ];
}

/**
 * Email is the only registration verification channel. Codes are scoped to
 * the current PHP session and recipient, expire after 10 minutes, and are
 * limited to five guesses. There is no SMS or verify-later bypass.
 */
function registrationRequestEmailCode(
    string $sessionKey,
    string $email,
    string $recipientName,
    string $accountType
): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'status' => 422, 'message' => 'Enter a valid email address.'];
    }
    if (empty(registrationVerificationServiceStatus()['available'])) {
        return ['success' => false, 'status' => 503, 'message' => 'Email verification is unavailable. Please try again later.'];
    }

    $existing = $_SESSION[$sessionKey] ?? null;
    $previousEmail = is_array($existing) ? (string) ($existing['email'] ?? '') : '';
    $lastSent = is_array($existing) ? (int) ($existing['sent_at'] ?? 0) : 0;
    $wait = REGISTRATION_EMAIL_CODE_RESEND_SECONDS - (time() - $lastSent);
    if ($previousEmail === $email && $lastSent > 0 && $wait > 0) {
        return [
            'success' => false, 'status' => 429,
            'message' => 'Please wait ' . $wait . ' seconds before requesting another email code.',
            'retry_after' => $wait,
        ];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $delivery = registrationSendCodeEmail($email, $recipientName, $code, $accountType);
    if (empty($delivery['success'])) {
        error_log('Email verification code delivery failed: ' . (string) ($delivery['message'] ?? 'unknown'));
        return ['success' => false, 'status' => 502, 'message' => 'Unable to send verification email. Please try again later.'];
    }

    $_SESSION[$sessionKey] = [
        'email' => $email,
        'code_hash' => hash('sha256', $code),
        'sent_at' => time(),
        'expires_at' => time() + REGISTRATION_EMAIL_CODE_EXPIRY_SECONDS,
        'attempts' => 0,
    ];

    return [
        'success' => true, 'status' => 200,
        'message' => 'A 6-digit verification code was sent to your email address.',
        'delivery_channel' => 'email',
        'retry_after' => REGISTRATION_EMAIL_CODE_RESEND_SECONDS,
    ];
}

function registrationVerifyEmailCode(string $sessionKey, string $email, string $code): array
{
    $email = strtolower(trim($email));
    $verification = $_SESSION[$sessionKey] ?? null;
    if (!is_array($verification)) {
        return ['success' => false, 'message' => 'Please request an email verification code first.'];
    }
    if (!hash_equals((string) ($verification['email'] ?? ''), $email)) {
        return ['success' => false, 'message' => 'The verification code belongs to a different email address.'];
    }
    if ((int) ($verification['expires_at'] ?? 0) < time()) {
        return ['success' => false, 'message' => 'The email verification code expired. Please request another.'];
    }
    if ((int) ($verification['attempts'] ?? 0) >= 5) {
        return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new email code.'];
    }
    if (!preg_match('/^[0-9]{6}$/D', $code) ||
        !hash_equals((string) ($verification['code_hash'] ?? ''), hash('sha256', $code))) {
        $_SESSION[$sessionKey]['attempts'] = ((int) ($verification['attempts'] ?? 0)) + 1;
        return ['success' => false, 'message' => 'The email verification code is incorrect.'];
    }
    return ['success' => true, 'message' => 'Email address verified.'];
}

function registrationSendVerificationEmail(
    string $recipientEmail,
    string $recipientName,
    string $accountType,
    string $accountName,
    string $verifyUrl
): array {
    $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim((string) (getenv('BREVO_SENDER_EMAIL') ?: ''));
    $senderName = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'Metadata Berhad'));
    $replyTo = trim((string) (getenv('BREVO_REPLY_TO_EMAIL') ?: $senderEmail));

    if ($apiKey === '' || $senderEmail === '') {
        return ['success' => false, 'message' => 'BREVO_API_KEY or BREVO_SENDER_EMAIL is not configured.'];
    }
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Verification email address is invalid.'];
    }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'BREVO_SENDER_EMAIL is invalid.'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL is required for Brevo Transactional Email.'];
    }

    $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeAccountType = htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8');
    $safeAccountName = htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8');
    $safeVerifyUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Verify Your Email - Metadata eSports ' . $accountType;
    $html = <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f8;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
<tr><td style="padding:28px;">
<h1 style="font-size:22px;margin:0 0 16px 0;">Verify your email</h1>
<p style="font-size:15px;line-height:1.6;margin:0 0 12px 0;">Hi {$safeRecipientName},</p>
<p style="font-size:15px;line-height:1.6;margin:0 0 18px 0;">Your {$safeAccountType} registration for <strong>{$safeAccountName}</strong> was successful. Please verify this email address by clicking the button below.</p>
<p style="margin:22px 0;text-align:center;"><a href="{$safeVerifyUrl}" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:700;">Verify Email</a></p>
<p style="font-size:13px;line-height:1.6;color:#6c757d;margin:18px 0 0 0;">This verification link expires in 24 hours. If you did not make this registration, you can ignore this email.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $text = "Hi {$recipientName},\n\nYour {$accountType} registration for {$accountName} was successful.\n\nVerify your email: {$verifyUrl}\n\nThis link expires in 24 hours.";

    $payload = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['name' => $recipientName, 'email' => $recipientEmail]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
        'tags' => ['registration-email-verification'],
    ];

    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['replyTo'] = ['name' => $senderName, 'email' => $replyTo];
    }

    $ch = curl_init(REGISTRATION_BREVO_EMAIL_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
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
        $apiMessage = is_array($decoded)
            ? (string) ($decoded['message'] ?? $decoded['code'] ?? 'Unknown Brevo error')
            : 'Unknown Brevo error';
        return ['success' => false, 'message' => 'Brevo email HTTP ' . $httpCode . ': ' . $apiMessage];
    }

    return ['success' => true, 'messageId' => is_array($decoded) ? (string) ($decoded['messageId'] ?? '') : ''];
}

function registrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function staffContactVerificationSchemaReady(PDO $pdo, string $role): bool
{
    $table = $role === 'teacher' ? 'teachers' : ($role === 'coach' ? 'coaches' : '');
    if ($table === '') {
        return false;
    }

    foreach (['phone_verified_at', 'email_verified_at', 'email_verification_token_hash', 'email_verification_expires_at'] as $column) {
        if (!registrationColumnExists($pdo, $table, $column)) {
            return false;
        }
    }

    return true;
}

function createStaffEmailVerificationToken(): array
{
    $token = bin2hex(random_bytes(32));
    return [
        'token' => $token,
        'token_hash' => hash('sha256', $token),
        'expires_at' => (new DateTimeImmutable('now'))
            ->modify('+' . REGISTRATION_EMAIL_VERIFY_EXPIRY_HOURS . ' hours')
            ->format('Y-m-d H:i:s'),
    ];
}

function handleStaffEmailVerification(PDO $pdo, string $role, string $rawToken): array
{
    $config = [
        'teacher' => ['table' => 'teachers', 'label' => 'Teacher'],
        'coach' => ['table' => 'coaches', 'label' => 'Esports Coach'],
    ];

    if (!isset($config[$role])) {
        return ['success' => false, 'title' => 'Invalid verification link', 'message' => 'Unknown account type.'];
    }

    $table = $config[$role]['table'];
    $label = $config[$role]['label'];
    $hash = hash('sha256', trim($rawToken));

    // More than one coach row may intentionally share the same token when
    // a coach registers two schools in one submission.
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, email_verified_at, email_verification_expires_at
         FROM {$table}
         WHERE email_verification_token_hash = ?
         ORDER BY id ASC"
    );
    $stmt->execute([$hash]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return ['success' => false, 'title' => 'Invalid verification link', 'message' => 'The verification link is invalid or no longer available.'];
    }

    $row = $rows[0];

    if (empty($row['email_verification_expires_at']) || strtotime((string) $row['email_verification_expires_at']) < time()) {
        return ['success' => false, 'title' => 'Verification link expired', 'message' => 'This verification link has expired. Please contact the organiser to request another link.'];
    }

    $unverifiedCount = 0;
    foreach ($rows as $verificationRow) {
        if (empty($verificationRow['email_verified_at'])) {
            $unverifiedCount++;
        }
    }

    if ($unverifiedCount === 0) {
        return [
            'success' => true,
            'title' => 'Email already verified',
            'message' => 'This email address has already been verified.',
            'name' => (string) $row['full_name'],
            'email' => (string) $row['email'],
        ];
    }

    $update = $pdo->prepare(
        "UPDATE {$table}
         SET email_verified_at = COALESCE(email_verified_at, NOW())
         WHERE email_verification_token_hash = ?"
    );
    $update->execute([$hash]);

    $verifiedRecords = count($rows);
    $message = $role === 'coach' && $verifiedRecords > 1
        ? 'The email address has been verified successfully for both school coach registrations.'
        : 'The email address has been verified successfully.';

    return [
        'success' => true,
        'title' => $label . ' email verified',
        'message' => $message,
        'name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
    ];
}
