<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('teacher');

$teacherId=(int)($_SESSION['teacher_id']??0);
$schoolId=(int)($_SESSION['school_id']??0);

function teacherDashScalar(PDO $pdo,string $sql,array $params=[]): int|float {
    try{$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchColumn()?:0;}catch(Throwable $e){return 0;}
}

$counts=[
'students'=>(int)teacherDashScalar($pdo,'SELECT COUNT(*) FROM students WHERE school_id=?',[$schoolId]),
'active'=>(int)teacherDashScalar($pdo,"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",[$schoolId]),
'attendance'=>(int)teacherDashScalar($pdo,"SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? AND a.attendance_month=DATE_FORMAT(CURDATE(),'%Y-%m-01')",[$schoolId]),
'completed_modules'=>(int)teacherDashScalar($pdo,"SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=? AND p.progress_status='completed'",[$schoolId]),
'certificates'=>(int)teacherDashScalar($pdo,"SELECT COUNT(*) FROM issued_certificates ic LEFT JOIN students st ON st.id=ic.student_id WHERE st.school_id=? OR ic.teacher_id=?",[$schoolId,$teacherId]),
'events'=>(int)teacherDashScalar($pdo,"SELECT COUNT(*) FROM teacher_event_applications WHERE teacher_id=? AND event_type='programme'",[$teacherId]),
'competitions'=>(int)teacherDashScalar($pdo,"SELECT COUNT(*) FROM teacher_event_applications WHERE teacher_id=? AND event_type='tournament'",[$teacherId]),
];

$q=$pdo->prepare("SELECT st.id,st.student_number,st.full_name,st.class_name,st.membership_status,st.membership_next_due_at,st.created_at
                  FROM students st WHERE st.school_id=? ORDER BY st.created_at DESC LIMIT 100");
$q->execute([$schoolId]);$rows=$q->fetchAll();

portalPageStart('teacher','dashboard','Dashboard');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">

<div class="row g-3">
<?php foreach([
['Students',$counts['students'],'bi-people','/teacher/students.php'],
['Active Students',$counts['active'],'bi-person-check','/teacher/students.php'],
['Attendance This Month',$counts['attendance'],'bi-clock-history','/teacher/attendance.php'],
['Completed Modules',$counts['completed_modules'],'bi-journal-check','/teacher/learning_modules.php'],
['Certificates',$counts['certificates'],'bi-award','/teacher/certificates.php'],
['Event Applications',$counts['events'],'bi-calendar-event','/teacher/events.php'],
['Competition Applications',$counts['competitions'],'bi-trophy','/teacher/competitions.php'],
] as [$l,$v,$i,$href]):?>
<div class="col-sm-6 col-xl-3"><a class="text-decoration-none" href="<?=e($href)?>"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small text-secondary"><?=e($l)?></div><div class="h2 mb-0 text-dark"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></a></div>
<?php endforeach;?>
</div>

<div class="card stat-card mt-4">
<div class="card-header bg-white py-3"><h2 class="h5 mb-0">Recent Students</h2></div>
<div class="card-body"><div class="table-responsive">
<table id="teacherStudentsTable" class="table table-hover align-middle w-100">
<thead><tr><th>Student No.</th><th>Student</th><th>Class</th><th>Membership</th><th>Expiry</th><th>Registered</th><th></th></tr></thead>
<tbody>
<?php foreach($rows as $r):?><tr>
<td><?=e((string)$r['student_number'])?></td>
<td><?=e((string)$r['full_name'])?></td>
<td><?=e((string)$r['class_name'])?></td>
<td><?=e(ucwords(str_replace('_',' ',(string)$r['membership_status'])))?></td>
<td><?=e($r['membership_next_due_at']?date('d M Y',strtotime((string)$r['membership_next_due_at'])):'-')?></td>
<td><?=e(date('d M Y',strtotime((string)$r['created_at'])))?></td>
<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/teacher/students.php?edit=<?=(int)$r['id']?>"><i class="bi bi-pencil-square"></i></a></td>
</tr><?php endforeach;?>
</tbody></table>
</div></div></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery&&jQuery.fn.DataTable)$('#teacherStudentsTable').DataTable({
pageLength:5,lengthChange:false,searching:true,ordering:true,info:true,order:[[5,'desc']],
columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No students registered.'}
});});
</script>
<?php portalPageEnd(); ?>
