<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
$studentOptions=parentPortalStudentOptions($pdo,$guardianId);
$eventOptions=[];
$stmt=$pdo->query("SELECT id,event_code,title,event_type,start_at,status FROM programme_events WHERE status IN ('open','draft') ORDER BY start_at DESC,title");
foreach($stmt->fetchAll() as $r)$eventOptions[(int)$r['id']]=$r['event_code'].' — '.$r['title'].' — '.strtoupper((string)$r['event_type']);
function parentEventOwns(PDO $pdo,int $id,int $guardianId): bool{$q=$pdo->prepare('SELECT COUNT(*) FROM parent_event_applications WHERE id=? AND guardian_id=?');$q->execute([$id,$guardianId]);return(int)$q->fetchColumn()===1;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf();$action=(string)($_POST['crud_action']??'');
        if(in_array($action,['create','update'],true)){
            $id=(int)($_POST['id']??0);$studentId=(int)($_POST['student_id']??0);$eventId=(int)($_POST['event_id']??0);$status=trim((string)($_POST['status']??'applied'));$notes=trim((string)($_POST['notes']??''))?:null;
            if(!isset($studentOptions[$studentId]))throw new RuntimeException('Select a child linked to this account.');
            if(!isset($eventOptions[$eventId]))throw new RuntimeException('Select an available event or competition.');
            $q=$pdo->prepare('SELECT event_type FROM programme_events WHERE id=? LIMIT 1');$q->execute([$eventId]);$eventType=(string)($q->fetchColumn()?:'programme');
            if($action==='create'){
                $q=$pdo->prepare("INSERT INTO parent_event_applications (guardian_id,student_id,event_id,event_type,status,notes) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),notes=VALUES(notes),updated_at=CURRENT_TIMESTAMP");
                $q->execute([$guardianId,$studentId,$eventId,$eventType,$status,$notes]);
            }else{
                if(!parentEventOwns($pdo,$id,$guardianId))throw new RuntimeException('Event application was not found.');
                $q=$pdo->prepare('UPDATE parent_event_applications SET student_id=?,event_id=?,event_type=?,status=?,notes=? WHERE id=? AND guardian_id=?');$q->execute([$studentId,$eventId,$eventType,$status,$notes,$id,$guardianId]);
            }
            setFlash('success','Event application saved.');redirect('/parent/events.php');
        }
        if($action==='delete'){
            $id=(int)($_POST['id']??0);if(!parentEventOwns($pdo,$id,$guardianId))throw new RuntimeException('Event application was not found.');
            $pdo->prepare('DELETE FROM parent_event_applications WHERE id=? AND guardian_id=?')->execute([$id,$guardianId]);setFlash('success','Event application removed.');redirect('/parent/events.php');
        }
    }catch(Throwable $e){setFlash('danger',$e->getMessage());redirect('/parent/events.php');}
}
$edit=null;$editId=(int)($_GET['edit']??0);if($editId>0&&parentEventOwns($pdo,$editId,$guardianId)){$q=$pdo->prepare('SELECT * FROM parent_event_applications WHERE id=? AND guardian_id=?');$q->execute([$editId,$guardianId]);$edit=$q->fetch()?:null;}
$q=$pdo->prepare("SELECT a.id,a.student_id,a.event_id,a.event_type,a.status,a.notes,a.created_at,st.student_number,st.full_name,pe.event_code,pe.title,pe.start_at FROM parent_event_applications a JOIN students st ON st.id=a.student_id JOIN programme_events pe ON pe.id=a.event_id WHERE a.guardian_id=? ORDER BY a.created_at DESC");$q->execute([$guardianId]);$rows=$q->fetchAll();
$total=count($rows);$approved=count(array_filter($rows,fn($r)=>$r['status']==='approved'));$applied=count(array_filter($rows,fn($r)=>$r['status']==='applied'));$competitions=count(array_filter($rows,fn($r)=>$r['event_type']==='tournament'));
portalPageStart('parent','events','Events');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Applications',$total,'bi-calendar-event'],['Applied',$applied,'bi-hourglass-split'],['Approved',$approved,'bi-check2-circle'],['Competitions',$competitions,'bi-trophy']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card mb-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=$edit?'Edit Event Application':'Apply for Event / Competition'?></h2></div><div class="card-body"><form method="post" class="row g-3">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="<?=$edit?'update':'create'?>"><?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<div class="col-md-5"><label class="form-label">Child *</label><select class="form-select" name="student_id" required><option value="">Select</option><?php foreach($studentOptions as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['student_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-5"><label class="form-label">Event / Competition *</label><select class="form-select" name="event_id" required><option value="">Select</option><?php foreach($eventOptions as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['event_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-2"><label class="form-label">Status *</label><select class="form-select" name="status"><option value="applied" <?=($edit['status']??'applied')==='applied'?'selected':''?>>Applied</option><option value="withdrawn" <?=($edit['status']??'')==='withdrawn'?'selected':''?>>Withdrawn</option></select></div>
<div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"><?=e((string)($edit['notes']??''))?></textarea></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi <?=$edit?'bi-save':'bi-plus-circle'?> me-1"></i><?=$edit?'Save Changes':'Submit Application'?></button><?php if($edit):?><a class="btn btn-outline-secondary ms-2" href="/parent/events.php">Cancel</a><?php endif;?></div>
</form></div></div>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="eventsTable" class="table table-hover align-middle w-100"><thead><tr><th>Child</th><th>Code</th><th>Event / Competition</th><th>Type</th><th>Start</th><th>Status</th><th>Applied</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['full_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)$r['event_code'])?></td><td><?=e((string)$r['title'])?></td><td><?=e(ucfirst((string)$r['event_type']))?></td><td><?=e($r['start_at']?date('d M Y H:i',strtotime((string)$r['start_at'])):'-')?></td><td><?=parentPortalStatusBadge((string)$r['status'])?></td><td><?=e(date('d M Y H:i',strtotime((string)$r['created_at'])))?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/parent/events.php?edit=<?=(int)$r['id']?>"><i class="bi bi-pencil-square"></i></a><form method="post" class="d-inline" onsubmit="return confirm('Delete this application?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('eventsTable', [[6,'desc']], 'No event applications found.'); ?>
<?php portalPageEnd(); ?>
