<?php

/**
 * Load .env values when config.php does not already load them.
 * Existing environment variables are never overwritten.
 */
function loadStudentRegistrationEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
             ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadStudentRegistrationEnv(__DIR__ . '/.env');

const GUARDIAN_CONSENT_VERSION = '2026-08-09';
const VERIFICATION_EXPIRY_HOURS = 24;
const BREVO_SMS_ENDPOINT = 'https://api.brevo.com/v3/transactionalSMS/send';

function normalizeIdentityNumber(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[\\s\\-]+/', '', $value) ?? $value;
}

function normalizeSmsNumber(string $value): string
{
    $digits = preg_replace('/\\D+/', '', trim($value)) ?? '';
    if ($digits === '') {
        return '';
    }

    // Malaysian local format 01xxxxxxxx -> 601xxxxxxxx.
    if (str_starts_with($digits, '0')) {
        $digits = '60' . substr($digits, 1);
    }

    return $digits;
}

function studentAppBaseUrl(): string
{
    if (defined('APP_URL') && trim((string) APP_URL) !== '') {
        return rtrim((string) APP_URL, '/');
    }

    $env = trim((string) (getenv('APP_URL') ?: ''));
    return $env !== '' ? rtrim($env, '/') : 'https://esports.metadata.technology';
}

/**
 * Send a direct Brevo Transactional SMS. No SMS template is required.
 *
 * First child / unverified guardian:
 *   verifies parent/guardian + student.
 * Additional child under an already verified guardian:
 *   verifies the new student only.
 */
function sendBrevoSmsVerification(
    string $recipient,
    bool $verifyGuardian,
    string $guardianNumber,
    string $studentNumber,
    string $verifyUrl
): array {
    $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));
    $sender = trim((string) (getenv('BREVO_SMS_SENDER') ?: 'Metadata'));
    $recipient = normalizeSmsNumber($recipient);

    if ($apiKey === '') {
        return ['success' => false, 'message' => 'BREVO_API_KEY is not configured.'];
    }

    // Brevo allows up to 11 alphanumeric characters for an alphanumeric sender.
    if ($sender === '' || strlen($sender) > 11 || !preg_match('/^[A-Za-z0-9]+$/', $sender)) {
        return [
            'success' => false,
            'message' => 'BREVO_SMS_SENDER must be 1-11 alphanumeric characters, e.g. Metadata.',
        ];
    }

    if ($recipient === '' || strlen($recipient) < 6 || strlen($recipient) > 15) {
        return ['success' => false, 'message' => 'Parent/guardian mobile number is invalid.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'PHP cURL is required for Brevo Transactional SMS.'];
    }

    // Keep the copy concise so the verification URL is more likely to fit in one SMS segment.
    $content = $verifyGuardian
        ? 'Metadata: Verify parent ' . $guardianNumber . ' and student ' . $studentNumber . ': ' . $verifyUrl
        : 'Metadata: Verify student ' . $studentNumber . ': ' . $verifyUrl;

    $payload = [
        'sender' => $sender,
        'recipient' => $recipient,
        'content' => $content,
        'type' => 'transactional',
        'tag' => 'account-verification',
        'unicodeEnabled' => false,
    ];

    $webhookUrl = trim((string) (getenv('BREVO_SMS_WEBHOOK_URL') ?: ''));
    if ($webhookUrl !== '' && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        $payload['webUrl'] = $webhookUrl;
    }

    $ch = curl_init(BREVO_SMS_ENDPOINT);
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
        return ['success' => false, 'message' => 'Brevo SMS connection error: ' . $error];
    }

    $decoded = json_decode((string) $body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiMessage = is_array($decoded)
            ? (string) ($decoded['message'] ?? $decoded['code'] ?? 'Unknown Brevo error')
            : 'Unknown Brevo error';

        return [
            'success' => false,
            'message' => 'Brevo SMS HTTP ' . $httpCode . ': ' . $apiMessage,
        ];
    }

    return [
        'success' => true,
        'messageId' => is_array($decoded) ? (string) ($decoded['messageId'] ?? '') : '',
        'content' => $content,
    ];
}

