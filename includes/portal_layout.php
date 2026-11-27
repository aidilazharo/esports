<?php
require_once __DIR__ . '/mobile_navigation.php';
require_once __DIR__ . '/portal_language.php';
function portalLayoutMenu(string $role): array
{
    $menu = match ($role) {
        'admin' => [
            'dashboard'=>['Dashboard','/admin/','bi-speedometer2'],
            'test_roles'=>['Test Role Views','/admin/test_roles.php','bi-eye'],
            'schools'=>['Schools','/admin/schools.php','bi-building'],
            'psn_accounts'=>['PSN Accounts','/admin/psn_accounts.php','bi-shield-lock'],
            'teachers'=>['Teachers','/admin/teachers.php','bi-person-badge'],
            'students'=>['Students','/admin/students.php','bi-people'],
            'parents'=>['Parents','/admin/parents.php','bi-person-hearts'],
            'payments'=>['Payments','/admin/payments.php','bi-credit-card'],
            'events'=>['Events','/admin/events.php','bi-calendar-event'],
            'competitions'=>['Competitions','/admin/competitions.php','bi-trophy'],
            'learning_modules'=>['Learning Modules','/admin/learning_modules.php','bi-journal-text'],
            'certificates'=>['Certificates','/admin/certificates.php','bi-award'],
            'attendance'=>['Attendance','/admin/attendance.php','bi-clock-history'],
            'coupons'=>['Coupons','/admin/coupons.php','bi-ticket-perforated'],
            'reports'=>['Reports','/admin/reports.php','bi-bar-chart-line'],
            'settings'=>['Settings','/admin/settings.php','bi-gear'],
        ],
        'school' => [
            'dashboard'=>['Dashboard','/school/','bi-speedometer2'],
            'profile'=>['Profile','/school/profile.php','bi-building'],
            'teachers'=>['Teachers','/school/teachers.php','bi-person-badge'],
            'students'=>['Students','/school/students.php','bi-people'],
            'parents'=>['Parents','/school/parents.php','bi-person-hearts'],
            'equipment'=>['Equipment','/school/equipment.php','bi-tools'],
            'psn_accounts'=>['PSN Accounts','/school/psn_accounts.php','bi-shield-lock'],
            'attendance'=>['Attendance','/school/attendance.php','bi-clock-history'],
            'learning_modules'=>['Learning Modules','/school/learning_modules.php','bi-journal-text'],
            'certificates'=>['Certificates','/school/certificates.php','bi-award'],
            'events'=>['Events','/school/events.php','bi-calendar-event'],
            'reports'=>['Reports','/school/reports.php','bi-bar-chart-line'],
        ],
        'teacher' => [
            'dashboard'=>['Dashboard','/teacher/','bi-speedometer2'],
            'profile'=>['Profile','/teacher/profile.php','bi-person-badge'],
            'students'=>['Students','/teacher/students.php','bi-people'],
            'equipment'=>['Equipment','/teacher/equipment.php','bi-tools'],
            'attendance'=>['Attendance','/teacher/attendance.php','bi-clock-history'],
            'learning_modules'=>['Learning Modules','/teacher/learning_modules.php','bi-journal-text'],
            'certificates'=>['Certificates','/teacher/certificates.php','bi-award'],
            'events'=>['Events','/teacher/events.php','bi-calendar-event'],
            'competitions'=>['Competitions','/teacher/competitions.php','bi-trophy'],
        ],
        'parent' => [
            'dashboard'=>['Dashboard','/parent/','bi-speedometer2'],
            'profile'=>['Profile','/parent/profile.php','bi-person'],
            'children'=>['Children','/parent/children.php','bi-people'],
            'membership'=>['Membership','/parent/membership.php','bi-card-checklist'],
            'payments'=>['Payments','/parent/payments.php','bi-credit-card'],
            'certificates'=>['Certificates','/parent/certificates.php','bi-award'],
            'attendance'=>['Attendance','/parent/attendance.php','bi-clock-history'],
            'learning_progress'=>['Learning Progress','/parent/learning_progress.php','bi-graph-up'],
            'events'=>['Events','/parent/events.php','bi-calendar-event'],
        ],
        default => [],
    };
    foreach ($menu as &$item) {
        $item[0] = portalT((string) $item[0]);
    }
    unset($item);
    return $menu;
}

function portalLayoutTitleForRole(string $role): string
{
    return portalT(match ($role) {'admin'=>'Administration','school'=>'School Portal','teacher'=>'Teacher Portal','parent'=>'Parent Portal',default=>'Portal'});
}

