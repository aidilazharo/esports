<?php
$pageTitle = $pageTitle ?? APP_NAME;
$currentPage = basename($_SERVER['PHP_SELF']);

require_once __DIR__ . '/school_auth.php';
require_once __DIR__ . '/mobile_navigation.php';

/*
 * Root/public pages are for guests only.
 *
 * Once a user is authenticated, always send the user to the dashboard for
 * their own role. Role portals have their own navigation, so this public
 * header does not expose a second "logged-in" menu.
 */
$jpnAuthFile = __DIR__ . '/jpn_auth.php';
if (is_file($jpnAuthFile)) {
    require_once $jpnAuthFile;
}

$isAdminPortal = !empty($_SESSION['admin_authenticated']);

$schoolPortalSession = $isAdminPortal
    ? null
    : schoolAccessSession();

$isSchoolPortal = $schoolPortalSession !== null;

$sessionPortalRole = strtolower(
    trim((string)($_SESSION['portal_role'] ?? ''))
);

if ($isAdminPortal) {
    $portalRole = 'admin';
} elseif (
    function_exists('jpnAuthIsAuthenticated')
    && jpnAuthIsAuthenticated()
) {
    $portalRole = 'jpn';
} elseif (!empty($_SESSION['jpn_authenticated'])) {
    $portalRole = 'jpn';
} elseif ($isSchoolPortal) {
    $portalRole = (string)(
        $schoolPortalSession['auth_type']
        ?? 'school'
    );
} elseif (
    in_array(
        $sessionPortalRole,
        ['parent', 'guardian'],
        true
    )
) {
    $portalRole = 'parent';
} else {
    $portalRole = 'guest';
}

$portalDestinations = [
    'admin' => '/admin/',
    'school' => '/school/',
    'teacher' => '/teacher/',
    'parent' => '/parent/',
    'jpn' => '/jpn/',
];

if (
    $portalRole !== 'guest'
    && isset($portalDestinations[$portalRole])
) {
    $portalDestination =
        $portalDestinations[$portalRole];

    $requestPath = parse_url(
        (string)($_SERVER['REQUEST_URI'] ?? '/'),
        PHP_URL_PATH
    );

    $requestPath = is_string($requestPath)
        ? $requestPath
        : '/';

    /*
     * Avoid a redirect loop if this shared header is ever loaded from
     * inside the correct role portal.
     */
    $destinationPrefix =
        rtrim($portalDestination, '/') . '/';

    if (
        $requestPath !== rtrim($portalDestination, '/')
        && !str_starts_with(
            $requestPath,
            $destinationPrefix
        )
    ) {
        if (!headers_sent()) {
            header(
                'Location: ' . $portalDestination,
                true,
                302
            );
            exit;
        }
    }
}

$schoolPortalId = $isSchoolPortal
    ? (int)$schoolPortalSession['school_id']
    : 0;

$schoolPortalCode = $isSchoolPortal
    ? (string)$schoolPortalSession['school_code']
    : '';

$schoolPortalName = $isSchoolPortal
    ? (string)$schoolPortalSession['school_name']
    : '';

$teacherPortalName = ($portalRole === 'teacher')
    ? (string)($schoolPortalSession['teacher_name'] ?? '')
    : '';

$teacherPortalEmail = ($portalRole === 'teacher')
    ? (string)($schoolPortalSession['teacher_email'] ?? '')
    : '';


/*
 * SEO + PERFORMANCE DEFAULTS
 *
 * Pages may override before including this header:
 * $seoTitle, $seoDescription, $seoImage, $seoRobots
 */
$portalRequestStartedAt = isset($_SERVER['REQUEST_TIME_FLOAT'])
    ? (float) $_SERVER['REQUEST_TIME_FLOAT']
    : microtime(true);

$portalSlowRequestThresholdSeconds = 2.5;

