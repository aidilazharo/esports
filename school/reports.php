<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/school_crud.php';
require_once __DIR__.'/_configs.php';
requirePortalRole('school');

$schoolId=(int)($_SESSION['school_id']??0);
$config=schoolPortalConfig($pdo,'reports',$schoolId);

try {
    schoolCrudHandle($pdo,$config,$schoolId);
} catch (Throwable $e) {
    error_log('School reports CRUD error: '.$e->getMessage());
    setFlash('danger','Unable to save the report. Please try again.');
    redirect($config['page']);
}

schoolCrudPage($pdo,$config,$schoolId);
