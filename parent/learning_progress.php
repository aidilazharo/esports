<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
$stmt=$pdo->prepare("SELECT p.id,p.progress_status,p.total_score,p.attended_on,p.completed_at,st.student_number,st.full_name,s.school_code,lm.module_code,lm.module_name,lm.week_number FROM student_module_progress p JOIN students st ON st.id=p.student_id JOIN student_guardians sg ON sg.student_id=st.id JOIN learning_modules lm ON lm.id=p.module_id LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY lm.sequence_no,st.full_name");
$stmt->execute([$guardianId]);$rows=$stmt->fetchAll();
$total=count($rows);$completed=count(array_filter($rows,fn($r)=>$r['progress_status']==='completed'));$progress=count(array_filter($rows,fn($r)=>$r['progress_status']==='in_progress'));$scores=array_values(array_filter(array_column($rows,'total_score'),fn($v)=>$v!==null));$avg=$scores?array_sum(array_map('floatval',$scores))/count($scores):null;
portalPageStart('parent','learning_progress','Learning Progress');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Progress Records',$total,'bi-journal-text'],['Completed',$completed,'bi-check2-circle'],['In Progress',$progress,'bi-play-circle'],['Average Score',$avg!==null?number_format($avg,1).'%':'-','bi-graph-up']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=e((string)$v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="learningTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>School</th><th>Week</th><th>Module</th><th>Status</th><th>Score</th><th>Attended</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['full_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)($r['school_code']??''))?></td><td><?=e((string)$r['week_number'])?></td><td><?=e((string)$r['module_code'])?> — <?=e((string)$r['module_name'])?></td><td><?=parentPortalStatusBadge((string)$r['progress_status'])?></td><td><?=e($r['total_score']!==null?number_format((float)$r['total_score'],2).'%':'-')?></td><td><?=e((string)($r['attended_on']??'-'))?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('learningTable', [[2,'asc']], 'No learning progress records found.'); ?>
<?php portalPageEnd(); ?>