register_shutdown_function(
    static function () use (
        $portalRequestStartedAt,
        $portalSlowRequestThresholdSeconds,
        $currentPage
    ): void {
        $elapsed = microtime(true) - $portalRequestStartedAt;

        if ($elapsed < $portalSlowRequestThresholdSeconds) {
            return;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $entry = [
            'event' => 'slow_php_page',
            'page' => $currentPage,
            'path' => $path,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'duration_ms' => round($elapsed * 1000, 1),
            'status' => http_response_code(),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'timestamp_utc' => gmdate('c'),
        ];

        error_log(
            '[SLOW_PAGE_SERVER] '
            . json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }
);

$seoDefaultDescription =
    'Malaysia school programme for STEM, TVET, artificial intelligence (AI), '
    . 'simulation, digital innovation and esports education, connecting '
    . 'students, teachers and schools with future-ready learning opportunities.';

$seoDescriptions = [
    'index.php' =>
        'STEM, TVET, AI, simulation, digital innovation and esports education '
        . 'programme for Malaysian schools, students and teachers.',
    'about-programme.php' =>
        'Explore Malaysia\'s school STEM, TVET, AI, simulation, digital '
        . 'innovation and esports programme, learning pathways and student opportunities.',
    'schools.php' =>
        'Discover Malaysian schools participating in STEM, TVET, AI, digital '
        . 'innovation and esports education programmes.',
    'events-competitions.php' =>
        'Discover STEM, TVET, AI, digital innovation and esports events and '
        . 'competitions for schools and students in Malaysia.',
    'programmes.php' =>
        'Explore STEM, TVET, AI, simulation, innovation and esports learning '
        . 'programmes for Malaysian schools and students.',
    'programme.php' =>
        'Explore a school STEM, TVET, AI, innovation and esports learning '
        . 'programme for students in Malaysia.',
    'learning_modules.php' =>
        'Explore structured STEM, TVET, AI, digital innovation and esports '
        . 'learning modules for Malaysian students.',
    'school_profile.php' =>
        'View a participating school profile, student participation, teacher team, esports achievements and programme progress.',
    'teacher_profile.php' =>
        'View a school team teacher profile, learning-module progress, esports achievements and uploaded gameplay videos.',
    'student_profile.php' =>
        'View a student esports profile, completed learning modules, tournament participation, certificates and gameplay videos.',
    'tournaments.php' =>
        'School esports tournaments and structured digital competition '
        . 'opportunities for students in Malaysia.',
    'faq.php' =>
        'Frequently asked questions about the Malaysian school STEM, TVET, AI, '
        . 'digital innovation and esports education programme.',
];

$seoTitleOverrides = [
    'index.php' =>
        'STEM, TVET, AI & Esports Education Malaysia',
    'about-programme.php' =>
        'STEM, TVET, AI & Esports School Programme Malaysia',
    'schools.php' =>
        'STEM & Digital Education Schools Malaysia',
    'school_profile.php' =>
        'School Esports Profile',
    'teacher_profile.php' =>
        'Teacher Esports Profile',
    'student_profile.php' =>
        'Student Esports Profile',
    'events-competitions.php' =>
        'STEM, AI & Esports Events for Schools Malaysia',
];

$seoNoIndexPages = [
    'login.php',
    'logout.php',
    'admin.php',
    'dashboard.php',
    'payments.php',
    'student_payment.php',
    'events-competitions-payment.php',
    'events-competitions-payment-success.php',
    'attendance_scanner.php',
    'redeem_coupon.php',
    'school_portal.php',
    'edit_school.php',
    'register_school.php',
    'register_student.php',
    'register_teacher.php',
    'contact.php',
];

$seoRobots = $seoRobots ?? (
    in_array($currentPage, $seoNoIndexPages, true)
        ? 'noindex,nofollow,noarchive'
        : 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
);

$seoTitle = trim(
    (string) (
        $seoTitle
        ?? $seoTitleOverrides[$currentPage]
        ?? $pageTitle
    )
);

if ($seoTitle === '') {
    $seoTitle = APP_NAME;
}

$seoDescription = trim(
    (string) (
        $seoDescription
        ?? $seoDescriptions[$currentPage]
        ?? $seoDefaultDescription
    )
);

$seoScheme = 'http';
$forwardedProto = strtolower(
    trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))
);

if (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $forwardedProto === 'https'
) {
    $seoScheme = 'https';
}

$seoHost = preg_replace(
    '/[^A-Za-z0-9.\-:\[\]]/',
    '',
    (string) ($_SERVER['HTTP_HOST'] ?? '')
) ?? '';

if ($seoHost === '') {
    $seoHost = 'localhost';
}

