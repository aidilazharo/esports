<?php
declare(strict_types=1);

require_once 'config.php';
require_once __DIR__ . '/includes/role_auth.php';

const CONTACT_CODE_TTL_SECONDS = 600;
const CONTACT_CODE_RESEND_SECONDS = 60;
const CONTACT_MAX_CODE_ATTEMPTS = 5;
const CONTACT_MAX_CODE_SENDS_PER_HOUR = 5;
const CONTACT_MAX_SUBMISSIONS_PER_HOUR = 5;
const CONTACT_MIN_FORM_SECONDS = 2;
const CONTACT_SUBMIT_COOLDOWN_SECONDS = 30;

function contactSessionList(string $key): array
{
    $value = $_SESSION[$key] ?? [];
    return is_array($value) ? $value : [];
}

function contactPruneTimes(array $times, int $windowSeconds = 3600): array
{
    $cutoff = time() - $windowSeconds;

    return array_values(
        array_filter(
            $times,
            static fn($value): bool =>
                is_int($value) && $value >= $cutoff
        )
    );
}

function contactMaskEmail(string $email): string
{
    $parts = explode('@', $email, 2);

    if (count($parts) !== 2) {
        return $email;
    }

    $local = $parts[0];
    $domain = $parts[1];

    if ($local === '') {
        return '***@' . $domain;
    }

    $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

    return $visible
        . str_repeat('*', max(2, mb_strlen($local) - mb_strlen($visible)))
        . '@'
        . $domain;
}

function contactSendBrevo(
    string $toEmail,
    string $toName,
    string $subject,
    string $html,
    string $text,
    string $tag,
    ?string $replyToEmail = null,
    ?string $replyToName = null
): array {
    $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ''));
    $senderEmail = trim(
        (string)(getenv('BREVO_SENDER_EMAIL') ?: '')
    );
    $senderName = trim(
        (string)(getenv('BREVO_SENDER_NAME') ?: 'Metadata Berhad')
    );

    if (
        $apiKey === ''
        || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)
    ) {
        return [
            'success' => false,
            'message' => 'Email service is not configured.',
        ];
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Invalid recipient email.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'PHP cURL is required.',
        ];
    }

    $payload = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [[
            'name' => $toName !== '' ? $toName : $toEmail,
            'email' => $toEmail,
        ]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
        'tags' => [$tag],
    ];

    if (
        $replyToEmail !== null
        && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $payload['replyTo'] = [
            'name' => trim((string)$replyToName) !== ''
                ? trim((string)$replyToName)
                : $replyToEmail,
            'email' => $replyToEmail,
        ];
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');

    curl_setopt_array(
        $ch,
        [
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
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ),
        ]
    );

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false || $error !== '') {
        return [
            'success' => false,
            'message' => 'Unable to connect to the email service.',
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log(
            'Contact Brevo HTTP '
            . $httpCode
            . ': '
            . (string)$body
        );

        return [
            'success' => false,
            'message' => 'Unable to send email at this time.',
        ];
    }

    return ['success' => true];
}

function contactSendVerificationCode(
    string $email,
    string $name,
    string $code
): array {
    $safeName = e($name !== '' ? $name : 'there');
    $safeCode = e($code);

    $subject = 'Metadata Contact Form Verification Code';

    $html = <<<HTML
<!doctype html>
<html>
<body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table width="100%" cellspacing="0" cellpadding="0" role="presentation" style="padding:24px 12px;background:#f5f6f8;">
<tr><td align="center">
<table width="100%" cellspacing="0" cellpadding="0" role="presentation" style="max-width:560px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;">
<tr><td style="padding:28px;">
<h1 style="font-size:22px;margin:0 0 14px;">Verify your email</h1>
<p style="font-size:15px;line-height:1.6;">Hello <strong>{$safeName}</strong>,</p>
<p style="font-size:15px;line-height:1.6;">
Enter this code on the Contact Us page before submitting your message.
</p>
<div style="font-size:30px;letter-spacing:8px;font-weight:700;text-align:center;background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:20px 0;">{$safeCode}</div>
<p style="font-size:13px;color:#6c757d;margin:0;">
The code expires in 10 minutes. If you did not request this code, you can ignore this email.
</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $text =
        "Hello " . ($name !== '' ? $name : 'there') . ",\n\n"
        . "Your Contact Us verification code is: {$code}\n\n"
        . "This code expires in 10 minutes.\n";

    return contactSendBrevo(
        $email,
        $name,
        $subject,
        $html,
        $text,
        'contact-verification-code'
    );
}

