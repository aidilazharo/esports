<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/role_auth.php';
require_once __DIR__.'/../includes/portal_layout.php';
requirePortalRole('teacher');

$teacherId=(int)($_SESSION['teacher_id']??0);
$schoolId=(int)($_SESSION['school_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verifyCsrf();
        $full=trim((string)($_POST['full_name']??''));
        $position=trim((string)($_POST['position']??''));
        $phone=trim((string)($_POST['phone']??''));
        $email=trim((string)($_POST['email']??''));
        if($full==='')throw new RuntimeException('Full name is required.');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Email is invalid.');

        $q=$pdo->prepare('UPDATE teachers SET full_name=?,position=?,phone=?,email=? WHERE id=? AND school_id=?');
        $q->execute([$full,$position?:null,$phone?:null,$email?:null,$teacherId,$schoolId]);

        $_SESSION['teacher_name']=$full;
        $_SESSION['teacher_email']=$email;
        setFlash('success','Teacher profile updated successfully.');
        redirect('/teacher/profile.php');
    }catch(Throwable $e){
        setFlash('danger',$e->getMessage());
        redirect('/teacher/profile.php');
    }
}

$q=$pdo->prepare('SELECT t.*,s.school_name,s.school_code FROM teachers t JOIN schools s ON s.id=t.school_id WHERE t.id=? AND t.school_id=? LIMIT 1');
$q->execute([$teacherId,$schoolId]);$teacher=$q->fetch()?:[];

function teacherProfileScalar(PDO $pdo,string $sql,array $params=[]): int {
    try{$q=$pdo->prepare($sql);$q->execute($params);return(int)$q->fetchColumn();}catch(Throwable $e){return 0;}
}
$studentCount=teacherProfileScalar($pdo,'SELECT COUNT(*) FROM students WHERE school_id=?',[$schoolId]);
$activeCount=teacherProfileScalar($pdo,"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",[$schoolId]);
$attendanceCount=teacherProfileScalar($pdo,"SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? AND a.attendance_month=DATE_FORMAT(CURDATE(),'%Y-%m-01')",[$schoolId]);
$completedModules=teacherProfileScalar($pdo,"SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=? AND p.progress_status='completed'",[$schoolId]);

portalPageStart('teacher','profile','Profile');
?>
<div class="row g-3 mb-4">
<?php foreach([
['School Students',$studentCount,'bi-people'],
['Active Students',$activeCount,'bi-person-check'],
['Attendance This Month',$attendanceCount,'bi-clock-history'],
['Completed Modules',$completedModules,'bi-journal-check']
] as [$l,$v,$i]):?>
<div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div>
<?php endforeach;?>
</div>

<div class="card stat-card"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Edit Teacher Profile</h2></div><div class="card-body">
<form method="post" class="row g-3">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>">
<div class="col-md-4"><label class="form-label">School</label><input class="form-control" value="<?=e((string)($teacher['school_code']??''))?> — <?=e((string)($teacher['school_name']??''))?>" disabled></div>
<div class="col-md-8"><label class="form-label">Full Name *</label><input class="form-control" name="full_name" value="<?=e((string)($teacher['full_name']??''))?>" required></div>
<div class="col-md-4"><label class="form-label">Position</label><input class="form-control" name="position" value="<?=e((string)($teacher['position']??''))?>"></div>
<div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?=e((string)($teacher['phone']??''))?>"></div>
<div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?=e((string)($teacher['email']??''))?>"></div>
<div class="col-md-4"><label class="form-label">Identity Number</label><input class="form-control" value="<?=e((string)($teacher['identity_number']??''))?>" disabled></div>
<div class="col-md-4"><label class="form-label">Registered</label><input class="form-control" value="<?=e((string)($teacher['created_at']??''))?>" disabled></div>
<div class="col-md-4"><label class="form-label">Email Verified</label><input class="form-control" value="<?=!empty($teacher['email_verified_at'])?e((string)$teacher['email_verified_at']):'Pending'?>" disabled></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Profile</button></div>
</form>
</div></div>
<?php portalPageEnd(); ?>
