<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('admin');

function dashboardCount(PDO $pdo, string $sql): int
{
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { error_log($e->getMessage()); return 0; }
}

$counts = [
    'schools'=>dashboardCount($pdo,'SELECT COUNT(*) FROM schools'),
    'teachers'=>dashboardCount($pdo,'SELECT COUNT(*) FROM teachers'),
    'students'=>dashboardCount($pdo,'SELECT COUNT(*) FROM students'),
    'parents'=>dashboardCount($pdo,'SELECT COUNT(*) FROM guardians'),
    'active_students'=>dashboardCount($pdo,"SELECT COUNT(*) FROM students WHERE membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()"),
    'events'=>dashboardCount($pdo,"SELECT COUNT(*) FROM programme_events WHERE event_type='programme' AND status='open'"),
    'competitions'=>dashboardCount($pdo,"SELECT COUNT(*) FROM programme_events WHERE event_type='tournament' AND status='open'"),
    'coupons'=>dashboardCount($pdo,"SELECT COUNT(*) FROM coupons WHERE is_active=1"),
];

$recentStudents = [];
try {
    $recentStudents = $pdo->query(
        "SELECT st.id,st.student_number,st.full_name,st.membership_status,st.created_at,s.school_code,s.school_name
         FROM students st JOIN schools s ON s.id=st.school_id
         ORDER BY st.created_at DESC LIMIT 100"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Admin dashboard recent students: '.$e->getMessage());
}

portalPageStart('admin','dashboard','Dashboard');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">

<div class="row g-3">
<?php
$cards = [
    ['Schools',$counts['schools'],'bi-building','/admin/schools.php'],
    ['Teachers',$counts['teachers'],'bi-person-badge','/admin/teachers.php'],
    ['Students',$counts['students'],'bi-people','/admin/students.php'],
    ['Parents',$counts['parents'],'bi-person-hearts','/admin/parents.php'],
    ['Active Students',$counts['active_students'],'bi-person-check','/admin/students.php'],
    ['Open Events',$counts['events'],'bi-calendar-event','/admin/events.php'],
    ['Open Competitions',$counts['competitions'],'bi-trophy','/admin/competitions.php'],
    ['Active Coupons',$counts['coupons'],'bi-ticket-perforated','/admin/coupons.php'],
];
foreach($cards as [$label,$value,$icon,$href]):?>
<div class="col-sm-6 col-xl-3">
    <a class="text-decoration-none" href="<?=e($href)?>">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div><div class="text-secondary small"><?=e($label)?></div><div class="h2 text-dark mb-0 fw-semibold"><?=number_format($value)?></div></div>
                <i class="bi <?=e($icon)?> fs-2 text-primary"></i>
            </div>
        </div>
    </a>
</div>
<?php endforeach;?>
</div>

<div class="card stat-card mt-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="h5 mb-1">Test Role Views</h2>
                <p class="text-secondary mb-0">Test a School, Teacher or Parent portal without their 6-digit code.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-warning" href="/admin/test_roles.php?role=school"><i class="bi bi-building me-1"></i>School</a>
                <a class="btn btn-warning" href="/admin/test_roles.php?role=teacher"><i class="bi bi-person-badge me-1"></i>Teacher</a>
                <a class="btn btn-warning" href="/admin/test_roles.php?role=parent"><i class="bi bi-person-hearts me-1"></i>Parent</a>
            </div>
        </div>
    </div>
</div>

<div class="card stat-card mt-4">
    <div class="card-header bg-white py-3"><h2 class="h5 mb-0">Recent Students</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="recentStudentsTable" class="table table-hover align-middle w-100">
                <thead><tr><th>Student No.</th><th>Student</th><th>School</th><th>Membership</th><th>Registered</th><th></th></tr></thead>
                <tbody>
                <?php foreach($recentStudents as $row):?>
                    <tr>
                        <td><?=e((string)$row['student_number'])?></td>
                        <td><?=e((string)$row['full_name'])?></td>
                        <td><?=e((string)$row['school_code'])?> — <?=e((string)$row['school_name'])?></td>
                        <td><?=adminDashboardBadge((string)$row['membership_status'])?></td>
                        <td><?=e(date('d M Y',strtotime((string)$row['created_at'])))?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/students.php?edit=<?=(int)$row['id']?>"><i class="bi bi-pencil-square"></i></a></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
function adminDashboardBadge(string $status): string
{
    $class = $status === 'active' ? 'success' : (in_array($status,['pending_payment','pending_activation'],true) ? 'warning' : 'secondary');
    return '<span class="badge text-bg-'.e($class).'">'.e(ucwords(str_replace('_',' ',$status))).'</span>';
}
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    if (window.jQuery && jQuery.fn.DataTable) {
        $('#recentStudentsTable').DataTable({
            pageLength: 5,
            lengthChange: false,
            searching: true,
            ordering: true,
            info: true,
            order: [[4,'desc']],
            columnDefs: [{orderable:false,searchable:false,targets:-1}],
            language:{emptyTable:'No students registered.'}
        });
    }
});
</script>
<?php portalPageEnd(); ?>
