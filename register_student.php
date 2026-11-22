<?php
declare(strict_types=1);

require_once 'config.php';

$verificationView = null;
if (isset($_GET['verify'])) {
    require_once 'student_guardian_verification.php';
    $verificationView = handleStudentGuardianVerification(
        $pdo,
        (string)$_GET['verify']
    );
}

$requestedSchoolCode = strtoupper(trim((string)($_GET['code'] ?? '')));
$pageTitle = 'Student Registration';
require 'includes/header.php';
?>

<?php if ($verificationView !== null): ?>
<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="card content-card">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="display-6 <?= $verificationView['success'] ? 'text-success' : 'text-danger' ?> mb-3">
                    <i class="bi <?= $verificationView['success'] ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
                </div>

                <h1 class="h3 mb-3"><?= e($verificationView['title']) ?></h1>
                <p class="text-secondary mb-4"><?= e($verificationView['message']) ?></p>

                <?php if (!empty($verificationView['student_number'])): ?>
                    <p class="mb-2">
                        <strong>Student Account:</strong>
                        <?= e($verificationView['student_number']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($verificationView['guardian_number'])): ?>
                    <p class="mb-4">
                        <strong>Parent / Guardian Account:</strong>
                        <?= e($verificationView['guardian_number']) ?>
                    </p>
                <?php endif; ?>

                <?php if ($verificationView['success'] && !empty($verificationView['student_id'])): ?>
                    <a
                        class="btn btn-success"
                        href="student_payment.php?student_id=<?= (int)$verificationView['student_id'] ?>"
                    >
                        <i class="bi bi-credit-card me-2"></i>Continue to RM100 Membership
                    </a>
                <?php else: ?>
                    <a class="btn btn-outline-primary" href="register_student.php">
                        Back to Registration
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; exit; ?>
<?php endif; ?>

<style>
#studentRegistrationForm .form-label {
    margin-bottom: .3rem;
}
#studentRegistrationForm .form-text {
    font-size: .78rem;
    line-height: 1.3;
}
#studentRegistrationForm .registration-section {
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    padding: .9rem;
    margin-bottom: 1rem;
    background: var(--bs-body-bg);
}
#studentRegistrationForm .section-title {
    margin-bottom: .15rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid rgba(0,0,0,.12);
}
#studentRegistrationForm .compact-gate {
    display: contents;
}
#studentRegistrationForm .compact-gate:disabled > div {
    opacity: .58;
}
#studentRegistrationForm .compact-gate:disabled input,
#studentRegistrationForm .compact-gate:disabled select,
#studentRegistrationForm .compact-gate:disabled button,
#studentRegistrationForm .compact-gate:disabled textarea {
    cursor: not-allowed;
}
#studentRegistrationForm .row {
    --bs-gutter-y: .7rem;
}
#studentRegistrationErrors {
    white-space: normal;
}
@media (max-width: 991.98px) {
    #studentRegistrationForm .registration-section {
        padding: .8rem;
    }
}
</style>

