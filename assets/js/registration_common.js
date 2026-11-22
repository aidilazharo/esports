(() => {
    'use strict';

    const API_BASE = '/api/registration';
    const schoolCache = new Map();
    const identityCache = new Map();
    const schoolControllers = new WeakMap();

    const upper = (value) => String(value ?? '').toLocaleUpperCase();

    const uppercaseFields = (root = document) => {
        root.querySelectorAll(
            'form.registration-uppercase input[type="text"],' +
            'form.registration-uppercase input[type="search"],' +
            'form.registration-uppercase input[type="tel"],' +
            'form.registration-uppercase input[type="email"],' +
            'form.registration-uppercase textarea'
        ).forEach((input) => {
            if (input.dataset.uppercaseSkip === '1') return;

            const apply = () => {
                const start = typeof input.selectionStart === 'number' ? input.selectionStart : null;
                const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : null;
                const next = upper(input.value);

                if (next !== input.value) {
                    input.value = next;
                    if (start !== null && end !== null && typeof input.setSelectionRange === 'function') {
                        try { input.setSelectionRange(start, end); } catch (_) {}
                    }
                }
            };

            apply();
            input.addEventListener('input', apply);
            input.style.textTransform = 'uppercase';
        });
    };

    const cachedFetchJson = async (url, map, ttlMs, sessionCache = true) => {
        const now = Date.now();
        const memory = map.get(url);

        if (memory && memory.expires > now) return memory.data;

        const sessionKey = 'REGAPI:' + url;

        if (sessionCache) {
            try {
                const raw = sessionStorage.getItem(sessionKey);
                if (raw) {
                    const parsed = JSON.parse(raw);
                    if (parsed.expires > now) {
                        map.set(url, parsed);
                        return parsed.data;
                    }
                }
            } catch (_) {}
        }

        const response = await fetch(url, {
            headers: {'Accept': 'application/json'},
            cache: 'default',
        });

        let data = {};
        try { data = await response.json(); } catch (_) {}

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'REQUEST FAILED.');
        }

        const record = {data, expires: now + ttlMs};
        map.set(url, record);

        if (sessionCache) {
            try { sessionStorage.setItem(sessionKey, JSON.stringify(record)); } catch (_) {}
        }

        return data;
    };

    const setupSchoolPicker = (select) => {
        const search = document.getElementById(select.dataset.searchInput || '');
        const status = document.getElementById(select.dataset.statusTarget || '');
        const clear = document.getElementById(select.dataset.clearButton || '');
        const role = select.dataset.role || '';
        const valueField = select.dataset.valueField || 'id';
        const initialCode = upper(select.dataset.initialCode || '');
        const initialValue = String(select.dataset.initialValue || '');
        const emptyLabel = select.dataset.emptyLabel || 'SELECT SCHOOL';
        const disableCoached = select.dataset.disableCoached === '1';

        let timer = null;
        let requestSerial = 0;
        let loaded = false;

        const render = (schools) => {
            const selectedValue = String(select.value || initialValue);
            select.innerHTML = '';

            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = emptyLabel;
            select.appendChild(empty);

            schools.forEach((school) => {
                const option = document.createElement('option');
                option.value = valueField === 'code'
                    ? String(school.code || '')
                    : String(school.id || '');

                option.textContent =
                    `${school.name} (${school.code})` +
                    `${school.type ? ' · ' + school.type : ''}` +
                    `${school.has_coach ? ' · COACH ALREADY REGISTERED' : ''}`;

                option.dataset.code = school.code || '';
                option.dataset.schoolType = school.type || '';
                option.dataset.hasCoach = school.has_coach ? '1' : '0';

                if (disableCoached && school.has_coach) option.disabled = true;
                if (selectedValue && option.value === selectedValue && !option.disabled) {
                    option.selected = true;
                }

                select.appendChild(option);
            });

            select.dispatchEvent(new CustomEvent('registration:schools-loaded', {
                bubbles: true
            }));
        };

        const load = async (query, exact = false) => {
            const serial = ++requestSerial;
            const firstLoad = !loaded;

            const effectiveQuery =
                query === undefined
                    ? (firstLoad && initialCode ? initialCode : '')
                    : String(query);

            const effectiveExact =
                query === undefined
                    ? Boolean(firstLoad && initialCode)
                    : exact;

            if (status) {
                status.textContent = 'SEARCHING SCHOOLS...';
                status.className = 'form-text text-secondary';
            }

            try {
                const params = new URLSearchParams({
                    q: upper(effectiveQuery),
                    role,
                    limit: '30',
                });
                if (effectiveExact) params.set('exact', '1');

                const data = await cachedFetchJson(
                    `${API_BASE}/schools.php?${params.toString()}`,
                    schoolCache,
                    120000,
                    true
                );

                if (serial !== requestSerial) return null;

                loaded = true;
                render(data.schools || []);

                if (search && effectiveExact && effectiveQuery) {
                    search.value = effectiveQuery;
                }

                if (status) {
                    const count = Number(data.count || 0);
                    status.textContent = `${count} SCHOOL${count === 1 ? '' : 'S'} FOUND.`;
                    status.className = 'form-text text-success';
                }

                return data;
            } catch (error) {
                if (serial !== requestSerial) return null;

                if (status) {
                    status.textContent = upper(error.message || 'UNABLE TO SEARCH SCHOOLS.');
                    status.className = 'form-text text-danger';
                }
                return null;
            }
        };

        search?.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => load(search.value, false), 220);
        });

        clear?.addEventListener('click', () => {
            if (search) search.value = '';
            load('', false);
            search?.focus();
        });

        const controller = {
            load,
            isLoaded: () => loaded,
        };

        schoolControllers.set(select, controller);

        if (select.dataset.deferLoad !== '1') {
            load();
        }

        return controller;
    };

    const loadSchoolSelect = (select, query, exact = false) => {
        if (!select) return Promise.resolve(null);

        const controller = schoolControllers.get(select) || setupSchoolPicker(select);

        if (
            controller.isLoaded() &&
            (query === undefined || query === null || query === '')
        ) {
            return Promise.resolve(null);
        }

        return controller.load(query, exact);
    };

    const checkIdentity = async (input) => {
        const status = document.getElementById(input.dataset.statusTarget || '');
        const role = String(input.dataset.identityRole || '').toLowerCase();
        const endpoint = input.dataset.identityEndpoint || `${API_BASE}/identity.php`;
        const nric = upper(input.value).replace(/[^A-Z0-9]/g, '');

        input.value = nric;
        input.classList.remove('is-valid', 'is-invalid');

        if (!nric) return null;

        if (status) {
            status.textContent = 'CHECKING NRIC / PASSPORT...';
            status.className = 'form-text text-secondary';
        }

        try {
            const params = new URLSearchParams({nric});
            if (role) params.set('role', role);

            const sensitive = role === 'parent' || endpoint.includes('guardian.php');

            const data = await cachedFetchJson(
                `${endpoint}?${params.toString()}`,
                identityCache,
                sensitive ? 15000 : 30000,
                !sensitive
            );

            const duplicateStudent = role === 'student' && Boolean(data.exists);

            input.classList.toggle('is-invalid', duplicateStudent);
            input.classList.toggle('is-valid', !duplicateStudent);

            if (status) {
                status.textContent = upper(data.message || '');
                status.className = duplicateStudent
                    ? 'form-text text-danger'
                    : (data.exists ? 'form-text text-info' : 'form-text text-success');
            }

            input.dispatchEvent(new CustomEvent('registration:identity-result', {
                bubbles: true,
                detail: data,
            }));

            return data;
        } catch (error) {
            input.classList.remove('is-valid', 'is-invalid');

            if (status) {
                status.textContent = upper(error.message || 'UNABLE TO CHECK NRIC / PASSPORT.');
                status.className = 'form-text text-warning';
            }

            return null;
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        uppercaseFields();

        document
            .querySelectorAll('select[data-registration-school-select="1"]')
            .forEach(setupSchoolPicker);

        document
            .querySelectorAll('input[data-registration-identity="1"]')
            .forEach((input) => {
                let timer = null;

                input.addEventListener('blur', () => {
                    if (input.value.trim().length >= 4) checkIdentity(input);
                });

                input.addEventListener('input', () => {
                    clearTimeout(timer);
                    input.classList.remove('is-valid', 'is-invalid');

                    timer = setTimeout(() => {
                        if (input.value.trim().length >= 4) {
                            checkIdentity(input);
                        }
                    }, 450);
                });
            });

        window.MetadataRegistration = {
            uppercase: upper,
            checkIdentity,
            loadSchoolSelect,
        };
    });
})();
