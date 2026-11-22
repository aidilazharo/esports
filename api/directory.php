<?php
declare(strict_types=1);

/**
 * Search paginated JSON snapshots with no MySQL connection.
 * Public: basic school directory. Other lists: session authorization required.
 */
define('ESPORTS_DIRECTORY_SNAPSHOT_ONLY', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/role_auth.php';
require_once dirname(__DIR__) . '/includes/directory_snapshot.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');

function esportsDirectoryRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    esportsDirectoryRespond(405, ['success' => false, 'message' => 'GET only.']);
}

$type = strtolower(trim((string) ($_GET['type'] ?? 'schools')));
if (!in_array($type, ['schools', 'teachers', 'students', 'parents'], true)) {
    esportsDirectoryRespond(400, ['success' => false, 'message' => 'Invalid directory type.']);
}

$role = portalAuthCurrentRole();
if ($type !== 'schools' && !in_array($role, ['admin', 'school', 'teacher', 'parent'], true)) {
    esportsDirectoryRespond(401, ['success' => false, 'message' => 'Sign in to access this directory.']);
}
if ($role === 'parent' && $type !== 'schools' && $type !== 'parents') {
    esportsDirectoryRespond(403, ['success' => false, 'message' => 'Directory access denied.']);
}

$kind = $type === 'schools' && $role === 'admin' ? 'schools_admin' : $type;
$schoolId = ($role === 'school' || $role === 'teacher')
    ? (int) ($_SESSION['school_id'] ?? 0)
    : 0;
$parentId = $role === 'parent' ? (int) ($_SESSION['parent_id'] ?? 0) : 0;

if (($role === 'school' || $role === 'teacher') && $schoolId <= 0) {
    esportsDirectoryRespond(403, ['success' => false, 'message' => 'School scope is missing.']);
}
if ($role === 'parent' && $parentId <= 0) {
    esportsDirectoryRespond(403, ['success' => false, 'message' => 'Parent scope is missing.']);
}

try {
    $snapshot = esportsDirectorySnapshotRead($kind);
    if ($snapshot === null) {
        esportsDirectoryRespond(503, [
            'success' => false,
            'message' => 'Directory is being prepared. Retry after the scheduled refresh.',
        ]);
    }

    $result = esportsDirectorySnapshotSearch(
        $snapshot['rows'],
        $kind,
        $role,
        $schoolId,
        $parentId,
        (string) ($_GET['q'] ?? ''),
        $type === 'schools' ? (string) ($_GET['state'] ?? '') : '',
        max(0, (int) ($_GET['school_id'] ?? 0)),
        (int) ($_GET['page'] ?? 1),
        (int) ($_GET['limit'] ?? 25)
    );

    esportsDirectoryRespond(200, [
        'success' => true,
        'type' => $type,
        'generated_at' => $snapshot['generated_at'],
        'total' => $result['total'],
        'page' => $result['page'],
        'limit' => $result['limit'],
        'data' => $result['rows'],
    ]);
} catch (Throwable $e) {
    error_log('Directory API read failed: ' . $e->getMessage());
    esportsDirectoryRespond(503, ['success' => false, 'message' => 'Directory is temporarily unavailable.']);
}
