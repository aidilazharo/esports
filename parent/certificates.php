<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
$stmt=$pdo->prepare("SELECT ic.id,ic.certificate_number,ic.public_token,ic.completion_date,ic.status,ic.created_at,ct.template_name,ct.certificate_title,st.student_number,st.full_name,s.school_code FROM issued_certificates ic JOIN certificate_templates ct ON ct.id=ic.template_id JOIN students st ON st.id=ic.student_id JOIN student_guardians sg ON sg.student_id=st.id LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY ic.created_at DESC");
$stmt->execute([$guardianId]);$rows=$stmt->fetchAll();
$total=count($rows);$completed=count(array_filter($rows,fn($r)=>$r['status']==='completed'));$draft=count(array_filter($rows,fn($r)=>$r['status']==='draft'));$revoked=count(array_filter($rows,fn($r)=>$r['status']==='revoked'));
portalPageStart('parent','certificates','Certificates');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Certificates',$total,'bi-award'],['Completed',$completed,'bi-patch-check'],['Draft',$draft,'bi-file-earmark-text'],['Revoked',$revoked,'bi-file-earmark-x']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="certificatesTable" class="table table-hover align-middle w-100"><thead><tr><th>Certificate</th><th>Student</th><th>School</th><th>Template</th><th>Date</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['certificate_number'])?></td><td><?=e((string)$r['full_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)($r['school_code']??''))?></td><td><?=e((string)$r['template_name'])?></td><td><?=e((string)($r['completion_date']??'-'))?></td><td><?=parentPortalStatusBadge((string)$r['status'])?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('certificatesTable', [[4,'desc']], 'No certificates found.'); ?>
<?php portalPageEnd(); ?>
