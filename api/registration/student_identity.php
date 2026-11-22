<?php
declare(strict_types=1);

$startedAt = microtime(true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/student_registration_service.php';

function normalizeStudentIdentityApi(string $value): string
{
    return strtoupper(
        preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? ''
    );
}

function studentIdentityCachePath(string $identity): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'metadata_student_identity_cache';

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    return $dir
        . DIRECTORY_SEPARATOR
        . hash('sha256', $identity)
        . '.json';
}

function studentIdentityCacheGet(
    string $identity,
    int $ttl = 300
): ?array {
    $path = studentIdentityCachePath($identity);

    if (!is_file($path)) {
        return null;
    }

    $mtime = @filemtime($path);
    if ($mtime === false || ($mtime + $ttl) < time()) {
        @unlink($path);
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function studentIdentityCacheSet(
    string $identity,
    array $payload
): void {
    // Positive cache only. Negative results remain live so a newly-created
    // student cannot be hidden by a stale negative cache.
    if (empty($payload['exists'])) {
        return;
    }

    @file_put_contents(
        studentIdentityCachePath($identity),
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );
}

function studentIdentityRespond(
    array $payload,
    int $status,
    float $startedAt,
    string $source
): never {
    $durationMs = round(
        (microtime(true) - $startedAt) * 1000,
        2
    );

    $payload['server_ms'] = $durationMs;
    $payload['source'] = $source;

    header('Server-Timing: student_identity;dur=' . $durationMs);
    header('X-Student-Identity-Source: ' . $source);

    studentRegistrationJson($payload, $status);
}

try {
    $identity = normalizeStudentIdentityApi(
        (string)($_GET['identity_number'] ?? $_GET['nric'] ?? '')
    );

    if (strlen($identity) < 4) {
        studentIdentityRespond([
            'success' => false,
            'exists' => false,
            'message' => 'Enter at least 4 numbers / characters.',
        ], 422, $startedAt, 'validation');
    }

    $cached = studentIdentityCacheGet($identity);
    if ($cached !== null) {
        studentIdentityRespond(
            $cached,
            200,
            $startedAt,
            'server-cache'
        );
    }

    $stmt = $pdo->prepare(
        'SELECT id, student_number, membership_status
         FROM students
         WHERE identity_number = ?
         LIMIT 1'
    );
    $stmt->execute([$identity]);
    $student = $stmt->fetch();

    $source = 'exact-index';

    if (!$student) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, student_number, membership_status
                 FROM students
                 WHERE identity_number_normalized = ?
                 LIMIT 1'
            );
            $stmt->execute([$identity]);
            $student = $stmt->fetch();
            $source = 'normalized-index';
        } catch (PDOException $e) {
            $stmt = $pdo->prepare(
                "SELECT id, student_number, membership_status
                 FROM students
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
            $student = $stmt->fetch();
            $source = 'legacy-scan';
        }
    }

    $exists = (bool)$student;

    $payload = [
        'success' => true,
        'exists' => $exists,
        'student_id' => $exists ? (int)$student['id'] : null,
        'student_number' => $exists
            ? (string)($student['student_number'] ?? '')
            : null,
        'membership_status' => $exists
            ? (string)($student['membership_status'] ?? '')
            : null,
        'message' => $exists
            ? 'This NRIC / Passport is already registered as a student.'
            : 'NRIC / Passport is available for the Student role.',
    ];

    studentIdentityCacheSet($identity, $payload);

    studentIdentityRespond(
        $payload,
        200,
        $startedAt,
        $source
    );
} catch (Throwable $e) {
    error_log(
        'student_identity.php error: ' . $e->getMessage()
    );

    studentIdentityRespond([
        'success' => false,
        'exists' => false,
        'message' => 'Unable to check Student NRIC / Passport at this time.',
    ], 500, $startedAt, 'error');
}
