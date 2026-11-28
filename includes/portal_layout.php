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

function portalLayoutGroups(string $role): array
{
    return match($role) {
        'school'=>['Overview'=>['dashboard'],'People'=>['students','teachers','parents'],'Programme'=>['attendance','learning_modules','certificates'],'Events'=>['events'],'Equipment'=>['equipment','psn_accounts'],'Reports'=>['reports'],'Profile'=>['profile']],
        'admin'=>['Overview'=>['dashboard'],'People'=>['schools','teachers','students','parents'],'Programme'=>['attendance','learning_modules','certificates'],'Events'=>['events','competitions'],'Equipment'=>['psn_accounts'],'Finance'=>['payments','coupons'],'Reports'=>['reports'],'Settings'=>['settings','test_roles']],
        'teacher'=>['Overview'=>['dashboard'],'People'=>['students'],'Programme'=>['attendance','learning_modules','certificates'],'Events'=>['events','competitions'],'Equipment'=>['equipment'],'Profile'=>['profile']],
        'parent'=>['Overview'=>['dashboard'],'People'=>['children'],'Programme'=>['attendance','learning_progress','certificates'],'Events'=>['events'],'Finance'=>['membership','payments'],'Profile'=>['profile']],
        default=>[],
    };
}

function portalSchoolMobileNavigation(array $menu,string $activeKey): string {
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $groups=['Home'=>['bi-house',['dashboard']],'People'=>['bi-people',['students','teachers','parents']],'Programme'=>['bi-journal-text',['attendance','learning_modules','certificates']],'Equipment'=>['bi-tools',['equipment','psn_accounts']],'More'=>['bi-three-dots',['events','reports','profile']]];
    $html='<nav class="portal-school-bottom" aria-label="'.$e(portalT('Primary navigation')).'" data-mobile-icon-links="off">';
    foreach($groups as $label=>[$icon,$keys]){
        $active=in_array($activeKey,$keys,true);
        $content='<i class="bi '.$e($icon).'" aria-hidden="true"></i><span>'.$e(portalT($label)).'</span>';
        if($label==='Home'){
            $html.='<a class="school-bottom-item'.($active?' active':'').'" href="'.$e($menu['dashboard'][1]).'"'.($active?' aria-current="page"':'').'>'.$content.'</a>';
            continue;
        }
        $html.='<details class="school-bottom-group"><summary class="school-bottom-item'.($active?' active':'').'">'.$content.'</summary><div class="school-bottom-panel">';
        foreach($keys as $key){
            if(!isset($menu[$key]))continue;
            [$name,$href,$itemIcon]=$menu[$key];
            $html.='<a href="'.$e($href).'"'.($key===$activeKey?' aria-current="page" class="active"':'').'><i class="bi '.$e($itemIcon).'" aria-hidden="true"></i> '.$e($name).'</a>';
        }
        if($label==='More')$html.='<a href="/login.php?logout=1">'.$e(portalT('Logout')).'</a>';
        $html.='</div></details>';
    }
    return $html.'</nav>';
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
    echo '<link href="/assets/css/portal-design.css?v=2" rel="stylesheet"></head><body class="portal-shell'.($role==='school'?' portal-school-shell':'').'">';
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
        .'<span class="d-inline d-md-none">'.$e($role==='school'?$displayName:'Metadata').'</span></a>'
        .($role==='school'?'':mobileNavigationBar($mobileTopItems, 'Primary role navigation', 'top'))
        .($role==='school'?'':portalLanguageSelector())
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
    $GLOBALS['portalMobileFooterNav'] = $role==='school' ? portalSchoolMobileNavigation($menu,$activeKey) : mobileNavigationBar(
        $mobileBottomItems, 'Secondary role navigation', 'bottom', $mobileExtraControls
    );
    echo '<div class="container-fluid"><div class="row"><aside class="col-lg-2 col-md-3 bg-white border-end portal-sidebar p-3"><div class="nav nav-pills flex-column gap-1">';
    foreach (portalLayoutGroups($role) as $group=>$keys) {
        echo '<div class="portal-nav-heading">'.$e(portalT($group)).'</div>';
        foreach ($keys as $key) {
        if (!isset($menu[$key])) continue;
        $item=$menu[$key];
        $label=(string)($item[0]??'');
        $href=(string)($item[1]??'#');
        $icon=(string)($item[2]??'bi-circle');
        echo '<a class="nav-link'.($key===$activeKey?' active':'').'" href="'.$e($href).'"'.($key===$activeKey?' aria-current="page"':'').'><i class="bi '.$e($icon).'" aria-hidden="true"></i><span>'.$e($label).'</span></a>';
    }
    }
    echo '</div></aside><main class="col-lg-10 col-md-9 p-3 p-lg-4" id="portal-main"><div class="mb-3"><h1 class="h4 mb-0">'.$e($title).'</h1></div>';
    if ($role==='school' && $activeKey==='profile') echo '<section class="card stat-card mb-3"><div class="card-body"><h2 class="h6">'.$e(portalT('Language')).'</h2>'.portalLanguageSelector().'</div></section>';
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
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="/assets/js/mobile-icon-links.js?v=2"></script><script src="/assets/js/portal-navigation.js?v=1"></script></body></html>';
}

function portalPlaceholder(string $message, ?string $link=null, ?string $label=null): void
{
    $e=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    echo '<div class="card stat-card"><div class="card-body p-4"><p class="mb-3">'.$e($message).'</p>';
    if ($link!==null && $label!==null) echo '<a class="btn btn-primary" href="'.$e($link).'">'.$e($label).'</a>';
    echo '</div></div>';
}