function contactSendMessage(
    string $name,
    string $email,
    string $phone,
    string $subject,
    string $message
): array {
    $recipientEmail = 'aidilazhar@me.com';

    $safeName = e($name);
    $safeEmail = e($email);
    $safePhone = e($phone !== '' ? $phone : '-');
    $safeSubject = e($subject);
    $safeMessage = nl2br(e($message));

    $emailSubject =
        '[Website Contact] '
        . preg_replace('/[\r\n]+/', ' ', $subject);

    $html = <<<HTML
<!doctype html>
<html>
<body style="font-family:Arial,Helvetica,sans-serif;color:#212529;">
<h2>New Website Contact Message</h2>
<table cellspacing="0" cellpadding="8" border="0" style="border-collapse:collapse;">
<tr><td><strong>Name</strong></td><td>{$safeName}</td></tr>
<tr><td><strong>Email</strong></td><td>{$safeEmail}</td></tr>
<tr><td><strong>Phone</strong></td><td>{$safePhone}</td></tr>
<tr><td><strong>Subject</strong></td><td>{$safeSubject}</td></tr>
</table>
<hr>
<div style="line-height:1.6;">{$safeMessage}</div>
</body>
</html>
HTML;

    $text =
        "New Website Contact Message\n\n"
        . "Name: {$name}\n"
        . "Email: {$email}\n"
        . "Phone: " . ($phone !== '' ? $phone : '-') . "\n"
        . "Subject: {$subject}\n\n"
        . $message
        . "\n";

    return contactSendBrevo(
        $recipientEmail,
        'Metadata Berhad',
        $emailSubject,
        $html,
        $text,
        'website-contact-form',
        $email,
        $name
    );
}

if (!isset($_SESSION['contact_form_started_at'])) {
    $_SESSION['contact_form_started_at'] = time();
}

$error = '';
$success = '';
$codeNotice = '';

