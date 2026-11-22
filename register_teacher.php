<?php
declare(strict_types=1);

require_once 'config.php';

$verificationView = null;

if (isset($_GET['verify_email'])) {
    require_once __DIR__ . '/registration_contact_verification.php';

    if (staffContactVerificationSchemaReady($pdo, 'teacher')) {
        $verificationView = handleStaffEmailVerification(
            $pdo,
            'teacher',
            (string)$_GET['verify_email']
        );
    } else {
        $verificationView = [
            'success' => false,
            'title' => 'Database update required',
            'message' =>
                'Run add_staff_contact_verification.sql '
                . 'before using email verification.',
        ];
    }
}

$requestedSchoolCode =
    strtoupper(trim((string)($_GET['code'] ?? '')));

$pageTitle = 'School Team Teacher Registration';
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

                <h1 class="h3 mb-3">
                    <?= e($verificationView['title']) ?>
                </h1>

                <p class="text-secondary mb-4">
                    <?= e($verificationView['message']) ?>
                </p>

                <?php if (!empty($verificationView['email'])): ?>
                    <p>
                        <strong>Email:</strong>
                        <?= e($verificationView['email']) ?>
                    </p>
                <?php endif; ?>

                <a class="btn btn-outline-primary" href="register_teacher.php">
                    Back to Registration
                </a>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; exit; ?>
<?php endif; ?>

<style>
#teacherRegistrationForm .form-label {
    margin-bottom: .3rem;
}
#teacherRegistrationForm .form-text {
    font-size: .8rem;
    line-height: 1.3;
}
#teacherRegistrationForm .contents-fieldset {
    display: contents;
}
#teacherRegistrationForm .contents-fieldset:disabled > div {
    opacity: .58;
}
#teacherRegistrationForm .contents-fieldset:disabled input,
#teacherRegistrationForm .contents-fieldset:disabled select,
#teacherRegistrationForm .contents-fieldset:disabled button {
    cursor: not-allowed;
}
#teacherRegistrationErrors {
    white-space: normal;
}
</style>

<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="card content-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h3 mb-1">School Team Teacher Registration</h1>

                <p class="text-secondary mb-3">
                    Register as the teacher responsible for coordinating
                    the school's participation in the programme.
                </p>

                <p class="text-secondary mb-4">
                    Select your school, check NRIC / Passport first,
                    then complete the teacher details.
                </p>

                <div class="alert alert-danger d-none" id="teacherRegistrationErrors"></div>

                <form
                    method="post"
                    class="row g-3"
                    id="teacherRegistrationForm"
                    data-initial-school-code="<?= e($requestedSchoolCode) ?>"
                    novalidate
                >
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="col-lg-5 col-md-6">
                        <label class="form-label" for="schoolSearch">Search School</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input
                                type="search"
                                class="form-control"
                                id="schoolSearch"
                                placeholder="School name or code"
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
                            SEARCH OR SELECT SCHOOL.
                        </div>
                    </div>

                    <div class="col-lg-7 col-md-6">
                        <label class="form-label required" for="schoolId">School</label>
                        <select
                            class="form-select"
                            name="school_id"
                            id="schoolId"
                            required
                        >
                            <option value="">SELECT SCHOOL</option>
                        </select>
                    </div>

                    <div class="col-lg-4 col-md-5">
                        <label class="form-label required" for="teacherIdentityNumber">
                            NRIC / Passport
                        </label>
                        <input
                            class="form-control"
                            id="teacherIdentityNumber"
                            name="identity_number"
                            autocomplete="off"
                            required
                        >
                        <div class="form-text" id="identityNumberStatus">
                            ENTER 4 OR MORE NUMBERS / CHARACTERS TO CHECK.
                        </div>
                    </div>

                    <fieldset
                        id="teacherDetailsFieldset"
                        class="contents-fieldset"
                        disabled
                    >
                        <div class="col-lg-5 col-md-7">
                            <label class="form-label required">
                                School Team Teacher Full Name
                            </label>
                            <input
                                class="form-control"
                                name="full_name"
                                required
                            >
                        </div>

                        <div class="col-lg-3 col-md-5">
                            <label class="form-label">Position</label>
                            <input
                                class="form-control"
                                name="position"
                                placeholder="Teacher Advisor"
                            >
                        </div>

                        <div class="col-lg-5 col-md-7">
                            <label class="form-label required" for="teacherEmail">Email</label>
                            <input
                                type="email"
                                class="form-control"
                                id="teacherEmail"
                                name="email"
                                required
                            >
                            <div class="form-text">
                                You will receive an email verification link after registration.
                            </div>
                        </div>

                        <div class="col-lg-4 col-md-7">
                            <label class="form-label required" for="teacherPhone">Mobile Number (contact only)</label>
                            <input class="form-control" id="teacherPhone" name="phone" placeholder="60123456789" inputmode="tel" required>
                            <div class="form-text">No SMS code is required. Your email address is verified using a link.</div>
                        </div>

                        <div class="col-12">
                            <button
                                class="btn btn-warning"
                                type="submit"
                                id="registerTeacherButton"
                                disabled
                            >
                                <i class="bi bi-person-badge me-2"></i>
                                Register Teacher
                            </button>
                        </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/register_teacher.js"></script>

<?php require 'includes/footer.php'; ?>
