<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../student_guardian_verification.php';
require_once __DIR__ . '/../../registration_contact_verification.php';
require_once __DIR__ . '/student_registration_service.php';

const STUDENT_GUARDIAN_SMS_EXPIRY = 600;
const STUDENT_GUARDIAN_SMS_RESEND = 60;

function sendStudentGuardianSmsCode(
    string $recipient,
    string $code
): array {
    $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ''));
    $sender = trim(
        (string)(getenv('BREVO_SMS_SENDER') ?: 'Metadata')
    );
    $recipient = normalizeSmsNumber($recipient);

    if ($apiKey === '') {
        return [
            'success' => false,
            'message' => 'BREVO_API_KEY is not configured.',
        ];
    }

    if (
        $sender === '' ||
        strlen($sender) > 11 ||
        !preg_match('/^[A-Za-z0-9]+$/', $sender)
    ) {
        return [
            'success' => false,
            'message' => 'BREVO_SMS_SENDER is invalid.',
        ];
    }

    if (
        $recipient === '' ||
        strlen($recipient) < 10 ||
        strlen($recipient) > 15
    ) {
        return [
            'success' => false,
            'message' => 'Enter a valid mobile number.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'PHP cURL is required.',
        ];
    }

    $payload = [
        'sender' => $sender,
        'recipient' => $recipient,
        'content' =>
            'Metadata verification code: '
            . $code
            . '. Valid for 10 minutes. Do not share this code.',
        'type' => 'transactional',
        'tag' => 'guardian-verification-code',
        'unicodeEnabled' => false,
    ];

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
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ),
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($body === false || $error !== '') {
        return [
            'success' => false,
            'message' => 'Brevo SMS connection error.',
        ];
    }

    $decoded = json_decode((string)$body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'message' => is_array($decoded)
                ? (string)(
                    $decoded['message']
                    ?? $decoded['code']
                    ?? 'Brevo SMS error'
                )
                : 'Brevo SMS error',
        ];
    }

    return [
        'success' => true,
        'messageId' => is_array($decoded)
            ? (string)($decoded['messageId'] ?? '')
            : '',
    ];
}

try {
    verifyCsrf();

    $phone = normalizeSmsNumber(
        (string)($_POST['guardian_phone'] ?? '')
    );

    $email = strtoupper(
        trim((string)($_POST['guardian_email'] ?? ''))
    );

    $identity = normalizeIdentityNumber(
        (string)($_POST['guardian_identity_number'] ?? '')
    );

    if ($identity !== '' && $email === '') {
        $stmt = $pdo->prepare(
            "SELECT email, email_verified_at
             FROM guardians
             WHERE UPPER(
                REPLACE(
                    REPLACE(
                        COALESCE(identity_number, ''),
                        '-',
                        ''
                    ),
                    ' ',
                    ''
                )
             ) = ?
             LIMIT 1"
        );
        $stmt->execute([$identity]);
        $guardian = $stmt->fetch();

        if ($guardian && !empty($guardian['email_verified_at'])) {
            $email = strtoupper(
                trim((string)($guardian['email'] ?? ''))
            );
        }
    }

    if ($phone === '' || strlen($phone) < 10 || strlen($phone) > 15) {
        studentRegistrationJson([
            'success' => false,
            'message' => 'Enter a valid parent / guardian mobile number.',
        ], 422);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        studentRegistrationJson([
            'success' => false,
            'message' => 'Enter a valid parent / guardian email.',
        ], 422);
    }

    $service = registrationVerificationServiceStatus();

    if (empty($service['available'])) {
        studentRegistrationJson([
            'success' => false,
            'bypass_allowed' => true,
            'config_unavailable' => true,
            'message' =>
                'SMS/email verification is currently unavailable. '
                . 'You may continue and verify later.',
        ], 503);
    }

    $existing = $_SESSION['guardian_sms_verification'] ?? null;
    $lastSent = is_array($existing)
        ? (int)($existing['sent_at'] ?? 0)
        : 0;

    $elapsed = time() - $lastSent;

    if (
        $lastSent > 0 &&
        $elapsed < STUDENT_GUARDIAN_SMS_RESEND
    ) {
        $wait = STUDENT_GUARDIAN_SMS_RESEND - $elapsed;

        studentRegistrationJson([
            'success' => false,
            'message' =>
                'Please wait '
                . $wait
                . ' seconds before requesting another code.',
            'retry_after' => $wait,
        ], 429);
    }

    $code = str_pad(
        (string)random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );

    $sms = sendStudentGuardianSmsCode(
        $phone,
        $code
    );

    if (!$sms['success']) {
        $_SESSION['guardian_sms_verification'] = [
            'phone' => $phone,
            'email' => $email,
            'code_hash' => '',
            'expires_at' =>
                time() + STUDENT_GUARDIAN_SMS_EXPIRY,
            'sent_at' => time(),
            'attempts' => 0,
            'bypass_allowed' => true,
            'failure_at' => time(),
            'failure_reason' => 'sms_send_error',
        ];

        studentRegistrationJson([
            'success' => false,
            'bypass_allowed' => true,
            'message' =>
                'Unable to send the SMS verification code. '
                . 'You may continue and verify later.',
        ], 502);
    }

    $_SESSION['guardian_sms_verification'] = [
        'phone' => $phone,
        'email' => $email,
        'code_hash' => hash('sha256', $code),
        'expires_at' =>
            time() + STUDENT_GUARDIAN_SMS_EXPIRY,
        'sent_at' => time(),
        'attempts' => 0,
        'bypass_allowed' => false,
    ];

    studentRegistrationJson([
        'success' => true,
        'message' => 'A 6-digit verification code has been sent by SMS.',
        'retry_after' => STUDENT_GUARDIAN_SMS_RESEND,
    ]);
} catch (Throwable $e) {
    error_log(
        'guardian_send_code.php error: '
        . $e->getMessage()
    );

    studentRegistrationJson([
        'success' => false,
        'message' => 'Unable to send the verification code.',
    ], 500);
}
