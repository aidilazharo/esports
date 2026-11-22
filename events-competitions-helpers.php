<?php
declare(strict_types=1);

/**
 * Shared helpers for Events & Competitions management.
 * Legacy programme_* database/table/function names are retained for backward compatibility.
 */

function pmTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function pmColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function pmSchemaReady(PDO $pdo): bool
{
    foreach (['programme_events', 'programme_applications', 'programme_winners'] as $table) {
        if (!pmTableExists($pdo, $table)) {
            return false;
        }
    }
    return true;
}

function pmNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
}

function pmDbDateTime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat(
            $format,
            $value,
            new DateTimeZone('Asia/Kuala_Lumpur')
        );

        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function pmLocalDateTime(?string $value): string
{
    if (!$value) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Kuala_Lumpur')))
            ->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

function pmDisplayDateTime(?string $value): string
{
    if (!$value) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Kuala_Lumpur')))
            ->format('d M Y, h:i A');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function pmDisplayDateRange(?string $start, ?string $end): string
{
    if (!$start && !$end) {
        return '-';
    }

    if ($start && $end) {
        return pmDisplayDateTime($start) . ' - ' . pmDisplayDateTime($end);
    }

    return pmDisplayDateTime($start ?: $end);
}

function pmMoney(int $minor, string $currency = 'MYR'): string
{
    return strtoupper($currency) . ' ' . number_format($minor / 100, 2);
}

function pmAmountToMinor(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '0';
    return max(0, (int) round(((float) $value) * 100));
}

function pmAdminAuthenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function pmEventPrefix(string $type): string
{
    return $type === 'tournament' ? 'TMT' : 'PRG';
}

function pmCreateEventCode(string $type, int $id, ?string $createdAt = null): string
{
    $year = $createdAt ? (int) date('Y', strtotime($createdAt)) : (int) pmNow()->format('Y');
    return sprintf('%s-%04d-%05d', pmEventPrefix($type), $year, $id);
}

function pmCreateApplicationCode(int $id, ?string $createdAt = null): string
{
    $year = $createdAt ? (int) date('Y', strtotime($createdAt)) : (int) pmNow()->format('Y');
    return sprintf('APP-%04d-%06d', $year, $id);
}

function pmEventIsOpen(array $event): bool
{
    if (($event['status'] ?? '') !== 'open') {
        return false;
    }

    $deadline = (string) ($event['application_deadline'] ?? '');
    if ($deadline === '') {
        return false;
    }

    try {
        $deadlineDt = new DateTimeImmutable($deadline, new DateTimeZone('Asia/Kuala_Lumpur'));
        return pmNow() <= $deadlineDt;
    } catch (Throwable $e) {
        return false;
    }
}

function pmEventStatusLabel(array $event): string
{
    if (($event['status'] ?? '') === 'open' && !pmEventIsOpen($event)) {
        return 'Deadline Closed';
    }

    return match ((string) ($event['status'] ?? 'draft')) {
        'open' => 'Open',
        'closed' => 'Closed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'Draft',
    };
}

function pmEventStatusBadge(array $event): string
{
    if (($event['status'] ?? '') === 'open' && !pmEventIsOpen($event)) {
        return 'secondary';
    }

    return match ((string) ($event['status'] ?? 'draft')) {
        'open' => 'success',
        'closed' => 'secondary',
        'completed' => 'primary',
        'cancelled' => 'danger',
        default => 'warning',
    };
}

function pmEligibilityLabel(string $eligibility): string
{
    return match ($eligibility) {
        'school' => 'School',
        'student' => 'Student',
        default => 'School or Student',
    };
}

function pmApplicationStatusBadge(string $status): string
{
    return match ($status) {
        'approved' => 'success',
        'rejected' => 'danger',
        'withdrawn' => 'secondary',
        default => 'warning',
    };
}

function pmPaymentStatusBadge(string $status): string
{
    return match ($status) {
        'paid' => 'success',
        'refunded' => 'info',
        'waived' => 'secondary',
        default => 'warning',
    };
}

function pmPrizeSummary(PDO $pdo, array $event): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(fee_paid_minor), 0)
         FROM programme_applications
         WHERE event_id = ?
           AND payment_status = 'paid'"
    );
    $stmt->execute([(int) $event['id']]);
    $pool = max(0, (int) $stmt->fetchColumn());

    $p1 = (float) ($event['first_prize_percent'] ?? 50);
    $p2 = (float) ($event['second_prize_percent'] ?? 30);
    $p3 = (float) ($event['third_prize_percent'] ?? 20);

    $first = (int) round($pool * ($p1 / 100));
    $second = (int) round($pool * ($p2 / 100));

    // Make third place absorb rounding differences when percentages total 100%.
    if (abs(($p1 + $p2 + $p3) - 100.0) < 0.001) {
        $third = max(0, $pool - $first - $second);
    } else {
        $third = (int) round($pool * ($p3 / 100));
    }

    return [
        'pool_minor' => $pool,
        'first_minor' => $first,
        'second_minor' => $second,
        'third_minor' => $third,
        'paid_applications' => (int) $pdo->query(
            "SELECT COUNT(*) FROM programme_applications
             WHERE event_id = " . (int) $event['id'] . " AND payment_status = 'paid'"
        )->fetchColumn(),
    ];
}


