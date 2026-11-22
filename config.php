<?php
declare(strict_types=1);

/**
 * Application configuration.
 * Target runtime: PHP 8.5.
 *
 * Server-level environment variables take precedence. For hosts that do not
 * inject environment variables, .env (preferred) or env is loaded here.
 */

function loadApplicationEnv(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && $value[-1] === '"')
                || ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function envString(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

function envInt(string $key, int $default = 0): int
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : (int) $value;
}

function envBool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

$preferredEnv = __DIR__ . '/.env';
$fallbackEnv = __DIR__ . '/env';

if (is_file($preferredEnv)) {
    loadApplicationEnv($preferredEnv);
} elseif (is_file($fallbackEnv)) {
    loadApplicationEnv($fallbackEnv);
}

/* Application */
define('APP_NAME', envString('APP_NAME', 'Malaysia School Esports Portal'));
define('APP_ENV', envString('APP_ENV', 'production'));
define('APP_URL', rtrim(envString('APP_URL', 'https://esports.metadata.technology'), '/'));
define('APP_TIMEZONE', envString('APP_TIMEZONE', 'Asia/Kuala_Lumpur'));
define('APP_DEBUG', envBool('APP_DEBUG', false));

/* Limit list results to at most 50 records per database request. */
define('MAX_RECORDS_PER_REQUEST', 50);
define('RECORDS_PER_REQUEST', min(MAX_RECORDS_PER_REQUEST, max(1, envInt('RECORDS_PER_REQUEST', 50))));

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', envBool('DISPLAY_ERRORS', false) ? '1' : '0');
ini_set('log_errors', envBool('LOG_ERRORS', true) ? '1' : '0');

/* Slow request logger */
$requestStartedNs = hrtime(true);
$slowRequestThresholdMs = max(0, envInt('SLOW_REQUEST_MS', 1000));

if ($slowRequestThresholdMs > 0) {
    register_shutdown_function(
        static function () use ($requestStartedNs, $slowRequestThresholdMs): void {
            $durationMs = (hrtime(true) - $requestStartedNs) / 1_000_000;

            if ($durationMs < $slowRequestThresholdMs) {
                return;
            }

            error_log(sprintf(
                '[SLOW REQUEST] %.0f ms | %s | %s | %.1f MB peak',
                $durationMs,
                $_SERVER['REQUEST_METHOD'] ?? '-',
                $_SERVER['REQUEST_URI'] ?? '-',
                memory_get_peak_usage(true) / 1048576
            ));
        }
    );
}

/* Hostinger deployment fallback: expire generated HTML when VERSION changes.
 * Git post-merge hooks (if installed) perform the same cleanup immediately.
 * Requests do not need MySQL or an admin session to invalidate this cache.
 */
require_once __DIR__ . '/includes/html_cache_deploy.php';
esportsInvalidateHtmlCacheForVersion(__DIR__);

/* Session */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', envBool('SESSION_USE_STRICT_MODE', true) ? '1' : '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', envBool('SESSION_COOKIE_HTTPONLY', true) ? '1' : '0');
    ini_set('session.lazy_write', '1');

    $secureCookie = envBool(
        'SESSION_COOKIE_SECURE',
        str_starts_with(strtolower(APP_URL), 'https://')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => envBool('SESSION_COOKIE_HTTPONLY', true),
        'samesite' => envString('SESSION_COOKIE_SAMESITE', 'Lax'),
    ]);

    session_start();
}

function releaseSessionLock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/* Database */
define('DB_HOST', envString('DB_HOST', 'localhost'));
define('DB_NAME', envString('DB_NAME'));
define('DB_USER', envString('DB_USER'));
define('DB_PASS', envString('DB_PASS'));
define('DB_CONNECT_TIMEOUT', max(1, envInt('DB_CONNECT_TIMEOUT', 5)));
define('DB_PERSISTENT', envBool('DB_PERSISTENT', true));

