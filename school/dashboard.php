<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/equipment_entitlements.php';
requirePortalRole('school');
$schoolId=(int)($_SESSION['school_id']??0);

function schoolDashScalar(PDO $pdo,string $sql,array $params=[]): ?int {
    try {
        $q=$pdo->prepare($sql);
        $q->execute($params);
        $value=$q->fetchColumn();
        return is_numeric($value) ? (int)$value : null;
    } catch (Throwable $e) {
        error_log('School dashboard count failed: '.$e->getMessage());
        return null;
    }
}
$counts=[
'teachers'=>schoolDashScalar($pdo,'SELECT COUNT(*) FROM teachers WHERE school_id=?',[$schoolId]),
'students'=>schoolDashScalar($pdo,'SELECT COUNT(*) FROM students WHERE school_id=?',[$schoolId]),
'active'=>schoolDashScalar($pdo,"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",[$schoolId]),
'parents'=>schoolDashScalar($pdo,'SELECT COUNT(DISTINCT sg.guardian_id) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE st.school_id=?',[$schoolId]),
'attendance'=>schoolDashScalar($pdo,"SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? AND a.attendance_month=DATE_FORMAT(CURDATE(),'%Y-%m-01')",[$schoolId]),
'modules'=>schoolDashScalar($pdo,"SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=? AND p.progress_status='completed'",[$schoolId]),
];
$eligible = null;
$ps5 = null;
$next = null;
try {
    $eligible = equipmentCurrentYearActiveStudents($pdo, $schoolId);
    $ps5 = equipmentEntitlementTotals($eligible)['ps5'];
    $next = equipmentNextMilestones($eligible, 1)[0];
} catch (Throwable $e) {
    error_log('School dashboard equipment count failed: '.$e->getMessage());
}
$rows = [];
$rowsUnavailable = false;
try {
    $q=$pdo->prepare("SELECT id,student_number,full_name,class_name,
        CASE WHEN membership_status='active' AND membership_next_due_at < NOW() THEN 'expired'
             WHEN membership_status='active' AND membership_next_due_at IS NULL THEN 'inactive'
             ELSE membership_status END AS membership_status,
        membership_next_due_at,created_at FROM students WHERE school_id=? ORDER BY created_at DESC LIMIT 100");
    $q->execute([$schoolId]);
    $rows=$q->fetchAll();
} catch (Throwable $e) {
    error_log('School dashboard recent students failed: '.$e->getMessage());
    $rowsUnavailable = true;
}

portalPageStart('school','dashboard','Dashboard');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h2 class="h5 mb-0"><?=e((string)date('Y'))?> · <?=e(portalT('Programme'))?></h2>
    <div class="d-flex flex-wrap gap-2" data-mobile-icon-links="off">
        <a class="btn btn-primary btn-sm" href="/school/students.php?add=1"><i class="bi bi-person-plus" aria-hidden="true"></i> <?=e(portalT('Add Student'))?></a>
        <a class="btn btn-outline-primary btn-sm" href="/school/attendance.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> <?=e(portalT('Attendance'))?></a>
        <a class="btn btn-outline-primary btn-sm" href="/school/events.php"><?=e(portalT('Events'))?></a>
    </div>
</div>
<div class="row g-3">
<?php foreach([
['Teachers',$counts['teachers'],'bi-person-badge','/school/teachers.php'],
['Students',$counts['students'],'bi-people','/school/students.php'],
['Active Students',$counts['active'],'bi-person-check','/school/students.php'],
['Parents',$counts['parents'],'bi-person-hearts','/school/parents.php'],
['Attendance This Month',$counts['attendance'],'bi-clock-history','/school/attendance.php'],
['Completed Modules',$counts['modules'],'bi-journal-check','/school/learning_modules.php'],
['Eligible PS5 ('.date('Y').')',$ps5,'bi-controller','/school/equipment.php'],
['Eligible students ('.date('Y').')',$eligible,'bi-person-check','/school/equipment.php'],
] as [$l,$v,$i,$href]):?><div class="col-sm-6 col-xl-3"><a class="text-decoration-none" href="<?=e($href)?>"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e(portalT($l))?></div><div class="h3 mb-0 text-dark"><?=$v===null?'<span class="small text-secondary">'.e(portalT('Unavailable')).'</span>':number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></a></div><?php endforeach;?>
</div>

<?php if ($next !== null): ?>
<div class="card stat-card mt-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <h2 class="h6"><?=e(portalT('Equipment'))?> · <?=e((string)date('Y'))?></h2>
        <a href="/school/equipment.php"><?=e(portalT('View Equipment'))?></a>
    </div>
    <p class="mb-2"><?=number_format($eligible)?> / <?=number_format($next['threshold'])?> · <?=e(portalT($next['label']))?></p>
    <div class="progress" role="progressbar" aria-label="<?=e(portalT('Equipment'))?>" aria-valuenow="<?=$eligible?>" aria-valuemin="0" aria-valuemax="<?=$next['threshold']?>"><div class="progress-bar" style="width:<?=round(100*$eligible/$next['threshold'],2)?>%"></div></div>
    <p class="small text-secondary mt-2 mb-0"><?=number_format($next['students_needed'])?> <?=e(portalT('Students needed for next milestone'))?></p>
</div></div>
<?php endif; ?>
<div class="card stat-card mt-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=e(portalT('Recent Students'))?></h2></div><div class="card-body">
<?php if ($rowsUnavailable): ?><div class="alert alert-warning" role="alert"><?=e(portalT('Student records are temporarily unavailable.'))?></div><?php endif; ?>
<div class="table-responsive">
<table id="schoolRecentStudents" class="table table-hover align-middle w-100"><thead><tr><th>Student No.</th><th>Student</th><th>Class</th><th>Membership</th><th>Expiry</th><th>Registered</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['student_number'])?></td><td><?=e((string)$r['full_name'])?></td><td><?=e((string)$r['class_name'])?></td><td><?=e(ucwords(str_replace('_',' ',(string)$r['membership_status'])))?></td><td><?=e($r['membership_next_due_at']?date('d M Y',strtotime((string)$r['membership_next_due_at'])):'-')?></td><td><?=e(date('d M Y',strtotime((string)$r['created_at'])))?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/school/students.php?edit=<?=(int)$r['id']?>" aria-label="<?=e(portalT('Edit Student'))?>"><i class="bi bi-pencil-square"></i></a></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery&&jQuery.fn.DataTable)$('#schoolRecentStudents').DataTable({pageLength:5,lengthChange:false,searching:true,ordering:true,info:true,order:[[5,'desc']],columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No students registered.'}});});</script>
<?php portalPageEnd(); ?>
