<?php
declare(strict_types=1);

/*
 * Client-side page performance logger.
 *
 * No query strings, cookies, form values, NRICs, emails or tokens are logged.
 * Only coarse navigation timing and the URL path are written to PHP's error log.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false]);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 8192) {
    http_response_code(413);
    echo json_encode(['success' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$durationMs = max(
    0,
    min(300000, (int) ($data['duration_ms'] ?? 0))
);

$ttfbMs = max(
    0,
    min(300000, (int) ($data['ttfb_ms'] ?? 0))
);

$domContentLoadedMs = max(
    0,
    min(
        300000,
        (int) ($data['dom_content_loaded_ms'] ?? 0)
    )
);

if ($durationMs < 2500 && $ttfbMs < 1500) {
    http_response_code(204);
    exit;
}

$path = (string) ($data['path'] ?? '/');
$parsedPath = parse_url($path, PHP_URL_PATH);

$path = is_string($parsedPath) && $parsedPath !== ''
    ? $parsedPath
    : '/';

$path = preg_replace(
    '/[^A-Za-z0-9_\-\.\/]/',
    '',
    $path
) ?? '/';

$entry = [
    'event' => 'slow_browser_page',
    'path' => substr($path, 0, 500),
    'duration_ms' => $durationMs,
    'ttfb_ms' => $ttfbMs,
    'dom_content_loaded_ms' => $domContentLoadedMs,
    'transfer_size' => max(
        0,
        min(
            100000000,
            (int) ($data['transfer_size'] ?? 0)
        )
    ),
    'encoded_body_size' => max(
        0,
        min(
            100000000,
            (int) ($data['encoded_body_size'] ?? 0)
        )
    ),
    'timestamp_utc' => gmdate('c'),
];

error_log(
    '[SLOW_PAGE_CLIENT] '
    . json_encode(
        $entry,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )
);

echo json_encode(['success' => true]);
