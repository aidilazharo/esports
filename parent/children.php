<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
require_once __DIR__.'/../includes/parent_portal.php';
requirePortalRole('parent');
$guardianId=(int)($_SESSION['parent_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf(); $action=(string)($_POST['crud_action']??'');
        if($action==='create'){
            $schoolCode=strtoupper(trim((string)($_POST['school_code']??''))); $studentNumber=trim((string)($_POST['student_number']??'')); $identity=trim((string)($_POST['identity_number']??'')); $relationship=trim((string)($_POST['relationship']??'Guardian'));
            if($schoolCode===''||$studentNumber===''||$identity==='')throw new RuntimeException('School code, student number and identity number are required.');
            $stmt=$pdo->prepare("SELECT st.id FROM students st JOIN schools s ON s.id=st.school_id WHERE UPPER(s.school_code)=? AND st.student_number=? AND st.identity_number=? LIMIT 1");
            $stmt->execute([$schoolCode,$studentNumber,$identity]); $studentId=(int)($stmt->fetchColumn()?:0);
            if($studentId<=0)throw new RuntimeException('Student verification failed. Check the school code, student number and identity number.');
            $stmt=$pdo->prepare("INSERT INTO student_guardians (student_id,guardian_id,relationship,is_primary,consent_given_at,consent_version,consent_ip,consent_user_agent) VALUES (?,?,?,0,NOW(),'parent-portal',?,?) ON DUPLICATE KEY UPDATE relationship=VALUES(relationship)");
            $stmt->execute([$studentId,$guardianId,$relationship?:'Guardian',substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]); setFlash('success','Child linked successfully.'); redirect('/parent/children.php');
        }
        if($action==='update'){
            $studentId=(int)($_POST['student_id']??0); if(!parentPortalOwnsLink($pdo,$guardianId,$studentId))throw new RuntimeException('Child link was not found.');
            $relationship=trim((string)($_POST['relationship']??'Guardian')); if($relationship==='')throw new RuntimeException('Relationship is required.');
            $pdo->prepare('UPDATE student_guardians SET relationship=? WHERE student_id=? AND guardian_id=?')->execute([$relationship,$studentId,$guardianId]); setFlash('success','Relationship updated.'); redirect('/parent/children.php');
        }
        if($action==='delete'){
            $studentId=(int)($_POST['student_id']??0); if(!parentPortalOwnsLink($pdo,$guardianId,$studentId))throw new RuntimeException('Child link was not found.');
            $pdo->prepare('DELETE FROM student_guardians WHERE student_id=? AND guardian_id=?')->execute([$studentId,$guardianId]); setFlash('success','Child link removed.'); redirect('/parent/children.php');
        }
    }catch(Throwable $e){setFlash('danger',$e->getMessage()); redirect('/parent/children.php');}
}
$edit=null;$editStudentId=(int)($_GET['edit_student']??0);
if($editStudentId>0&&parentPortalOwnsLink($pdo,$guardianId,$editStudentId)){
    $stmt=$pdo->prepare("SELECT sg.student_id,sg.relationship,st.student_number,st.full_name,s.school_code,s.school_name FROM student_guardians sg JOIN students st ON st.id=sg.student_id JOIN schools s ON s.id=st.school_id WHERE sg.student_id=? AND sg.guardian_id=?");
    $stmt->execute([$editStudentId,$guardianId]); $edit=$stmt->fetch()?:null;
}
$stmt=$pdo->prepare("SELECT sg.student_id,sg.relationship,sg.is_primary,st.id AS student_id,st.student_number,st.full_name,st.class_name,st.membership_status,st.membership_next_due_at,s.school_code,s.school_name FROM student_guardians sg JOIN students st ON st.id=sg.student_id LEFT JOIN schools s ON s.id=st.school_id WHERE sg.guardian_id=? ORDER BY st.full_name");
$stmt->execute([$guardianId]); $rows=$stmt->fetchAll();
$total=count($rows); $active=count(array_filter($rows,fn($r)=>$r['membership_status']==='active'&&!empty($r['membership_next_due_at'])&&strtotime((string)$r['membership_next_due_at'])>=time())); $schools=count(array_unique(array_filter(array_column($rows,'school_code')))); $primary=count(array_filter($rows,fn($r)=>(int)$r['is_primary']===1));
portalPageStart('parent','children','Children');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4"><?php foreach([['Children',$total,'bi-people'],['Active Memberships',$active,'bi-person-check'],['Schools',$schools,'bi-building'],['Primary Links',$primary,'bi-star']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card mb-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=$edit?'Edit Relationship':'Link Child'?></h2></div><div class="card-body">
<?php if($edit):?><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="update"><input type="hidden" name="student_id" value="<?=(int)$edit['student_id']?>">
<div class="col-md-6"><label class="form-label">Student</label><input class="form-control" value="<?=e((string)$edit['student_number'])?> — <?=e((string)$edit['full_name'])?>" disabled></div><div class="col-md-3"><label class="form-label">School</label><input class="form-control" value="<?=e((string)$edit['school_code'])?>" disabled></div><div class="col-md-3"><label class="form-label">Relationship *</label><select class="form-select" name="relationship" required><?php foreach(['Father','Mother','Guardian','Other'] as $r):?><option value="<?=e($r)?>" <?=$edit['relationship']===$r?'selected':''?>><?=e($r)?></option><?php endforeach;?></select></div><div class="col-12"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button><a class="btn btn-outline-secondary ms-2" href="/parent/children.php">Cancel</a></div></form>
<?php else:?><div class="alert alert-info">To link a child securely, enter the exact school code, student number and student identity number.</div><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="create"><div class="col-md-3"><label class="form-label">School Code *</label><input class="form-control text-uppercase" name="school_code" required></div><div class="col-md-3"><label class="form-label">Student Number *</label><input class="form-control" name="student_number" required></div><div class="col-md-3"><label class="form-label">Student Identity Number *</label><input class="form-control" name="identity_number" required></div><div class="col-md-3"><label class="form-label">Relationship *</label><select class="form-select" name="relationship" required><option>Father</option><option>Mother</option><option>Guardian</option><option>Other</option></select></div><div class="col-12"><button class="btn btn-primary"><i class="bi bi-link-45deg me-1"></i>Link Child</button></div></form><?php endif;?>
</div></div>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="childrenTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>School</th><th>Class</th><th>Relationship</th><th>Membership</th><th>Expiry</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><strong><?=e((string)$r['full_name'])?></strong><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)($r['school_code']??''))?><br><span class="small text-secondary"><?=e((string)($r['school_name']??''))?></span></td><td><?=e((string)($r['class_name']??''))?></td><td><?=e((string)$r['relationship'])?><?php if((int)$r['is_primary']===1):?> <span class="badge text-bg-primary">Primary</span><?php endif;?></td><td><?=parentPortalStatusBadge((string)$r['membership_status'])?></td><td><?=e($r['membership_next_due_at']?date('d M Y',strtotime((string)$r['membership_next_due_at'])):'-')?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/parent/children.php?edit_student=<?=(int)$r['student_id']?>"><i class="bi bi-pencil-square"></i></a><form method="post" class="d-inline" onsubmit="return confirm('Remove this child link from your account?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="delete"><input type="hidden" name="student_id" value="<?=(int)$r['student_id']?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<?php parentPortalDataTableScripts('childrenTable', [[0,'asc']], 'No children linked to this account.'); ?>
<?php portalPageEnd(); ?>
