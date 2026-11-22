<?php
declare(strict_types=1);

/**
 * Invalidate only generated html/ pages when a newly deployed VERSION is seen.
 * This is a first-request fallback for Hostinger deployment methods that
 * do not execute server-side Git hooks.
 */
function esportsEmptyGeneratedHtml(string $root): bool
{
    $root = realpath($root);
    if ($root === false) {
        error_log('HTML cache reset: application directory unavailable.');
        return false;
    }

    $html = $root . DIRECTORY_SEPARATOR . 'html';

    if (is_link($html)) {
        error_log('HTML cache reset refused: html/ is a symlink.');
        return false;
    }

    if (!is_dir($html) && !@mkdir($html, 0755, true) && !is_dir($html)) {
        error_log('HTML cache reset: unable to create html/ directory.');
        return false;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($html, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $deleted = $entry->isLink() || !$entry->isDir()
                ? @unlink($path)
                : @rmdir($path);
            if (!$deleted) {
                error_log('HTML cache reset: failed to remove generated file.');
                return false;
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log('HTML cache reset failed: ' . $e->getMessage());
        return false;
    }
}

function esportsInvalidateHtmlCacheForVersion(string $root): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $versionFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
    if (!is_readable($versionFile)) {
        return;
    }

    $version = trim((string) @file_get_contents($versionFile));
    if (preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+$/D', $version) !== 1) {
        return;
    }

    // State lives outside html/ and uses a per-installation identifier.
    $stateFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'esports-html-'
        . hash('sha256', (string) (realpath($root) ?: $root))
        . '.version';

    if (is_file($stateFile) && trim((string) @file_get_contents($stateFile)) === $version) {
        return;
    }

    $handle = @fopen($stateFile, 'c+');
    if ($handle === false) {
        error_log('HTML cache reset: unable to open version marker.');
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        rewind($handle);
        $previousVersion = trim((string) stream_get_contents($handle));

        if ($previousVersion !== $version && esportsEmptyGeneratedHtml($root)) {
            rewind($handle);
            if (ftruncate($handle, 0) && fwrite($handle, $version) === strlen($version)) {
                fflush($handle);
            } else {
                error_log('HTML cache reset: failed to write version marker.');
            }
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
