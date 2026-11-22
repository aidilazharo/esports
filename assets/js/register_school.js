(() => {
    'use strict';

    const form = document.getElementById('schoolRegistrationForm');
    if (!form) return;

    const registrationFields =
        document.getElementById('schoolRegistrationFields');

    const schoolCode = document.getElementById('schoolCode');
    const schoolName = document.getElementById('schoolName');
    const schoolType = document.getElementById('schoolType');
    const state = document.getElementById('state');
    const district = document.getElementById('district');
    const address = document.getElementById('address');
    const contactName = document.getElementById('contactName');
    const contactPhone = document.getElementById('contactPhone');
    const contactEmail = document.getElementById('contactEmail');

    const schoolLogo = document.getElementById('schoolLogo');
    const existingSchoolLogo =
        document.getElementById('existingSchoolLogo');

    const schoolLogoPreview =
        document.getElementById('schoolLogoPreview');

    const schoolLogoPreviewWrap =
        document.getElementById('schoolLogoPreviewWrap');

    const lookupStatus =
        document.getElementById('schoolLookupStatus');

    const existingNotice =
        document.getElementById('existingSchoolNotice');

    const registeredNotice =
        document.getElementById('schoolRegisteredNotice');

    const registeredMessage =
        document.getElementById('schoolRegisteredMessage');

    const registerButton =
        document.getElementById('registerSchoolButton');

    const referrerSearch =
        document.getElementById('referrerSearch');

    const referrerSelect =
        document.getElementById('referrerSchoolCode');

    const referrerResult =
        document.getElementById('referrerSearchResult');

    const clearReferrer =
        document.getElementById('clearReferrerSearch');

    const errorsBox =
        document.getElementById('schoolRegistrationErrors');

    let schoolLookupTimer = null;
    let schoolController = null;
    let schoolLookupComplete = false;

    let referrerTimer = null;
    let referrerController = null;

    const upper = (value) =>
        String(value ?? '').toLocaleUpperCase();

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    };

    const showErrors = (errors) => {
        const list = Array.isArray(errors)
            ? errors
            : [String(errors || 'Unable to register school.')];

        errorsBox.innerHTML =
            '<ul class="mb-0">'
            + list.map((item) => `<li>${escapeHtml(item)}</li>`).join('')
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

    const fetchJson = async (url, options = {}) => {
        const response = await fetch(url, options);
        const type = String(
            response.headers.get('content-type') || ''
        );

        let data = null;

        if (type.includes('application/json')) {
            data = await response.json();
        } else {
            const body = await response.text();
            throw new Error(
                `API ${response.status}: `
                + body.replace(/\s+/g, ' ').trim().slice(0, 160)
            );
        }

        if (!response.ok || !data?.success) {
            const error = new Error(
                data?.message
                || data?.errors?.[0]
                || `REQUEST FAILED (${response.status}).`
            );
            error.data = data;
            throw error;
        }

        return data;
    };

    const forceUppercase = (el) => {
        if (!el) return;

        el.addEventListener('input', () => {
            const start =
                typeof el.selectionStart === 'number'
                    ? el.selectionStart
                    : null;

            const end =
                typeof el.selectionEnd === 'number'
                    ? el.selectionEnd
                    : null;

            const next = upper(el.value);

            if (next !== el.value) {
                el.value = next;

                if (
                    start !== null
                    && end !== null
                    && typeof el.setSelectionRange === 'function'
                ) {
                    try {
                        el.setSelectionRange(start, end);
                    } catch (_) {}
                }
            }
        });
    };

    [
        schoolCode,
        schoolName,
        district,
        address,
        contactName,
        contactPhone,
        contactEmail,
        referrerSearch,
    ].forEach(forceUppercase);

    const ensureSelectOption = (select, value) => {
        const wanted = upper(value).trim();
        if (!select || !wanted) return;

        let option = Array.from(select.options).find(
            (item) => upper(item.value) === wanted
        );

        if (!option) {
            option = document.createElement('option');
            option.value = wanted;
            option.textContent = wanted;
            select.appendChild(option);
        }

        select.value = option.value;
    };

    const setSchoolFieldsLocked = (locked) => {
        registrationFields.disabled = Boolean(locked);

        registerButton.disabled =
            Boolean(locked)
            || !schoolLookupComplete;
    };

    const clearAutofill = (unlockAfterCheck = false) => {
        schoolName.value = '';
        schoolType.value = '';
        state.value = '';
        district.value = '';
        address.value = '';
        contactName.value = '';
        contactPhone.value = '';
        contactEmail.value = '';

        existingSchoolLogo.value = '';
        schoolLogo.required = true;
        schoolLogo.value = '';

        schoolLogoPreview.src = '';
        schoolLogoPreviewWrap.classList.add('d-none');

        existingNotice.classList.add('d-none');
        registeredNotice.classList.add('d-none');

        referrerSearch.value = '';
        referrerSelect.innerHTML =
            '<option value="">NO REFERRER</option>';

        schoolLookupComplete =
            Boolean(unlockAfterCheck);

        setSchoolFieldsLocked(!unlockAfterCheck);
    };

    const applySchool = (school) => {
        schoolName.value = upper(school.name || '');
        ensureSelectOption(schoolType, school.type || '');
        ensureSelectOption(state, school.state || '');

        district.value = upper(school.district || '');
        address.value = upper(school.address || '');
        contactName.value =
            upper(school.contact_name || '');
        contactPhone.value =
            upper(school.contact_phone || '');
        contactEmail.value =
            upper(school.contact_email || '');

        const logoPath =
            String(school.school_logo || '').trim();

        existingSchoolLogo.value = logoPath;
        schoolLogo.required = logoPath === '';

        if (logoPath) {
            schoolLogoPreview.src =
                logoPath.replace(/^\/+/, '');

            schoolLogoPreviewWrap.classList.remove('d-none');
        } else {
            schoolLogoPreview.src = '';
            schoolLogoPreviewWrap.classList.add('d-none');
        }

        const registered =
            Boolean(school.registration_complete);

        schoolLookupComplete = true;
        setSchoolFieldsLocked(registered);

        registeredNotice.classList.toggle(
            'd-none',
            !registered
        );

        existingNotice.classList.toggle(
            'd-none',
            registered
        );

        if (registered) {
            registeredMessage.textContent =
                `${upper(school.name || '')} `
                + `(${upper(school.code || '')}) IS ALREADY REGISTERED. `
                + `ENTER A DIFFERENT SCHOOL CODE OR LOG IN.`;
        }

        return registered;
    };

    const loadReferrers = async () => {
        if (!schoolLookupComplete || registrationFields.disabled) {
            return;
        }

        const q = upper(referrerSearch.value).trim();

        referrerController?.abort();
        referrerController = new AbortController();

        try {
            const params = new URLSearchParams({
                q,
                role: 'referrer',
                limit: '30',
            });

            const data = await fetchJson(
                `api/registration/schools.php?${params.toString()}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'default',
                    signal: referrerController.signal,
                }
            );

            const current = referrerSelect.value;

            referrerSelect.innerHTML =
                '<option value="">NO REFERRER</option>';

            for (const school of data.schools || []) {
                if (
                    upper(school.code)
                    === upper(schoolCode.value)
                ) {
                    continue;
                }

                const option =
                    document.createElement('option');

                option.value = upper(school.code);
                option.textContent =
                    `${upper(school.name)} `
                    + `(${upper(school.code)})`;

                if (
                    current
                    && upper(option.value) === upper(current)
                ) {
                    option.selected = true;
                }

                referrerSelect.appendChild(option);
            }

            const count =
                Number(data.count ?? data.schools?.length ?? 0);

            referrerResult.textContent =
                `${count} SCHOOL${count === 1 ? '' : 'S'} FOUND.`;

            referrerResult.className =
                'form-text text-secondary';
        } catch (error) {
            if (error?.name === 'AbortError') return;

            referrerResult.textContent =
                upper(
                    error.message
                    || 'UNABLE TO SEARCH REFERRERS.'
                );

            referrerResult.className =
                'form-text text-danger';
        }
    };

    const lookupSchool = async () => {
        const code = upper(schoolCode.value)
            .replace(/\s+/g, '')
            .trim();

        schoolCode.value = code;

        if (!code) {
            clearAutofill(false);

            lookupStatus.textContent =
                'ENTER THE SCHOOL CODE. SCHOOL DETAILS WILL BE SEARCHED AUTOMATICALLY.';

            lookupStatus.className =
                'form-text text-secondary';

            return;
        }

        schoolController?.abort();
        schoolController = new AbortController();

        schoolLookupComplete = false;
        setSchoolFieldsLocked(true);

        registeredNotice.classList.add('d-none');
        existingNotice.classList.add('d-none');

        lookupStatus.textContent =
            'SEARCHING SCHOOL...';

        lookupStatus.className =
            'form-text text-secondary';

        try {
            const params = new URLSearchParams({
                q: code,
                exact: '1',
                details: '1',
                limit: '1',
            });

            const data = await fetchJson(
                `api/registration/schools.php?${params.toString()}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'default',
                    signal: schoolController.signal,
                }
            );

            if (
                !Array.isArray(data.schools)
                || data.schools.length === 0
            ) {
                clearAutofill(true);
                schoolCode.value = code;

                lookupStatus.textContent =
                    'SCHOOL CODE NOT FOUND. COMPLETE ALL REQUIRED FIELDS TO REGISTER A NEW SCHOOL.';

                lookupStatus.className =
                    'form-text text-success';

                loadReferrers();
                return;
            }

            const school = data.schools[0];
            const registered = applySchool(school);

            lookupStatus.textContent =
                registered
                    ? 'SCHOOL ALREADY REGISTERED.'
                    : 'EXISTING SCHOOL FOUND. INFORMATION FILLED AUTOMATICALLY.';

            lookupStatus.className =
                registered
                    ? 'form-text text-warning fw-semibold'
                    : 'form-text text-success';

            if (!registered) {
                loadReferrers();
            }
        } catch (error) {
            if (error?.name === 'AbortError') return;

            schoolLookupComplete = false;
            setSchoolFieldsLocked(true);

            lookupStatus.textContent =
                upper(
                    error.message
                    || 'UNABLE TO SEARCH SCHOOL.'
                );

            lookupStatus.className =
                'form-text text-danger';
        }
    };

    schoolCode.addEventListener('input', () => {
        schoolCode.value =
            upper(schoolCode.value).replace(/\s+/g, '');

        schoolController?.abort();
        schoolLookupComplete = false;
        clearAutofill(false);

        clearTimeout(schoolLookupTimer);

        if (schoolCode.value.trim()) {
            lookupStatus.textContent =
                'WAITING FOR SCHOOL CODE CHECK...';

            lookupStatus.className =
                'form-text text-secondary';

            schoolLookupTimer =
                setTimeout(lookupSchool, 350);
        } else {
            lookupStatus.textContent =
                'ENTER THE SCHOOL CODE. SCHOOL DETAILS WILL BE SEARCHED AUTOMATICALLY.';

            lookupStatus.className =
                'form-text text-secondary';
        }
    });

    schoolCode.addEventListener('blur', () => {
        clearTimeout(schoolLookupTimer);
        lookupSchool();
    });

    referrerSearch.addEventListener('input', () => {
        clearTimeout(referrerTimer);
        referrerTimer =
            setTimeout(loadReferrers, 250);
    });

    clearReferrer.addEventListener('click', () => {
        referrerSearch.value = '';
        loadReferrers();
        referrerSearch.focus();
    });

    schoolLogo.addEventListener('change', () => {
        const file = schoolLogo.files?.[0];

        if (!file) {
            if (!existingSchoolLogo.value) {
                schoolLogoPreviewWrap.classList.add('d-none');
            }
            return;
        }

        const url = URL.createObjectURL(file);
        schoolLogoPreview.src = url;
        schoolLogoPreviewWrap.classList.remove('d-none');
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();

        if (!schoolLookupComplete) {
            lookupStatus.textContent =
                'WAIT FOR THE SCHOOL CODE CHECK TO COMPLETE BEFORE REGISTERING.';

            lookupStatus.className =
                'form-text text-warning fw-semibold';

            schoolCode.focus();
            return;
        }

        if (registrationFields.disabled) {
            schoolCode.focus();
            return;
        }

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        if (
            !schoolLogo.files?.length
            && !existingSchoolLogo.value
        ) {
            schoolLogo.setCustomValidity(
                'School logo is required.'
            );
            schoolLogo.reportValidity();
            return;
        }

        schoolLogo.setCustomValidity('');

        registerButton.disabled = true;
        registerButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>'
            + 'REGISTERING...';

        try {
            const data = await fetchJson(
                'api/registration/school_submit.php',
                {
                    method: 'POST',
                    headers: {'Accept': 'application/json'},
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
                error?.data?.errors
                || error?.message
                || 'Unable to register school.'
            );

            registerButton.innerHTML =
                '<i class="bi bi-check2-circle me-2"></i>'
                + 'Register School';

            registerButton.disabled = false;
        }
    });

    schoolLookupComplete = false;
    setSchoolFieldsLocked(true);

    if (form.dataset.initialCode?.trim()) {
        schoolCode.value =
            upper(form.dataset.initialCode).replace(/\s+/g, '');
        lookupSchool();
    }
})();
