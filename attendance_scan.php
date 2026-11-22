<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'POST request required.', [], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = (string) ($payload['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    jsonResponse(false, 'The scanner session expired. Refresh the page and try again.', [], 419);
}

$scannedValue = trim((string) ($payload['qr_value'] ?? ''));
$scanMethod = trim((string) ($payload['scan_method'] ?? 'qr_camera'));
$scanMethod = in_array($scanMethod, ['qr_camera', 'manual'], true) ? $scanMethod : 'qr_camera';

if ($scannedValue === '') {
    jsonResponse(false, 'No QR value was received.', [], 422);
}

$token = $scannedValue;
if (str_starts_with($token, 'ESPORTS:')) {
    $token = substr($token, 8);
} elseif (filter_var($token, FILTER_VALIDATE_URL)) {
    $parts = parse_url($token);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        $token = (string) ($query['token'] ?? '');
    }
}
$token = trim($token);

if (!preg_match('/^[a-f0-9]{48,64}$/i', $token)) {
    jsonResponse(false, 'This is not a valid student attendance QR code.', ['result_type' => 'invalid'], 422);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT st.id, st.full_name, st.student_number, st.membership_status, st.school_id,
                s.school_name, s.school_code
         FROM students st
         JOIN schools s ON s.id = st.school_id
         WHERE st.qr_token = ?
         FOR UPDATE'
    );
    $stmt->execute([$token]);
    $student = $stmt->fetch();

    if (!$student) {
        $pdo->rollBack();
        jsonResponse(false, 'Student QR code was not found.', ['result_type' => 'not_found'], 404);
    }

    $now = new DateTimeImmutable('now');
    $nowSql = $now->format('Y-m-d H:i:s');

    $openStmt = $pdo->prepare(
        "SELECT * FROM attendance_sessions
         WHERE student_id = ? AND status = 'open'
         ORDER BY check_in_at DESC
         LIMIT 1
         FOR UPDATE"
    );
    $openStmt->execute([(int) $student['id']]);
    $openSession = $openStmt->fetch();

    if ($openSession) {
        $checkIn = new DateTimeImmutable($openSession['check_in_at']);
        $seconds = max(0, $now->getTimestamp() - $checkIn->getTimestamp());

        if ($seconds < 15) {
            $pdo->rollBack();
            jsonResponse(true, 'Duplicate scan ignored. The student is already checked in.', [
                'result_type' => 'duplicate',
                'action' => 'checked_in',
                'student' => $student,
            ]);
        }

        $actualMinutes = max(1, (int) ceil($seconds / 60));
        $usedStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(credited_minutes), 0)
             FROM attendance_sessions
             WHERE student_id = ? AND attendance_month = ? AND status = 'completed'"
        );
        $usedStmt->execute([(int) $student['id'], $openSession['attendance_month']]);
        $usedBefore = (int) $usedStmt->fetchColumn();
        $remainingBefore = max(0, MONTHLY_ATTENDANCE_MINUTES - $usedBefore);
        $creditedMinutes = min($actualMinutes, $remainingBefore);

        $update = $pdo->prepare(
            "UPDATE attendance_sessions
             SET check_out_at = ?, actual_minutes = ?, credited_minutes = ?, status = 'completed', scan_method = ?
             WHERE id = ?"
        );
        $update->execute([$nowSql, $actualMinutes, $creditedMinutes, $scanMethod, (int) $openSession['id']]);

        $usedTotal = min(MONTHLY_ATTENDANCE_MINUTES, $usedBefore + $creditedMinutes);
        $pdo->commit();

        $extraNote = $actualMinutes > $creditedMinutes
            ? ' The session exceeded the remaining monthly allowance; only ' . formatMinutes($creditedMinutes) . ' was credited.'
            : '';

        jsonResponse(true, $student['full_name'] . ' checked out successfully.' . $extraNote, [
            'result_type' => 'success',
            'action' => 'checked_out',
            'student' => $student,
            'actual_minutes' => $actualMinutes,
            'credited_minutes' => $creditedMinutes,
            'used_minutes' => $usedTotal,
            'remaining_minutes' => max(0, MONTHLY_ATTENDANCE_MINUTES - $usedTotal),
            'time' => $now->format('d M Y, h:i A'),
        ]);
    }

    if ($student['membership_status'] !== 'active') {
        $pdo->rollBack();
        jsonResponse(false, $student['full_name'] . ' is not an active paid member and cannot check in.', [
            'result_type' => 'inactive',
            'student' => $student,
        ], 403);
    }

    $recentStmt = $pdo->prepare(
        "SELECT check_out_at
         FROM attendance_sessions
         WHERE student_id = ? AND status = 'completed' AND check_out_at IS NOT NULL
         ORDER BY check_out_at DESC
         LIMIT 1"
    );
    $recentStmt->execute([(int) $student['id']]);
    $lastCheckOut = $recentStmt->fetchColumn();
    if ($lastCheckOut) {
        $last = new DateTimeImmutable($lastCheckOut);
        if (($now->getTimestamp() - $last->getTimestamp()) < 15) {
            $pdo->rollBack();
            jsonResponse(true, 'Duplicate scan ignored. The previous check-out was just recorded.', [
                'result_type' => 'duplicate',
                'action' => 'checked_out',
                'student' => $student,
            ]);
        }
    }

    $attendanceMonth = $now->format('Y-m-01');
    $usedStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(credited_minutes), 0)
         FROM attendance_sessions
         WHERE student_id = ? AND attendance_month = ? AND status = 'completed'"
    );
    $usedStmt->execute([(int) $student['id'], $attendanceMonth]);
    $usedMinutes = (int) $usedStmt->fetchColumn();

    if ($usedMinutes >= MONTHLY_ATTENDANCE_MINUTES) {
        $pdo->rollBack();
        jsonResponse(false, $student['full_name'] . ' has used all 5 attendance hours for this month.', [
            'result_type' => 'allowance_used',
            'student' => $student,
            'used_minutes' => $usedMinutes,
            'remaining_minutes' => 0,
        ], 403);
    }

    $insert = $pdo->prepare(
        "INSERT INTO attendance_sessions
         (student_id, attendance_month, check_in_at, status, scan_method)
         VALUES (?, ?, ?, 'open', ?)"
    );
    $insert->execute([(int) $student['id'], $attendanceMonth, $nowSql, $scanMethod]);

    $pdo->commit();
    jsonResponse(true, $student['full_name'] . ' checked in successfully.', [
        'result_type' => 'success',
        'action' => 'checked_in',
        'student' => $student,
        'used_minutes' => $usedMinutes,
        'remaining_minutes' => max(0, MONTHLY_ATTENDANCE_MINUTES - $usedMinutes),
        'time' => $now->format('d M Y, h:i A'),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Attendance scan error: ' . $e->getMessage());
    jsonResponse(false, 'Attendance could not be recorded. Please try again.', ['result_type' => 'error'], 500);
}
