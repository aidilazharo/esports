<?php
/**
 * brevo_webhook.php
 *
 * Brevo transactional webhook receiver for school invitation open tracking.
 * PHP 7.2+ compatible.
 *
 * Brevo configuration:
 *   URL: https://esports.metadata.technology/brevo_webhook.php
 *   Authentication method: Token
 *   Token: same value as BREVO_WEBHOOK_TOKEN in .env
 *   Events: First opening (uniqueOpened) and Opened
 *
 * invite_read stores the FIRST open timestamp in Asia/Kuala_Lumpur (UTC+8).
 */

declare(strict_types=1);

function loadWebhookEnv($file)
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
        if ($line === '' || substr($line, 0, 1) === '#' || strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function getHeaderValue($name)
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (strcasecmp($name, 'Authorization') === 0) {
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        if (!empty($_SERVER['Authorization'])) {
            return trim((string) $_SERVER['Authorization']);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return trim((string) $value);
                }
            }
        }
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return trim((string) $value);
                }
            }
        }
    }

    return '';
}

function finishWebhook($status, $message)
{
    http_response_code((int) $status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function getBearerToken()
{
    $authorization = getHeaderValue('Authorization');
    if ($authorization === '') {
        return '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    // Fallback in case a proxy passes only the raw token.
    return trim($authorization);
}

function normalizeWebhookEvents($payload)
{
    if (!is_array($payload)) {
        return array();
    }

    // Brevo normally posts a single object. Support batched arrays as well.
    if (isset($payload['event']) || isset($payload['email'])) {
        return array($payload);
    }

    $events = array();
    foreach ($payload as $item) {
        if (is_array($item)) {
            $events[] = $item;
        }
    }
    return $events;
}

function eventTimeKualaLumpur($payload)
{
    $unix = null;

    if (isset($payload['ts_event']) && is_numeric($payload['ts_event'])) {
        $unix = (int) $payload['ts_event'];
    } elseif (isset($payload['ts_epoch']) && is_numeric($payload['ts_epoch'])) {
        $epoch = (int) $payload['ts_epoch'];
        $unix = ($epoch > 9999999999) ? intdiv($epoch, 1000) : $epoch;
    } elseif (isset($payload['ts']) && is_numeric($payload['ts'])) {
        $unix = (int) $payload['ts'];
    }

    $timezone = new DateTimeZone('Asia/Kuala_Lumpur');

    if ($unix !== null && $unix > 0) {
        $dt = new DateTimeImmutable('@' . $unix);
        return $dt->setTimezone($timezone)->format('Y-m-d H:i:s');
    }

    return (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
}

loadWebhookEnv(__DIR__ . '/.env');
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    finishWebhook(500, 'Webhook configuration error: PDO connection not available from config.php.');
}

$requestMethod = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');

// Browser health check. It never updates the database.
if ($requestMethod === 'GET' || $requestMethod === 'HEAD') {
    $tokenConfigured = trim((string) getenv('BREVO_WEBHOOK_TOKEN')) !== '' ? 'yes' : 'no';
    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM schools LIKE 'invite_read'");
        $inviteReadExists = ($columnCheck && $columnCheck->fetch()) ? 'yes' : 'no';
    } catch (Throwable $e) {
        $inviteReadExists = 'database-check-failed';
    }

    finishWebhook(
        200,
        'Brevo webhook ready. Token configured: ' . $tokenConfigured .
        '. invite_read column: ' . $inviteReadExists .
        '. POST authentication required.'
    );
}

if ($requestMethod !== 'POST') {
    finishWebhook(405, 'Method Not Allowed.');
}

$expectedToken = trim((string) getenv('BREVO_WEBHOOK_TOKEN'));
$legacySecret = trim((string) getenv('BREVO_WEBHOOK_SECRET'));

if ($expectedToken === '' && $legacySecret === '') {
    finishWebhook(500, 'BREVO_WEBHOOK_TOKEN is not configured in .env.');
}

$authorized = false;

// Preferred: Brevo Authentication method = Token (Bearer token).
if ($expectedToken !== '') {
    $receivedToken = getBearerToken();
    if ($receivedToken !== '' && hash_equals($expectedToken, $receivedToken)) {
        $authorized = true;
    }
}

// Backward-compatible custom header support.
if (!$authorized && $legacySecret !== '') {
    $receivedSecret = getHeaderValue('X-Webhook-Secret');
    if ($receivedSecret !== '' && hash_equals($legacySecret, $receivedSecret)) {
        $authorized = true;
    }
}

if (!$authorized) {
    error_log('Brevo webhook rejected: missing/invalid authentication.');
    finishWebhook(401, 'Unauthorized.');
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    error_log('Brevo webhook rejected: invalid JSON.');
    finishWebhook(400, 'Invalid JSON.');
}

$events = normalizeWebhookEvents($payload);
if (count($events) === 0) {
    finishWebhook(200, 'Ignored: no events.');
}

$allowedOpenEvents = array(
    'unique_opened',
    'uniqueopened',
    'opened',
    'proxy_open',
    'unique_proxy_open',
    'uniqueproxyopen'
);

$processed = 0;
$matched = 0;
$ignored = 0;

try {
    $update = $pdo->prepare(
        'UPDATE schools
         SET invite_read = COALESCE(invite_read, ?)
         WHERE LOWER(TRIM(contact_email)) = ?
           AND COALESCE(invite_email, 0) > 0
           AND (invite_date IS NULL OR ? >= invite_date)'
    );

    foreach ($events as $eventPayload) {
        $eventName = strtolower(trim((string) (isset($eventPayload['event']) ? $eventPayload['event'] : '')));
        $eventName = str_replace('-', '_', $eventName);

        if (!in_array($eventName, $allowedOpenEvents, true)) {
            $ignored++;
            continue;
        }

        $email = strtolower(trim((string) (isset($eventPayload['email']) ? $eventPayload['email'] : '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('Brevo open event ignored: missing/invalid recipient email. Event=' . $eventName);
            $ignored++;
            continue;
        }

        $readAt = eventTimeKualaLumpur($eventPayload);
        $update->execute(array($readAt, $email, $readAt));

        $processed++;
        if ($update->rowCount() > 0) {
            $matched++;
        }

        error_log(
            'Brevo open event processed. event=' . $eventName .
            ' email=' . $email .
            ' invite_read=' . $readAt .
            ' affected=' . $update->rowCount()
        );
    }
} catch (Throwable $e) {
    error_log('brevo_webhook.php database error: ' . $e->getMessage());
    finishWebhook(500, 'Database update failed. Check server error log.');
}

finishWebhook(
    200,
    'OK. processed=' . $processed . ', matched=' . $matched . ', ignored=' . $ignored
);
