<?php
declare(strict_types=1);

require_once 'config.php';

$requestedCode = strtoupper(trim((string)($_GET['code'] ?? '')));
$requestedCode = substr($requestedCode, 0, 30);

$pageTitle = 'School Registration';
require 'includes/header.php';
?>

<style>
#schoolRegistrationFields {
    display: contents;
}
#schoolRegistrationFields > [class*="col-"] {
    flex-shrink: 0;
    max-width: 100%;
    padding-right: calc(var(--bs-gutter-x) * .5);
    padding-left: calc(var(--bs-gutter-x) * .5);
    margin-top: var(--bs-gutter-y);
}
#schoolRegistrationFields:disabled > [class*="col-"] {
    opacity: .58;
}
#schoolRegistrationFields:disabled input,
#schoolRegistrationFields:disabled select,
#schoolRegistrationFields:disabled textarea,
#schoolRegistrationFields:disabled button {
    cursor: not-allowed;
}
#schoolRegistrationForm textarea {
    min-height: 88px;
}
#schoolRegistrationErrors {
    white-space: normal;
}
@media (max-width: 767.98px) {
    #schoolRegistrationFields > [class*="col-"] {
        width: 100%;
    }
}
</style>

<div class="row justify-content-center">
    <div class="col-xl-9">
        <div class="card content-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h3 mb-1">School Registration</h1>
                <p class="text-secondary mb-4">
                    Enter the official school code. Existing school information
                    will be searched automatically and filled into the form.
                </p>

                <div class="alert alert-danger d-none" id="schoolRegistrationErrors"></div>

                <div class="alert alert-warning d-none" id="schoolRegisteredNotice">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div>
                            <div class="fw-semibold">
                                <i class="bi bi-check-circle me-2"></i>
                                School already registered
                            </div>
                            <div id="schoolRegisteredMessage"></div>
                        </div>
                        <a class="btn btn-primary flex-shrink-0" href="login.php">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                        </a>
                    </div>
                </div>

                <div class="alert alert-info d-none" id="existingSchoolNotice">
                    <i class="bi bi-building-check me-2"></i>
                    Existing school information was found and filled automatically.
                    Complete all required fields to finish registration.
                </div>

                <form
                    method="post"
                    enctype="multipart/form-data"
                    id="schoolRegistrationForm"
                    class="row g-3"
                    data-initial-code="<?= e($requestedCode) ?>"
                    novalidate
                >
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="col-md-3">
                        <label class="form-label required" for="schoolCode">School Code</label>
                        <input
                            type="text"
                            class="form-control text-uppercase"
                            id="schoolCode"
                            name="school_code"
                            maxlength="30"
                            value="<?= e($requestedCode) ?>"
                            placeholder="EXAMPLE: CEA4088"
                            autocomplete="off"
                            required
                        >
                        <div class="form-text" id="schoolLookupStatus">
                            ENTER SCHOOL CODE TO UNLOCK THE REGISTRATION FIELDS.
                        </div>
                    </div>

                    <fieldset
                        id="schoolRegistrationFields"
                        class="border-0 p-0 m-0"
                        disabled
                    >
                        <div class="col-md-6">
                            <label class="form-label required" for="schoolName">School Name</label>
                            <input
                                type="text"
                                class="form-control text-uppercase"
                                id="schoolName"
                                name="school_name"
                                maxlength="200"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required" for="schoolType">School Type</label>
                            <select
                                class="form-select text-uppercase"
                                id="schoolType"
                                name="school_type"
                                required
                            >
                                <option value="">SELECT TYPE</option>
                                <option value="SK">SK</option>
                                <option value="SJK(C)">SJK(C)</option>
                                <option value="SJK(T)">SJK(T)</option>
                                <option value="SMK">SMK</option>
                                <option value="SMKA">SMKA</option>
                                <option value="SBP">SBP</option>
                                <option value="MRSM">MRSM</option>
                                <option value="KV">KV</option>
                                <option value="KOLEJ TINGKATAN ENAM">KOLEJ TINGKATAN ENAM</option>
                                <option value="PRIMARY SCHOOL">PRIMARY SCHOOL</option>
                                <option value="SECONDARY SCHOOL">SECONDARY SCHOOL</option>
                                <option value="VOCATIONAL COLLEGE">VOCATIONAL COLLEGE</option>
                                <option value="COMMUNITY COLLEGE">COMMUNITY COLLEGE</option>
                                <option value="PRIVATE SCHOOL">PRIVATE SCHOOL</option>
                                <option value="OTHER">OTHER</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required" for="state">State</label>
                            <select
                                class="form-select text-uppercase"
                                id="state"
                                name="state"
                                required
                            >
                                <option value="">SELECT STATE</option>
                                <option value="JOHOR">JOHOR</option>
                                <option value="KEDAH">KEDAH</option>
                                <option value="KELANTAN">KELANTAN</option>
                                <option value="MELAKA">MELAKA</option>
                                <option value="NEGERI SEMBILAN">NEGERI SEMBILAN</option>
                                <option value="PAHANG">PAHANG</option>
                                <option value="PERAK">PERAK</option>
                                <option value="PERLIS">PERLIS</option>
                                <option value="PULAU PINANG">PULAU PINANG</option>
                                <option value="SABAH">SABAH</option>
                                <option value="SARAWAK">SARAWAK</option>
                                <option value="SELANGOR">SELANGOR</option>
                                <option value="TERENGGANU">TERENGGANU</option>
                                <option value="KUALA LUMPUR">KUALA LUMPUR</option>
                                <option value="LABUAN">LABUAN</option>
                                <option value="PUTRAJAYA">PUTRAJAYA</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required" for="district">District</label>
                            <input
                                type="text"
                                class="form-control text-uppercase"
                                id="district"
                                name="district"
                                maxlength="100"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required" for="totalStudents">
                                Total Students
                            </label>
                            <input
                                type="number"
                                class="form-control"
                                id="totalStudents"
                                name="total_students"
                                min="1"
                                max="99999"
                                step="1"
                                inputmode="numeric"
                                placeholder="EXAMPLE: 850"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required d-block">
                                School Area
                            </label>
                            <div class="border rounded px-3 py-2 d-flex align-items-center gap-4">
                                <div class="form-check mb-0">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="school_area_type"
                                        id="schoolAreaUrban"
                                        value="URBAN"
                                        required
                                    >
                                    <label
                                        class="form-check-label"
                                        for="schoolAreaUrban"
                                    >
                                        Urban
                                    </label>
                                </div>

                                <div class="form-check mb-0">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="school_area_type"
                                        id="schoolAreaRural"
                                        value="RURAL"
                                        required
                                    >
                                    <label
                                        class="form-check-label"
                                        for="schoolAreaRural"
                                    >
                                        Rural
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label required" for="contactName">Contact Person</label>
                            <input
                                type="text"
                                class="form-control text-uppercase"
                                id="contactName"
                                name="contact_name"
                                maxlength="150"
                                required
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label required" for="contactPhone">Contact Phone</label>
                            <input
                                type="tel"
                                class="form-control text-uppercase"
                                id="contactPhone"
                                name="contact_phone"
                                maxlength="30"
                                required
                            >
                        </div>

                        <div class="col-md-5">
                            <label class="form-label required" for="contactEmail">Contact Email</label>
                            <input
                                type="email"
                                class="form-control text-uppercase"
                                id="contactEmail"
                                name="contact_email"
                                maxlength="150"
                                required
                            >
                        </div>

                        <div class="col-md-7">
                            <label class="form-label required" for="address">Address</label>
                            <textarea
                                class="form-control text-uppercase"
                                id="address"
                                name="address"
                                rows="2"
                                maxlength="1000"
                                required
                            ></textarea>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label required" for="schoolLogo">School Logo</label>
                            <div class="row g-2 align-items-center">
                                <div class="col-auto">
                                    <div id="schoolLogoPreviewWrap" class="d-none">
                                        <img
                                            id="schoolLogoPreview"
                                            src=""
                                            alt="School logo"
                                            width="72"
                                            height="72"
                                            style="width:72px;height:72px;object-fit:contain;border:1px solid #dee2e6;border-radius:10px;background:#fff;padding:5px;"
                                        >
                                    </div>
                                </div>
                                <div class="col">
                                    <input
                                        type="file"
                                        class="form-control"
                                        id="schoolLogo"
                                        name="school_logo"
                                        accept="image/jpeg,image/png,image/webp"
                                    >
                                    <input type="hidden" id="existingSchoolLogo" value="">
                                    <div class="form-text">JPG, PNG OR WEBP. MAX 5 MB.</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label" for="referrerSearch">Search Referrer</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input
                                    type="search"
                                    class="form-control text-uppercase"
                                    id="referrerSearch"
                                    placeholder="NAME / CODE"
                                    autocomplete="off"
                                >
                                <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    id="clearReferrerSearch"
                                >
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <div class="form-text" id="referrerSearchResult">OPTIONAL.</div>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label" for="referrerSchoolCode">Referrer</label>
                            <select
                                class="form-select text-uppercase"
                                name="referrer"
                                id="referrerSchoolCode"
                            >
                                <option value="">NO REFERRER</option>
                            </select>
                        </div>

                        <div class="col-12 mt-2">
                            <button
                                class="btn btn-primary"
                                id="registerSchoolButton"
                                type="submit"
                                disabled
                            >
                                <i class="bi bi-check2-circle me-2"></i>
                                Register School
                            </button>

                            <a class="btn btn-outline-secondary" href="/about-programme.php#programme-schools">Cancel</a>
                        </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/register_school.js?v=20260929-1"></script>

<?php require 'includes/footer.php'; ?>
