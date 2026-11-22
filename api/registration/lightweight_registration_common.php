<?php
declare(strict_types=1);

function lightweightRegistrationJson(
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

function lightweightUpper(string $value): string
{
    return strtoupper(trim($value));
}

function lightweightNormalizeIdentity(string $value): string
{
    return strtoupper(
        preg_replace('/[^A-Za-z0-9]/', '', trim($value)) ?? ''
    );
}
