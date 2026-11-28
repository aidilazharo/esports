<?php
declare(strict_types=1);
require_once __DIR__ . '/mobile_navigation.php';
require_once __DIR__ . '/portal_language.php';

function jpnPageStart(
    string $activeKey,
    string $title,
    array $official,
    string $scopeLabel
): void {
    $e = static fn($v) => htmlspecialchars(
        (string)$v,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $title = portalT($title);
    $menu = [
        'dashboard' => [
            'Dashboard',
            '/jpn/',
            'bi-speedometer2',
        ],
        'report' => [
            'Monthly / Yearly Report',
            '/jpn/report.php',
            'bi-file-earmark-bar-graph',
        ],
    ];

    foreach ($menu as &$entry) {
        $entry[0] = portalT($entry[0]);
    }
    unset($entry);

    echo '<!doctype html><html lang="'.$e(portalLanguageHtmlTag()).'"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow,noarchive">';
    echo '<title>'.$e($title).' | JPN Portal</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">';
    echo '<link href="/assets/css/mobile-icon-links.css?v=2" rel="stylesheet">';
    echo '<link href="/assets/css/mobile-split-navigation.css?v=2" rel="stylesheet">';
    echo portalLanguageAssets();
    echo '<style>
        body{background:#f5f7fb;color:#1f2937}
        .jpn-sidebar{min-height:calc(100vh - 58px)}
        .jpn-sidebar .nav-link{color:#374151;display:flex;gap:.65rem;align-items:center;padding:.7rem .8rem}
        .jpn-sidebar .nav-link.active{color:#fff;background:#0d6efd}
        .stat-card{border:0;box-shadow:0 .125rem .5rem rgba(0,0,0,.06)}
        .card{border-radius:.8rem}
        .report-table th,.report-table td{font-size:.82rem;vertical-align:middle}
        @media print{
            .no-print,.jpn-sidebar,.navbar{display:none!important}
            main{width:100%!important;padding:0!important}
            body{background:#fff}
            .card{box-shadow:none!important;border:0!important}
        }
        @media(max-width:767.98px){.jpn-sidebar{min-height:auto}}
    </style>';
    echo '<link href="/assets/css/portal-design.css?v=2" rel="stylesheet"></head><body class="portal-shell">';

    [$mobileTopItems, $mobileBottomItems] = mobileNavigationRoleItems(
        $menu, ['dashboard', 'report'], $activeKey
    );
    $mobileBottomItems[] = [portalT('Logout'), '/login.php?logout=1', 'bi-box-arrow-right', false];
    $GLOBALS['jpnMobileFooterNav'] = mobileNavigationBar(
        $mobileBottomItems, 'Secondary JPN navigation', 'bottom'
    );
    echo '<nav class="navbar navbar-dark bg-primary jpn-role-navbar">';
    echo '<div class="container-fluid">';
    echo '<a class="navbar-brand fw-semibold" href="/jpn/"><span class="d-none d-md-inline">Metadata eSports · JPN Portal</span><span class="d-inline d-md-none">Metadata</span></a>';
    echo mobileNavigationBar($mobileTopItems, 'Primary JPN navigation', 'top');
    echo portalLanguageSelector();
    echo '<div class="d-flex align-items-center gap-2 text-white">';
    echo '<div class="small d-none d-lg-block text-end">';
    echo '<div class="fw-semibold">'.$e($official['name'] ?? 'JPN Official').'</div>';
    echo '<div class="opacity-75">'.$e($scopeLabel).'</div>';
    echo '</div>';
    echo '<a class="btn btn-sm btn-outline-light d-none d-md-inline-flex" href="/login.php?logout=1">';
    echo '<i class="bi bi-box-arrow-right me-1"></i>'.$e(portalT('Logout')).'</a>';
    echo '</div></div></nav>';

    echo '<div class="container-fluid"><div class="row">';
    echo '<aside class="col-lg-2 col-md-3 bg-white border-end jpn-sidebar p-3">';
    echo '<div class="nav nav-pills flex-column gap-1">';

    foreach ($menu as $key => $item) {
        [$label, $href, $icon] = $item;

        echo '<a class="nav-link'
            . ($key === $activeKey ? ' active' : '')
            . '" href="'.$e($href).'">';
        echo '<i class="bi '.$e($icon).'"></i>';
        echo '<span>'.$e($label).'</span></a>';
    }

    echo '</div></aside>';
    echo '<main class="col-lg-10 col-md-9 p-3 p-lg-4">';
    echo '<div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-4">';
    echo '<div><h1 class="h3 mb-1">'.$e($title).'</h1>';
    echo '<div class="text-secondary small">'.$e(portalT('JPN Education Department Portal')).' · '.$e($scopeLabel).'</div></div>';
    echo '</div>';
}

function jpnPageEnd(): void
{
    echo '</main></div></div>';
    echo '<footer class="mobile-navigation-footer">'.($GLOBALS['jpnMobileFooterNav'] ?? '').'</footer>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script src="/assets/js/mobile-icon-links.js?v=2"></script></body></html>';
}
