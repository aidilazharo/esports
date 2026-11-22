<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/role_auth.php';
require_once __DIR__ . '/../includes/portal_layout.php';
requirePortalRole('admin');

$allowedRoles = ['school', 'teacher', 'parent'];
$selectedRole = strtolower(trim((string)($_GET['role'] ?? $_POST['role'] ?? 'school')));
if (!in_array($selectedRole, $allowedRoles, true)) {
    $selectedRole = 'school';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'start_test') {
            $role = strtolower(trim((string)($_POST['role'] ?? '')));
            $targetId = (int)($_POST['target_id'] ?? 0);

            $result = portalAuthStartAdminTest($pdo, $role, $targetId);
            if (!empty($result['success'])) {
                redirect((string)$result['redirect']);
            }

            $error = (string)($result['message'] ?? 'Unable to start Admin Test Mode.');
        }

        if ($action === 'stop_test') {
            portalAuthEndAdminTest();
            setFlash('success', 'Admin Test Mode ended.');
            redirect('/admin/test_roles.php');
        }
    } catch (Throwable $e) {
        error_log('Admin test role page error: ' . $e->getMessage());
        $error = 'Unable to process the Admin Test Mode request.';
    }
}

$rows = [];
try {
    if ($selectedRole === 'school') {
        $stmt = $pdo->query(
            "SELECT id, school_code, school_name, state, district
             FROM schools
             ORDER BY school_name
             LIMIT 5000"
        );
        $rows = $stmt->fetchAll();
    }

    if ($selectedRole === 'teacher') {
        $stmt = $pdo->query(
            "SELECT t.id, t.full_name, t.email, t.position, s.school_name, s.school_code
             FROM teachers t
             JOIN schools s ON s.id=t.school_id
             ORDER BY t.full_name
             LIMIT 5000"
        );
        $rows = $stmt->fetchAll();
    }

    if ($selectedRole === 'parent') {
        $stmt = $pdo->query(
            "SELECT g.id, g.guardian_number, g.full_name, g.email, g.phone,
                    COUNT(DISTINCT sg.student_id) AS child_count
             FROM guardians g
             LEFT JOIN student_guardians sg ON sg.guardian_id=g.id
             GROUP BY g.id, g.guardian_number, g.full_name, g.email, g.phone
             ORDER BY g.full_name
             LIMIT 5000"
        );
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('Admin role test listing error: ' . $e->getMessage());
    $error = 'Unable to load the selected role records.';
}

portalPageStart('admin', 'test_roles', 'Test Role Views');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card stat-card mb-4">
    <div class="card-body">
        <h2 class="h5">Admin Test / Impersonation Mode</h2>
        <p class="text-secondary mb-3">
            Select a School, Teacher or Parent record to see exactly what that user sees.
            No 6-digit email code is required. The real administrator session stays active in the background.
        </p>
        <div class="alert alert-info mb-0">
            Test sessions expire after 1 hour and do not change the selected user's login code or access-code records.
        </div>
    </div>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $selectedRole === 'school' ? 'active' : '' ?>" href="/admin/test_roles.php?role=school">School</a></li>
    <li class="nav-item"><a class="nav-link <?= $selectedRole === 'teacher' ? 'active' : '' ?>" href="/admin/test_roles.php?role=teacher">Teacher</a></li>
    <li class="nav-item"><a class="nav-link <?= $selectedRole === 'parent' ? 'active' : '' ?>" href="/admin/test_roles.php?role=parent">Parent</a></li>
</ul>

<div class="card stat-card">
    <div class="card-body">


        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="testRolesTable">
                <?php if ($selectedRole === 'school'): ?>
                    <thead><tr><th>School</th><th>State / District</th><th style="width:130px">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string)$row['school_name']) ?></strong><br><span class="small text-secondary"><?= e((string)$row['school_code']) ?></span></td>
                            <td><?= e((string)($row['state'] ?? '')) ?><br><span class="small text-secondary"><?= e((string)($row['district'] ?? '')) ?></span></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="start_test">
                                    <input type="hidden" name="role" value="school">
                                    <input type="hidden" name="target_id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-eye me-1"></i>Test View</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>

                <?php elseif ($selectedRole === 'teacher'): ?>
                    <thead><tr><th>Teacher</th><th>School</th><th>Position</th><th style="width:130px">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string)$row['full_name']) ?></strong><br><span class="small text-secondary"><?= e((string)($row['email'] ?? '')) ?></span></td>
                            <td><?= e((string)$row['school_name']) ?><br><span class="small text-secondary"><?= e((string)$row['school_code']) ?></span></td>
                            <td><?= e((string)($row['position'] ?? '')) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="start_test">
                                    <input type="hidden" name="role" value="teacher">
                                    <input type="hidden" name="target_id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-eye me-1"></i>Test View</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>

                <?php else: ?>
                    <thead><tr><th>Parent / Guardian</th><th>Contact</th><th>Children</th><th style="width:130px">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string)$row['full_name']) ?></strong><br><span class="small text-secondary"><?= e((string)($row['guardian_number'] ?? '')) ?></span></td>
                            <td><?= e((string)($row['email'] ?? '')) ?><br><span class="small text-secondary"><?= e((string)($row['phone'] ?? '')) ?></span></td>
                            <td><?= number_format((int)($row['child_count'] ?? 0)) ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="start_test">
                                    <input type="hidden" name="role" value="parent">
                                    <input type="hidden" name="target_id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-eye me-1"></i>Test View</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php if (!$rows): ?>
            <div class="text-center text-secondary py-4">No matching records found.</div>
        <?php elseif (count($rows) >= 5000): ?>
            <div class="small text-secondary mt-3">
                Showing up to 5,000 records. Use the DataTables search box to narrow the list.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-warning d-none mt-3" id="dataTablesLoadWarning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    DataTables could not be loaded. Check CDN access or browser console.
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
(function ($) {
    'use strict';

    $(function () {
        var tableSelector = '#testRolesTable';
        var table = $(tableSelector);

        if (!table.length || !table.find('tbody tr').length) {
            return;
        }

        if (!$.fn || !$.fn.DataTable) {
            console.error('DataTables library failed to load.');
            $('#dataTablesLoadWarning').removeClass('d-none');
            return;
        }

        if ($.fn.DataTable.isDataTable(tableSelector)) {
            table.DataTable().destroy();
        }

        table.DataTable({
            pageLength: 5,
            lengthChange: false,
            paging: true,
            pagingType: 'full_numbers',
            searching: true,
            ordering: true,
            info: true,
            autoWidth: false,
            order: [],
            columnDefs: [
                {
                    targets: -1,
                    orderable: false,
                    searchable: false
                }
            ],
            language: {
                search: 'Search:',
                searchPlaceholder: 'Name, code, email, school or phone',
                info: 'Showing _START_ to _END_ of _TOTAL_ records',
                infoEmpty: 'No records available',
                zeroRecords: 'No matching records found',
                paginate: {
                    first: 'First',
                    previous: 'Previous',
                    next: 'Next',
                    last: 'Last'
                }
            }
        });
    });
})(jQuery);
</script>

<?php portalPageEnd(); ?>
