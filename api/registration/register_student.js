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

    const guardianSmsGroup = document.getElementById('guardianSmsGroup');
    const guardianSmsCode = document.getElementById('guardianSmsCode');
    const guardianSmsCodeLabel = document.getElementById('guardianSmsCodeLabel');
    const guardianSmsCodeHelp = document.getElementById('guardianSmsCodeHelp');
    const sendSmsCodeButton = document.getElementById('sendSmsCodeButton');
    const smsCodeStatus = document.getElementById('smsCodeStatus');
    const skipPhoneVerification = document.getElementById('skipPhoneVerification');
    const skipPhoneVerificationGroup = document.getElementById('skipPhoneVerificationGroup');
    const verificationBypass = document.getElementById('verificationBypass');
    const verificationBypassNotice = document.getElementById('verificationBypassNotice');

    const couponCode = document.getElementById('couponCode');
    const validateCouponButton = document.getElementById('validateCouponButton');
    const couponValidationStatus = document.getElementById('couponValidationStatus');

    const registerSubmitButton = document.getElementById('registerSubmitButton');
    const registerSubmitText = document.getElementById('registerSubmitText');
    const errorsBox = document.getElementById('studentRegistrationErrors');

    let studentIdentityPassed = false;
    let guardianIdentityPassed = false;
    let guardianPhoneVerified = false;
    let guardianEmailVerified = false;

    let studentTimer = null;
    let studentController = null;
    let guardianTimer = null;
    let guardianController = null;
    let schoolTimer = null;
    let schoolController = null;

    const studentCache = new Map();
    const schoolCache = new Map();

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
            !guardianIdentityPassed;
    };

    const setStudentGate = (passed) => {
        studentIdentityPassed = Boolean(passed);
        studentDetails.disabled = !studentIdentityPassed;
        guardianIdentity.disabled = !studentIdentityPassed;

        if (!studentIdentityPassed) {
            guardianIdentityPassed = false;
            guardianDetails.disabled = true;
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

        let data = null;
        try {
            data = await response.json();
        } catch (_) {
            throw new Error('INVALID API RESPONSE.');
        }

        if (!response.ok || !data?.success) {
            const error = new Error(
                data?.message ||
                data?.errors?.[0] ||
                'REQUEST FAILED.'
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
                'ENTER AT LEAST 4 NUMBERS / CHARACTERS.';
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
                `/api/registration/student_identity.php?identity_number=${encodeURIComponent(identity)}`,
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
                'ENTER AT LEAST 4 NUMBERS / CHARACTERS.';
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
                `/api/registration/schools.php?${params.toString()}`,
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
                `/api/registration/student_number.php?school_id=${encodeURIComponent(schoolId)}`,
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

    schoolSelect.addEventListener(
        'change',
        loadStudentNumber
    );

    const resetGuardianContactVisibility = () => {
        guardianPhoneVerified = false;
        guardianEmailVerified = false;

        guardianPhoneGroup.classList.remove('d-none');
        guardianEmailGroup.classList.remove('d-none');
        guardianSmsGroup.classList.remove('d-none');
        skipPhoneVerificationGroup.classList.remove('d-none');

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
            guardianSmsGroup.classList.add('d-none');
            skipPhoneVerificationGroup.classList.add('d-none');
            guardianPhoneVerifiedNotice.classList.remove('d-none');

            guardianPhone.value = '';
            guardianPhone.required = false;
            guardianPhone.disabled = true;

            guardianSmsCode.value = '';
            guardianSmsCode.required = false;
            guardianSmsCode.disabled = true;

            verificationBypass.value = '0';
        } else {
            guardianPhone.value =
                String(data.phone || '');

            skipPhoneVerification.checked = true;
            setVerificationMode(true);
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
                '/api/registration/guardian_lookup.php',
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
                setVerificationMode(true);

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

            guardianIdentityStatus.textContent =
                upper(
                    error.message ||
                    'UNABLE TO CHECK PARENT / GUARDIAN.'
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

    const setVerificationMode = (skip) => {
        if (guardianPhoneVerified) {
            verificationBypass.value = '0';
            return;
        }

        const bypass = Boolean(skip);

        verificationBypass.value = bypass ? '1' : '0';
        verificationBypassNotice.classList.toggle(
            'd-none',
            !bypass
        );

        guardianSmsCode.required = !bypass;
        guardianSmsCode.disabled = bypass;
        guardianSmsCode.placeholder =
            bypass
                ? 'VERIFY LATER'
                : '6-DIGIT CODE';

        guardianSmsCodeLabel.classList.toggle(
            'required',
            !bypass
        );

        guardianSmsCodeLabel.textContent =
            bypass
                ? 'SMS Verification Code (Optional)'
                : 'SMS Verification Code';

        guardianSmsCodeHelp.textContent =
            bypass
                ? 'VERIFICATION CODE IS DISABLED. YOU MAY VERIFY LATER.'
                : 'ENTER THE 6-DIGIT CODE. VALID FOR 10 MINUTES.';

        sendSmsCodeButton.disabled = bypass;
        sendSmsCodeButton.innerHTML =
            bypass
                ? '<i class="bi bi-skip-forward me-1"></i>Skipped'
                : '<i class="bi bi-chat-dots me-1"></i>Send Code';
    };

    skipPhoneVerification.addEventListener(
        'change',
        () => {
            setVerificationMode(
                skipPhoneVerification.checked
            );

            smsCodeStatus.textContent =
                skipPhoneVerification.checked
                    ? 'PHONE VERIFICATION WILL BE SKIPPED.'
                    : 'PHONE VERIFICATION ENABLED. CLICK SEND CODE.';

            smsCodeStatus.className =
                skipPhoneVerification.checked
                    ? 'form-text text-secondary'
                    : 'form-text text-primary';
        }
    );

    sendSmsCodeButton.addEventListener(
        'click',
        async () => {
            if (skipPhoneVerification.checked) {
                return;
            }

            if (
                !guardianPhone.value.trim() ||
                (
                    !guardianEmailVerified &&
                    !guardianEmail.checkValidity()
                )
            ) {
                smsCodeStatus.textContent =
                    'ENTER A VALID MOBILE NUMBER AND EMAIL FIRST.';

                smsCodeStatus.className =
                    'form-text text-danger';
                return;
            }

            sendSmsCodeButton.disabled = true;
            sendSmsCodeButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>SENDING...';

            try {
                const body = new URLSearchParams({
                    csrf_token: csrf,
                    guardian_phone:
                        guardianPhone.value.trim(),
                    guardian_email:
                        guardianEmailVerified
                            ? ''
                            : guardianEmail.value.trim(),
                    guardian_identity_number:
                        guardianIdentity.value.trim(),
                });

                const data = await fetchJson(
                    '/api/registration/guardian_send_code.php',
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

                guardianSmsCode.disabled = false;
                guardianSmsCode.required = true;
                guardianSmsCode.value = '';
                guardianSmsCode.focus();

                smsCodeStatus.textContent =
                    upper(data.message || 'CODE SENT.');

                smsCodeStatus.className =
                    'form-text text-success';

                sendSmsCodeButton.innerHTML =
                    '<i class="bi bi-chat-dots me-1"></i>Resend Code';

                setTimeout(() => {
                    if (!skipPhoneVerification.checked) {
                        sendSmsCodeButton.disabled = false;
                    }
                }, Number(data.retry_after || 60) * 1000);
            } catch (error) {
                const bypassAllowed =
                    Boolean(error?.data?.bypass_allowed);

                if (bypassAllowed) {
                    skipPhoneVerification.checked = true;
                    setVerificationMode(true);

                    smsCodeStatus.textContent =
                        upper(
                            error.message ||
                            'VERIFICATION UNAVAILABLE. VERIFY LATER.'
                        );

                    smsCodeStatus.className =
                        'form-text text-warning';
                } else {
                    smsCodeStatus.textContent =
                        upper(
                            error.message ||
                            'UNABLE TO SEND CODE.'
                        );

                    smsCodeStatus.className =
                        'form-text text-danger';

                    sendSmsCodeButton.disabled = false;
                    sendSmsCodeButton.innerHTML =
                        '<i class="bi bi-chat-dots me-1"></i>Send Code';
                }
            }
        }
    );

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
                    '/api/registration/coupon_validate.php',
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
                '/api/registration/register_student_submit.php',
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
    setVerificationMode(true);
})();
