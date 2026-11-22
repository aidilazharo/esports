<?php
declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/registration_contact_verification.php';

function expectVerification(bool $pass, string $message): void
{
    if (!$pass) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$email = 'guardian@example.test';
$code = '123456';
$key = 'parent_email_verification';

$_SESSION[$key] = [
    'email' => $email,
    'code_hash' => hash('sha256', $code),
    'sent_at' => time(),
    'expires_at' => time() + 600,
    'attempts' => 0,
];

expectVerification(registrationVerifyEmailCode($key, $email, $code)['success'], 'Valid email-bound code accepted');
expectVerification(!registrationVerifyEmailCode($key, 'other@example.test', $code)['success'], 'Code rejected for different email');
expectVerification(!registrationVerifyEmailCode($key, $email, '000000')['success'], 'Incorrect code rejected');
expectVerification($_SESSION[$key]['attempts'] === 1, 'Incorrect code increments attempts');
$_SESSION[$key]['expires_at'] = time() - 1;
expectVerification(!registrationVerifyEmailCode($key, $email, $code)['success'], 'Expired code rejected');

$_SESSION[$key]['expires_at'] = time() + 600;
$_SESSION[$key]['attempts'] = 5;
expectVerification(!registrationVerifyEmailCode($key, $email, $code)['success'], 'Max attempts enforced');
unset($_SESSION[$key]);
expectVerification(!registrationVerifyEmailCode($key, $email, $code)['success'], 'Missing session rejected');

echo "Email-only code checks passed.\n";
