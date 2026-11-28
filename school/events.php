<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../events-competitions-helpers.php';
requirePortalRole('school');
$schoolId=(int)($_SESSION['school_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){http_response_code(405);exit('Use the event participation workflow.');}
$events=[];$applications=[];$available=true;
try {
    $q=$pdo->query("SELECT * FROM programme_events WHERE status='open' AND eligibility IN ('school','both') ORDER BY application_deadline");
    $events=array_values(array_filter($q->fetchAll(), 'pmEventIsOpen'));
    $q=$pdo->prepare("SELECT pa.application_code,pe.title,pa.status,pa.payment_status,pa.applied_at FROM programme_applications pa JOIN programme_events pe ON pe.id=pa.event_id WHERE pa.school_id=? AND pa.applicant_type='school' ORDER BY pa.applied_at DESC");
    $q->execute([$schoolId]);$applications=$q->fetchAll();
} catch(Throwable $ex){error_log('School events unavailable: '.$ex->getMessage());$available=false;}
portalPageStart('school','events','Events');
?>
<div data-mobile-icon-links="off">
<?php if(!$available):?><div class="alert alert-warning">Event information is temporarily unavailable.</div><?php endif;?>
<section class="card stat-card mb-3"><div class="card-header bg-white"><h2 class="h5 mb-0">Upcoming events</h2></div><div class="card-body">
<?php if($available&&!$events):?><p class="text-secondary mb-0">No open school events.</p><?php endif;?>
<div class="row g-3"><?php foreach($events as $event):?><div class="col-md-6"><article class="card h-100"><div class="card-body">
<h3 class="h6"><?=e($event['title'])?></h3><p class="small mb-2">Deadline: <?=e(pmDisplayDateTime($event['application_deadline']))?></p>
<p class="small"><?=e(pmMoney((int)$event['entry_fee_minor'],(string)$event['currency']))?></p>
<a class="btn btn-primary btn-sm" href="/events-competitions.php?apply=<?=(int)$event['id']?>#applyForm">View and apply</a></div></article></div><?php endforeach;?></div></div></section>
<section class="card stat-card"><div class="card-header bg-white"><h2 class="h5 mb-0">School applications</h2></div><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Application</th><th>Event</th><th>Status</th><th>Payment</th><th>Applied</th></tr></thead><tbody>
<?php foreach($applications as $a):?><tr><td><?=e($a['application_code'])?></td><td><?=e($a['title'])?></td><td><?=e(ucfirst($a['status']))?></td><td><?=e(ucfirst($a['payment_status']))?></td><td><?=e(pmDisplayDateTime($a['applied_at']))?></td></tr><?php endforeach;?>
<?php if($available&&!$applications):?><tr><td colspan="5">No applications yet.</td></tr><?php endif;?>
</tbody></table></div><a href="/events-competitions.php">Manage participation through Events &amp; Competitions</a></div></section></div>
<?php portalPageEnd(); ?>
