<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('school');
$schoolId=(int)($_SESSION['school_id']??0);

$students=[];
$s=$pdo->prepare('SELECT id,student_number,full_name FROM students WHERE school_id=? ORDER BY full_name');$s->execute([$schoolId]);
foreach($s->fetchAll() as $r)$students[(int)$r['id']]=$r['student_number'].' — '.$r['full_name'];

function schoolAttendanceOwned(PDO $pdo,int $id,int $schoolId): bool{
    $s=$pdo->prepare('SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE a.id=? AND st.school_id=?');$s->execute([$id,$schoolId]);return(int)$s->fetchColumn()===1;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf();$action=(string)($_POST['crud_action']??'');
        if(in_array($action,['create','update'],true)){
            $id=(int)($_POST['id']??0);$studentId=(int)($_POST['student_id']??0);
            if(!isset($students[$studentId]))throw new RuntimeException('Select a student from this school.');
            if($action==='update'&&!schoolAttendanceOwned($pdo,$id,$schoolId))throw new RuntimeException('You do not have access to this attendance record.');
            $checkIn=trim((string)($_POST['check_in_at']??''));$checkOut=trim((string)($_POST['check_out_at']??''));
            $in=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$checkIn);
            $out=$checkOut!==''?DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$checkOut):null;
            if(!$in||$in->format('Y-m-d\\TH:i')!==$checkIn||($checkOut!==''&&(!$out||$out->format('Y-m-d\\TH:i')!==$checkOut||$out<=$in)))throw new RuntimeException('Invalid check-in or check-out.');
            $checkInDb=$in->format('Y-m-d H:i:s');$checkOutDb=$out?$out->format('Y-m-d H:i:s'):null;
            $month=$in->format('Y-m-01');
            $status=(($_POST['status']??'')==='cancelled')?'cancelled':($out?'completed':'open');
            $scan='manual';$notes=trim((string)($_POST['notes']??''));
            $actual=$out?max(1,(int)ceil(($out->getTimestamp()-$in->getTimestamp())/60)):0;
            $pdo->beginTransaction();
            $lock=$pdo->prepare('SELECT id FROM students WHERE id=? AND school_id=? FOR UPDATE');
            $lock->execute([$studentId,$schoolId]);if(!$lock->fetchColumn())throw new RuntimeException('Student unavailable.');
            $dup=$pdo->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE student_id=? AND id<>? AND status<>'cancelled' AND (check_in_at=? OR (?='open' AND status='open'))");
            $dup->execute([$studentId,$id,$checkInDb,$status]);if($status!=='cancelled'&&(int)$dup->fetchColumn())throw new RuntimeException('Duplicate attendance session.');
            $used=$pdo->prepare("SELECT COALESCE(SUM(credited_minutes),0) FROM attendance_sessions WHERE student_id=? AND attendance_month=? AND status='completed' AND id<>?");
            $used->execute([$studentId,$month,$id]);
            $credited=$status==='completed'?min($actual,max(0,MONTHLY_ATTENDANCE_MINUTES-(int)$used->fetchColumn())):0;
            if($action==='create'){
                $q=$pdo->prepare('INSERT INTO attendance_sessions (student_id,attendance_month,check_in_at,check_out_at,actual_minutes,credited_minutes,status,scan_method,notes) VALUES (?,?,?,?,?,?,?,?,?)');
                $q->execute([$studentId,$month,$checkInDb,$checkOutDb,$actual,$credited,$status,$scan,$notes?:null]);
            }else{
                $q=$pdo->prepare('UPDATE attendance_sessions SET student_id=?,attendance_month=?,check_in_at=?,check_out_at=?,actual_minutes=?,credited_minutes=?,status=?,scan_method=?,notes=? WHERE id=?');
                $q->execute([$studentId,$month,$checkInDb,$checkOutDb,$actual,$credited,$status,$scan,$notes?:null,$id]);
            }
            $pdo->commit();
            setFlash('success','Attendance record saved.');redirect('/school/attendance.php');
        }
        if($action==='delete'){
            $id=(int)($_POST['id']??0);if(!schoolAttendanceOwned($pdo,$id,$schoolId))throw new RuntimeException('Attendance record not found.');
            $pdo->prepare('DELETE FROM attendance_sessions WHERE id=?')->execute([$id]);setFlash('success','Attendance record deleted.');redirect('/school/attendance.php');
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('School operation failed: '.$e->getMessage());setFlash('danger','Unable to save the record. Check the details and try again.');redirect('/school/attendance.php');}
}

$edit=null;$editId=(int)($_GET['edit']??0);
if($editId>0&&schoolAttendanceOwned($pdo,$editId,$schoolId)){$q=$pdo->prepare('SELECT * FROM attendance_sessions WHERE id=?');$q->execute([$editId]);$edit=$q->fetch()?:null;}