<div class="row justify-content-center">
    <div class="col-xl-10">
        <div class="card content-card mb-4">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                    <div>
                        <h1 class="h3 mb-1">Student Registration</h1>
                        <p class="text-secondary mb-0">
                            RM100 payment or valid coupon · membership valid for 1 year
                        </p>
                    </div>

                    <div class="text-lg-end">
                        <span class="badge text-bg-primary fs-6">
                            RM100 / 1 Year · Coupon Accepted
                        </span>
                    </div>
                </div>

                <div class="alert alert-danger d-none" id="studentRegistrationErrors"></div>

                <form
                    method="post"
                    id="studentRegistrationForm"
                    class="registration-uppercase"
                    data-initial-school-code="<?= e($requestedSchoolCode) ?>"
                    novalidate
                >
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="registration-section student-section">
                        <div class="row g-2 g-lg-3 align-items-start">
                            <div class="col-12">
                                <h2 class="h5 section-title">Student Information</h2>
                            </div>

                            <div class="col-xl-4 col-lg-4 col-md-6">
                                <label class="form-label required" for="studentIdentityNumber">
                                    Student NRIC / Passport
                                </label>
                                <input
                                    class="form-control"
                                    id="studentIdentityNumber"
                                    name="identity_number"
                                    autocomplete="off"
                                    required
                                >
                                <div class="form-text" id="studentIdentityStatus">
                                    ENTER AT LEAST 4 NUMBERS / CHARACTERS.
                                </div>
                            </div>

                            <fieldset id="studentDetailsFieldset" class="compact-gate" disabled>
                                <div class="col-xl-5 col-lg-5 col-md-6">
                                    <label class="form-label required">Full Name</label>
                                    <input class="form-control" name="full_name" required>
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-4">
                                    <label class="form-label">Student ID</label>
                                    <input
                                        class="form-control fw-semibold"
                                        id="studentNumberPreview"
                                        placeholder="SELECT SCHOOL"
                                        readonly
                                    >
                                </div>

                                <div class="col-xl-4 col-lg-4 col-md-6">
                                    <label class="form-label" for="schoolSearch">Search School</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bi bi-search"></i>
                                        </span>
                                        <input
                                            type="search"
                                            class="form-control"
                                            id="schoolSearch"
                                            placeholder="SCHOOL NAME OR CODE"
                                            autocomplete="off"
                                        >
                                        <button
                                            class="btn btn-outline-secondary"
                                            type="button"
                                            id="clearSchoolSearch"
                                            title="Clear school search"
                                        >
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div class="form-text" id="schoolSearchResult">
                                        SCHOOL LIST LOADS AFTER STUDENT NRIC CHECK.
                                    </div>
                                </div>

                                <div class="col-xl-4 col-lg-4 col-md-6">
                                    <label class="form-label required" for="schoolId">School</label>
                                    <select class="form-select" name="school_id" id="schoolId" required>
                                        <option value="">SELECT SCHOOL</option>
                                    </select>
                                </div>

                                <div class="col-xl-4 col-lg-4 col-md-6">
                                    <label class="form-label required" for="coachingTeacherId">
                                        Coaching Teacher
                                    </label>
                                    <select
                                        class="form-select"
                                        name="coaching_teacher_id"
                                        id="coachingTeacherId"
                                        required
                                        disabled
                                    >
                                        <option value="">SELECT COACHING TEACHER</option>
                                    </select>
                                    <div class="form-text" id="coachingTeacherStatus">
                                        SELECT SCHOOL FIRST. COACHING TEACHER IS REQUIRED.
                                    </div>
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-4">
                                    <label class="form-label required">Gender</label>
                                    <select class="form-select" name="gender" required>
                                        <option value="">SELECT</option>
                                        <option value="Male">MALE</option>
                                        <option value="Female">FEMALE</option>
                                    </select>
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-4">
                                    <label class="form-label required">Ethnicity</label>
                                    <select class="form-select" name="ethnicity" required>
                                        <option value="">SELECT</option>
                                        <option value="Malay">MALAY</option>
                                        <option value="Chinese">CHINESE</option>
                                        <option value="Indian">INDIAN</option>
                                        <option value="Orang Asli">ORANG ASLI</option>
                                        <option value="Other">OTHER</option>
                                    </select>
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-4">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" name="date_of_birth">
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-4">
                                    <label class="form-label">Class / Form</label>
                                    <input
                                        class="form-control"
                                        name="class_name"
                                        placeholder="FORM 4 AMANAH"
                                    >
                                </div>

                                <div class="col-xl-8 col-lg-8 col-md-8">
                                    <label class="form-label required">Jersey Name</label>
                                    <input
                                        class="form-control"
                                        name="jersey_name"
                                        maxlength="30"
                                        placeholder="AIDIL"
                                        required
                                    >
                                </div>

                                <div class="col-xl-4 col-lg-4 col-md-4">
                                    <label class="form-label required text-nowrap">Jersey No.</label>
                                    <input
                                        class="form-control text-center"
                                        name="jersey_number"
                                        inputmode="numeric"
                                        maxlength="2"
                                        pattern="[0-9]{1,2}"
                                        placeholder="07"
                                        required
                                    >
                                </div>
                            </fieldset>
                        </div>
                    </div>

                    <div class="registration-section guardian-section">
                        <div class="row g-2 g-lg-3 align-items-start">
                            <div class="col-12">
                                <h2 class="h5 section-title">Parent / Guardian Information</h2>
                            </div>

                            <div class="col-xl-4 col-lg-4 col-md-6">
                                <label class="form-label required" for="guardianIdentityNumber">
                                    Parent / Guardian NRIC / Passport
                                </label>
                                <input
                                    class="form-control"
                                    id="guardianIdentityNumber"
                                    name="guardian_identity_number"
                                    autocomplete="off"
                                    disabled
                                    required
                                >
                                <div class="form-text" id="guardianIdentityStatus">
                                    COMPLETE THE STUDENT NRIC CHECK FIRST.
                                </div>
                            </div>

                            <fieldset id="guardianDetailsFieldset" class="compact-gate" disabled>
                                <div class="col-xl-5 col-lg-5 col-md-6">
                                    <label class="form-label required">
                                        Parent / Guardian Full Name
                                    </label>
                                    <input
                                        class="form-control"
                                        id="guardianFullName"
                                        name="guardian_full_name"
                                        required
                                    >
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-4">
                                    <label class="form-label required">Relationship</label>
                                    <select
                                        class="form-select"
                                        id="guardianRelationship"
                                        name="guardian_relationship"
                                        required
                                    >
                                        <option value="">SELECT</option>
                                        <option value="Mother">MOTHER</option>
                                        <option value="Father">FATHER</option>
                                        <option value="Legal Guardian">LEGAL GUARDIAN</option>
                                        <option value="Other">OTHER</option>
                                    </select>
                                </div>

                                <div class="col-12 d-none" id="existingGuardianNotice">
                                    <div class="alert alert-info py-2 px-3 mb-0 small">
                                        <i class="bi bi-person-check me-1"></i>
                                        <span id="existingGuardianNoticeText">
                                            EXISTING PARENT ACCOUNT FOUND.
                                        </span>
                                    </div>
                                </div>

                                <div class="col-xl-5 col-lg-5 col-md-6" id="guardianEmailGroup">
                                    <label class="form-label required" for="guardianEmail">
                                        Email
                                    </label>
                                    <input
                                        type="email"
                                        class="form-control"
                                        id="guardianEmail"
                                        name="guardian_email"
                                        required
                                    >
                                    <div class="form-text">
                                        FOR RECEIPTS, BILLING AND PROGRAMME COMMUNICATION.
                                    </div>
                                </div>

                                <div class="col-xl-7 col-lg-7 col-md-6" id="guardianPhoneGroup">
                                    <label class="form-label required" for="guardianPhone">Mobile Number (contact only)</label>
                                    <input class="form-control" id="guardianPhone" name="guardian_phone" placeholder="60123456789" inputmode="tel" required>
                                    <div class="form-text">An email link verifies the student and guardian account. No phone verification code is required.</div>
                                </div>

                                <div class="col-12 d-none" id="guardianPhoneVerifiedNotice">
                                    <div class="alert alert-success py-2 px-3 mb-0">
                                        <i class="bi bi-telephone-check me-2"></i>
                                        AN EXISTING CONTACT NUMBER IS ON FILE FOR THIS PARENT / GUARDIAN.
                                        THE PHONE FIELD IS NOT REQUIRED AGAIN.
                                    </div>
                                </div>

                                <div class="col-12 d-none" id="guardianEmailVerifiedNotice">
                                    <div class="alert alert-success py-2 px-3 mb-0">
                                        <i class="bi bi-envelope-check me-2"></i>
                                        THE EXISTING PARENT / GUARDIAN EMAIL IS ALREADY
                                        VERIFIED. THE EMAIL FIELD IS HIDDEN.
                                    </div>
                                </div>

                            </fieldset>
                        </div>
                    </div>

                    <div class="registration-section membership-section">
                        <div class="row g-2 g-lg-3 align-items-stretch">
                            <div class="col-12">
                                <h2 class="h5 section-title">Membership & Consent</h2>
                            </div>

                            <div class="col-lg-6">
                                <label class="form-label fw-semibold" for="couponCode">
                                    <i class="bi bi-ticket-perforated me-1"></i>
                                    Membership Coupon Code (Optional)
                                </label>
                                <div class="input-group">
                                    <input
                                        class="form-control"
                                        id="couponCode"
                                        name="coupon_code"
                                        maxlength="64"
                                        placeholder="SCHOOL2026"
                                        autocomplete="off"
                                    >
                                    <button
                                        class="btn btn-outline-success"
                                        type="button"
                                        id="validateCouponButton"
                                    >
                                        <i class="bi bi-patch-check me-1"></i>Validate
                                    </button>
                                </div>
                                <div class="form-text" id="couponValidationStatus">
                                    VALID COUPON = RM0 PAYMENT + 1-YEAR MEMBERSHIP.
                                    LEAVE BLANK FOR RM100 PAYMENT.
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="card bg-light border h-100">
                                    <div class="card-body py-3">
                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                value="1"
                                                name="guardian_consent"
                                                id="guardianConsent"
                                                required
                                            >
                                            <label
                                                class="form-check-label"
                                                for="guardianConsent"
                                            >
                                                I CONFIRM THAT I AM THE PARENT / LEGAL
                                                GUARDIAN AND CONSENT TO THIS STUDENT'S
                                                PROGRAMME PARTICIPATION AND RELATED
                                                ADMINISTRATION.
                                            </label>
                                        </div>
                                        <div class="small text-secondary mt-2">
                                            CONSENT VERSION: 2026-08-09
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <button
                                    class="btn btn-success"
                                    type="submit"
                                    id="registerSubmitButton"
                                    disabled
                                >
                                    <i class="bi bi-check2-circle me-2"></i>
                                    <span id="registerSubmitText">
                                        Register & Continue to Payment
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/register_student.js?v=20260927-4"></script>

<?php require 'includes/footer.php'; ?>
