<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/teacher_crud.php';
require_once __DIR__.'/_configs.php';
requirePortalRole('teacher');

$teacherId=(int)($_SESSION['teacher_id']??0);
$schoolId=(int)($_SESSION['school_id']??0);
$config=teacherPortalConfig($pdo,'attendance',$teacherId,$schoolId);

try {
    teacherCrudHandle($pdo,$config,$teacherId,$schoolId);
} catch (Throwable $e) {
    error_log('Teacher attendance CRUD error: '.$e->getMessage());
    setFlash('danger',$e->getMessage());
    redirect($config['page']);
}

teacherCrudPage($pdo,$config,$teacherId,$schoolId);
