<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('school');
$schoolId=(int)($_SESSION['school_id']??0);

function schoolDashScalar(PDO $pdo,string $sql,array $params=[]): int|float {
    try{$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchColumn()?:0;}catch(Throwable $e){return 0;}
}
$counts=[
'teachers'=>(int)schoolDashScalar($pdo,'SELECT COUNT(*) FROM teachers WHERE school_id=?',[$schoolId]),
'students'=>(int)schoolDashScalar($pdo,'SELECT COUNT(*) FROM students WHERE school_id=?',[$schoolId]),
'active'=>(int)schoolDashScalar($pdo,"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",[$schoolId]),
'parents'=>(int)schoolDashScalar($pdo,'SELECT COUNT(DISTINCT sg.guardian_id) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE st.school_id=?',[$schoolId]),
'attendance'=>(int)schoolDashScalar($pdo,"SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? AND a.attendance_month=DATE_FORMAT(CURDATE(),'%Y-%m-01')",[$schoolId]),
'modules'=>(int)schoolDashScalar($pdo,"SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=? AND p.progress_status='completed'",[$schoolId]),
];
$ps5=$counts['active']<10?0:1+intdiv($counts['active']-10,30);$ws=intdiv(max(0,$counts['active']),100);
$q=$pdo->prepare('SELECT id,student_number,full_name,class_name,membership_status,membership_next_due_at,created_at FROM students WHERE school_id=? ORDER BY created_at DESC LIMIT 100');$q->execute([$schoolId]);$rows=$q->fetchAll();

portalPageStart('school','dashboard','Dashboard');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3">
<?php foreach([
['Teachers',$counts['teachers'],'bi-person-badge','/school/teachers.php'],
['Students',$counts['students'],'bi-people','/school/students.php'],
['Active Students',$counts['active'],'bi-person-check','/school/students.php'],
['Parents',$counts['parents'],'bi-person-hearts','/school/parents.php'],
['Attendance This Month',$counts['attendance'],'bi-clock-history','/school/attendance.php'],
['Completed Modules',$counts['modules'],'bi-journal-check','/school/learning_modules.php'],
['Eligible PS5',$ps5,'bi-controller','/school/equipment.php'],
['Eligible Workstations',$ws,'bi-pc-display','/school/equipment.php'],
] as [$l,$v,$i,$href]):?><div class="col-sm-6 col-xl-3"><a class="text-decoration-none" href="<?=e($href)?>"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h2 mb-0 text-dark"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></a></div><?php endforeach;?>
</div>

<div class="card stat-card mt-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Recent Students</h2></div><div class="card-body"><div class="table-responsive">
<table id="schoolRecentStudents" class="table table-hover align-middle w-100"><thead><tr><th>Student No.</th><th>Student</th><th>Class</th><th>Membership</th><th>Expiry</th><th>Registered</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['student_number'])?></td><td><?=e((string)$r['full_name'])?></td><td><?=e((string)$r['class_name'])?></td><td><?=e(ucwords(str_replace('_',' ',(string)$r['membership_status'])))?></td><td><?=e($r['membership_next_due_at']?date('d M Y',strtotime((string)$r['membership_next_due_at'])):'-')?></td><td><?=e(date('d M Y',strtotime((string)$r['created_at'])))?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/school/students.php?edit=<?=(int)$r['id']?>"><i class="bi bi-pencil-square"></i></a></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery&&jQuery.fn.DataTable)$('#schoolRecentStudents').DataTable({pageLength:5,lengthChange:false,searching:true,ordering:true,info:true,order:[[5,'desc']],columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No students registered.'}});});</script>
<?php portalPageEnd(); ?>
