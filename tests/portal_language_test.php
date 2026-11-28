<?php
declare(strict_types=1);

/**
 * PHP-only contract for the four-language selector, without database access.
 * Run: php tests/portal_language_test.php
 */
require_once dirname(__DIR__) . '/includes/portal_language.php';
require_once dirname(__DIR__) . '/includes/portal_layout.php';

function checkLanguage(bool $passed, string $message): void
{
    if (!$passed) {
        fwrite(STDERR, "FAILED: {$message}\n");
        exit(1);
    }
}

$_COOKIE['portal_lang'] = 'en';
checkLanguage(portalLanguage() === 'en', 'English cookie');
checkLanguage(portalT('Dashboard') === 'Dashboard', 'English fallback');
checkLanguage(portalLanguageHtmlTag() === 'en-MY', 'English HTML language');

$_COOKIE['portal_lang'] = 'ms';
checkLanguage(portalLanguage() === 'ms', 'Malay cookie');
checkLanguage(portalT('Dashboard') === 'Papan Pemuka', 'Malay translation');
checkLanguage(portalLanguageHtmlTag() === 'ms-MY', 'Malay HTML language');
$menu = portalLayoutMenu('school');
checkLanguage($menu['dashboard'][0] === 'Papan Pemuka', 'Role sidebar translation');
checkLanguage($menu['dashboard'][1] === '/school/', 'Role routes unchanged');
$_SERVER['REQUEST_URI'] = '/school/students.php?page=2';
$selector = portalLanguageSelector();
checkLanguage(str_contains($selector, 'value="ms" selected'), 'Selected language');
checkLanguage(str_contains($selector, 'data-return-url="/school/students.php?page=2"'), 'Return route');
checkLanguage(str_contains(portalLanguageAssets(), 'portal-language.js'), 'Language script');

$_COOKIE['portal_lang'] = 'zh';
checkLanguage(portalT('Schools') === '学校', 'Chinese translation');
checkLanguage(portalLanguageHtmlTag() === 'zh-Hans-MY', 'Chinese HTML language');

$_COOKIE['portal_lang'] = 'ta';
checkLanguage(portalT('Schools') === 'பள்ளிகள்', 'Tamil translation');
checkLanguage(portalLanguageHtmlTag() === 'ta-MY', 'Tamil HTML language');

$_COOKIE['portal_lang'] = 'unknown';
checkLanguage(portalLanguage() === 'en', 'Unknown language fallback');
$_COOKIE['portal_lang'] = ['ms'];
checkLanguage(portalLanguage() === 'en', 'Array cookie rejected');
$_SERVER['REQUEST_URI'] = '//evil.test/phishing';
checkLanguage(str_contains(portalLanguageSelector(), 'data-return-url="/index.php"'), 'No off-site redirect');

$header = (string) file_get_contents(dirname(__DIR__) . '/includes/header.php');
$jpn = (string) file_get_contents(dirname(__DIR__) . '/includes/jpn_layout.php');
checkLanguage(str_contains($header, "portalLanguage() !== 'en'"), 'Non-English HTML cache bypass');
checkLanguage(str_contains($header, 'portalLanguageSelector()'), 'Public selector');
checkLanguage(str_contains($jpn, 'portalLanguageSelector()'), 'JPN selector');


$programme = (string) file_get_contents(dirname(__DIR__) . '/about-programme.php');
$gallery = (string) file_get_contents(dirname(__DIR__) . '/includes/video_gallery.php');
$programmeCatalog = require dirname(__DIR__) . '/includes/portal_language_programme.php';
foreach (['ms', 'zh', 'ta'] as $locale) {
    $_COOKIE['portal_lang'] = $locale;
    foreach ($programmeCatalog as $english => $translations) {
        checkLanguage(trim(portalT($english)) !== '', "Missing translation: {$locale} / {$english}");
        checkLanguage(portalT($english) === $translations[array_search($locale, ['ms','zh','ta'], true)], "Wrong translation: {$locale} / {$english}");
    }
    foreach (['About the Programme', 'Programme at a Glance', 'School-based learning', 'Learning + competition', 'Programme Objectives', 'Frequently Asked Questions', 'Student & Teacher Programme Videos', 'Student submission', 'Watch on YouTube'] as $label) {
        checkLanguage(portalT($label) !== $label, "Untranslated programme UI: {$locale} / {$label}");
    }
}
checkLanguage(str_contains($programme, "portalT('Programme at a Glance')"), 'Programme hero rendered server-side');
checkLanguage(str_contains($programme, "portalT('About the Programme')"), 'Programme badge rendered server-side');
checkLanguage(str_contains($gallery, "portalT('Videos')"), 'Gallery rendered server-side');
checkLanguage(str_contains($gallery, 'data-language-no-translate'), 'Original video titles protected');

// Exercise actual rendered markup, not only dictionary lookups.
// The hero is a trusted repository template; no database or external services are needed.
require_once dirname(__DIR__) . '/includes/video_gallery.php';
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$heroStart = strpos($programme, '<section class="programme-hero');
$heroEnd = $heroStart === false ? false : strpos($programme, '</section>', $heroStart);
checkLanguage($heroStart !== false && $heroEnd !== false, 'Programme hero template located');
$heroTemplate = substr($programme, $heroStart, $heroEnd + strlen('</section>') - $heroStart);
$fixture = tempnam(sys_get_temp_dir(), 'portal-hero-');
checkLanguage($fixture !== false, 'Temporary hero test fixture');
try {
    checkLanguage(file_put_contents($fixture, $heroTemplate) !== false, 'Hero test fixture written');
    foreach (['en', 'ms', 'zh', 'ta'] as $locale) {
        $_COOKIE['portal_lang'] = $locale;
        ob_start();
        include $fixture;
        portalRenderVideoGallery([
            ['video_id' => 'dQw4w9WgXcQ', 'title' => 'Original video title', 'source' => 'Student submission'],
        ], 'Student & Teacher Programme Videos',
            'Random videos from teacher and student learning-module submissions across the programme.', 'test-videos');
        $rendered = (string) ob_get_clean();
        foreach ([
            'About the Programme',
            'STEM, TVET, AI, Simulation, Digital Innovation & Esports Talent Development Programme',
            'A school-based talent development programme designed to give students practical exposure to digital technology, artificial intelligence, innovation, simulation, STEM, TVET and electronic sports through structured learning, activities and competitions.',
            'Programme at a Glance',
            'School-based learning',
            'Technology exposure',
            'Learning + competition',
            'Student & Teacher Programme Videos',
            'Student submission',
            'Watch on YouTube',
        ] as $label) {
            checkLanguage(str_contains($rendered, e(portalT($label))), "Rendered text missing: {$locale} / {$label}");
            if ($locale !== 'en') {
                checkLanguage(!str_contains($rendered, e($label)), "English text leaked: {$locale} / {$label}");
            }
        }
        checkLanguage(str_contains($rendered, 'Original video title'), "Original video title changed: {$locale}");
    }
} finally {
    @unlink($fixture);
}

echo "Four-language selector, rendered programme content and role navigation checks passed.\n";