function createAccountVerification(PDO $pdo, int $studentId, int $guardianId, bool $verifyGuardian): array
{
    $invalidate = $pdo->prepare(
        'UPDATE account_verifications
         SET verified_at = NOW()
         WHERE student_id = ? AND verified_at IS NULL'
    );
    $invalidate->execute([$studentId]);

    // 128-bit one-time random token; shorter than the old token to reduce SMS length.
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('now'))
        ->modify('+' . VERIFICATION_EXPIRY_HOURS . ' hours')
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO account_verifications
         (token_hash, student_id, guardian_id, verify_guardian, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$hash, $studentId, $guardianId, $verifyGuardian ? 1 : 0, $expiresAt]);

    return ['token' => $token, 'expires_at' => $expiresAt];
}

function handleStudentGuardianVerification(PDO $pdo, string $rawToken): array
{
    $hash = hash('sha256', trim($rawToken));
    $stmt = $pdo->prepare(
        'SELECT
            v.id AS verification_id, v.student_id, v.guardian_id, v.verify_guardian,
            v.expires_at, v.verified_at,
            s.student_number, s.full_name AS student_name,
            g.guardian_number, g.full_name AS guardian_name
         FROM account_verifications v
         INNER JOIN students s ON s.id = v.student_id
         INNER JOIN guardians g ON g.id = v.guardian_id
         WHERE v.token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'success' => false,
            'title' => 'Invalid verification link',
            'message' => 'The verification link is invalid or no longer available.',
        ];
    }

    if (!empty($row['verified_at'])) {
        return [
            'success' => true,
            'title' => 'Account already verified',
            'message' => 'This verification link has already been used.',
            'student_id' => (int) $row['student_id'],
            'student_number' => (string) $row['student_number'],
            'guardian_number' => (string) $row['guardian_number'],
        ];
    }

    if (strtotime((string) $row['expires_at']) < time()) {
        return [
            'success' => false,
            'title' => 'Verification link expired',
            'message' => 'This verification link has expired. Please request a new verification link.',
        ];
    }

    try {
        $pdo->beginTransaction();

        $studentVerify = $pdo->prepare(
            'UPDATE students
             SET account_verified_at = COALESCE(account_verified_at, NOW())
             WHERE id = ?'
        );
        $studentVerify->execute([(int) $row['student_id']]);

        // The verification link is delivered to the parent/guardian email address.
        // Mark that email as verified when the secure link is used, when the column
        // is available from add_staff_contact_verification.sql.
        $guardianEmailColumn = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'guardians'
               AND COLUMN_NAME = 'email_verified_at'"
        );
        $guardianEmailColumn->execute();
        if ((int) $guardianEmailColumn->fetchColumn() > 0) {
            $guardianEmailVerify = $pdo->prepare(
                'UPDATE guardians
                 SET email_verified_at = COALESCE(email_verified_at, NOW())
                 WHERE id = ?'
            );
            $guardianEmailVerify->execute([(int) $row['guardian_id']]);
        }

        if ((int) $row['verify_guardian'] === 1) {
            $guardianVerify = $pdo->prepare(
                'UPDATE guardians
                 SET phone_verified_at = COALESCE(phone_verified_at, NOW())
                 WHERE id = ?'
            );
            $guardianVerify->execute([(int) $row['guardian_id']]);
        }

        $mark = $pdo->prepare(
            'UPDATE account_verifications
             SET verified_at = NOW()
             WHERE id = ? AND verified_at IS NULL'
        );
        $mark->execute([(int) $row['verification_id']]);

        $pdo->commit();

        $both = (int) $row['verify_guardian'] === 1;
        return [
            'success' => true,
            'title' => $both ? 'Parent/guardian and student verified' : 'Student verified',
            'message' => $both
                ? 'The parent/guardian account and student account have been verified successfully.'
                : 'The new student account has been verified successfully. The parent/guardian account was already verified.',
            'student_id' => (int) $row['student_id'],
            'student_number' => (string) $row['student_number'],
            'guardian_number' => (string) $row['guardian_number'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'success' => false,
            'title' => 'Verification failed',
            'message' => 'Unable to verify the account at this time. Please try again.',
        ];
    }
}
