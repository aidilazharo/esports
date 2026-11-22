<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';

if (!portalAuthIsAdminAuthenticated()) {
    redirect('/login.php?role=admin');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/');
}

try {
    verifyCsrf();
    portalAuthEndAdminTest();
    setFlash('success', 'Admin Test Mode ended. You are back in the Admin portal.');
} catch (Throwable $e) {
    error_log('Stop Admin Test Mode error: ' . $e->getMessage());
    setFlash('danger', 'Unable to end Admin Test Mode.');
}

redirect('/admin/');
