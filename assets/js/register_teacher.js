(() => {
    'use strict';

    const form =
        document.getElementById('teacherRegistrationForm');

    if (!form) return;

    const csrf =
        form.querySelector('input[name="csrf_token"]')?.value || '';

    const schoolSearch =
        document.getElementById('schoolSearch');

    const clearSchoolSearch =
        document.getElementById('clearSchoolSearch');

    const schoolSelect =
        document.getElementById('schoolId');

    const schoolSearchResult =
        document.getElementById('schoolSearchResult');

    const identityInput =
        document.getElementById('teacherIdentityNumber');

    const identityStatus =
        document.getElementById('identityNumberStatus');

    const teacherDetails =
        document.getElementById('teacherDetailsFieldset');

    const registerButton =
        document.getElementById('registerTeacherButton');

    const nameInput =
        form.querySelector('input[name="full_name"]');

    const phoneInput =
        document.getElementById('teacherPhone');

    const emailInput =
        document.getElementById('teacherEmail');

    const errorsBox =
        document.getElementById('teacherRegistrationErrors');

    let identityTimer = null;
    let identityController = null;
    let schoolTimer = null;
    let schoolController = null;
    let teacherIdentityAvailable = false;

    const schoolCache = new Map();
    const identityCache = new Map();

    const upper = (value) =>
        String(value ?? '').toLocaleUpperCase();

    const normalizeIdentity = (value) =>
        upper(value).replace(/[^A-Z0-9]/g, '');

    const escapeHtml = (value) => {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    };

    const showErrors = (errors) => {
        const list = Array.isArray(errors)
            ? errors
            : [String(errors || 'Unable to register teacher.')];

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
                start !== null
                && end !== null
                && typeof input.setSelectionRange === 'function'
            ) {
                try {
                    input.setSelectionRange(start, end);
                } catch (_) {}
            }
        });
    });

    const setTeacherDetailsEnabled = (enabled) => {
        teacherIdentityAvailable = Boolean(enabled);
        teacherDetails.disabled = !teacherIdentityAvailable;

        registerButton.disabled =
            !teacherIdentityAvailable
            || !schoolSelect.value;
    };

    const renderSchools = (schools) => {
        const selected = schoolSelect.value;

        schoolSelect.innerHTML =
            '<option value="">SELECT SCHOOL</option>';

        schools.forEach((school) => {
            const option =
                document.createElement('option');

            option.value = String(school.id || '');
            option.dataset.code =
                upper(school.code || '');

            option.textContent =
                `${upper(school.name || '')} `
                + `(${upper(school.code || '')})`;

            if (
                selected
                && option.value === selected
            ) {
                option.selected = true;
            }

            schoolSelect.appendChild(option);
        });
    };

    const loadSchools = async (query = '', exact = false) => {
        const q = upper(query.trim());
        const key = `${exact ? 'E:' : 'Q:'}${q}`;

        const cached = schoolCache.get(key);

        if (cached && cached.expires > Date.now()) {
            renderSchools(cached.schools);

            if (exact && cached.schools[0]) {
                schoolSelect.value =
                    String(cached.schools[0].id || '');
            }

            const cachedCount = cached.schools.length;

            if (exact) {
                schoolSearchResult.textContent =
                    cachedCount > 0
                        ? 'SCHOOL FOUND.'
                        : 'SCHOOL NOT FOUND.';
            } else if (q === '') {
                schoolSearchResult.textContent =
                    cachedCount >= 30
                        ? 'SHOWING FIRST 30 SCHOOLS. TYPE SCHOOL NAME OR CODE TO SEARCH ALL SCHOOLS.'
                        : `${cachedCount} SCHOOL${cachedCount === 1 ? '' : 'S'} AVAILABLE.`;
            } else {
                schoolSearchResult.textContent =
                    cachedCount === 0
                        ? 'NO MATCHING SCHOOL FOUND.'
                        : `${cachedCount} MATCHING SCHOOL${cachedCount === 1 ? '' : 'S'} FOUND.`;
            }

            schoolSearchResult.className =
                cachedCount === 0 && q !== ''
                    ? 'form-text text-warning'
                    : 'form-text text-success';

            setTeacherDetailsEnabled(
                teacherIdentityAvailable
            );

            return;
        }

        schoolController?.abort();
        schoolController = new AbortController();

        schoolSearchResult.textContent =
            'SEARCHING SCHOOLS...';

        schoolSearchResult.className =
            'form-text text-secondary';

        try {
            /*
             * Keep initial page load lightweight: only request 30 suggestions.
             * Once the user types a school name/code, the API performs a
             * database search across all schools and returns the first 50
             * matching results.
             */
            const params = new URLSearchParams({
                q,
                limit: q === '' ? '30' : '50',
            });

            if (exact) {
                params.set('exact', '1');
                params.set('details', '1');
            }

            const data = await fetchJson(
                `api/registration/schools.php?${params.toString()}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'default',
                    signal: schoolController.signal,
                }
            );

            const schools =
                Array.isArray(data.schools)
                    ? data.schools
                    : [];

            schoolCache.set(key, {
                schools,
                expires: Date.now() + 120000,
            });

            renderSchools(schools);

            if (exact && schools[0]) {
                schoolSelect.value =
                    String(schools[0].id || '');
            }

            const resultCount = schools.length;

            if (exact) {
                schoolSearchResult.textContent =
                    resultCount > 0
                        ? 'SCHOOL FOUND.'
                        : 'SCHOOL NOT FOUND.';
            } else if (q === '') {
                schoolSearchResult.textContent =
                    resultCount >= 30
                        ? 'SHOWING FIRST 30 SCHOOLS. TYPE SCHOOL NAME OR CODE TO SEARCH ALL SCHOOLS.'
                        : `${resultCount} SCHOOL${resultCount === 1 ? '' : 'S'} AVAILABLE.`;
            } else {
                schoolSearchResult.textContent =
                    resultCount === 0
                        ? 'NO MATCHING SCHOOL FOUND.'
                        : `${resultCount} MATCHING SCHOOL${resultCount === 1 ? '' : 'S'} FOUND.`;
            }

            schoolSearchResult.className =
                resultCount === 0 && q !== ''
                    ? 'form-text text-warning'
                    : 'form-text text-success';

            setTeacherDetailsEnabled(
                teacherIdentityAvailable
            );
        } catch (error) {
            if (error?.name === 'AbortError') return;

            schoolSearchResult.textContent =
                upper(
                    error.message
                    || 'UNABLE TO SEARCH SCHOOLS.'
                );

            schoolSearchResult.className =
                'form-text text-danger';
        }
    };

    schoolSearch.addEventListener('input', () => {
        clearTimeout(schoolTimer);

        schoolTimer = setTimeout(
            () => loadSchools(
                schoolSearch.value,
                false
            ),
            250
        );
    });

    clearSchoolSearch.addEventListener('click', () => {
        schoolSearch.value = '';
        loadSchools('', false);
        schoolSearch.focus();
    });

    schoolSelect.addEventListener('change', () => {
        setTeacherDetailsEnabled(
            teacherIdentityAvailable
        );
    });

    const applyIdentityResult = (data) => {
        const duplicate = Boolean(data.exists);

        identityInput.classList.toggle(
            'is-invalid',
            duplicate
        );

        identityInput.classList.toggle(
            'is-valid',
            !duplicate
        );

        identityStatus.textContent =
            upper(data.message || '');

        identityStatus.className =
            duplicate
                ? 'form-text text-danger'
                : 'form-text text-success';

        setTeacherDetailsEnabled(!duplicate);
    };

    const checkTeacherIdentity = async () => {
        const identity =
            normalizeIdentity(identityInput.value);

        identityInput.value = identity;

        if (identity.length < 4) {
            setTeacherDetailsEnabled(false);

            identityInput.classList.remove(
                'is-valid',
                'is-invalid'
            );

            identityStatus.textContent =
                'ENTER 4 OR MORE NUMBERS / CHARACTERS TO CHECK.';

            identityStatus.className =
                'form-text';

            return false;
        }

        const cached =
            identityCache.get(identity);

        if (cached && cached.expires > Date.now()) {
            applyIdentityResult(cached.data);
            return !cached.data.exists;
        }

        identityController?.abort();
        identityController = new AbortController();

        setTeacherDetailsEnabled(false);

        identityStatus.textContent =
            'CHECKING NRIC / PASSPORT...';

        identityStatus.className =
            'form-text text-secondary';

        try {
            const data = await fetchJson(
                `api/registration/teacher_identity.php?identity_number=${encodeURIComponent(identity)}`,
                {
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store',
                    signal: identityController.signal,
                }
            );

            identityCache.set(identity, {
                data,
                expires: Date.now() + 30000,
            });

            applyIdentityResult(data);

            return !data.exists;
        } catch (error) {
            if (error?.name === 'AbortError') {
                return false;
            }

            setTeacherDetailsEnabled(false);

            identityInput.classList.remove(
                'is-valid',
                'is-invalid'
            );

            identityStatus.textContent =
                upper(
                    error.message
                    || 'UNABLE TO CHECK NRIC / PASSPORT.'
                );

            identityStatus.className =
                'form-text text-warning';

            return false;
        }
    };

    identityInput.addEventListener('input', () => {
        setTeacherDetailsEnabled(false);
        clearTimeout(identityTimer);

        const identity =
            normalizeIdentity(identityInput.value);

        identityInput.value = identity;

        identityInput.classList.remove(
            'is-valid',
            'is-invalid'
        );

        if (identity.length < 4) {
            identityStatus.textContent =
                'ENTER 4 OR MORE NUMBERS / CHARACTERS TO CHECK.';

            identityStatus.className =
                'form-text';

            return;
        }

        identityStatus.textContent =
            'WAITING TO CHECK NRIC / PASSPORT...';

        identityStatus.className =
            'form-text text-secondary';

        identityTimer =
            setTimeout(checkTeacherIdentity, 300);
    });

    identityInput.addEventListener('blur', () => {
        clearTimeout(identityTimer);

        if (
            normalizeIdentity(identityInput.value).length >= 4
        ) {
            checkTeacherIdentity();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();

        if (!teacherIdentityAvailable) {
            identityInput.focus();
            return;
        }

        if (!schoolSelect.value) {
            schoolSelect.focus();
            return;
        }

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        registerButton.disabled = true;
        registerButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>'
            + 'REGISTERING...';

        try {
            const data = await fetchJson(
                'api/registration/teacher_submit.php',
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
                || 'Unable to register teacher.'
            );

            registerButton.innerHTML =
                '<i class="bi bi-person-badge me-2"></i>'
                + 'Register Teacher';

            setTeacherDetailsEnabled(
                teacherIdentityAvailable
            );
        }
    });

    setTeacherDetailsEnabled(false);

    const initialCode =
        upper(form.dataset.initialSchoolCode || '');

    if (initialCode) {
        schoolSearch.value = initialCode;
        loadSchools(initialCode, true);
    } else {
        loadSchools('', false);
    }
})();
