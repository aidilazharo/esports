<?php
declare(strict_types=1);

function studentRegistrationNormalizeCouponCode(string $code): string
{
    $code = strtoupper(trim($code));
    return preg_replace('/\s+/', '', $code) ?? '';
}

function studentRegistrationGetCoupon(PDO $pdo, string $code, bool $lock = false): ?array
{
    $code = studentRegistrationNormalizeCouponCode($code);
    if ($code === '') {
        return null;
    }

    $sql = 'SELECT * FROM coupons WHERE code = ? LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function studentRegistrationCouponError(?array $coupon): ?string
{
    if (!$coupon) {
        return 'Coupon code is invalid.';
    }

    if (empty($coupon['is_active'])) {
        return 'This coupon is no longer active.';
    }

    $now = time();

    if (!empty($coupon['starts_at'])) {
        $startsAt = strtotime((string)$coupon['starts_at']);
        if ($startsAt !== false && $startsAt > $now) {
            return 'This coupon is not active yet.';
        }
    }

    if (!empty($coupon['expires_at'])) {
        $expiresAt = strtotime((string)$coupon['expires_at']);
        if ($expiresAt !== false && $expiresAt < $now) {
            return 'This coupon has expired.';
        }
    }

    $max = $coupon['max_redemptions'] === null
        ? null
        : (int)$coupon['max_redemptions'];

    $redeemed = (int)($coupon['redeemed_count'] ?? 0);

    if ($max !== null && $max > 0 && $redeemed >= $max) {
        return 'This coupon has reached its redemption limit.';
    }

    return null;
}

function studentRegistrationRedeemCoupon(
    PDO $pdo,
    string $code,
    int $studentId
): array {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Coupon redemption requires an active database transaction.'
        );
    }

    $code = studentRegistrationNormalizeCouponCode($code);
    $coupon = studentRegistrationGetCoupon($pdo, $code, true);
    $error = studentRegistrationCouponError($coupon);

    if ($error !== null) {
        throw new RuntimeException($error);
    }

    $duplicateStmt = $pdo->prepare(
        'SELECT id
         FROM coupon_redemptions
         WHERE coupon_id = ? AND student_id = ?
         LIMIT 1'
    );
    $duplicateStmt->execute([(int)$coupon['id'], $studentId]);

    if ($duplicateStmt->fetchColumn()) {
        throw new RuntimeException(
            'This coupon has already been redeemed for this student.'
        );
    }

    $studentStmt = $pdo->prepare(
        'SELECT membership_next_due_at
         FROM students
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch();

    if (!$student) {
        throw new RuntimeException(
            'Student not found while redeeming the coupon.'
        );
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $baseDate = $now;

    if (!empty($student['membership_next_due_at'])) {
        $expiry = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string)$student['membership_next_due_at'],
            new DateTimeZone('UTC')
        );

        if ($expiry instanceof DateTimeImmutable && $expiry > $now) {
            $baseDate = $expiry;
        }
    }

    $membershipExpiresAt = $baseDate
        ->modify('+1 year')
        ->format('Y-m-d H:i:s');

    $pdo->prepare(
        "UPDATE students
         SET membership_status = 'active',
             membership_next_due_at = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    )->execute([$membershipExpiresAt, $studentId]);

    $pdo->prepare(
        'INSERT INTO coupon_redemptions
         (
            coupon_id,
            student_id,
            coupon_code,
            membership_years,
            membership_expires_at,
            redeemed_at
         )
         VALUES (?, ?, ?, 1, ?, CURRENT_TIMESTAMP)'
    )->execute([
        (int)$coupon['id'],
        $studentId,
        (string)$coupon['code'],
        $membershipExpiresAt,
    ]);

    $pdo->prepare(
        'UPDATE coupons
         SET redeemed_count = redeemed_count + 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    )->execute([(int)$coupon['id']]);

    return [
        'coupon_id' => (int)$coupon['id'],
        'code' => (string)$coupon['code'],
        'membership_expires_at' => $membershipExpiresAt,
    ];
}

function studentRegistrationNextNumber(
    PDO $pdo,
    int $schoolId,
    bool $lockSchool = false
): ?array {
    $sql = 'SELECT id, school_code, school_name
            FROM schools
            WHERE id = ?';

    if ($lockSchool) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();

    if (!$school) {
        return null;
    }

    $schoolCode = strtoupper(
        trim((string)($school['school_code'] ?? ''))
    );

    if ($schoolCode === '') {
        throw new RuntimeException(
            'The selected school does not have a school code.'
        );
    }

    $numberStmt = $pdo->prepare(
        "SELECT COALESCE(
                    MAX(
                        CAST(
                            SUBSTRING_INDEX(student_number, '-', -1)
                            AS UNSIGNED
                        )
                    ),
                    0
                )
         FROM students
         WHERE school_id = ?
           AND student_number LIKE ?"
    );
    $numberStmt->execute([$schoolId, $schoolCode . '-%']);

    $sequence = ((int)$numberStmt->fetchColumn()) + 1;

    return [
        'school_id' => (int)$school['id'],
        'school_code' => $schoolCode,
        'school_name' => (string)$school['school_name'],
        'sequence' => $sequence,
        'student_number' => $schoolCode . '-' . $sequence,
    ];
}

function studentRegistrationJson(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}
