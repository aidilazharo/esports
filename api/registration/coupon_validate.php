<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/student_registration_service.php';

try {
    verifyCsrf();

    $code = studentRegistrationNormalizeCouponCode(
        (string)($_POST['coupon_code'] ?? '')
    );

    if ($code === '') {
        studentRegistrationJson([
            'success' => false,
            'message' => 'Enter a coupon code first.',
        ], 422);
    }

    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,63}$/', $code)) {
        studentRegistrationJson([
            'success' => false,
            'message' => 'Coupon code format is invalid.',
        ], 422);
    }

    $coupon = studentRegistrationGetCoupon($pdo, $code);
    $error = studentRegistrationCouponError($coupon);

    if ($error !== null) {
        studentRegistrationJson([
            'success' => false,
            'code' => $code,
            'message' => $error,
        ], 422);
    }

    $max = $coupon['max_redemptions'] === null
        ? null
        : (int)$coupon['max_redemptions'];

    $redeemed = (int)($coupon['redeemed_count'] ?? 0);

    studentRegistrationJson([
        'success' => true,
        'code' => (string)$coupon['code'],
        'description' => trim(
            (string)($coupon['description'] ?? '')
        ),
        'membership_years' => 1,
        'expires_at' => $coupon['expires_at'] ?: null,
        'remaining_redemptions' => $max === null
            ? null
            : max(0, $max - $redeemed),
        'message' => 'Coupon is valid.',
    ]);
} catch (PDOException $e) {
    studentRegistrationJson([
        'success' => false,
        'message' => 'Coupon validation is unavailable.',
    ], 503);
} catch (Throwable $e) {
    studentRegistrationJson([
        'success' => false,
        'message' => 'Unable to validate the coupon.',
    ], 500);
}
