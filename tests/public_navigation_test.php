<?php
declare(strict_types=1);

/**
 * Static public navigation contract. No database, session, or web server needed.
 * Run with: php tests/public_navigation_test.php
 */
$root = dirname(__DIR__);
$menu = file_get_contents($root . '/includes/header.php');
$apache = file_get_contents($root . '/.htaccess');

if ($menu === false || $apache === false) {
    fwrite(STDERR, "Cannot read public navigation source or routing configuration.\n");
    exit(1);
}

$required = [
    'index.php',
    'about-programme.php',
    'events-competitions.php',
    'register_school.php',
    'register_student.php',
    'register_teacher.php',
    'manuals.php',
    'contact.php',
    'login.php',
];

$errors = [];
foreach ($required as $name) {
    if (!is_file($root . '/' . $name)) {
        $errors[] = "Missing navigation target: {$name}";
    }
    if (!str_contains($menu, "href=\"/{$name}\"")) {
        $errors[] = "Desktop navigation does not link to /{$name}";
    }
    if (!str_contains($menu, "'/{$name}'")) {
        $errors[] = "Mobile navigation does not link to /{$name}";
    }
}

// Static links on these public pages must point to actual files or fragments.
// PHP dynamic href expressions are deliberately skipped.
$pages = [
    'includes/header.php',
    'manuals.php',
    'about-programme.php',
    'contact.php',
    'register_school.php',
];

foreach ($pages as $page) {
    $source = file_get_contents($root . '/' . $page);
    if ($source === false) {
        $errors[] = "Cannot read {$page}";
        continue;
    }

    preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/i', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $url = trim($match[2]);
        if ($url === '' || str_starts_with($url, '#')
            || str_starts_with($url, '?') || str_starts_with($url, '//')
            || preg_match('~^(?:https?:|mailto:|tel:|javascript:|data:)~i', $url)
            || str_contains($url, '<') || str_contains($url, '$')
            || $url === '../' || $url === '/') {
            continue;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            continue;
        }
        if (str_contains($path, '..')) {
            $errors[] = "Unsafe local URL in {$page}: {$url}";
            continue;
        }
        $target = $root . '/' . ltrim($path, '/');
        if (!is_file($target) && !is_dir($target)) {
            $errors[] = "Broken local href in {$page}: {$url}";
        }
    }
}

if (!str_contains($apache, 'RewriteCond %{REQUEST_FILENAME}.php -f')
    || !str_contains($apache, 'RewriteRule ^([A-Za-z0-9_-]+)$ $1.php')) {
    $errors[] = 'Apache clean-URL compatibility rule missing.';
}
if (!str_contains($apache, 'RewriteRule ^uploads/')
    || !str_contains($apache, 'RewriteRule ^(?:database|scripts)/')) {
    $errors[] = 'Apache security restrictions missing.';
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "Public navigation and clean-URL compatibility checks passed.\n";
