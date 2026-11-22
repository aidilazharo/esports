<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);

$counts=[
'children'=>(int)parentPortalScalar($pdo,'SELECT COUNT(DISTINCT student_id) FROM student_guardians WHERE guardian_id=?',[$guardianId]),
'active'=>(int)parentPortalScalar($pdo,"SELECT COUNT(*) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE sg.guardian_id=? AND st.membership_status='active' AND st.membership_next_due_at IS NOT NULL AND st.membership_next_due_at>=NOW()",[$guardianId]),
'payments'=>(int)parentPortalScalar($pdo,'SELECT COUNT(*) FROM payments p JOIN student_guardians sg ON sg.student_id=p.student_id WHERE sg.guardian_id=?',[$guardianId]),
'certificates'=>(int)parentPortalScalar($pdo,'SELECT COUNT(*) FROM issued_certificates ic JOIN student_guardians sg ON sg.student_id=ic.student_id WHERE sg.guardian_id=?',[$guardianId]),
'attendance'=>(int)parentPortalScalar($pdo,"SELECT COUNT(*) FROM attendance_sessions a JOIN student_guardians sg ON sg.student_id=a.student_id WHERE sg.guardian_id=? AND a.attendance_month=DATE_FORMAT(CURDATE(),'%Y-%m-01')",[$guardianId]),
'completed_modules'=>(int)parentPortalScalar($pdo,"SELECT COUNT(*) FROM student_module_progress p JOIN student_guardians sg ON sg.student_id=p.student_id WHERE sg.guardian_id=? AND p.progress_status='completed'",[$guardianId]),
'events'=>(int)parentPortalScalar($pdo,'SELECT COUNT(*) FROM parent_event_applications WHERE guardian_id=?',[$guardianId]),
];

$stmt=$pdo->prepare("SELECT st.id,st.student_number,st.full_name,st.class_name,st.membership_status,st.membership_next_due_at,s.school_code,s.school_name,sg.relationship
                     FROM student_guardians sg JOIN students st ON st.id=sg.student_id
                     LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY st.full_name");
$stmt->execute([$guardianId]); $children=$stmt->fetchAll();

portalPageStart('parent','dashboard','Dashboard');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3">
<?php foreach([
['Children',$counts['children'],'bi-people','/parent/children.php'],
['Active Memberships',$counts['active'],'bi-card-checklist','/parent/membership.php'],
['Payments',$counts['payments'],'bi-credit-card','/parent/payments.php'],
['Certificates',$counts['certificates'],'bi-award','/parent/certificates.php'],
['Attendance This Month',$counts['attendance'],'bi-clock-history','/parent/attendance.php'],
['Completed Modules',$counts['completed_modules'],'bi-journal-check','/parent/learning_progress.php'],
['Event Applications',$counts['events'],'bi-calendar-event','/parent/events.php'],
] as [$label,$value,$icon,$href]):?>
<div class="col-sm-6 col-xl-3"><a class="text-decoration-none" href="<?=e($href)?>"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small text-secondary"><?=e($label)?></div><div class="h2 mb-0 text-dark"><?=number_format($value)?></div></div><i class="bi <?=e($icon)?> fs-2 text-primary"></i></div></div></a></div>
<?php endforeach;?>
</div>
<div class="card stat-card mt-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Children</h2></div><div class="card-body"><div class="table-responsive">
<table id="parentDashboardChildren" class="table table-hover align-middle w-100"><thead><tr><th>Student No.</th><th>Student</th><th>School</th><th>Relationship</th><th>Membership</th><th>Expiry</th></tr></thead><tbody>
<?php foreach($children as $r):?><tr><td><?=e((string)$r['student_number'])?></td><td><?=e((string)$r['full_name'])?></td><td><?=e((string)($r['school_code']??''))?> — <?=e((string)($r['school_name']??''))?></td><td><?=e((string)$r['relationship'])?></td><td><?=parentPortalStatusBadge((string)$r['membership_status'])?></td><td><?=e($r['membership_next_due_at']?date('d M Y',strtotime((string)$r['membership_next_due_at'])):'-')?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('parentDashboardChildren', [[1,'asc']], 'No children linked to this account.'); ?>
<?php portalPageEnd(); ?>
