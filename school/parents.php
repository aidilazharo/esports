<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('school');

$schoolId=(int)($_SESSION['school_id']??0);

$studentOptions=[];
$stmt=$pdo->prepare('SELECT id,student_number,full_name FROM students WHERE school_id=? ORDER BY full_name');
$stmt->execute([$schoolId]);
foreach($stmt->fetchAll() as $r) $studentOptions[(int)$r['id']]=$r['student_number'].' — '.$r['full_name'];

function schoolParentOwned(PDO $pdo,int $guardianId,int $schoolId): bool {
    $s=$pdo->prepare('SELECT COUNT(*) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE sg.guardian_id=? AND st.school_id=?');
    $s->execute([$guardianId,$schoolId]); return (int)$s->fetchColumn()>0;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf();
        $action=(string)($_POST['crud_action']??'');
        if($action==='create'){
            $full=trim((string)($_POST['full_name']??''));
            $identity=trim((string)($_POST['identity_number']??''));
            $phone=trim((string)($_POST['phone']??''));
            $email=trim((string)($_POST['email']??''));
            $studentId=(int)($_POST['student_id']??0);
            $relationship=trim((string)($_POST['relationship']??'Guardian'));
            if($full===''||$identity===''||$phone===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!isset($studentOptions[$studentId])) throw new RuntimeException('Complete all required parent and student fields.');

            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT id,email FROM guardians WHERE identity_number=? LIMIT 1 FOR UPDATE');
            $q->execute([$identity]); $existing=$q->fetch(PDO::FETCH_ASSOC); $guardianId=(int)($existing['id']??0);
            if($guardianId<=0){
                $i=$pdo->prepare('INSERT INTO guardians (guardian_number,full_name,identity_number,phone,email) VALUES (NULL,?,?,?,?)');
                $i->execute([$full,$identity,$phone,$email]);
                $guardianId=(int)$pdo->lastInsertId();
                $number='PG-'.str_pad((string)$guardianId,6,'0',STR_PAD_LEFT);
                $pdo->prepare('UPDATE guardians SET guardian_number=? WHERE id=?')->execute([$number,$guardianId]);
            }else{
                if (strcasecmp(trim((string)$existing['email']),$email)!==0) throw new RuntimeException('Existing guardian contact does not match.');
                // Link only. Never overwrite another school or parent's shared account.
            }
            $link=$pdo->prepare('INSERT INTO student_guardians (student_id,guardian_id,relationship,is_primary) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE relationship=VALUES(relationship)');
            $link->execute([$studentId,$guardianId,$relationship?:'Guardian']);
            $pdo->commit();
            setFlash('success','Parent / guardian saved and linked to the selected student.');
            redirect('/school/parents.php');
        }

        if($action==='update'){
            $guardianId=(int)($_POST['id']??0);
            if(!schoolParentOwned($pdo,$guardianId,$schoolId)) throw new RuntimeException('You do not have access to this parent record.');
            $full=trim((string)($_POST['full_name']??''));
            $phone=trim((string)($_POST['phone']??''));
            $email=trim((string)($_POST['email']??''));
            if($full===''||$phone===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Name, phone and valid email are required.');
            $pdo->prepare('UPDATE guardians g SET g.full_name=?,g.phone=? WHERE g.id=? AND BINARY g.email=BINARY ? AND NOT EXISTS (SELECT 1 FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE sg.guardian_id=g.id AND st.school_id<>?)')->execute([$full,$phone,$guardianId,$email,$schoolId]);
            // Shared guardian contact changes and email changes require the parent/admin verification workflow.
            $check=$pdo->prepare('SELECT COUNT(*) FROM guardians g WHERE g.id=? AND BINARY g.email=BINARY ? AND NOT EXISTS (SELECT 1 FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE sg.guardian_id=g.id AND st.school_id<>?)');
            $check->execute([$guardianId,$email,$schoolId]);
            if(!(int)$check->fetchColumn())throw new RuntimeException('Shared account or email changes must be handled by the guardian or administrator.');
            setFlash('success','Parent / guardian updated.');
            redirect('/school/parents.php');
        }

        if($action==='unlink'){
            $guardianId=(int)($_POST['id']??0);
            $studentId=(int)($_POST['student_id']??0);
            if(!isset($studentOptions[$studentId])||!schoolParentOwned($pdo,$guardianId,$schoolId)) throw new RuntimeException('Invalid parent/student link.');
            $pdo->prepare('DELETE FROM student_guardians WHERE guardian_id=? AND student_id=?')->execute([$guardianId,$studentId]);
            setFlash('success','Parent / guardian link removed from this student.');
            redirect('/school/parents.php');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('School guardian operation failed: '.$e->getMessage());
        setFlash('danger','Unable to save the guardian link or contact details. Check the details, or contact the administrator for shared account changes.'); redirect('/school/parents.php');
    }
}

$editId=(int)($_GET['edit']??0);
$edit=null;
if($editId>0&&schoolParentOwned($pdo,$editId,$schoolId)){
    $s=$pdo->prepare('SELECT * FROM guardians WHERE id=?');$s->execute([$editId]);$edit=$s->fetch()?:null;
}

$rows=[];
$s=$pdo->prepare(
"SELECT g.id,g.guardian_number,g.full_name,g.phone,g.email,g.email_verified_at,
        st.id AS student_id,st.student_number,st.full_name AS student_name,sg.relationship
 FROM guardians g
 JOIN student_guardians sg ON sg.guardian_id=g.id
 JOIN students st ON st.id=sg.student_id
 WHERE st.school_id=?
 ORDER BY g.full_name,st.full_name"
);
$s->execute([$schoolId]);$rows=$s->fetchAll();

$totalParents=(int)(new class($pdo,$schoolId){public function __construct(private PDO $p,private int $s){} public function c($sql){$q=$this->p->prepare($sql);$q->execute([$this->s]);return(int)$q->fetchColumn();}})->c('SELECT COUNT(DISTINCT sg.guardian_id) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE st.school_id=?');
$verified=0;$linkedStudents=0;
try{$q=$pdo->prepare('SELECT COUNT(DISTINCT sg.guardian_id) FROM student_guardians sg JOIN students st ON st.id=sg.student_id JOIN guardians g ON g.id=sg.guardian_id WHERE st.school_id=? AND g.email_verified_at IS NOT NULL');$q->execute([$schoolId]);$verified=(int)$q->fetchColumn();}catch(Throwable $e){}
try{$q=$pdo->prepare('SELECT COUNT(DISTINCT sg.student_id) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE st.school_id=?');$q->execute([$schoolId]);$linkedStudents=(int)$q->fetchColumn();}catch(Throwable $e){}

portalPageStart('school','parents','Parents');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4">
<?php foreach([['Parents / Guardians',$totalParents,'bi-person-hearts'],['Verified Email',$verified,'bi-envelope-check'],['Students Linked',$linkedStudents,'bi-diagram-3'],['Parent Links',count($rows),'bi-link-45deg']] as [$l,$v,$i]):?>
<div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div>
<?php endforeach;?>
</div>

<div class="d-flex justify-content-end mb-3" data-mobile-icon-links="off"><?php if(!$edit&&!isset($_GET['add'])):?><a class="btn btn-primary btn-sm" href="/school/parents.php?add=1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add / Link Guardian</a><?php endif;?></div>
<?php if($edit||isset($_GET['add'])):?><div class="card stat-card mb-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=$edit?'Edit Parent / Guardian':'Add Parent / Guardian'?></h2></div><div class="card-body">
<form method="post" class="row g-3">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>">
<input type="hidden" name="crud_action" value="<?=$edit?'update':'create'?>">
<?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<?php if(!$edit):?>
<div class="col-md-4"><label class="form-label">Student *</label><select class="form-select" name="student_id" required><option value="">Select student</option><?php foreach($studentOptions as $id=>$label):?><option value="<?=$id?>"><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Relationship *</label><select class="form-select" name="relationship" required><option>Father</option><option>Mother</option><option>Guardian</option><option>Other</option></select></div>
<div class="col-md-5"><label class="form-label">Identity Number *</label><input class="form-control" name="identity_number" required></div>
<?php endif;?>
<div class="col-md-4"><label class="form-label">Full Name *</label><input class="form-control" name="full_name" value="<?=e((string)($edit['full_name']??''))?>" required></div>
<div class="col-md-4"><label class="form-label">Phone *</label><input class="form-control" name="phone" value="<?=e((string)($edit['phone']??''))?>" required></div>
<div class="col-md-4"><label class="form-label">Email *</label><input type="email" class="form-control" name="email" value="<?=e((string)($edit['email']??''))?>" required <?=$edit?'readonly':''?>></div>
<div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi <?=$edit?'bi-save':'bi-plus-circle'?> me-1"></i><?=$edit?'Save Changes':'Add or Link Guardian'?></button><?php if($edit||isset($_GET['add'])):?><a class="btn btn-outline-secondary ms-2" href="/school/parents.php">Cancel</a><?php endif;?></div>
</form>
</div></div>

<?php endif;?>
<div class="card stat-card"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Parent / Student Links</h2></div><div class="card-body"><div class="table-responsive">
<table id="parentsTable" class="table table-hover align-middle w-100"><thead><tr><th>Parent</th><th>Contact</th><th>Student</th><th>Relationship</th><th>Verified</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr>
<td><strong><?=e((string)$r['full_name'])?></strong><br><span class="small text-secondary"><?=e((string)$r['guardian_number'])?></span></td>
<td><?=e((string)$r['phone'])?><br><span class="small"><?=e((string)$r['email'])?></span></td>
<td><?=e((string)$r['student_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td>
<td><?=e((string)$r['relationship'])?></td>
<td><?=$r['email_verified_at']?'<span class="badge text-bg-success">Verified</span>':'<span class="badge text-bg-warning">Pending</span>'?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/school/parents.php?edit=<?=(int)$r['id']?>" aria-label="Edit record"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
<form method="post" class="d-inline" onsubmit="return confirm('Remove this parent link from the student?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="unlink"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="student_id" value="<?=(int)$r['student_id']?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove guardian link"><i class="bi bi-link-45deg"></i></button></form></td>
</tr><?php endforeach;?>
</tbody></table></div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery&&jQuery.fn.DataTable)$('#parentsTable').DataTable({pageLength:10,lengthChange:false,searching:true,ordering:true,info:true,columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No parents linked to this school.'}});});</script>
<?php portalPageEnd(); ?>