$seoRequestPath = parse_url(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    PHP_URL_PATH
);

$seoRequestPath = is_string($seoRequestPath) && $seoRequestPath !== ''
    ? $seoRequestPath
    : '/';

$seoCanonicalUrl = $seoCanonicalUrl
    ?? ($seoScheme . '://' . $seoHost . $seoRequestPath);

$profileCanonicalParameters = [
    'school_profile.php' => 'school_id',
    'teacher_profile.php' => 'teacher_id',
    'student_profile.php' => 'student_id',
];

if (
    isset($profileCanonicalParameters[$currentPage])
) {
    $profileCanonicalParameter =
        $profileCanonicalParameters[$currentPage];

    $profileCanonicalId =
        $_GET[$profileCanonicalParameter]
        ?? null;

    if (
        !is_array($profileCanonicalId)
        && ctype_digit(
            (string)$profileCanonicalId
        )
        && (int)$profileCanonicalId > 0
    ) {
        $seoCanonicalUrl .=
            '?'
            . rawurlencode(
                $profileCanonicalParameter
            )
            . '='
            . rawurlencode(
                (string)(int)$profileCanonicalId
            );
    }
}

$seoSiteUrl = $seoScheme . '://' . $seoHost . '/';

$seoImagePath = trim(
    (string) (
        $seoImage
        ?? (
            is_file(dirname(__DIR__) . '/assets/images/social-share.jpg')
                ? 'assets/images/social-share.jpg'
                : 'assets/images/logo.svg'
        )
    )
);

$seoImageUrl = preg_match('/^https?:\/\//i', $seoImagePath)
    ? $seoImagePath
    : $seoScheme
        . '://'
        . $seoHost
        . '/'
        . ltrim($seoImagePath, '/');

$seoFullTitle = $seoTitle . ' | ' . APP_NAME;

$seoOrganizationSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    '@id' => $seoSiteUrl . '#organization',
    'name' => APP_NAME,
    'url' => $seoSiteUrl,
    'logo' => [
        '@type' => 'ImageObject',
        'url' => $seoScheme
            . '://'
            . $seoHost
            . '/assets/images/logo.svg',
    ],
    'description' => $seoDefaultDescription,
    'areaServed' => [
        '@type' => 'Country',
        'name' => 'Malaysia',
    ],
    'knowsAbout' => [
        'STEM education',
        'TVET education',
        'Artificial intelligence education',
        'Simulation learning',
        'Digital innovation',
        'Esports education',
        'School technology programmes',
    ],
];

$seoWebSiteSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    '@id' => $seoSiteUrl . '#website',
    'url' => $seoSiteUrl,
    'name' => APP_NAME,
    'publisher' => [
        '@id' => $seoSiteUrl . '#organization',
    ],
    'inLanguage' => 'en-MY',
];

$seoBreadcrumbSchema = null;

if ($currentPage !== 'index.php') {
    $seoBreadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $seoSiteUrl,
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $seoTitle,
                'item' => $seoCanonicalUrl,
            ],
        ],
    ];
}


/*
 * Static HTML cache for eligible public PHP pages.
 *
 * Behaviour:
 * - all generated files are stored in the project's /html folder;
 * - if html/page.html exists and is not older than 1 day, redirect the browser to it;
 * - if html/page.html is older than 1 day, render the PHP page and replace it;
 * - if html/page.html does not exist, render the PHP page and create it;
 * - generated HTML receives <base href="../"> so relative images, CSS, JS,
 *   forms and links continue to resolve correctly from inside /html.
 *
 * Safety rules:
 * - guest/public requests only;
 * - GET requests only;
 * - no query string for normal public pages;
 * - school_profile.php, teacher_profile.php and student_profile.php are allowed
 *   one positive integer profile ID query parameter and receive a unique HTML file;
 * - no flash message;
 * - excludes login, registration, payment, edit/admin and other stateful pages.
 *
 * A page can opt out before including the header with:
 *     $disableStaticHtmlGeneration = true;
 */
