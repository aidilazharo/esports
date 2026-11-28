<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('school');
$schoolId=(int)($_SESSION['school_id']??0);

$templates=[];foreach($pdo->query("SELECT id,template_name,recipient_scope FROM certificate_templates WHERE status='active' ORDER BY template_name")->fetchAll() as $r)$templates[(int)$r['id']]=$r['template_name'].' ('.$r['recipient_scope'].')';
$students=[];$teachers=[];
$q=$pdo->prepare('SELECT id,student_number,full_name FROM students WHERE school_id=? ORDER BY full_name');$q->execute([$schoolId]);foreach($q->fetchAll() as $r)$students[(int)$r['id']]=$r['student_number'].' — '.$r['full_name'];
$q=$pdo->prepare('SELECT id,full_name FROM teachers WHERE school_id=? ORDER BY full_name');$q->execute([$schoolId]);foreach($q->fetchAll() as $r)$teachers[(int)$r['id']]=$r['full_name'];

function schoolCertOwned(PDO $pdo,int $id,int $schoolId): bool{$q=$pdo->prepare("SELECT COUNT(*) FROM issued_certificates ic LEFT JOIN students st ON st.id=ic.student_id LEFT JOIN teachers t ON t.id=ic.teacher_id WHERE ic.id=? AND (st.school_id=? OR t.school_id=?)");$q->execute([$id,$schoolId,$schoolId]);return(int)$q->fetchColumn()===1;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf();$action=(string)($_POST['crud_action']??'');
        if(in_array($action,['create','update'],true)){
            $id=(int)($_POST['id']??0);$templateId=(int)($_POST['template_id']??0);$type=(string)($_POST['recipient_type']??'student');
            if(!in_array($type,['student','teacher'],true))throw new RuntimeException('Invalid certificate recipient.');
            $studentId=$type==='student'?(int)($_POST['student_id']??0):null;$teacherId=$type==='teacher'?(int)($_POST['teacher_id']??0):null;
            if(!isset($templates[$templateId]))throw new RuntimeException('Select a valid certificate template.');
            if($type==='student'&&!isset($students[(int)$studentId]))throw new RuntimeException('Select a student from this school.');
            if($type==='teacher'&&!isset($teachers[(int)$teacherId]))throw new RuntimeException('Select a teacher from this school.');
            $date=trim((string)($_POST['completion_date']??''))?:null;$status=(string)($_POST['status']??'draft');$notes=trim((string)($_POST['notes']??''))?:null;
            if($action==='create'){
                $num='CERT-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$token=bin2hex(random_bytes(32));
                $q=$pdo->prepare('INSERT INTO issued_certificates (template_id,recipient_type,student_id,teacher_id,certificate_number,public_token,completion_date,status,notes) VALUES (?,?,?,?,?,?,?,?,?)');
                $q->execute([$templateId,$type,$studentId,$teacherId,$num,$token,$date,$status,$notes]);
            }else{
                if(!schoolCertOwned($pdo,$id,$schoolId))throw new RuntimeException('Certificate record not found.');
                $q=$pdo->prepare('UPDATE issued_certificates SET template_id=?,recipient_type=?,student_id=?,teacher_id=?,completion_date=?,status=?,notes=? WHERE id=?');
                $q->execute([$templateId,$type,$studentId,$teacherId,$date,$status,$notes,$id]);
            }
            setFlash('success','Certificate record saved.');redirect('/school/certificates.php');
        }
        if($action==='delete'){
            $id=(int)($_POST['id']??0);if(!schoolCertOwned($pdo,$id,$schoolId))throw new RuntimeException('Certificate record not found.');
            $pdo->prepare('DELETE FROM issued_certificates WHERE id=?')->execute([$id]);setFlash('success','Certificate record deleted.');redirect('/school/certificates.php');
        }
    }catch(Throwable $e){error_log('School operation failed: '.$e->getMessage());setFlash('danger','Unable to save the record. Check the details and try again.');redirect('/school/certificates.php');}
}

