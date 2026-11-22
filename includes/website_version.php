<?php
declare(strict_types=1);

/**
 * Website version displayed in the public footer.
 * The root VERSION file contains MAJOR.MINOR.PATCH without a v prefix.
 */
function websiteVersion(): string
{
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $path = dirname(__DIR__) . '/VERSION';
    $text = is_readable($path) ? trim((string) file_get_contents($path)) : '';
    $version = preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $text) === 1
        ? $text
        : '0.0.0';

    return $version;
}