$name = trim((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$phone = trim((string)($_POST['phone'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

$verifiedEmail = strtolower(
    trim((string)($_SESSION['contact_verified_email'] ?? ''))
);

$isEmailVerified =
    $verifiedEmail !== ''
    && $verifiedEmail === $email;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = (string)($_POST['action'] ?? '');
    $honeypot = trim((string)($_POST['website'] ?? ''));

    if ($honeypot !== '') {
        http_response_code(400);
        exit('Unable to process this request.');
    }

    if ($action === 'send_code') {
        if (
            $email === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = 'Enter a valid email address.';
        } else {
            $sendTimes = contactPruneTimes(
                contactSessionList('contact_code_send_times')
            );

            $lastSentAt = (int)(
                $_SESSION['contact_code_last_sent_at']
                ?? 0
            );

            if (count($sendTimes) >= CONTACT_MAX_CODE_SENDS_PER_HOUR) {
                $error =
                    'Too many verification-code requests. '
                    . 'Please try again later.';
            } elseif (
                $lastSentAt > 0
                && time() - $lastSentAt < CONTACT_CODE_RESEND_SECONDS
            ) {
                $wait =
                    CONTACT_CODE_RESEND_SECONDS
                    - (time() - $lastSentAt);

                $error =
                    'Please wait '
                    . $wait
                    . ' seconds before requesting another code.';
            } else {
                $code = (string)random_int(100000, 999999);

                $sendResult = contactSendVerificationCode(
                    $email,
                    $name,
                    $code
                );

                if (empty($sendResult['success'])) {
                    $error =
                        (string)(
                            $sendResult['message']
                            ?? 'Unable to send verification code.'
                        );
                } else {
                    $_SESSION['contact_code_hash'] =
                        password_hash(
                            $code,
                            PASSWORD_DEFAULT
                        );

                    $_SESSION['contact_code_email'] = $email;
                    $_SESSION['contact_code_expires_at'] =
                        time() + CONTACT_CODE_TTL_SECONDS;

                    $_SESSION['contact_code_attempts'] = 0;
                    $_SESSION['contact_code_last_sent_at'] = time();

                    $sendTimes[] = time();
                    $_SESSION['contact_code_send_times'] =
                        $sendTimes;

                    unset(
                        $_SESSION['contact_verified_email'],
                        $_SESSION['contact_verified_at']
                    );

                    $codeNotice =
                        'A 6-digit verification code was sent to '
                        . contactMaskEmail($email)
                        . '.';
                }
            }
        }
    }

    if ($action === 'submit_message') {
        $code = preg_replace(
            '/\D+/',
            '',
            (string)($_POST['verification_code'] ?? '')
        ) ?? '';

        $formAge =
            time()
            - (int)(
                $_SESSION['contact_form_started_at']
                ?? time()
            );

        $submissionTimes = contactPruneTimes(
            contactSessionList(
                'contact_submission_times'
            )
        );

        $lastSubmissionAt = (int)(
            $_SESSION['contact_last_submission_at']
            ?? 0
        );

        $codeEmail = strtolower(
            trim(
                (string)(
                    $_SESSION['contact_code_email']
                    ?? ''
                )
            )
        );

        $codeExpiresAt = (int)(
            $_SESSION['contact_code_expires_at']
            ?? 0
        );

        $codeHash = (string)(
            $_SESSION['contact_code_hash']
            ?? ''
        );

        $attempts = (int)(
            $_SESSION['contact_code_attempts']
            ?? 0
        );

        if ($formAge < CONTACT_MIN_FORM_SECONDS) {
            $error =
                'Please review the form before submitting.';
        } elseif (
            count($submissionTimes)
            >= CONTACT_MAX_SUBMISSIONS_PER_HOUR
        ) {
            $error =
                'Too many messages have been submitted. '
                . 'Please try again later.';
        } elseif (
            $lastSubmissionAt > 0
            && time() - $lastSubmissionAt
                < CONTACT_SUBMIT_COOLDOWN_SECONDS
        ) {
            $error =
                'Please wait before submitting another message.';
        } elseif ($name === '' || mb_strlen($name) > 150) {
            $error =
                'Enter your name using 150 characters or fewer.';
        } elseif (
            $email === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = 'Enter a valid email address.';
        } elseif (mb_strlen($phone) > 40) {
            $error =
                'Phone number must be 40 characters or fewer.';
        } elseif (
            $subject === ''
            || mb_strlen($subject) > 180
        ) {
            $error =
                'Enter a subject using 180 characters or fewer.';
        } elseif (
            $message === ''
            || mb_strlen($message) < 10
            || mb_strlen($message) > 5000
        ) {
            $error =
                'Message must contain between 10 and 5,000 characters.';
        } elseif (
            $codeEmail === ''
            || !hash_equals($codeEmail, $email)
        ) {
            $error =
                'Request a verification code for this email address first.';
        } elseif (
            $codeExpiresAt < time()
            || $codeHash === ''
        ) {
            $error =
                'The verification code has expired. Request a new code.';
        } elseif ($attempts >= CONTACT_MAX_CODE_ATTEMPTS) {
            $error =
                'Too many incorrect verification attempts. '
                . 'Request a new code.';
        } elseif (
            !preg_match('/^\d{6}$/', $code)
            || !password_verify($code, $codeHash)
        ) {
            $_SESSION['contact_code_attempts'] =
                $attempts + 1;

            $remaining =
                max(
                    0,
                    CONTACT_MAX_CODE_ATTEMPTS
                    - ($attempts + 1)
                );

            $error =
                'Incorrect verification code. '
                . $remaining
                . ' attempt(s) remaining.';
        } else {
            $_SESSION['contact_verified_email'] = $email;
            $_SESSION['contact_verified_at'] = time();

            $sendResult = contactSendMessage(
                $name,
                $email,
                $phone,
                $subject,
                $message
            );

            if (empty($sendResult['success'])) {
                $error =
                    (string)(
                        $sendResult['message']
                        ?? 'Unable to send your message.'
                    );
            } else {
                $submissionTimes[] = time();

                $_SESSION['contact_submission_times'] =
                    $submissionTimes;

                $_SESSION['contact_last_submission_at'] =
                    time();

                unset(
                    $_SESSION['contact_code_hash'],
                    $_SESSION['contact_code_email'],
                    $_SESSION['contact_code_expires_at'],
                    $_SESSION['contact_code_attempts'],
                    $_SESSION['contact_verified_email'],
                    $_SESSION['contact_verified_at']
                );

                $_SESSION['contact_form_started_at'] =
                    time();

                $success =
                    'Your message has been sent successfully.';

                $name = '';
                $email = '';
                $phone = '';
                $subject = '';
                $message = '';
                $isEmailVerified = false;
            }
        }
    }
}

$pageTitle = 'Contact Us';
$seoTitle = 'Contact Us';
$seoDescription =
    'Contact Metadata Berhad regarding the school STEM, TVET, AI and esports programme.';

$disableStaticHtmlGeneration = true;

require 'includes/header.php';
?>

<style>
.contact-card{
    border:0;
    box-shadow:0 .125rem .75rem rgba(0,0,0,.06);
}
.contact-step{
    width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    font-weight:700;
}
.contact-honeypot{
    position:absolute !important;
    left:-10000px !important;
    top:auto !important;
    width:1px !important;
    height:1px !important;
    overflow:hidden !important;
}
</style>

<div class="row justify-content-center py-3 py-lg-4">
    <div class="col-xl-9">
        <div class="mb-4">
            <span class="badge text-bg-primary mb-2">
                Contact Us
            </span>

            <h1 class="h2 mb-2">
                Contact Metadata Berhad
            </h1>

            <p class="text-secondary mb-0">
                Verify your email first, then submit your enquiry.
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($codeNotice !== ''): ?>
            <div class="alert alert-info">
                <i class="bi bi-envelope-check me-2"></i>
                <?= e($codeNotice) ?>
            </div>
        <?php endif; ?>

        <div class="card contact-card">
            <div class="card-body p-4 p-md-5">
                <form method="post" autocomplete="off">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(csrfToken()) ?>"
                    >

                    <div
                        class="contact-honeypot"
                        aria-hidden="true"
                    >
                        <label for="website">
                            Website
                        </label>
                        <input
                            type="text"
                            name="website"
                            id="website"
                            tabindex="-1"
                            autocomplete="off"
                        >
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="d-flex align-items-center gap-2 border-bottom pb-2 mb-1">
                                <span class="contact-step bg-primary-subtle text-primary">
                                    1
                                </span>
                                <div>
                                    <div class="fw-semibold">
                                        Your Details
                                    </div>
                                    <div class="small text-secondary">
                                        Enter the email address you can verify.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label
                                class="form-label required"
                                for="contactName"
                            >
                                Full Name
                            </label>

                            <input
                                class="form-control"
                                id="contactName"
                                name="name"
                                maxlength="150"
                                value="<?= e($name) ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label
                                class="form-label required"
                                for="contactEmail"
                            >
                                Email
                            </label>

                            <div class="input-group">
                                <input
                                    type="email"
                                    class="form-control"
                                    id="contactEmail"
                                    name="email"
                                    maxlength="190"
                                    value="<?= e($email) ?>"
                                    required
                                >

                                <button
                                    class="btn btn-outline-primary"
                                    type="submit"
                                    name="action"
                                    value="send_code"
                                >
                                    <i class="bi bi-envelope-check me-1"></i>
                                    Send Code
                                </button>
                            </div>

                            <div class="form-text">
                                A 6-digit code will be sent to this email before the message can be submitted.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label
                                class="form-label"
                                for="contactPhone"
                            >
                                Phone / WhatsApp
                            </label>

                            <input
                                class="form-control"
                                id="contactPhone"
                                name="phone"
                                maxlength="40"
                                value="<?= e($phone) ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label
                                class="form-label required"
                                for="verificationCode"
                            >
                                Email Verification Code
                            </label>

                            <input
                                class="form-control"
                                id="verificationCode"
                                name="verification_code"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                placeholder="6-DIGIT CODE"
                                required
                            >
                        </div>

                        <div class="col-12 mt-4">
                            <div class="d-flex align-items-center gap-2 border-bottom pb-2 mb-1">
                                <span class="contact-step bg-primary-subtle text-primary">
                                    2
                                </span>
                                <div>
                                    <div class="fw-semibold">
                                        Your Message
                                    </div>
                                    <div class="small text-secondary">
                                        Tell us how we can help.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label
                                class="form-label required"
                                for="contactSubject"
                            >
                                Subject
                            </label>

                            <input
                                class="form-control"
                                id="contactSubject"
                                name="subject"
                                maxlength="180"
                                value="<?= e($subject) ?>"
                                required
                            >
                        </div>

                        <div class="col-12">
                            <label
                                class="form-label required"
                                for="contactMessage"
                            >
                                Message
                            </label>

                            <textarea
                                class="form-control"
                                id="contactMessage"
                                name="message"
                                rows="6"
                                minlength="10"
                                maxlength="5000"
                                required
                            ><?= e($message) ?></textarea>

                            <div class="form-text">
                                Maximum 5,000 characters.
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="alert alert-light border small mb-0">
                                <i class="bi bi-shield-check me-2"></i>
                                Spam protection includes email verification,
                                request limits, CSRF protection and automated-bot detection.
                            </div>
                        </div>

                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button
                                class="btn btn-primary"
                                type="submit"
                                name="action"
                                value="submit_message"
                            >
                                <i class="bi bi-send me-2"></i>
                                Submit Message
                            </button>

                            <a
                                href="/index.php"
                                class="btn btn-outline-secondary"
                            >
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