// The read-only JSON snapshot API can work without opening MySQL.
// Only that dedicated endpoint sets this flag before loading this file.
if (!defined('ESPORTS_DIRECTORY_SNAPSHOT_ONLY') || ESPORTS_DIRECTORY_SNAPSHOT_ONLY !== true) {
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => DB_CONNECT_TIMEOUT,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => DB_PERSISTENT,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());

    http_response_code(500);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Service Temporarily Unavailable</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '</head><body class="bg-light"><div class="container py-5">'
        . '<div class="alert alert-danger"><h4 class="alert-heading">Service temporarily unavailable</h4>'
        . '<p class="mb-0">Unable to connect to the database. Please try again shortly.</p>'
        . '</div></div></body></html>';
    exit;
}

}

/* External services / application settings */
define('ADMIN_ACCESS_CODE', envString('ADMIN_ACCESS_CODE'));
define('ADMIN_PASSWORD', envString('ADMIN_PASSWORD')); // Legacy compatibility only.

define('STRIPE_SECRET_KEY', envString('STRIPE_SECRET_KEY'));
define('STRIPE_WEBHOOK_SECRET', envString('STRIPE_WEBHOOK_SECRET'));

define('CURRENCY', envString('CURRENCY', 'myr'));

/* Current fee model: RM100 per participant per year. */
define('ANNUAL_MEMBERSHIP_FEE', envInt('ANNUAL_MEMBERSHIP_FEE', 10000));

/* Legacy constants retained temporarily for older pages. */
define('REGISTRATION_FEE', envInt('REGISTRATION_FEE', 0));
define('JERSEY_CARD_FEE', envInt('JERSEY_CARD_FEE', ANNUAL_MEMBERSHIP_FEE));
define('MONTHLY_MEMBERSHIP_FEE', envInt('MONTHLY_MEMBERSHIP_FEE', 0));
define('MONTHLY_ATTENDANCE_MINUTES', envInt('MONTHLY_ATTENDANCE_MINUTES', 0));

define('PANDASCORE_API_TOKEN', envString('PANDASCORE_API_TOKEN'));

define('PS5_REWARD_THRESHOLD', envInt('PS5_REWARD_THRESHOLD', 30));
define('WORKSTATION_REWARD_THRESHOLD', envInt('WORKSTATION_REWARD_THRESHOLD', 200));

/* Common helpers */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

/* APCu helpers */
function appApcuAvailable(): bool
{
    if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) {
        return false;
    }

    if (function_exists('apcu_enabled')) {
        return apcu_enabled();
    }

    return filter_var((string) ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
}

function schoolsCacheKey(): string
{
    // New key avoids reusing previously cached unlimited school listings.
    return 'metadata_schools_v2_limit_' . RECORDS_PER_REQUEST;
}

function clearSchoolsCache(): void
{
    if (appApcuAvailable() && function_exists('apcu_delete')) {
        apcu_delete(schoolsCacheKey());
    }
}

/**
 * Fetch up to 50 school records per request, with database-side search/paging.
 *
 * getSchools($pdo)                    -> first page, alphabetically ordered
 * getSchools($pdo, 'ABC')             -> first 50 matching names/codes
 * getSchools($pdo, 'ABC', 2)          -> second page of matching schools
 *
 * Callers that need more records must paginate or search, rather than
 * loading the entire school table into a single PHP request.
 */