$edit=null;$editId=(int)($_GET['edit']??0);if($editId>0&&schoolCertOwned($pdo,$editId,$schoolId)){$q=$pdo->prepare('SELECT * FROM issued_certificates WHERE id=?');$q->execute([$editId]);$edit=$q->fetch()?:null;}
$q=$pdo->prepare("SELECT ic.*,ct.template_name,COALESCE(st.full_name,t.full_name) AS recipient_name,COALESCE(st.student_number,'Teacher') AS recipient_number
FROM issued_certificates ic JOIN certificate_templates ct ON ct.id=ic.template_id LEFT JOIN students st ON st.id=ic.student_id LEFT JOIN teachers t ON t.id=ic.teacher_id
WHERE st.school_id=? OR t.school_id=? ORDER BY ic.created_at DESC");$q->execute([$schoolId,$schoolId]);$rows=$q->fetchAll();
$total=count($rows);$completed=count(array_filter($rows,fn($r)=>$r['status']==='completed'));$draft=count(array_filter($rows,fn($r)=>$r['status']==='draft'));$revoked=count(array_filter($rows,fn($r)=>$r['status']==='revoked'));

portalPageStart('school','certificates','Certificates');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Certificates',$total,'bi-award'],['Completed',$completed,'bi-patch-check'],['Draft',$draft,'bi-file-earmark-text'],['Revoked',$revoked,'bi-file-earmark-x']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="d-flex justify-content-end mb-3" data-mobile-icon-links="off"><?php if(!$edit&&!isset($_GET['add'])):?><a class="btn btn-primary btn-sm" href="/school/certificates.php?add=1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Issue Certificate</a><?php endif;?></div>
<?php if($edit||isset($_GET['add'])):?><div class="card stat-card mb-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=$edit?'Edit Certificate':'Issue Certificate'?></h2></div><div class="card-body"><form method="post" class="row g-3">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="<?=$edit?'update':'create'?>"><?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<div class="col-md-5"><label class="form-label">Template *</label><select class="form-select" name="template_id" required><option value="">Select</option><?php foreach($templates as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['template_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-2"><label class="form-label">Recipient Type</label><select class="form-select" name="recipient_type" id="recipientType"><option value="student" <?=($edit['recipient_type']??'student')==='student'?'selected':''?>>Student</option><option value="teacher" <?=($edit['recipient_type']??'')==='teacher'?'selected':''?>>Teacher</option></select></div>
<div class="col-md-5" id="studentWrap"><label class="form-label">Student</label><select class="form-select" name="student_id"><option value="">Select</option><?php foreach($students as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['student_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-5" id="teacherWrap"><label class="form-label">Teacher</label><select class="form-select" name="teacher_id"><option value="">Select</option><?php foreach($teachers as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['teacher_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Completion Date</label><input type="date" class="form-control" name="completion_date" value="<?=e((string)($edit['completion_date']??date('Y-m-d')))?>"></div>
<div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="draft" <?=($edit['status']??'draft')==='draft'?'selected':''?>>Draft</option><option value="completed" <?=($edit['status']??'')==='completed'?'selected':''?>>Completed</option><option value="revoked" <?=($edit['status']??'')==='revoked'?'selected':''?>>Revoked</option></select></div>
<div class="col-md-6"><label class="form-label">Notes</label><input class="form-control" name="notes" value="<?=e((string)($edit['notes']??''))?>"></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi <?=$edit?'bi-save':'bi-plus-circle'?> me-1"></i><?=$edit?'Save Changes':'Issue Certificate'?></button><?php if($edit||isset($_GET['add'])):?><a class="btn btn-outline-secondary ms-2" href="/school/certificates.php">Cancel</a><?php endif;?></div>
</form></div></div>
<?php endif;?>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="certTable" class="table table-hover align-middle w-100"><thead><tr><th>Certificate</th><th>Recipient</th><th>Template</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['certificate_number'])?></td><td><?=e((string)$r['recipient_name'])?><br><span class="small text-secondary"><?=e((string)$r['recipient_number'])?></span></td><td><?=e((string)$r['template_name'])?></td><td><?=e((string)($r['completion_date']??'-'))?></td><td><?=e(ucfirst((string)$r['status']))?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/school/certificates.php?edit=<?=(int)$r['id']?>" aria-label="Edit record"><i class="bi bi-pencil-square" aria-hidden="true"></i></a> <form class="d-inline" method="post" onsubmit="return confirm('Delete this certificate record?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete record"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>
function toggleRecipient(){const t=document.getElementById('recipientType').value;document.getElementById('studentWrap').style.display=t==='student'?'block':'none';document.getElementById('teacherWrap').style.display=t==='teacher'?'block':'none';}
document.addEventListener('DOMContentLoaded',()=>{toggleRecipient();document.getElementById('recipientType').addEventListener('change',toggleRecipient);if(window.jQuery&&jQuery.fn.DataTable)$('#certTable').DataTable({pageLength:10,lengthChange:false,searching:true,ordering:true,info:true,columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No certificates issued.'}});});
</script>
<?php portalPageEnd(); ?>
