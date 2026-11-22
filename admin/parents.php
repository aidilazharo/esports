<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';
require_once __DIR__ . '/../includes/admin_crud.php';
require_once __DIR__ . '/_configs.php';
requirePortalRole('admin');

$config = adminCrudConfig($pdo, 'parents');
try {
    adminCrudHandle($pdo, $config);
} catch (Throwable $e) {
    error_log('Admin parents CRUD error: ' . $e->getMessage());
    setFlash('danger', $e->getMessage());
    redirect($config['page']);
}

adminCrudPage($pdo, $config);