/**
 * Return the effective prize distribution without querying the database.
 *
 * $paidFeePoolMinor is the amount collected from application fees marked Paid.
 * Automatic mode distributes that amount by percentage.
 * Manual mode uses the fixed 1st/2nd/3rd prize amounts saved on the event.
 */
function pmEffectivePrizeSummaryFromPool(
    array $event,
    int $paidFeePoolMinor,
    int $paidApplications = 0
): array {
    $paidFeePoolMinor = max(0, $paidFeePoolMinor);

    $p1 = (float) ($event['first_prize_percent'] ?? 50);
    $p2 = (float) ($event['second_prize_percent'] ?? 30);
    $p3 = (float) ($event['third_prize_percent'] ?? 20);

    $automaticFirst = (int) round($paidFeePoolMinor * ($p1 / 100));
    $automaticSecond = (int) round($paidFeePoolMinor * ($p2 / 100));

    if (abs(($p1 + $p2 + $p3) - 100.0) < 0.001) {
        $automaticThird = max(
            0,
            $paidFeePoolMinor - $automaticFirst - $automaticSecond
        );
    } else {
        $automaticThird = (int) round($paidFeePoolMinor * ($p3 / 100));
    }

    $mode = (string) ($event['prize_distribution_mode'] ?? 'automatic');

    if ($mode === 'manual') {
        $first = max(0, (int) ($event['manual_first_prize_minor'] ?? 0));
        $second = max(0, (int) ($event['manual_second_prize_minor'] ?? 0));
        $third = max(0, (int) ($event['manual_third_prize_minor'] ?? 0));

        return [
            'distribution_mode' => 'manual',
            'pool_minor' => $first + $second + $third,
            'fee_pool_minor' => $paidFeePoolMinor,
            'first_minor' => $first,
            'second_minor' => $second,
            'third_minor' => $third,
            'paid_applications' => max(0, $paidApplications),
        ];
    }

    return [
        'distribution_mode' => 'automatic',
        'pool_minor' => $paidFeePoolMinor,
        'fee_pool_minor' => $paidFeePoolMinor,
        'first_minor' => $automaticFirst,
        'second_minor' => $automaticSecond,
        'third_minor' => $automaticThird,
        'paid_applications' => max(0, $paidApplications),
    ];
}

/**
 * Return the effective prize distribution for an event.
 *
 * Automatic:
 *   prize pool = total application fees marked Paid.
 *
 * Manual:
 *   prize pool = fixed 1st + 2nd + 3rd place amounts configured by admin.
 *   fee_pool_minor still reports the amount actually collected from paid fees.
 */
