(() => {
    'use strict';

    const form = document.getElementById('studentRegistrationForm');
    if (!form) return;

    const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';

    const studentIdentity = document.getElementById('studentIdentityNumber');
    const studentIdentityStatus = document.getElementById('studentIdentityStatus');
    const studentDetails = document.getElementById('studentDetailsFieldset');

    const schoolSearch = document.getElementById('schoolSearch');
    const clearSchoolSearch = document.getElementById('clearSchoolSearch');
    const schoolSearchResult = document.getElementById('schoolSearchResult');
    const schoolSelect = document.getElementById('schoolId');
    const studentNumberPreview = document.getElementById('studentNumberPreview');
    const coachingTeacherSelect = document.getElementById('coachingTeacherId');
    const coachingTeacherStatus = document.getElementById('coachingTeacherStatus');

    const guardianIdentity = document.getElementById('guardianIdentityNumber');
    const guardianIdentityStatus = document.getElementById('guardianIdentityStatus');
    const guardianDetails = document.getElementById('guardianDetailsFieldset');
    const guardianFullName = document.getElementById('guardianFullName');
    const guardianRelationship = document.getElementById('guardianRelationship');
    const existingGuardianNotice = document.getElementById('existingGuardianNotice');
    const existingGuardianNoticeText = document.getElementById('existingGuardianNoticeText');

    const guardianEmailGroup = document.getElementById('guardianEmailGroup');
    const guardianEmail = document.getElementById('guardianEmail');
    const guardianEmailVerifiedNotice = document.getElementById('guardianEmailVerifiedNotice');

    const guardianPhoneGroup = document.getElementById('guardianPhoneGroup');
    const guardianPhone = document.getElementById('guardianPhone');
    const guardianPhoneVerifiedNotice = document.getElementById('guardianPhoneVerifiedNotice');

    const couponCode = document.getElementById('couponCode');
    const validateCouponButton = document.getElementById('validateCouponButton');
    const couponValidationStatus = document.getElementById('couponValidationStatus');

    const registerSubmitButton = document.getElementById('registerSubmitButton');
    const registerSubmitText = document.getElementById('registerSubmitText');
    const errorsBox = document.getElementById('studentRegistrationErrors');

    let studentIdentityPassed = false;
    let guardianIdentityPassed = false;
    let coachingTeacherSelected = false;
    let guardianPhoneVerified = false;
    let guardianEmailVerified = false;

    let studentTimer = null;
    let studentController = null;
    let guardianTimer = null;
    let guardianController = null;
    let schoolTimer = null;
    let schoolController = null;
    let lastTeacherSchoolId = '';
    let teacherController = null;
    let teacherRequestSequence = 0;

    const studentCache = new Map();
    const schoolCache = new Map();

    // Visible confirmation that this page JavaScript loaded successfully.
    if (studentIdentityStatus) {
        studentIdentityStatus.textContent =
            'ENTER 4 OR MORE NUMBERS / CHARACTERS TO START CHECKING.';
        studentIdentityStatus.className = 'form-text';
    }

    const upper = (value) => String(value ?? '').toLocaleUpperCase();
    const normalizeIdentity = (value) =>
        upper(value).replace(/[^A-Z0-9]/g, '');

    const showErrors = (errors) => {
        const items = Array.isArray(errors)
            ? errors
            : [String(errors || 'Unable to complete registration.')];

        errorsBox.innerHTML =
            '<ul class="mb-0">'
            + items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')
            + '</ul>';

        errorsBox.classList.remove('d-none');
        errorsBox.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    };

    const clearErrors = () => {
        errorsBox.classList.add('d-none');
        errorsBox.innerHTML = '';
    };

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    };

    form.querySelectorAll(
        'input[type="text"],'
        + 'input[type="search"],'
        + 'input[type="tel"],'
        + 'input[type="email"],'
        + 'textarea'
    ).forEach((input) => {
        input.addEventListener('input', () => {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            input.value = upper(input.value);

            if (
                start !== null &&
                end !== null &&
                typeof input.setSelectionRange === 'function'
            ) {
                try {
                    input.setSelectionRange(start, end);
                } catch (_) {}
            }
        });
    });

    const updateSubmitState = () => {
        registerSubmitButton.disabled =
            !studentIdentityPassed ||
            !guardianIdentityPassed ||
            !coachingTeacherSelected;
    };

    const setStudentGate = (passed) => {
        studentIdentityPassed = Boolean(passed);
        studentDetails.disabled = !studentIdentityPassed;
        guardianIdentity.disabled = !studentIdentityPassed;

        if (!studentIdentityPassed) {
            guardianIdentityPassed = false;
            coachingTeacherSelected = false;
            guardianDetails.disabled = true;

            if (coachingTeacherSelect) {
                coachingTeacherSelect.value = '';
                coachingTeacherSelect.disabled = true;
            }
        }

        updateSubmitState();
    };

    const setGuardianGate = (passed) => {
        guardianIdentityPassed = Boolean(passed);
        guardianDetails.disabled = !guardianIdentityPassed;
        updateSubmitState();
    };

    const fetchJson = async (url, options = {}) => {
        const response = await fetch(url, options);
        const contentType = String(response.headers.get('content-type') || '');

        let data = null;

        if (contentType.includes('application/json')) {
            try {
                data = await response.json();
            } catch (_) {
                throw new Error(
                    `INVALID JSON FROM API (${response.status}).`
                );
            }
        } else {
            const body = await response.text();
            const shortBody = body
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 160);

            throw new Error(
                `API ${response.status}: `
                + (
                    shortBody
                        ? shortBody
                        : 'ENDPOINT NOT FOUND OR RETURNED HTML.'
                )
            );
        }

        if (!response.ok || !data?.success) {
            const error = new Error(
                data?.message ||
                data?.errors?.[0] ||
                `REQUEST FAILED (${response.status}).`
            );
            error.data = data;
            throw error;
        }

        return data;
    };

    const checkStudentIdentity = async () => {
        const identity = normalizeIdentity(studentIdentity.value);
        studentIdentity.value = identity;

        if (identity.length < 4) {
            setStudentGate(false);
            studentIdentity.classList.remove('is-valid', 'is-invalid');
            studentIdentityStatus.textContent =
                'ENTER 4 OR MORE NUMBERS / CHARACTERS TO START CHECKING.';
            studentIdentityStatus.className = 'form-text';
            return;
        }

        const cached = studentCache.get(identity);
        if (cached && cached.expires > Date.now()) {
            applyStudentIdentityResult(cached.data);
            return;
        }

        studentController?.abort();
        studentController = new AbortController();

        studentIdentityStatus.textContent =
            'CHECKING STUDENT NRIC / PASSPORT...';
        studentIdentityStatus.className =
            'form-text text-secondary';

        try {
            const data = await fetchJson(
                `api/registration/student_identity.php?identity_number=${encodeURIComponent(identity)}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store',
                    signal: studentController.signal,
                }
            );

            studentCache.set(identity, {
                data,
                expires: Date.now() + 30000,
            });

            applyStudentIdentityResult(data);
        } catch (error) {
            if (error?.name === 'AbortError') return;

            setStudentGate(false);
            studentIdentity.classList.remove('is-valid', 'is-invalid');

            studentIdentityStatus.textContent =
                upper(
                    error.message ||
                    'UNABLE TO CHECK STUDENT NRIC / PASSPORT.'
                );

            studentIdentityStatus.className =
                'form-text text-warning';
        }
    };

    const applyStudentIdentityResult = (data) => {
        const duplicate = Boolean(data.exists);

        studentIdentity.classList.toggle('is-invalid', duplicate);
        studentIdentity.classList.toggle('is-valid', !duplicate);

        studentIdentityStatus.textContent =
            upper(data.message || '');

        studentIdentityStatus.className = duplicate
            ? 'form-text text-danger'
            : 'form-text text-success';

        setStudentGate(!duplicate);

        if (!duplicate) {
            loadSchools(
                schoolSearch.value ||
                form.dataset.initialSchoolCode ||
                ''
            );
        }
    };

    studentIdentity.addEventListener('input', () => {
        setStudentGate(false);

        clearTimeout(studentTimer);
        const identity = normalizeIdentity(studentIdentity.value);
        studentIdentity.value = identity;

        if (identity.length < 4) {
            studentIdentityStatus.textContent =
                'ENTER 4 OR MORE NUMBERS / CHARACTERS TO START CHECKING.';
            studentIdentityStatus.className = 'form-text';
            return;
        }

        studentIdentityStatus.textContent =
            'WAITING TO CHECK STUDENT NRIC / PASSPORT...';
        studentIdentityStatus.className =
            'form-text text-secondary';

        studentTimer = setTimeout(
            checkStudentIdentity,
            250
        );
    });

    studentIdentity.addEventListener('blur', () => {
        clearTimeout(studentTimer);

        if (
            normalizeIdentity(studentIdentity.value).length >= 4
        ) {
            checkStudentIdentity();
        }
    });

    const renderSchools = (schools) => {
        const current = schoolSelect.value;
        schoolSelect.innerHTML =
            '<option value="">SELECT SCHOOL</option>';

        schools.forEach((school) => {
            const option = document.createElement('option');
            option.value = String(school.id || '');
            option.dataset.code = upper(school.code || '');
            option.textContent =
                `${upper(school.name || '')}`
                + ` (${upper(school.code || '')})`;

            if (current && option.value === current) {
                option.selected = true;
            }

            schoolSelect.appendChild(option);
        });
    };

    const loadSchools = async (query = '') => {
        if (!studentIdentityPassed) return;

        const q = upper(query.trim());
        const cacheKey = q || '__ALL__';
        const cached = schoolCache.get(cacheKey);

        if (cached && cached.expires > Date.now()) {
            renderSchools(cached.schools);
            schoolSearchResult.textContent =
                `${cached.schools.length} SCHOOL`
                + `${cached.schools.length === 1 ? '' : 'S'} FOUND.`;
            return;
        }

        schoolController?.abort();
        schoolController = new AbortController();

        schoolSearchResult.textContent = 'SEARCHING SCHOOLS...';
        schoolSearchResult.className =
            'form-text text-secondary';

        try {
            const params = new URLSearchParams({
                q,
                role: 'student',
                limit: '30',
            });

            const data = await fetchJson(
                `api/registration/schools.php?${params.toString()}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'default',
                    signal: schoolController.signal,
                }
            );

            const schools = Array.isArray(data.schools)
                ? data.schools
                : [];

            schoolCache.set(cacheKey, {
                schools,
                expires: Date.now() + 120000,
            });

            renderSchools(schools);

            schoolSearchResult.textContent =
                `${schools.length} SCHOOL`
                + `${schools.length === 1 ? '' : 'S'} FOUND.`;

            schoolSearchResult.className =
                'form-text text-success';
        } catch (error) {
            if (error?.name === 'AbortError') return;

            schoolSearchResult.textContent =
                upper(error.message || 'UNABLE TO SEARCH SCHOOLS.');

            schoolSearchResult.className =
                'form-text text-danger';
        }
    };

    schoolSearch.addEventListener('input', () => {
        clearTimeout(schoolTimer);
        schoolTimer = setTimeout(
            () => loadSchools(schoolSearch.value),
            250
        );
    });

    clearSchoolSearch.addEventListener('click', () => {
        schoolSearch.value = '';
        loadSchools('');
        schoolSearch.focus();
    });

    const loadStudentNumber = async () => {
        const schoolId = schoolSelect.value;

        if (!schoolId) {
            studentNumberPreview.value = '';
            studentNumberPreview.placeholder =
                'SELECT SCHOOL';
            return;
        }

        studentNumberPreview.value = '';
        studentNumberPreview.placeholder =
            'GENERATING...';

        try {
            const data = await fetchJson(
                `api/registration/student_number.php?school_id=${encodeURIComponent(schoolId)}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store',
                }
            );

            studentNumberPreview.value =
                upper(data.student_number || '');
        } catch (_) {
            studentNumberPreview.value = '';
            studentNumberPreview.placeholder =
                'UNAVAILABLE';
        }
    };

    const loadCoachingTeachers = async (force = false) => {
        if (!coachingTeacherSelect || !coachingTeacherStatus) {
            return;
        }

        const schoolId = schoolSelect.value;

        if (
            !force
            && schoolId
            && schoolId === lastTeacherSchoolId
            && coachingTeacherSelect.options.length > 1
        ) {
            return;
        }
        const selectedOption =
            schoolSelect.options[schoolSelect.selectedIndex];

        const schoolCode =
            upper(selectedOption?.dataset?.code || '');

        coachingTeacherSelected = false;
        updateSubmitState();

        coachingTeacherSelect.innerHTML =
            '<option value="">SELECT COACHING TEACHER</option>';

        coachingTeacherSelect.value = '';

        if (!schoolId) {
            lastTeacherSchoolId = '';
            coachingTeacherSelect.disabled = true;

            coachingTeacherStatus.textContent =
                'SELECT SCHOOL FIRST. COACHING TEACHER IS REQUIRED.';

            coachingTeacherStatus.className =
                'form-text text-secondary';

            return;
        }

        coachingTeacherSelect.disabled = true;

        coachingTeacherStatus.textContent =
            'LOADING SCHOOL TEACHERS...';

        coachingTeacherStatus.className =
            'form-text text-secondary';

        teacherController?.abort();
        teacherController = new AbortController();

        const requestSequence =
            ++teacherRequestSequence;

        try {
            const data = await fetchJson(
                `api/registration/school_teachers.php?school_id=${encodeURIComponent(schoolId)}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store',
                    signal: teacherController.signal,
                }
            );

            /*
             * Ignore a response from an older request. This prevents
             * duplicate options when more than one school-change event
             * occurs while the first request is still loading.
             */
            if (
                requestSequence !== teacherRequestSequence
                || schoolSelect.value !== schoolId
            ) {
                return;
            }

            const rawTeachers = Array.isArray(data.teachers)
                ? data.teachers
                : [];

            const uniqueTeachers = [];
            const seenTeachers = new Set();

            rawTeachers.forEach((teacher) => {
                const teacherId =
                    String(teacher.id || '').trim();

                const teacherName =
                    upper(teacher.full_name || '').trim();

                const teacherPosition =
                    upper(teacher.position || '').trim();

                const uniqueKey =
                    teacherId
                    || `${teacherName}|${teacherPosition}`;

                if (!uniqueKey || seenTeachers.has(uniqueKey)) {
                    return;
                }

                seenTeachers.add(uniqueKey);
                uniqueTeachers.push(teacher);
            });

            lastTeacherSchoolId = schoolId;

            /*
             * Clear immediately before rendering the latest response,
             * not only before the request starts.
             */
            coachingTeacherSelect.innerHTML =
                '<option value="">SELECT COACHING TEACHER</option>';

            uniqueTeachers.forEach((teacher) => {
                const option =
                    document.createElement('option');

                option.value =
                    String(teacher.id || '');

                option.textContent =
                    upper(teacher.full_name || '')
                    + (
                        teacher.position
                            ? ` — ${upper(teacher.position)}`
                            : ''
                    );

                coachingTeacherSelect.appendChild(option);
            });

            if (uniqueTeachers.length > 0) {
                coachingTeacherSelect.disabled = false;

                coachingTeacherStatus.textContent =
                    `${uniqueTeachers.length} TEACHER`
                    + `${uniqueTeachers.length === 1 ? '' : 'S'} AVAILABLE. `
                    + 'SELECT ONE TO CONTINUE.';

                coachingTeacherStatus.className =
                    'form-text text-success';
            } else {
                coachingTeacherSelect.disabled = true;

                const registerUrl =
                    'register_teacher.php'
                    + (
                        schoolCode
                            ? `?code=${encodeURIComponent(schoolCode)}`
                            : ''
                    );

                coachingTeacherStatus.innerHTML =
                    'NO TEACHER REGISTERED FOR THIS SCHOOL. '
                    + `<a href="${registerUrl}">REGISTER TEACHER FIRST</a>.`;

                coachingTeacherStatus.className =
                    'form-text text-danger';
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            if (requestSequence !== teacherRequestSequence) {
                return;
            }

            lastTeacherSchoolId = '';
            coachingTeacherSelect.disabled = true;

            coachingTeacherStatus.textContent =
                upper(
                    error.message
                    || 'UNABLE TO LOAD SCHOOL TEACHERS.'
                );

            coachingTeacherStatus.className =
                'form-text text-danger';
        }

        coachingTeacherSelected = false;
        updateSubmitState();
    };

    coachingTeacherSelect?.addEventListener(
        'change',
        () => {
            coachingTeacherSelected =
                Boolean(coachingTeacherSelect.value);

            coachingTeacherSelect.classList.toggle(
                'is-valid',
                coachingTeacherSelected
            );

            coachingTeacherSelect.classList.toggle(
                'is-invalid',
                !coachingTeacherSelected
            );

            if (coachingTeacherSelected) {
                coachingTeacherStatus.textContent =
                    'COACHING TEACHER SELECTED.';

                coachingTeacherStatus.className =
                    'form-text text-success';
            } else {
                coachingTeacherStatus.textContent =
                    'COACHING TEACHER IS REQUIRED.';

                coachingTeacherStatus.className =
                    'form-text text-danger';
            }

            updateSubmitState();
        }
    );

    const handleSchoolSelection = () => {
        coachingTeacherSelected = false;
        lastTeacherSchoolId = '';
        updateSubmitState();

        loadStudentNumber();
        loadCoachingTeachers(true);
    };

    schoolSelect.addEventListener(
        'change',
        handleSchoolSelection
    );

    const resetGuardianContactVisibility = () => {
        guardianPhoneVerified = false;
        guardianEmailVerified = false;

        guardianPhoneGroup.classList.remove('d-none');
        guardianEmailGroup.classList.remove('d-none');
        guardianPhoneVerifiedNotice.classList.add('d-none');
        guardianEmailVerifiedNotice.classList.add('d-none');

        guardianPhone.disabled = false;
        guardianPhone.required = true;

        guardianEmail.disabled = false;
        guardianEmail.required = true;
    };

    const clearGuardian = () => {
        resetGuardianContactVisibility();

        guardianFullName.value = '';
        guardianFullName.readOnly = false;
        guardianRelationship.value = '';
        guardianPhone.value = '';
        guardianEmail.value = '';

        existingGuardianNotice.classList.add('d-none');
    };

    const applyGuardian = (data) => {
        resetGuardianContactVisibility();

        guardianFullName.value =
            upper(data.full_name || '');
        guardianFullName.readOnly = Boolean(data.exists);

        if (data.relationship) {
            const wanted = upper(data.relationship);

            Array.from(
                guardianRelationship.options
            ).forEach((option) => {
                option.selected =
                    upper(option.value) === wanted;
            });
        }

        if (data.exists) {
            existingGuardianNotice.classList.remove('d-none');

            existingGuardianNoticeText.textContent =
                `EXISTING PARENT ACCOUNT `
                + `${upper(data.guardian_number || '')} `
                + `FOUND. DETAILS LOADED.`;
        } else {
            existingGuardianNotice.classList.add('d-none');
        }

        guardianPhoneVerified =
            Boolean(data.phone_verified);

        guardianEmailVerified =
            Boolean(data.email_verified);

        if (guardianPhoneVerified) {
            guardianPhoneGroup.classList.add('d-none');
            guardianPhoneVerifiedNotice.classList.remove('d-none');
            guardianPhone.value = '';
            guardianPhone.required = false;
            guardianPhone.disabled = true;
        } else {
            guardianPhone.value = String(data.phone || '');
        }

        if (guardianEmailVerified) {
            guardianEmailGroup.classList.add('d-none');
            guardianEmailVerifiedNotice.classList.remove('d-none');

            guardianEmail.value = '';
            guardianEmail.required = false;
            guardianEmail.disabled = true;
        } else {
            guardianEmail.value =
                upper(data.email || '');
        }
    };

    const checkGuardianIdentity = async () => {
        const identity =
            normalizeIdentity(guardianIdentity.value);

        guardianIdentity.value = identity;

        if (identity.length < 4) {
            setGuardianGate(false);
            guardianIdentityStatus.textContent =
                'ENTER AT LEAST 4 NUMBERS / CHARACTERS.';
            guardianIdentityStatus.className =
                'form-text';
            return;
        }

        guardianController?.abort();
        guardianController = new AbortController();

        guardianIdentityStatus.textContent =
            'CHECKING PARENT / GUARDIAN...';

        guardianIdentityStatus.className =
            'form-text text-secondary';

        try {
            const body = new URLSearchParams({
                csrf_token: csrf,
                guardian_identity_number: identity,
            });

            const data = await fetchJson(
                'api/registration/guardian_lookup.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: body.toString(),
                    cache: 'no-store',
                    signal: guardianController.signal,
                }
            );

            if (data.exists) {
                applyGuardian(data);
                guardianIdentityStatus.textContent =
                    'EXISTING PARENT / GUARDIAN FOUND.';
                guardianIdentityStatus.className =
                    'form-text text-info';
            } else {
                clearGuardian();
    
                guardianIdentityStatus.textContent =
                    'NEW PARENT / GUARDIAN. ENTER DETAILS.';
                guardianIdentityStatus.className =
                    'form-text text-success';
            }

            setGuardianGate(true);
        } catch (error) {
            if (error?.name === 'AbortError') return;

            clearGuardian();
            setGuardianGate(false);

            const errorType = String(
                error?.data?.error_type || ''
            ).trim();

            guardianIdentityStatus.textContent =
                upper(
                    (
                        error.message ||
                        'UNABLE TO CHECK PARENT / GUARDIAN.'
                    )
                    + (
                        errorType
                            ? ` [${errorType}]`
                            : ''
                    )
                );

            guardianIdentityStatus.className =
                'form-text text-danger';
        }
    };

    guardianIdentity.addEventListener('input', () => {
        clearGuardian();
        setGuardianGate(false);
        clearTimeout(guardianTimer);

        const identity =
            normalizeIdentity(guardianIdentity.value);

        guardianIdentity.value = identity;

        if (identity.length < 4) {
            guardianIdentityStatus.textContent =
                'ENTER AT LEAST 4 NUMBERS / CHARACTERS.';
            guardianIdentityStatus.className =
                'form-text';
            return;
        }

        guardianIdentityStatus.textContent =
            'WAITING TO CHECK PARENT / GUARDIAN...';

        guardianIdentityStatus.className =
            'form-text text-secondary';

        guardianTimer = setTimeout(
            checkGuardianIdentity,
            250
        );
    });

    guardianIdentity.addEventListener('blur', () => {
        clearTimeout(guardianTimer);

        if (
            normalizeIdentity(guardianIdentity.value).length >= 4
        ) {
            checkGuardianIdentity();
        }
    });

    couponCode.addEventListener('input', () => {
        couponCode.value =
            upper(couponCode.value)
                .replace(/\s+/g, '');

        couponValidationStatus.textContent =
            couponCode.value
                ? 'COUPON NOT VALIDATED YET. CLICK VALIDATE.'
                : 'VALID COUPON = RM0 PAYMENT + 1-YEAR MEMBERSHIP. LEAVE BLANK FOR RM100 PAYMENT.';

        couponValidationStatus.className =
            'form-text text-secondary';

        registerSubmitText.textContent =
            couponCode.value
                ? 'Register & Redeem 1-Year Coupon'
                : 'Register & Continue to Payment';
    });

    validateCouponButton.addEventListener(
        'click',
        async () => {
            const code = couponCode.value.trim();

            if (!code) {
                couponValidationStatus.textContent =
                    'ENTER A COUPON CODE FIRST.';

                couponValidationStatus.className =
                    'form-text text-danger';
                return;
            }

            validateCouponButton.disabled = true;

            try {
                const body = new URLSearchParams({
                    csrf_token: csrf,
                    coupon_code: code,
                });

                const data = await fetchJson(
                    'api/registration/coupon_validate.php',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8',
                            'Accept': 'application/json',
                        },
                        body: body.toString(),
                        cache: 'no-store',
                    }
                );

                couponCode.value =
                    upper(data.code || code);

                couponValidationStatus.textContent =
                    'COUPON VALID. 1-YEAR MEMBERSHIP WILL BE ACTIVATED WITH RM0 PAYMENT.';

                couponValidationStatus.className =
                    'form-text text-success fw-semibold';
            } catch (error) {
                couponValidationStatus.textContent =
                    upper(
                        error.message ||
                        'COUPON IS NOT VALID.'
                    );

                couponValidationStatus.className =
                    'form-text text-danger fw-semibold';
            } finally {
                validateCouponButton.disabled = false;
            }
        }
    );

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();

        if (
            !coachingTeacherSelect
            || coachingTeacherSelect.disabled
            || !coachingTeacherSelect.value
        ) {
            coachingTeacherSelected = false;
            updateSubmitState();

            if (coachingTeacherSelect) {
                coachingTeacherSelect.classList.add('is-invalid');
                coachingTeacherSelect.focus();
            }

            showErrors(
                'Please select a coaching teacher before registering the student.'
            );

            return;
        }

        coachingTeacherSelected = true;

        if (!studentIdentityPassed) {
            studentIdentity.focus();
            return;
        }

        if (!guardianIdentityPassed) {
            guardianIdentity.focus();
            return;
        }

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        registerSubmitButton.disabled = true;
        registerSubmitButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>REGISTERING...';

        try {
            const data = await fetchJson(
                'api/registration/register_student_submit.php',
                {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                    cache: 'no-store',
                }
            );

            if (data.redirect) {
                window.location.assign(data.redirect);
                return;
            }

            throw new Error(
                'Registration completed but no redirect was returned.'
            );
        } catch (error) {
            showErrors(
                error?.data?.errors ||
                error?.message ||
                'Unable to register the student.'
            );

            registerSubmitButton.innerHTML =
                '<i class="bi bi-check2-circle me-2"></i>'
                + '<span id="registerSubmitText">'
                + (
                    couponCode.value.trim()
                        ? 'Register & Redeem 1-Year Coupon'
                        : 'Register & Continue to Payment'
                )
                + '</span>';

            updateSubmitState();
        }
    });

    setStudentGate(false);
    setGuardianGate(false);
})();