function getSchools(PDO $pdo, string $search = '', int $page = 1): array
{
    static $requestCache = [];

    $search = trim(substr($search, 0, 150));
    $page = max(1, min(100000, $page));
    $limit = RECORDS_PER_REQUEST;
    $offset = ($page - 1) * $limit;
    $key = $page . ':' . $search;

    if (array_key_exists($key, $requestCache)) {
        return $requestCache[$key];
    }

    // Cache only the default unfiltered first page. A search always uses
    // the current database state and cannot reuse a cached unrelated list.
    $cacheDefaultList = ($search === '' && $page === 1);
    $ttl = max(0, envInt('SCHOOLS_CACHE_TTL', 60));

    if ($cacheDefaultList && $ttl > 0 && appApcuAvailable()) {
        $success = false;
        $cached = apcu_fetch(schoolsCacheKey(), $success);
        if ($success && is_array($cached)) {
            $requestCache[$key] = array_slice($cached, 0, $limit);
            return $requestCache[$key];
        }
    }

    if ($search === '') {
        $stmt = $pdo->prepare(
            'SELECT id, school_code, school_name
             FROM schools
             ORDER BY school_name ASC, id ASC
             LIMIT :limit OFFSET :offset'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, school_code, school_name
             FROM schools
             WHERE school_name LIKE :school_name
                OR school_code LIKE :school_code
             ORDER BY school_name ASC, id ASC
             LIMIT :limit OFFSET :offset'
        );
        $term = '%' . $search . '%';
        $stmt->bindValue(':school_name', $term, PDO::PARAM_STR);
        $stmt->bindValue(':school_code', $term, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $requestCache[$key] = $rows;

    if ($cacheDefaultList && $ttl > 0 && appApcuAvailable()) {
        apcu_store(schoolsCacheKey(), $rows, $ttl);
    }

    return $rows;
}

function moneyFromMinor(int $amount): string
{
    return 'RM ' . number_format($amount / 100, 2);
}

function membershipBadge(string $status): string
{
    return match ($status) {
        'active' => 'success',
        'inactive' => 'danger',
        'cancelled' => 'secondary',
        default => 'warning',
    };
}

function membershipLabel(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'inactive' => 'Non-Active',
        'cancelled' => 'Cancelled',
        default => 'Pending Payment',
    };
}

function learningStatusBadge(string $status): string
{
    return match ($status) {
        'completed' => 'success',
        'in_progress' => 'warning',
        default => 'secondary',
    };
}

function learningStatusLabel(string $status): string
{
    return match ($status) {
        'completed' => 'Completed',
        'in_progress' => 'In Progress',
        default => 'Not Started',
    };
}

function formatMinutes(int $minutes): string
{
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    if ($hours > 0 && $remaining > 0) {
        return $hours . 'h ' . $remaining . 'm';
    }

    if ($hours > 0) {
        return $hours . 'h';
    }

    return $remaining . 'm';
}

function monthRange(?string $month = null): array
{
    $value = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
    $start = new DateTimeImmutable($value . '-01 00:00:00');
    $end = $start->modify('first day of next month');

    return [
        'key' => $start->format('Y-m'),
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'label' => $start->format('F Y'),
    ];
}

function ensureStudentQrToken(PDO $pdo, int $studentId, ?string $existingToken = null): string
{
    if ($existingToken) {
        return $existingToken;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(32));

        try {
            $stmt = $pdo->prepare(
                "UPDATE students
                 SET qr_token = ?
                 WHERE id = ? AND (qr_token IS NULL OR qr_token = '')"
            );
            $stmt->execute([$token, $studentId]);

            $read = $pdo->prepare('SELECT qr_token FROM students WHERE id = ?');
            $read->execute([$studentId]);

            $saved = (string) $read->fetchColumn();

            if ($saved !== '') {
                return $saved;
            }
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    throw new RuntimeException('Unable to generate a unique QR token.');
}

function schoolReward(int $activeStudents): array
{
    if ($activeStudents >= WORKSTATION_REWARD_THRESHOLD) {
        return [
            'level' => 'PS5 + Workstation',
            'badge' => 'success',
            'description' => 'Eligible for 1 PS5 console, 2 controllers and 1 high-end PC workstation.',
            'next_target' => null,
        ];
    }

    if ($activeStudents >= PS5_REWARD_THRESHOLD) {
        return [
            'level' => 'PS5 Reward',
            'badge' => 'primary',
            'description' => 'Eligible for 1 PS5 console and 2 controllers.',
            'next_target' => WORKSTATION_REWARD_THRESHOLD - $activeStudents,
        ];
    }

    return [
        'level' => 'Not Yet Eligible',
        'badge' => 'secondary',
        'description' => 'Requires ' . (PS5_REWARD_THRESHOLD - $activeStudents)
            . ' more active students for the PS5 reward.',
        'next_target' => PS5_REWARD_THRESHOLD - $activeStudents,
    ];
}
