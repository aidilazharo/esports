<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_contact_verification.php';
require_once __DIR__ . '/lightweight_registration_common.php';

try {
    verifyCsrf();

    $service = registrationVerificationServiceStatus();

    if (empty($service['available'])) {
        lightweightRegistrationJson([
            'success' => false,
            'bypass_allowed' => true,
            'config_unavailable' => true,
            'message' =>
                'SMS/email verification is currently unavailable. '
                . 'You may continue and verify later.',
        ], 503);
    }

    $result = registrationRequestSmsCodeWithEmailFallback(
        'teacher_sms_verification',
        (string)($_POST['phone'] ?? ''),
        (string)($_POST['email'] ?? ''),
        (string)($_POST['full_name'] ?? ''),
        'teacher-registration-verification',
        'School Team Teacher'
    );

    lightweightRegistrationJson(
        $result,
        (int)($result['status'] ?? 200)
    );
} catch (Throwable $e) {
    error_log(
        'teacher_send_code.php error: '
        . $e->getMessage()
    );

    lightweightRegistrationJson([
        'success' => false,
        'message' =>
            'Unable to send the verification code at this time.',
    ], 500);
}
