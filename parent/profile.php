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
        verifyCsrf();
        $name=trim((string)($_POST['full_name']??'')); $phone=trim((string)($_POST['phone']??'')); $email=trim((string)($_POST['email']??''));
        if($name==='')throw new RuntimeException('Full name is required.');
        if($phone==='')throw new RuntimeException('Phone is required.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('A valid email is required.');
        $stmt=$pdo->prepare('UPDATE guardians SET full_name=?,phone=?,email=? WHERE id=?'); $stmt->execute([$name,$phone,$email,$guardianId]);
        $_SESSION['parent_name']=$name; $_SESSION['parent_email']=$email;
        setFlash('success','Parent / guardian profile updated.'); redirect('/parent/profile.php');
    }catch(Throwable $e){setFlash('danger',$e->getMessage()); redirect('/parent/profile.php');}
}
$stmt=$pdo->prepare('SELECT * FROM guardians WHERE id=? LIMIT 1'); $stmt->execute([$guardianId]); $parent=$stmt->fetch()?:[];
$childCount=(int)parentPortalScalar($pdo,'SELECT COUNT(DISTINCT student_id) FROM student_guardians WHERE guardian_id=?',[$guardianId]);
$activeCount=(int)parentPortalScalar($pdo,"SELECT COUNT(*) FROM student_guardians sg JOIN students st ON st.id=sg.student_id WHERE sg.guardian_id=? AND st.membership_status='active' AND st.membership_next_due_at>=NOW()",[$guardianId]);
$paymentCount=(int)parentPortalScalar($pdo,'SELECT COUNT(*) FROM payments p JOIN student_guardians sg ON sg.student_id=p.student_id WHERE sg.guardian_id=?',[$guardianId]);
$certificateCount=(int)parentPortalScalar($pdo,'SELECT COUNT(*) FROM issued_certificates ic JOIN student_guardians sg ON sg.student_id=ic.student_id WHERE sg.guardian_id=?',[$guardianId]);
portalPageStart('parent','profile','Profile');
?>
<div class="row g-3 mb-4"><?php foreach([['Children',$childCount,'bi-people'],['Active Memberships',$activeCount,'bi-card-checklist'],['Payments',$paymentCount,'bi-credit-card'],['Certificates',$certificateCount,'bi-award']] as [$l,$v,$i]):?><div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex justify-content-between"><div><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?=number_format($v)?></div></div><i class="bi <?=e($i)?> fs-2 text-primary"></i></div></div></div><?php endforeach;?></div>
<div class="card stat-card"><div class="card-header bg-white py-3"><h2 class="h5 mb-0">Edit Parent / Guardian Profile</h2></div><div class="card-body"><form method="post" class="row g-3">
<input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>">
<div class="col-md-4"><label class="form-label">Guardian Number</label><input class="form-control" value="<?=e((string)($parent['guardian_number']??''))?>" disabled></div>
<div class="col-md-8"><label class="form-label">Full Name *</label><input class="form-control" name="full_name" value="<?=e((string)($parent['full_name']??''))?>" required></div>
<div class="col-md-4"><label class="form-label">Identity Number</label><input class="form-control" value="<?=e((string)($parent['identity_number']??''))?>" disabled></div>
<div class="col-md-4"><label class="form-label">Phone *</label><input class="form-control" name="phone" value="<?=e((string)($parent['phone']??''))?>" required></div>
<div class="col-md-4"><label class="form-label">Email *</label><input type="email" class="form-control" name="email" value="<?=e((string)($parent['email']??''))?>" required></div>
<div class="col-md-6"><label class="form-label">Email Verified</label><input class="form-control" value="<?=!empty($parent['email_verified_at'])?e((string)$parent['email_verified_at']):'Pending'?>" disabled></div>
<div class="col-md-6"><label class="form-label">Phone Verified</label><input class="form-control" value="<?=!empty($parent['phone_verified_at'])?e((string)$parent['phone_verified_at']):'Pending'?>" disabled></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Profile</button></div>
</form></div></div>
<?php portalPageEnd(); ?>