function portalPageStart(string $role, string $activeKey, string $title): void
{
    $menu = portalLayoutMenu($role);
    $title = portalT($title);
    $portalTitle = portalLayoutTitleForRole($role);
    $displayName = match ($role) {
        'school'=>(string)($_SESSION['school_name'] ?? $_SESSION['school_code'] ?? 'School'),
        'teacher'=>(string)($_SESSION['teacher_name'] ?? 'Teacher'),
        'parent'=>(string)($_SESSION['parent_name'] ?? 'Parent'),
        default=>'Administrator'
    };
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="'.$e(portalLanguageHtmlTag()).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.$e($title).' | '.$e($portalTitle).'</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="/assets/css/mobile-icon-links.css?v=2" rel="stylesheet"><link href="/assets/css/mobile-split-navigation.css?v=2" rel="stylesheet">';
    echo portalLanguageAssets();
    echo '<style>
        body{background:#f5f7fb;color:#1f2937}
        .portal-sidebar{min-height:calc(100vh - 58px)}
        .nav-pills .nav-link{color:#374151;display:flex;align-items:center;gap:.65rem;padding:.65rem .75rem}
        .nav-pills .nav-link i{width:1.1rem;text-align:center}
        .nav-pills .nav-link.active{color:#fff}
        .stat-card{border:0;box-shadow:0 .125rem .5rem rgba(0,0,0,.06)}
        .card{border-radius:.8rem}
        @media (max-width:767.98px){.portal-sidebar{min-height:auto}}
    </style></head><body>';
    // Keep all current role links; mobile only moves their presentation.
    $mobilePrimaryKeys = match ($role) {
        'admin' => ['dashboard', 'schools', 'students', 'events'],
        'school' => ['dashboard', 'students', 'teachers', 'learning_modules'],
        'teacher' => ['dashboard', 'students', 'learning_modules', 'attendance'],
        'parent' => ['dashboard', 'children', 'payments', 'certificates'],
        default => ['dashboard'],
    };
    [$mobileTopItems, $mobileBottomItems] = mobileNavigationRoleItems($menu, $mobilePrimaryKeys, $activeKey);
    // Logout is a secondary action on mobile, not squeezed into the header.
    $mobileBottomItems[] = [portalT('Logout'), '/login.php?logout=1', 'bi-box-arrow-right', false];
    $isAdminTest = portalAuthIsAdminTestMode($role);
    $testLabel = (string)($_SESSION['admin_test_label'] ?? '');
    echo '<nav class="navbar navbar-dark bg-primary portal-role-navbar"><div class="container-fluid"><a class="navbar-brand fw-semibold" href="'.portalAuthDashboardForRole($role).'">'
        .'<span class="d-none d-md-inline">Metadata eSports · '.$e($portalTitle).'</span>'
        .'<span class="d-inline d-md-none">Metadata</span></a>'
        .mobileNavigationBar($mobileTopItems, 'Primary role navigation', 'top')
        .portalLanguageSelector()
        .'<div class="d-flex align-items-center gap-2 text-white"><span class="small d-none d-md-inline">'.$e($displayName).'</span>';
    if ($isAdminTest) {
        echo '<form method="post" action="/admin/stop_test.php" class="m-0 mobile-header-test-return"><input type="hidden" name="csrf_token" value="'.$e(csrfToken()).'"><button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-arrow-return-left me-1"></i>'.$e(portalT('Return to Admin')).'</button></form>';
    }
    echo '<a class="btn btn-sm btn-outline-light d-none d-md-inline-flex" href="/login.php?logout=1"><i class="bi bi-box-arrow-right me-1"></i>'.$e(portalT('Logout')).'</a></div></div></nav>';
    if ($isAdminTest) {
        echo '<div class="alert alert-warning rounded-0 border-0 border-bottom mb-0 py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">';
        echo '<div><strong><i class="bi bi-shield-exclamation me-1"></i>ADMIN TEST MODE</strong> &mdash; Viewing the '.$e(ucfirst($role)).' portal as <strong>'.$e($testLabel).'</strong>. No email login code was required.</div>';
        echo '<form method="post" action="/admin/stop_test.php" class="m-0"><input type="hidden" name="csrf_token" value="'.$e(csrfToken()).'"><button class="btn btn-sm btn-dark" type="submit">'.$e(portalT('Return to Admin')).'</button></form>';
        echo '</div>';
    }
    $mobileExtraControls = '';
    if ($isAdminTest) {
        $mobileExtraControls = '<form method="post" action="/admin/stop_test.php" class="mobile-nav-extra-form">'
            .'<input type="hidden" name="csrf_token" value="'.$e(csrfToken()).'">'
            .'<button type="submit" class="mobile-nav-action" title="Return to Admin" aria-label="Return to Admin">'
            .'<i class="bi bi-arrow-return-left" aria-hidden="true"></i><span class="visually-hidden">Return to Admin</span></button></form>';
    }
    $GLOBALS['portalMobileFooterNav'] = mobileNavigationBar(
        $mobileBottomItems, 'Secondary role navigation', 'bottom', $mobileExtraControls
    );
    echo '<div class="container-fluid"><div class="row"><aside class="col-lg-2 col-md-3 bg-white border-end portal-sidebar p-3"><div class="nav nav-pills flex-column gap-1">';
    foreach ($menu as $key => $item) {
        $label=(string)($item[0]??'');
        $href=(string)($item[1]??'#');
        $icon=(string)($item[2]??'bi-circle');
        echo '<a class="nav-link'.($key===$activeKey?' active':'').'" href="'.$e($href).'"><i class="bi '.$e($icon).'"></i><span>'.$e($label).'</span></a>';
    }
    echo '</div></aside><main class="col-lg-10 col-md-9 p-4"><div class="mb-4"><h1 class="h3 mb-1">'.$e($title).'</h1><div class="text-secondary small">'.$e($portalTitle).'</div></div>';
    if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        $flash=$_SESSION['flash']; unset($_SESSION['flash']);
        $type=preg_replace('/[^a-z]/','',(string)($flash['type']??'info')) ?: 'info';
        echo '<div class="alert alert-'.$e($type).'">'.$e($flash['message']??'').'</div>';
    }
}

function portalPageEnd(): void
{
    echo '</main></div></div>';
    echo '<footer class="mobile-navigation-footer">'.($GLOBALS['portalMobileFooterNav'] ?? '').'</footer>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/assets/js/mobile-icon-links.js?v=2"></script></body></html>';
}

function portalPlaceholder(string $message, ?string $link=null, ?string $label=null): void
{
    $e=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    echo '<div class="card stat-card"><div class="card-body p-4"><p class="mb-3">'.$e($message).'</p>';
    if ($link!==null && $label!==null) echo '<a class="btn btn-primary" href="'.$e($link).'">'.$e($label).'</a>';
    echo '</div></div>';
}
