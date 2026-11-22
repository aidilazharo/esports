<?php
declare(strict_types=1);

// Use a generated public snapshot for broad searches without opening MySQL.
// Full exact registration details still come from the current database.
require_once __DIR__ . '/../../includes/directory_snapshot.php';
$directorySchoolSnapshot = null;
if (!(($_GET['exact'] ?? '') === '1' && ($_GET['details'] ?? '') === '1')) {
    try {
        $directorySchoolSnapshot = esportsDirectorySnapshotRead('schools');
    } catch (Throwable $e) {
        error_log('School snapshot unavailable: ' . $e->getMessage());
    }
}
if (is_array($directorySchoolSnapshot)) {
    define('ESPORTS_DIRECTORY_SNAPSHOT_ONLY', true);
}
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../registration_api_cache.php';

function registrationSchoolColumnExists(
    PDO $pdo,
    string $column
): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );

    $stmt->execute([
        'schools',
        $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

try {
    $q = registrationUppercaseInput((string)($_GET['q'] ?? ''));
    $exact = (string)($_GET['exact'] ?? '') === '1';
    $details = (string)($_GET['details'] ?? '') === '1';
    $role = strtolower(trim((string)($_GET['role'] ?? '')));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));

    // Detailed contact/address data is only returned for an exact school-code lookup.
    // Broad school-search APIs used by Teacher/Student/Coach registration stay minimal.
    if ($exact && $details && $q !== '') {
        $cacheKey = 'registration-school-exact-v4:' . $q;
        $school = registrationApiCacheGet($cacheKey, 300);

        if (!is_array($school)) {
            $hasTotalStudents =
                registrationSchoolColumnExists(
                    $pdo,
                    'total_students'
                );

            $hasAreaType =
                registrationSchoolColumnExists(
                    $pdo,
                    'school_area_type'
                );

            $populationSelect = $hasTotalStudents
                ? 'total_students'
                : 'NULL AS total_students';

            $areaSelect = $hasAreaType
                ? 'school_area_type'
                : "'' AS school_area_type";

            $stmt = $pdo->prepare(
                "SELECT id, school_code, school_name, school_type, state, district,
                        {$populationSelect},
                        {$areaSelect},
                        address, contact_name, contact_phone, contact_email,
                        referrer, school_logo
                 FROM schools
                 WHERE UPPER(school_code) = ?
                 LIMIT 1"
            );
            $stmt->execute([$q]);
            $row = $stmt->fetch();

            if (!$row) {
                registrationJsonResponse([
                    'success' => true,
                    'query' => $q,
                    'count' => 0,
                    'schools' => [],
                ], 200, 30);
            }

            $school = [
                'id' => (int)$row['id'],
                'code' => registrationUppercaseInput((string)$row['school_code']),
                'name' => registrationUppercaseInput((string)$row['school_name']),
                'type' => registrationUppercaseInput((string)($row['school_type'] ?? '')),
                'state' => registrationUppercaseInput((string)($row['state'] ?? '')),
                'district' => registrationUppercaseInput((string)($row['district'] ?? '')),
                'total_students' => (int)($row['total_students'] ?? 0),
                'school_area_type' => registrationUppercaseInput(
                    (string)($row['school_area_type'] ?? '')
                ),
                'address' => registrationUppercaseInput((string)($row['address'] ?? '')),
                'contact_name' => registrationUppercaseInput((string)($row['contact_name'] ?? '')),
                'contact_phone' => registrationUppercaseInput((string)($row['contact_phone'] ?? '')),
                'contact_email' => registrationUppercaseInput((string)($row['contact_email'] ?? '')),
                'referrer' => registrationUppercaseInput((string)($row['referrer'] ?? '')),
                'school_logo' => trim((string)($row['school_logo'] ?? '')),
            ];
            $school['registration_complete'] =
                $school['contact_name'] !== ''
                && $school['contact_phone'] !== ''
                && $school['contact_email'] !== ''
                && (int)$school['total_students'] > 0
                && in_array(
                    $school['school_area_type'],
                    ['URBAN', 'RURAL'],
                    true
                );

            registrationApiCacheSet($cacheKey, $school);
        }

        registrationJsonResponse([
            'success' => true,
            'query' => $q,
            'count' => 1,
            'schools' => [$school],
        ], 200, 30);
    }

    $allSchools = is_array($directorySchoolSnapshot)
        ? $directorySchoolSnapshot['rows']
        : registrationApiCacheGet('registration-schools-v4', 300);

    if (!is_array($allSchools)) {
        $stmt = $pdo->query(
            "SELECT s.id, s.school_code, s.school_name, s.school_type, s.state, s.district,
                    EXISTS(SELECT 1 FROM coaches c WHERE c.school_id=s.id LIMIT 1) AS has_coach
             FROM schools s
             ORDER BY s.school_name ASC, s.school_code ASC"
        );

        $allSchools = [];
        foreach ($stmt->fetchAll() as $row) {
            $allSchools[] = [
                'id' => (int)$row['id'],
                'code' => registrationUppercaseInput((string)$row['school_code']),
                'name' => registrationUppercaseInput((string)$row['school_name']),
                'type' => registrationUppercaseInput((string)($row['school_type'] ?? '')),
                'state' => registrationUppercaseInput((string)($row['state'] ?? '')),
                'district' => registrationUppercaseInput((string)($row['district'] ?? '')),
                'has_coach' => (bool)$row['has_coach'],
            ];
        }
        registrationApiCacheSet('registration-schools-v4', $allSchools);
    }

    $matches = [];
    foreach ($allSchools as $school) {
        $haystack = registrationUppercaseInput(
            ($school['code'] ?? '') . ' ' .
            ($school['name'] ?? '') . ' ' .
            ($school['type'] ?? '') . ' ' .
            ($school['state'] ?? '') . ' ' .
            ($school['district'] ?? '')
        );

        $matched = $q === ''
            ? true
            : ($exact
                ? registrationUppercaseInput((string)($school['code'] ?? '')) === $q
                : str_contains($haystack, $q));

        if (!$matched) {
            continue;
        }

        $item = $school;
        $item['available'] = $role === 'coach' ? empty($school['has_coach']) : true;
        $matches[] = $item;

        if (count($matches) >= $limit) {
            break;
        }
    }

    registrationJsonResponse([
        'success' => true,
        'query' => $q,
        'cached_for_seconds' => 300,
        'count' => count($matches),
        'schools' => $matches,
    ], 200, 120);
} catch (Throwable $e) {
    error_log('Registration school API error: ' . $e->getMessage());
    registrationJsonResponse([
        'success' => false,
        'message' => 'Unable to search schools at this time.',
        'schools' => [],
    ], 500, 0);
}