function portalStartStaticHtmlGeneration(string $currentPage, string $portalRole): void
{
    global $disableStaticHtmlGeneration;

    if (!empty($disableStaticHtmlGeneration)) {
        return;
    }

    if ($portalRole !== 'guest') {
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $queryString = trim(
        (string)($_SERVER['QUERY_STRING'] ?? '')
    );

    /*
     * Profile pages are public and dynamic by ID, but can still be safely
     * cached as separate static HTML files when the request contains only
     * the expected positive integer identifier.
     *
     * Examples:
     *   school_profile.php?school_id=123
     *     -> /html/school_profile_123.html
     *
     *   teacher_profile.php?teacher_id=45
     *     -> /html/teacher_profile_45.html
     *
     *   student_profile.php?student_id=678
     *     -> /html/student_profile_678.html
     */
    $profileStaticPages = [
        'school_profile.php' => 'school_id',
        'teacher_profile.php' => 'teacher_id',
        'student_profile.php' => 'student_id',
    ];

    $profileStaticId = null;

    if (isset($profileStaticPages[$currentPage])) {
        $profileIdParameter =
            $profileStaticPages[$currentPage];

        $queryParameters = [];

        if ($queryString !== '') {
            parse_str(
                $queryString,
                $queryParameters
            );
        }

        if (
            count($queryParameters) !== 1
            || !array_key_exists(
                $profileIdParameter,
                $queryParameters
            )
        ) {
            return;
        }

        $rawProfileId =
            $queryParameters[$profileIdParameter];

        if (
            is_array($rawProfileId)
            || !ctype_digit((string)$rawProfileId)
            || (int)$rawProfileId < 1
        ) {
            return;
        }

        $profileStaticId =
            (int)$rawProfileId;
    } elseif ($queryString !== '') {
        return;
    }

    if (!empty($_SESSION['flash'])) {
        return;
    }

    if (!str_ends_with(strtolower($currentPage), '.php')) {
        return;
    }

    $blockedPages = [
        'login.php',
        'logout.php',
        'admin.php',
        'dashboard.php',
        'payments.php',
        'student_payment.php',
        'events-competitions-payment.php',
        'events-competitions-payment-success.php',
        'register_school.php',
        'register_student.php',
        'register_teacher.php',
        'edit_school.php',
        'school_portal.php',
        'attendance_scanner.php',
        'redeem_coupon.php',
        'contact.php',
    ];

    if (in_array($currentPage, $blockedPages, true)) {
        return;
    }

    $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($scriptFile === '' || !is_file($scriptFile)) {
        return;
    }

    $directory = dirname($scriptFile);
    $baseName = pathinfo(
        $scriptFile,
        PATHINFO_FILENAME
    );

    $staticBaseName =
        $profileStaticId !== null
            ? $baseName . '_' . $profileStaticId
            : $baseName;

    // Store all generated static pages in a dedicated /html folder
    // beside the public PHP pages, e.g.:
    //     /schools.php
    //     /html/schools.html
    $htmlDirectory = $directory . DIRECTORY_SEPARATOR . 'html';
    $htmlPath = $htmlDirectory . DIRECTORY_SEPARATOR . $staticBaseName . '.html';

    $cacheLifetimeSeconds = 86400; // 1 day
    $htmlExists = is_file($htmlPath) && is_readable($htmlPath);
    $htmlModifiedAt = $htmlExists ? @filemtime($htmlPath) : false;
    $htmlAgeSeconds = $htmlModifiedAt !== false ? max(0, time() - $htmlModifiedAt) : PHP_INT_MAX;
    $htmlIsFresh = $htmlExists && $htmlAgeSeconds < $cacheLifetimeSeconds;

    // Fresh HTML cache: redirect the browser to the static file in /html.
    if ($htmlIsFresh) {
        if (!headers_sent()) {
            $staticUrl = 'html/' . rawurlencode($staticBaseName) . '.html';

            header('X-Static-HTML: REDIRECT-HIT');
            header('X-Static-HTML-Age: ' . (string) $htmlAgeSeconds);
            header(
                'X-Static-HTML-File: '
                . $staticBaseName
                . '.html'
            );
            header('Location: ' . $staticUrl, true, 302);
            exit;
        }

        return;
    }

    // Missing or stale cache: render PHP and create/replace the HTML file.
    if (!is_dir($htmlDirectory)) {
        if (!@mkdir($htmlDirectory, 0755, true) && !is_dir($htmlDirectory)) {
            return;
        }
    }

    if (!is_writable($htmlDirectory)) {
        return;
    }

    ob_start(static function (string $buffer) use ($htmlPath, $htmlDirectory): string {
        $status = http_response_code();

        if ($buffer === '' || $status < 200 || $status >= 300) {
            return $buffer;
        }

        /*
         * The static file lives one directory deeper (/html), while the PHP
         * application and assets live in the parent directory. Add a base URL
         * only to the cached copy so existing relative href/src/action/fetch
         * URLs continue to work without changing the live PHP response.
         */
        $staticBuffer = $buffer;

        if (stripos($staticBuffer, '<base ') === false) {
            $staticBuffer = preg_replace(
                '/<head([^>]*)>/i',
                '<head$1>' . "\n" . '    <base href="../">',
                $staticBuffer,
                1
            ) ?? $staticBuffer;
        }

        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $tempPath = $htmlDirectory . DIRECTORY_SEPARATOR . '.html-' . $suffix . '.tmp';

        if (@file_put_contents($tempPath, $staticBuffer, LOCK_EX) !== false) {
            /*
             * rename() is used so visitors never receive a partially written file.
             * On the same filesystem this replaces the old cache atomically.
             */
            if (!@rename($tempPath, $htmlPath)) {
                // Fallback for hosts/filesystems that do not replace an existing file.
                @unlink($htmlPath);
                @rename($tempPath, $htmlPath);
            }
        }

        if (is_file($tempPath)) {
            @unlink($tempPath);
        }

        return $buffer;
    });

    if (!headers_sent()) {
        header($htmlExists ? 'X-Static-HTML: STALE-REGENERATE' : 'X-Static-HTML: MISS-GENERATE');
    }
}

portalStartStaticHtmlGeneration($currentPage, $portalRole);

$schoolPages = ['schools.php','edit_school.php','register_school.php','school_report.php','download.php','school_portal.php'];
$memberPages = ['memberships.php','student_payment.php','student_qr.php','register_student.php','register_teacher.php'];
$programmePages = ['attendance.php','attendance_scanner.php','learning_modules.php','tournaments.php','certificates.php','certificate_portal.php','programmes.php','programme.php'];
$eventsCompetitionPages = ['events-competitions.php','events-competitions-payment.php','events-competitions-payment-success.php','events-competitions-report.php'];

// Public mobile navigation: primary links in header; secondary links in footer.
// Preserve the existing desktop Programme dropdown and routes.
$mobilePublicPrimary = [
    ['Home', '/index.php', 'bi-house-door', $currentPage === 'index.php'],
    ['Programme', '/about-programme.php', 'bi-journal-richtext', $currentPage === 'about-programme.php'],
    ['Events & Competitions', '/events-competitions.php', 'bi-calendar-event', in_array($currentPage, $eventsCompetitionPages, true)],
    ['Login', '/login.php', 'bi-person-circle', $currentPage === 'login.php'],
];
$mobilePublicSecondary = [
    ['Register School', '/register_school.php', 'bi-building-add', $currentPage === 'register_school.php'],
    ['Register Student', '/register_student.php', 'bi-person-plus', $currentPage === 'register_student.php'],
    ['Register Teacher', '/register_teacher.php', 'bi-person-badge', $currentPage === 'register_teacher.php'],
    ['Manuals', '/manuals.php', 'bi-book', $currentPage === 'manuals.php'],
    ['Contact Us', '/contact.php', 'bi-envelope', $currentPage === 'contact.php'],
];

$adminPages = ['dashboard.php','payments.php','admin.php','programmes.php','certificates.php','events-competitions-report.php'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= e($seoFullTitle) ?></title>
    <meta name="description" content="<?= e($seoDescription) ?>">
    <meta name="robots" content="<?= e($seoRobots) ?>">
    <meta name="googlebot" content="<?= e($seoRobots) ?>">
    <meta name="author" content="Metadata Berhad">
    <meta name="language" content="English">
    <meta name="geo.region" content="MY">
    <meta name="theme-color" content="#0d6efd">

    <link rel="canonical" href="<?= e($seoCanonicalUrl) ?>">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_MY">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($seoFullTitle) ?>">
    <meta property="og:description" content="<?= e($seoDescription) ?>">
    <meta property="og:url" content="<?= e($seoCanonicalUrl) ?>">
    <meta property="og:image" content="<?= e($seoImageUrl) ?>">
    <meta property="og:image:alt" content="<?= e(APP_NAME) ?> - STEM, TVET, AI and esports education programme in Malaysia">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seoFullTitle) ?>">
    <meta name="twitter:description" content="<?= e($seoDescription) ?>">
    <meta name="twitter:image" content="<?= e($seoImageUrl) ?>">

    <script type="application/ld+json"><?= json_encode(
        $seoOrganizationSchema,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?></script>

    <script type="application/ld+json"><?= json_encode(
        $seoWebSiteSchema,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?></script>

    <?php if ($seoBreadcrumbSchema !== null): ?>
        <script type="application/ld+json"><?= json_encode(
            $seoBreadcrumbSchema,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>

    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">

    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/mobile-icon-links.css?v=2" rel="stylesheet">
    <link href="assets/css/mobile-split-navigation.css?v=1" rel="stylesheet">

    <style>
        .portal-navbar-brand{display:inline-flex;align-items:center;min-height:52px;padding-top:.25rem;padding-bottom:.25rem}
        .portal-navbar-logo{display:block;width:auto;height:44px;max-width:180px;object-fit:contain}
        .navbar .dropdown-item.active,.navbar .dropdown-item:active{color:var(--bs-dropdown-link-active-color);background-color:var(--bs-dropdown-link-active-bg)}

        #portalPageLoadProgress{
            position:fixed;
            top:0;
            left:0;
            width:0;
            height:3px;
            z-index:2000;
            opacity:0;
            pointer-events:none;
            background:currentColor;
            color:#ffc107;
            box-shadow:0 0 8px rgba(255,193,7,.65);
            transition:width .25s ease,opacity .2s ease;
        }
        #portalPageLoadProgress.is-visible{opacity:1}
        #portalPageLoadProgress.is-complete{
            width:100%!important;
            opacity:0;
            transition:width .15s ease,opacity .35s ease .15s;
        }

        @media(max-width:575.98px){
            .portal-navbar-brand{min-height:46px}
            .portal-navbar-logo{height:36px;max-width:145px}
        }

        @media(prefers-reduced-motion:reduce){
            #portalPageLoadProgress{transition:none}
        }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<div
    id="portalPageLoadProgress"
    role="progressbar"
    aria-label="Page loading"
    aria-valuemin="0"
    aria-valuemax="100"
    aria-valuenow="0"
></div>

<script>
(() => {
    'use strict';

    const bar = document.getElementById('portalPageLoadProgress');
    if (!bar) return;

    let progress = 4;
    let finished = false;
    let progressTimer = null;

    const setProgress = (value) => {
        progress = Math.max(progress, Math.min(95, value));
        bar.style.width = progress + '%';
        bar.setAttribute('aria-valuenow', String(Math.round(progress)));
    };

    const revealTimer = window.setTimeout(() => {
        if (finished) return;

        bar.classList.add('is-visible');
        setProgress(18);

        progressTimer = window.setInterval(() => {
            if (finished) return;

            if (progress < 55) {
                setProgress(progress + 8);
            } else if (progress < 78) {
                setProgress(progress + 4);
            } else if (progress < 92) {
                setProgress(progress + 1.5);
            }
        }, 450);
    }, 400);

    const reportSlowLoad = () => {
        const navigation = performance.getEntriesByType('navigation')[0];

        const duration = navigation
            ? navigation.duration
            : performance.now();

        const ttfb = navigation
            ? navigation.responseStart
            : 0;

        const domContentLoaded = navigation
            ? navigation.domContentLoadedEventEnd
            : 0;

        if (duration < 2500 && ttfb < 1500) {
            return;
        }

        const payload = JSON.stringify({
            path: window.location.pathname,
            duration_ms: Math.round(duration),
            ttfb_ms: Math.round(ttfb),
            dom_content_loaded_ms: Math.round(domContentLoaded),
            transfer_size: navigation
                ? Number(navigation.transferSize || 0)
                : 0,
            encoded_body_size: navigation
                ? Number(navigation.encodedBodySize || 0)
                : 0,
        });

        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(
                    'api/performance/slow_page_log.php',
                    new Blob([payload], {type:'application/json'})
                );
                return;
            }
        } catch (_) {}

        fetch(
            'api/performance/slow_page_log.php',
            {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:payload,
                keepalive:true,
                credentials:'same-origin'
            }
        ).catch(() => {});
    };

    window.addEventListener(
        'load',
        () => {
            finished = true;
            window.clearTimeout(revealTimer);

            if (progressTimer) {
                window.clearInterval(progressTimer);
            }

            setProgress(100);
            bar.classList.add('is-complete');

            window.setTimeout(() => {
                bar.style.display = 'none';
            }, 600);

            window.setTimeout(reportSlowLoad, 0);
        },
        {once:true}
    );

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            finished = true;
            bar.classList.add('is-complete');
        }
    });
})();
</script>
<nav class="navbar navbar-expand-xl navbar-dark bg-primary shadow-sm sticky-top">
<div class="container">
    <a class="navbar-brand portal-navbar-brand" href="/index.php" aria-label="<?= e(APP_NAME) ?> home">
        <img src="assets/images/logo.svg" alt="Metadata Berhad" class="portal-navbar-logo" width="180" height="44">
        <span class="visually-hidden"><?= e(APP_NAME) ?></span>
    </a>
    <?= mobileNavigationBar($mobilePublicPrimary, 'Primary mobile navigation', 'top') ?>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>

    <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav ms-auto align-items-xl-center">
            <li class="nav-item"><a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="/index.php">Home</a></li>

            <!--
                This header is only rendered for guests.
                Authenticated users are redirected to their own role portal
                before any navigation is output.
            -->
            <li class="nav-item dropdown">
                <a
                    class="nav-link dropdown-toggle <?= (
                        in_array(
                            $currentPage,
                            $eventsCompetitionPages,
                            true
                        )
                        || $currentPage === 'about-programme.php'
                        || in_array(
                            $currentPage,
                            [
                                'register_school.php',
                                'register_student.php',
                                'register_teacher.php',
                            ],
                            true
                        )
                    ) ? 'active' : '' ?>"
                    href="#"
                    role="button"
                    data-bs-toggle="dropdown"
                >
                    Programme
                </a>

                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a
                            class="dropdown-item"
                            href="/about-programme.php"
                        >
                            <i class="bi bi-info-circle me-2"></i>
                            About Programme
                        </a>
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="/events-competitions.php"
                        >
                            <i class="bi bi-calendar-event me-2"></i>
                            Events &amp; Competitions
                        </a>
                    </li>

                    <li>
                        <hr class="dropdown-divider">
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="/register_school.php"
                        >
                            <i class="bi bi-building-add me-2"></i>
                            Register School
                        </a>
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="/register_student.php"
                        >
                            <i class="bi bi-person-plus me-2"></i>
                            Register Student
                        </a>
                    </li>

                    <li>
                        <a
                            class="dropdown-item"
                            href="/register_teacher.php"
                        >
                            <i class="bi bi-person-badge me-2"></i>
                            Register Teacher
                        </a>
                    </li>

                    <li>
                        <hr class="dropdown-divider">
                    </li>

                    <li>
                        <a
                            class="dropdown-item <?= $currentPage === 'manuals.php' ? 'active' : '' ?>"
                            href="/manuals.php"
                        >
                            <i class="bi bi-book me-2"></i>
                            Manuals
                        </a>
                    </li>

                    <li>
                        <a
                            class="dropdown-item <?= $currentPage === 'contact.php' ? 'active' : '' ?>"
                            href="/contact.php"
                        >
                            <i class="bi bi-envelope me-2"></i>
                            Contact Us
                        </a>
                    </li>
                </ul>
            </li>

            <li class="nav-item ms-xl-2">
                <a
                    class="nav-link px-2 <?= $currentPage === 'login.php' ? 'active' : '' ?>"
                    href="/login.php"
                    title="Login"
                    aria-label="Login"
                >
                    <i class="bi bi-person-circle fs-5"></i>
                    <span class="visually-hidden">Login</span>
                </a>
            </li>
        </ul>
    </div>
</div>
</nav>

<main class="container py-4 flex-grow-1">
<?php if (!empty($_SESSION['flash'])): $flash=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
