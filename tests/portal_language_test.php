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

echo "Four-language selector and role navigation checks passed.\n";