function pmEffectivePrizeSummary(PDO $pdo, array $event): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN fee_paid_minor ELSE 0 END), 0) AS paid_fee_pool_minor,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_applications
         FROM programme_applications
         WHERE event_id = ?"
    );
    $stmt->execute([(int) ($event['id'] ?? 0)]);
    $row = $stmt->fetch() ?: [];

    return pmEffectivePrizeSummaryFromPool(
        $event,
        (int) ($row['paid_fee_pool_minor'] ?? 0),
        (int) ($row['paid_applications'] ?? 0)
    );
}

function pmPrizeDistributionModeLabel(array $event): string
{
    return (($event['prize_distribution_mode'] ?? 'automatic') === 'manual')
        ? 'Manual'
        : 'Automatic';
}

function pmApplicationName(array $row): string
{
    if (($row['applicant_type'] ?? '') === 'student') {
        return (string) ($row['student_name'] ?? $row['student_number'] ?? 'Student');
    }

    return (string) ($row['school_name'] ?? $row['school_code'] ?? 'School');
}

function pmApplicationReference(array $row): string
{
    if (($row['applicant_type'] ?? '') === 'student') {
        return (string) ($row['student_number'] ?? '');
    }

    return (string) ($row['school_code'] ?? '');
}


function pmProgrammeParticipationSchemaReady(PDO $pdo): bool
{
    return pmColumnExists($pdo, 'programme_events', 'max_students_per_school')
        && pmColumnExists($pdo, 'programme_applications', 'stripe_checkout_session_id')
        && pmColumnExists($pdo, 'programme_applications', 'stripe_payment_intent_id')
        && pmColumnExists($pdo, 'programme_applications', 'paid_at')
        && pmTableExists($pdo, 'programme_application_students');
}

function pmMinimumActiveStudentsForSchool(): int
{
    return 100;
}

function pmActiveStudentCount(PDO $pdo, int $schoolId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM students
         WHERE school_id = ?
           AND membership_status = 'active'"
    );
    $stmt->execute([$schoolId]);
    return (int) $stmt->fetchColumn();
}

function pmSchoolCompetitionEligibility(PDO $pdo, int $schoolId): array
{
    $activeCount = pmActiveStudentCount($pdo, $schoolId);
    $minimum = pmMinimumActiveStudentsForSchool();
    $isActiveSchool = $activeCount >= $minimum;

    return [
        'active_students' => $activeCount,
        'minimum_active_students' => $minimum,
        'active_school' => $isActiveSchool,
        'qualified' => true,
        'payment_required' => !$isActiveSchool,
    ];
}

function pmSchoolApplicationFeeMinor(PDO $pdo, array $event, int $schoolId): int
{
    $eligibility = pmSchoolCompetitionEligibility($pdo, $schoolId);

    if (!empty($eligibility['active_school'])) {
        return 0;
    }

    return max(0, (int) ($event['entry_fee_minor'] ?? 0));
}

function pmMaxStudentsPerSchool(array $event): int
{
    return max(1, (int) ($event['max_students_per_school'] ?? 1));
}

function pmApplicationParticipants(PDO $pdo, int $applicationId): array
{
    if (!pmTableExists($pdo, 'programme_application_students')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT
            pas.id,
            pas.student_id,
            st.student_number,
            st.full_name,
            st.membership_status,
            s.school_code,
            s.school_name
         FROM programme_application_students pas
         JOIN students st ON st.id = pas.student_id
         JOIN schools s ON s.id = pas.school_id
         WHERE pas.application_id = ?
         ORDER BY st.full_name, st.student_number"
    );
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll();
}

function pmApplicationParticipantCount(PDO $pdo, int $applicationId): int
{
    if (!pmTableExists($pdo, 'programme_application_students')) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM programme_application_students WHERE application_id = ?'
    );
    $stmt->execute([$applicationId]);
    return (int) $stmt->fetchColumn();
}

function pmProgrammePaymentUrl(string $applicationCode, string $withdrawToken): string
{
    return 'events-competitions-payment.php?application='
        . rawurlencode($applicationCode)
        . '&token='
        . rawurlencode($withdrawToken);
}

function pmBuildUrl(array $changes = [], string $path = 'events-competitions-report.php'): string
{
    $query = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return $path . ($query ? '?' . http_build_query($query) : '');
}
