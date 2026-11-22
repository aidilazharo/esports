<?php
require_once 'config.php';
$pageTitle = 'Register';
require 'includes/header.php';
?>
<div class="row justify-content-center">
<div class="col-xl-10">
<div class="card content-card">
<div class="card-body p-4 p-md-5">
<h1 class="h3 mb-2">Register</h1>
<p class="text-secondary mb-4">One person may register separately under different roles. An NRIC / Passport used for one role does not block registration for another role.</p>
<div class="row g-3">
<div class="col-md-4"><a class="card h-100 text-decoration-none" href="register_student.php"><div class="card-body"><i class="bi bi-people fs-2"></i><h2 class="h5 mt-3">Student</h2><p class="text-secondary mb-0">Register as a student.</p></div></a></div>
<div class="col-md-4"><a class="card h-100 text-decoration-none" href="register_teacher.php"><div class="card-body"><i class="bi bi-person-badge fs-2"></i><h2 class="h5 mt-3">Teacher</h2><p class="text-secondary mb-0">Register as a school team teacher.</p></div></a></div>
<div class="col-md-4"><a class="card h-100 text-decoration-none" href="register_parent.php"><div class="card-body"><i class="bi bi-person-hearts fs-2"></i><h2 class="h5 mt-3">Parent / Guardian</h2><p class="text-secondary mb-0">Create an independent parent / guardian account.</p></div></a></div>
</div>
<div class="row g-3 mt-1">
<div class="col-md-6"><a class="btn btn-outline-primary w-100" href="register_school.php"><i class="bi bi-building me-2"></i>Register School</a></div>
<div class="col-md-6"><a class="btn btn-outline-danger w-100" href="register_coach.php"><i class="bi bi-headset me-2"></i>Register Coach</a></div>
</div>
</div>
</div>
</div>
</div>
<?php require 'includes/footer.php'; ?>
