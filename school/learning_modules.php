<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('school');
$schoolId=(int)($_SESSION['school_id']??0);

$students=[];$modules=[];
$q=$pdo->prepare('SELECT id,student_number,full_name FROM students WHERE school_id=? ORDER BY full_name');$q->execute([$schoolId]);foreach($q->fetchAll() as $r)$students[(int)$r['id']]=$r['student_number'].' — '.$r['full_name'];
foreach($pdo->query('SELECT id,module_code,module_name FROM learning_modules ORDER BY sequence_no')->fetchAll() as $r)$modules[(int)$r['id']]=$r['module_code'].' — '.$r['module_name'];

function schoolProgressOwned(PDO $pdo,int $id,int $schoolId): bool{$q=$pdo->prepare('SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE p.id=? AND st.school_id=?');$q->execute([$id,$schoolId]);return(int)$q->fetchColumn()===1;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf();$action=(string)($_POST['crud_action']??'');
        if(in_array($action,['create','update'],true)){
            $id=(int)($_POST['id']??0);$studentId=(int)($_POST['student_id']??0);$moduleId=(int)($_POST['module_id']??0);
            if(!isset($students[$studentId])||!isset($modules[$moduleId]))throw new RuntimeException('Select a valid student and module.');
            if($action==='update'&&!schoolProgressOwned($pdo,$id,$schoolId))throw new RuntimeException('Progress record not found.');
            $status=trim((string)($_POST['progress_status']??'not_started'));
            $game=$_POST['gameplay_score']!==''?(float)$_POST['gameplay_score']:null;$quiz=$_POST['quiz_score']!==''?(float)$_POST['quiz_score']:null;$dev=$_POST['development_score']!==''?(float)$_POST['development_score']:null;$part=$_POST['participation_score']!==''?(float)$_POST['participation_score']:null;
            $scores=array_values(array_filter([$game,$quiz,$dev,$part],fn($v)=>$v!==null));$total=$scores?array_sum($scores)/count($scores):null;
            $attended=trim((string)($_POST['attended_on']??''))?:null;$notes=trim((string)($_POST['notes']??''))?:null;
            $started=$status==='not_started'?null:date('Y-m-d H:i:s');$completed=$status==='completed'?date('Y-m-d H:i:s'):null;
            if($action==='create'){
                $sql="INSERT INTO student_module_progress (student_id,module_id,progress_status,gameplay_score,quiz_score,development_score,participation_score,total_score,attended_on,notes,started_at,completed_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE progress_status=VALUES(progress_status),gameplay_score=VALUES(gameplay_score),quiz_score=VALUES(quiz_score),development_score=VALUES(development_score),participation_score=VALUES(participation_score),total_score=VALUES(total_score),attended_on=VALUES(attended_on),notes=VALUES(notes),started_at=VALUES(started_at),completed_at=VALUES(completed_at)";
                $pdo->prepare($sql)->execute([$studentId,$moduleId,$status,$game,$quiz,$dev,$part,$total,$attended,$notes,$started,$completed]);
            }else{
                $pdo->prepare('UPDATE student_module_progress SET student_id=?,module_id=?,progress_status=?,gameplay_score=?,quiz_score=?,development_score=?,participation_score=?,total_score=?,attended_on=?,notes=?,started_at=?,completed_at=? WHERE id=?')
                ->execute([$studentId,$moduleId,$status,$game,$quiz,$dev,$part,$total,$attended,$notes,$started,$completed,$id]);
            }
            setFlash('success','Learning progress saved.');redirect('/school/learning_modules.php');
        }
        if($action==='delete'){
            $id=(int)($_POST['id']??0);if(!schoolProgressOwned($pdo,$id,$schoolId))throw new RuntimeException('Progress record not found.');
            $pdo->prepare('DELETE FROM student_module_progress WHERE id=?')->execute([$id]);setFlash('success','Learning progress deleted.');redirect('/school/learning_modules.php');
        }
    }catch(Throwable $e){setFlash('danger',$e->getMessage());redirect('/school/learning_modules.php');}
}
$edit=null;$editId=(int)($_GET['edit']??0);if($editId>0&&schoolProgressOwned($pdo,$editId,$schoolId)){$q=$pdo->prepare('SELECT * FROM student_module_progress WHERE id=?');$q->execute([$editId]);$edit=$q->fetch()?:null;}
$q=$pdo->prepare("SELECT p.id,p.student_id,p.module_id,p.progress_status,p.total_score,p.attended_on,p.gameplay_score,p.quiz_score,p.development_score,p.participation_score,p.notes,
st.student_number,st.full_name,lm.module_code,lm.module_name
FROM student_module_progress p JOIN students st ON st.id=p.student_id JOIN learning_modules lm ON lm.id=p.module_id WHERE st.school_id=? ORDER BY lm.sequence_no,st.full_name");$q->execute([$schoolId]);$rows=$q->fetchAll();

$total=count($rows);$completed=0;$inProgress=0;$sum=0;$scoreCount=0;foreach($rows as $r){if($r['progress_status']==='completed')$completed++;elseif($r['progress_status']==='in_progress')$inProgress++;if($r['total_score']!==null){$sum+=(float)$r['total_score'];$scoreCount++;}}
portalPageStart('school','learning_modules','Learning Modules');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4">
<?php foreach([['Progress Records',$total,'bi-journal-text'],['Completed',$completed,'bi-check2-circle'],['In Progress',$inProgress,'bi-play-circle'],['Average Score',$scoreCount?number_format($sum/$scoreCount,1).'%':'-','bi-graph-up']] as [$l,$v,$i]):?>
<div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=e((string)$v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?>
</div>

<div class="card stat-card mb-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=$edit?'Edit Learning Progress':'Add Learning Progress'?></h2></div><div class="card-body"><form method="post" class="row g-3">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="<?=$edit?'update':'create'?>"><?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<div class="col-md-5"><label class="form-label">Student *</label><select class="form-select" name="student_id" required><option value="">Select</option><?php foreach($students as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['student_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-5"><label class="form-label">Module *</label><select class="form-select" name="module_id" required><option value="">Select</option><?php foreach($modules as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['module_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="progress_status"><option value="not_started" <?=($edit['progress_status']??'not_started')==='not_started'?'selected':''?>>Not Started</option><option value="in_progress" <?=($edit['progress_status']??'')==='in_progress'?'selected':''?>>In Progress</option><option value="completed" <?=($edit['progress_status']??'')==='completed'?'selected':''?>>Completed</option></select></div>
<?php foreach([['gameplay_score','Gameplay'],['quiz_score','Quiz'],['development_score','Development'],['participation_score','Participation']] as [$n,$l]):?><div class="col-md-3"><label class="form-label"><?=$l?> Score</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="<?=$n?>" value="<?=e((string)($edit[$n]??''))?>"></div><?php endforeach;?>
<div class="col-md-3"><label class="form-label">Attended On</label><input type="date" class="form-control" name="attended_on" value="<?=e((string)($edit['attended_on']??''))?>"></div>
<div class="col-md-9"><label class="form-label">Notes</label><input class="form-control" name="notes" value="<?=e((string)($edit['notes']??''))?>"></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi <?=$edit?'bi-save':'bi-plus-circle'?> me-1"></i><?=$edit?'Save Changes':'Save Progress'?></button><?php if($edit):?><a class="btn btn-outline-secondary ms-2" href="/school/learning_modules.php">Cancel</a><?php endif;?></div>
</form></div></div>

<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="learningTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>Module</th><th>Status</th><th>Total</th><th>Attended</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=e((string)$r['full_name'])?><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)$r['module_code'])?> — <?=e((string)$r['module_name'])?></td><td><?=e(ucwords(str_replace('_',' ',(string)$r['progress_status'])))?></td><td><?=e($r['total_score']!==null?number_format((float)$r['total_score'],2).'%':'-')?></td><td><?=e((string)($r['attended_on']??'-'))?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/school/learning_modules.php?edit=<?=(int)$r['id']?>"><i class="bi bi-pencil-square"></i></a> <form method="post" class="d-inline" onsubmit="return confirm('Delete this progress record?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery&&jQuery.fn.DataTable)$('#learningTable').DataTable({pageLength:10,lengthChange:false,searching:true,ordering:true,info:true,columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No learning progress records.'}});});</script>
<?php portalPageEnd(); ?>
