<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
$stmt=$pdo->prepare("SELECT st.id,st.student_number,st.full_name,st.membership_status,st.membership_next_due_at,s.school_code,s.school_name FROM student_guardians sg JOIN students st ON st.id=sg.student_id LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY st.full_name");
$stmt->execute([$guardianId]);$rows=$stmt->fetchAll();
$total=count($rows);$active=0;$pending=0;$expired=0;foreach($rows as $r){$ok=$r['membership_status']==='active'&&!empty($r['membership_next_due_at'])&&strtotime((string)$r['membership_next_due_at'])>=time();if($ok)$active++;elseif(in_array($r['membership_status'],['pending_payment','pending_activation'],true))$pending++;else$expired++;}
portalPageStart('parent','membership','Membership');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Children',$total,'bi-people'],['Active',$active,'bi-person-check'],['Pending',$pending,'bi-hourglass-split'],['Expired / Inactive',$expired,'bi-person-x']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Student Memberships</h2></div><div class="card-body"><div class="table-responsive"><table id="membershipTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>School</th><th>Status</th><th>Expiry</th><th>Fee</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):$ok=$r['membership_status']==='active'&&!empty($r['membership_next_due_at'])&&strtotime((string)$r['membership_next_due_at'])>=time();?><tr><td><strong><?=e((string)$r['full_name'])?></strong><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)($r['school_code']??''))?> — <?=e((string)($r['school_name']??''))?></td><td><?=parentPortalStatusBadge((string)$r['membership_status'])?></td><td><?=e($r['membership_next_due_at']?date('d M Y',strtotime((string)$r['membership_next_due_at'])):'-')?></td><td>RM100</td><td class="text-end text-nowrap"><?php if(!$ok):?><a class="btn btn-sm btn-outline-warning" href="/redeem_coupon.php?student_id=<?=(int)$r['id']?>" title="Redeem Coupon"><i class="bi bi-ticket-perforated"></i></a> <a class="btn btn-sm btn-primary" href="/student_payment.php?student_id=<?=(int)$r['id']?>" title="Pay Membership"><i class="bi bi-credit-card"></i></a><?php else:?><span class="badge text-bg-success">Valid</span><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('membershipTable', [[0,'asc']], 'No membership records available.'); ?>
<?php portalPageEnd(); ?>