$q=$pdo->prepare("SELECT a.id,a.student_id,a.attendance_month,a.check_in_at,a.check_out_at,a.actual_minutes,a.credited_minutes,a.status,a.scan_method,st.student_number,st.full_name
FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? ORDER BY a.check_in_at DESC");$q->execute([$schoolId]);$rows=$q->fetchAll();

$total=count($rows);$completed=0;$open=0;$minutes=0;foreach($rows as $r){if($r['status']==='completed')$completed++;if($r['status']==='open')$open++;$minutes+=(int)$r['credited_minutes'];}
portalPageStart('school','attendance','Attendance');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
<div class="row g-3 mb-4">
<?php foreach([['Sessions',$total,'bi-clock-history'],['Completed',$completed,'bi-check2-circle'],['Open',$open,'bi-stopwatch'],['Credited Hours',number_format($minutes/60,1),'bi-hourglass-split']] as [$l,$v,$i]):?>
<div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=e((string)$v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?>
</div>
<div class="d-flex justify-content-end mb-3" data-mobile-icon-links="off"><?php if(!$edit&&!isset($_GET['add'])):?><a class="btn btn-primary btn-sm" href="/school/attendance.php?add=1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Add Attendance</a><?php endif;?></div>
<?php if($edit||isset($_GET['add'])):?><div class="card stat-card mb-4"><div class="card-header bg-white py-3"><h2 class="h5 mb-0"><?=$edit?'Edit Attendance':'Add Attendance'?></h2></div><div class="card-body">
<form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="<?=$edit?'update':'create'?>"><?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<div class="col-md-6"><label class="form-label">Student *</label><select class="form-select" name="student_id" required><option value="">Select</option><?php foreach($students as $id=>$label):?><option value="<?=$id?>" <?=((int)($edit['student_id']??0)===$id?'selected':'')?>><?=e($label)?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Status *</label><select class="form-select" name="status"><option value="open" <?=($edit['status']??'open')==='open'?'selected':''?>>Open</option><option value="completed" <?=($edit['status']??'')==='completed'?'selected':''?>>Completed (requires check-out)</option><option value="cancelled" <?=($edit['status']??'')==='cancelled'?'selected':''?>>Cancelled</option></select></div>
<div class="col-md-4"><label class="form-label">Check In *</label><input type="datetime-local" class="form-control" name="check_in_at" value="<?=e(!empty($edit['check_in_at'])?date('Y-m-d\TH:i',strtotime((string)$edit['check_in_at'])):date('Y-m-d\TH:i'))?>" required></div>
<div class="col-md-4"><label class="form-label">Check Out</label><input type="datetime-local" class="form-control" name="check_out_at" value="<?=e(!empty($edit['check_out_at'])?date('Y-m-d\TH:i',strtotime((string)$edit['check_out_at'])):'')?>"></div>
<div class="col-md-8"><label class="form-label">Notes</label><input class="form-control" name="notes" value="<?=e((string)($edit['notes']??''))?>"></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi <?=$edit?'bi-save':'bi-plus-circle'?> me-1"></i><?=$edit?'Save Changes':'Add Attendance'?></button><?php if($edit||isset($_GET['add'])):?><a href="/school/attendance.php" class="btn btn-outline-secondary ms-2">Cancel</a><?php endif;?></div>
</form></div></div>

<?php endif;?>
<div class="card stat-card"><div class="card-body"><div class="table-responsive"><table id="attendanceTable" class="table table-hover align-middle w-100"><thead><tr><th>Student</th><th>Month</th><th>Check In</th><th>Check Out</th><th>Credited</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><strong><?=e((string)$r['full_name'])?></strong><br><span class="small text-secondary"><?=e((string)$r['student_number'])?></span></td><td><?=e((string)$r['attendance_month'])?></td><td><?=e((string)$r['check_in_at'])?></td><td><?=e((string)($r['check_out_at']??'-'))?></td><td><?=(int)$r['credited_minutes']?> min</td><td><?=e(ucfirst((string)$r['status']))?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/school/attendance.php?edit=<?=(int)$r['id']?>" aria-label="Edit record"><i class="bi bi-pencil-square" aria-hidden="true"></i></a> <form method="post" class="d-inline" onsubmit="return confirm('Delete this attendance record?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="crud_action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete record"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach;?>
</tbody></table></div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script><script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
<script>document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery&&jQuery.fn.DataTable)$('#attendanceTable').DataTable({pageLength:10,lengthChange:false,searching:true,ordering:true,info:true,order:[[2,'desc']],columnDefs:[{orderable:false,searchable:false,targets:-1}],language:{emptyTable:'No attendance records.'}});});</script>
<?php portalPageEnd(); ?>
