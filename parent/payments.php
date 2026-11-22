<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
$stmt=$pdo->prepare("SELECT p.id,p.payment_type,p.description,p.amount_minor,p.currency,p.status,p.paid_at,p.created_at,st.id AS student_id,st.student_number,st.full_name,s.school_code FROM payments p JOIN students st ON st.id=p.student_id JOIN student_guardians sg ON sg.student_id=st.id LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY p.created_at DESC");
$stmt->execute([$guardianId]);$rows=$stmt->fetchAll();
$total=count($rows);$paid=count(array_filter($rows,fn($r)=>$r['status']==='paid'));$pending=$total-$paid;$revenue=0;foreach($rows as $r)if($r['status']==='paid')$revenue+=(int)$r['amount_minor'];
portalPageStart('parent','payments','Payments');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Payment Records',$total,'bi-receipt'],['Paid',$paid,'bi-check-circle'],['Pending / Other',$pending,'bi-hourglass-split'],['Paid Amount','RM '.number_format($revenue/100,2),'bi-cash-stack']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=e((string)$v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Payment History</h2></div><div class="card-body"><div class="table-responsive"><table id="paymentsTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>School</th><th>Type</th><th>Amount</th><th>Status</th><th>Paid At</th><th>Created</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['full_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)($r['school_code']??''))?></td><td><?=e(ucwords(str_replace('_',' ',(string)$r['payment_type'])))?></td><td><?=e(strtoupper((string)$r['currency']).' '.number_format(((int)$r['amount_minor'])/100,2))?></td><td><?=parentPortalStatusBadge((string)$r['status'])?></td><td><?=e($r['paid_at']?date('d M Y H:i',strtotime((string)$r['paid_at'])):'-')?></td><td><?=e($r['created_at']?date('d M Y H:i',strtotime((string)$r['created_at'])):'-')?></td><td class="text-end"><?php if($r['status']!=='paid'):?><a class="btn btn-sm btn-primary" href="/student_payment.php?student_id=<?=(int)$r['student_id']?>"><i class="bi bi-credit-card me-1"></i>Pay</a><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('paymentsTable', [[6,'desc']], 'No payment records found.'); ?>
<?php portalPageEnd(); ?>
