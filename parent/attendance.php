<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
$stmt=$pdo->prepare("SELECT a.id,a.attendance_month,a.check_in_at,a.check_out_at,a.credited_minutes,a.status,st.student_number,st.full_name,s.school_code FROM attendance_sessions a JOIN students st ON st.id=a.student_id JOIN student_guardians sg ON sg.student_id=st.id LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY a.check_in_at DESC");
$stmt->execute([$guardianId]);$rows=$stmt->fetchAll();
$total=count($rows);$completed=count(array_filter($rows,fn($r)=>$r['status']==='completed'));$open=count(array_filter($rows,fn($r)=>$r['status']==='open'));$minutes=array_sum(array_map(fn($r)=>(int)$r['credited_minutes'],$rows));
portalPageStart('parent','attendance','Attendance');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Sessions',$total,'bi-clock-history'],['Completed',$completed,'bi-check2-circle'],['Open',$open,'bi-stopwatch'],['Credited Hours',number_format($minutes/60,1),'bi-hourglass-split']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=e((string)$v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="attendanceTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>School</th><th>Month</th><th>Check In</th><th>Check Out</th><th>Credited</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['full_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)($r['school_code']??''))?></td><td><?=e((string)$r['attendance_month'])?></td><td><?=e((string)$r['check_in_at'])?></td><td><?=e((string)($r['check_out_at']??'-'))?></td><td><?=(int)$r['credited_minutes']?> min</td><td><?=parentPortalStatusBadge((string)$r['status'])?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('attendanceTable', [[3,'desc']], 'No attendance records found.'); ?>
<?php portalPageEnd(); ?>
