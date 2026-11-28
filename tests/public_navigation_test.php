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
$navigationCss = (string)file_get_contents($root.'/assets/css/mobile-split-navigation.css');
$footer = (string)file_get_contents($root.'/includes/footer.php');
$home = (string)file_get_contents($root.'/index.php');
if (str_contains($menu, 'class="navbar-toggler"') || str_contains($menu, 'data-bs-target="#mainNav"')) {
    $errors[] = 'Legacy public hamburger must not be rendered.';
}
foreach (['@media (min-width:1200px)', '@media (max-width:1199.98px)', '.portal-public-navbar #mainNav', '.portal-public-navbar .mobile-top-nav', '.portal-public-footer .mobile-bottom-nav'] as $rule) {
    if (!str_contains($navigationCss,$rule)) $errors[] = 'Missing responsive navigation rule: '.$rule;
}
if (!str_contains($footer, "portalLanguageSelector('portal-footer-language-select')") || !str_contains($menu,'public-desktop-language')) {
    $errors[] = 'Language selector must remain accessible in both navigation modes.';
}
if (!str_contains($home, '.hero-section::before') || !str_contains($home, 'opacity: 0.2;') || !str_contains($home, "url('/assets/images/hero.png')") || !is_file($root.'/assets/images/hero.png')) {
    $errors[] = 'Small-screen hero artwork and opacity are missing.';
}
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

// Explicit .php links work without Apache clean-URL rewrite rules.
if (!str_contains($apache, 'RewriteRule ^uploads/')
    || !str_contains($apache, 'RewriteRule ^(?:database|scripts)/')) {
    $errors[] = 'Apache security restrictions missing.';
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "Public navigation target checks passed.\n";
