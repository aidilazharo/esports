<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/directory_snapshot.php';

function registrationUppercaseInput(?string $value): string
{
    $value = trim((string)$value);
    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function registrationNormalizeApiIdentity(?string $value): string
{
    return preg_replace('/[^A-Z0-9]/', '', registrationUppercaseInput($value)) ?? '';
}

function registrationApiCacheDirectory(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'metadata_registration_api_cache';

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    return $dir;
}

function registrationApiCachePath(string $key): string
{
    return registrationApiCacheDirectory()
        . DIRECTORY_SEPARATOR
        . hash('sha256', $key)
        . '.json';
}

function registrationApiCacheGet(string $key, int $ttlSeconds): mixed
{
    $path = registrationApiCachePath($key);
    if (!is_file($path)) return null;

    $mtime = @filemtime($path);
    if ($mtime === false || ($mtime + $ttlSeconds) < time()) {
        @unlink($path);
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function registrationApiCacheSet(string $key, mixed $value): void
{
    $path = registrationApiCachePath($key);
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

    $json = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) return;

    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

function registrationSchoolCacheInvalidate(string $schoolCode = ''): void
{
    @unlink(registrationApiCachePath('registration-schools-v4'));

    $schoolCode = registrationUppercaseInput($schoolCode);
    if ($schoolCode !== '') {
        @unlink(registrationApiCachePath('registration-school-exact-v4:' . $schoolCode));
    }
    // Make newly updated schools visible immediately via the MySQL fallback.
    try {
        esportsDirectorySnapshotInvalidate('schools');
        esportsDirectorySnapshotInvalidate('schools_admin');
    } catch (Throwable $e) {
        error_log('School directory invalidation failed: ' . $e->getMessage());
    }
}

function registrationIdentityCacheInvalidate(string $identityNumber): void
{
    $identityNumber = registrationNormalizeApiIdentity($identityNumber);
    if ($identityNumber === '') return;

    @unlink(registrationApiCachePath('registration-identity-v2:' . $identityNumber));
    @unlink(registrationApiCachePath('registration-student-v3:' . $identityNumber));
    @unlink(registrationApiCachePath('registration-guardian-v3:' . $identityNumber));
}

function registrationJsonResponse(array $payload, int $status = 200, int $browserCacheSeconds = 60): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    if ($browserCacheSeconds > 0 && $status === 200) {
        header('Cache-Control: public, max-age=' . $browserCacheSeconds . ', stale-while-revalidate=30');
    } else {
        header('Cache-Control: no-store');
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}
